<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\OrderException;
use App\Exceptions\StockException;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only place where orders and order items are written.
 *
 * Lifecycle: an order is "open" while items can be added and "completed" once it
 * is operationally finished. Completed does NOT mean paid: totals and payment
 * settlement are designed in later tasks. completeOrder() changes only the status
 * and never touches stock; completeSale() deducts the stock and completes the
 * order in one transaction. Completion is terminal here, and that is enforced by
 * this service only, not by the database.
 *
 * Every model passed in is re-read from the database; its in-memory attributes
 * are never trusted. Operations that change an existing order lock the order row
 * first, which is designed to serialize concurrent writers of one order. The
 * order status and the order's item rows are then read with locking reads; the
 * business owner and the variant with its product are plain reads, so a catalog
 * change committed at the same moment may or may not be reflected in a snapshot.
 * This has only been exercised sequentially: multi-process behaviour is verified
 * in a later phase and is not claimed to be proven.
 *
 * Database errors are never caught here. Integrity violations, deadlocks that
 * outlive the retries and lock timeouts propagate unchanged.
 */
class OrderService
{
    /** Largest quantity of one variant in one order; equals the database CHECK. */
    public const MAX_QUANTITY = 1_000_000;

    /** Deadlock retries (only the outermost transaction retries). */
    private const TRANSACTION_ATTEMPTS = 5;

    private readonly StockService $stock;

    public function __construct(?StockService $stock = null)
    {
        $this->stock = $stock ?? new StockService;
    }

    /**
     * Create an open order for an outlet of a business the actor owns.
     *
     * The business and outlet rows are not locked: inserting the order takes
     * shared locks on them through the foreign keys, which coordinates with
     * Business::delete().
     *
     * @throws OrderException
     */
    public function createOrder(User $actor, Business $business, Outlet $outlet): Order
    {
        [$businessId, $outletId] = $this->resolveCreateScope($actor, $business, $outlet);

        return DB::transaction(function () use ($businessId, $outletId): Order {
            $order = new Order;
            $order->forceFill([
                'business_id' => $businessId,
                'outlet_id' => $outletId,
                'status' => OrderStatus::Open,
                'completed_at' => null,
            ])->save();

            return $order->refresh();
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Add a variant to an open order.
     *
     * The first time a variant is added, its product name, variant name, SKU and
     * price are copied into the item as a historical snapshot. Adding the same
     * variant again only increases the quantity; the snapshot, including the
     * initial price, is kept.
     *
     * A bool never reaches the validation as a bool: without strict_types in the
     * caller PHP coerces it to int before this method runs (true reads as 1, false
     * as 0, which is invalid), and with strict_types it is a TypeError. Callers
     * must pass validated input.
     *
     * @param  string|int|float  $quantity  Whole number from 1 to MAX_QUANTITY (floats are rejected).
     *
     * @throws OrderException
     */
    public function addItem(User $actor, Order $order, ProductVariant $variant, string|int|float $quantity): OrderItem
    {
        $quantity = $this->normalizeQuantity($quantity);

        return DB::transaction(function () use ($actor, $order, $variant, $quantity): OrderItem {
            $locked = $this->lockOrderForActor($actor, $order);
            $this->assertOpen($locked);

            $freshVariant = ProductVariant::query()
                ->with('product:id,business_id,name')
                ->find($variant->getKey());

            if ($freshVariant?->product === null) {
                throw OrderException::notFound();
            }

            if ((int) $freshVariant->product->business_id !== (int) $locked->business_id) {
                throw OrderException::businessMismatch();
            }

            $item = OrderItem::query()
                ->where('order_id', $locked->getKey())
                ->where('product_variant_id', $freshVariant->getKey())
                ->lockForUpdate()
                ->first();

            if ($item === null) {
                $item = new OrderItem;
                $item->forceFill([
                    'order_id' => $locked->getKey(),
                    'product_variant_id' => $freshVariant->getKey(),
                    'product_name' => $freshVariant->product->name,
                    'variant_name' => $freshVariant->name,
                    'sku' => $freshVariant->sku,
                    'unit_price' => $freshVariant->price,
                    'quantity' => $quantity,
                ])->save();
            } else {
                $combined = (int) $item->quantity + $quantity;

                if ($combined > self::MAX_QUANTITY) {
                    throw OrderException::quantityOverflow();
                }

                $item->forceFill(['quantity' => $combined])->save();
            }

            return $item->refresh();
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Mark an open order as operationally completed.
     *
     * This changes only status and completed_at. It does not mean the order has
     * been paid, it calculates no total and it does not touch stock. An order
     * without items cannot be completed, and a completed order cannot be
     * completed again.
     *
     * @throws OrderException
     */
    public function completeOrder(User $actor, Order $order): Order
    {
        return DB::transaction(function () use ($actor, $order): Order {
            $locked = $this->lockOrderForActor($actor, $order);
            $this->assertOpen($locked);

            $hasItems = OrderItem::query()
                ->where('order_id', $locked->getKey())
                ->lockForUpdate()
                ->value('id') !== null;

            if (! $hasItems) {
                throw OrderException::emptyOrder();
            }

            $locked->forceFill([
                'status' => OrderStatus::Completed,
                'completed_at' => now(),
            ])->save();

            return $locked->refresh();
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Sell an open order: deduct its stock and complete it, all or nothing.
     *
     * In one transaction this locks the order for the actor, requires it to be
     * open and to have items, lets StockService::recordSale() deduct every line
     * and write one sale movement per item, and only then marks the order
     * completed. Any failure rolls the stock updates, the movements and the status
     * change back together. A StockException (for example insufficient stock) is
     * not wrapped: callers read its reason and productVariantId. A completed order
     * is rejected as not open, so stock is never deducted twice.
     *
     * Like completeOrder(), this does not mean the order has been paid. Only
     * sequential behaviour has been tested; multi-process behaviour is verified
     * in a later stage and is not claimed to be proven.
     *
     * @throws OrderException
     * @throws StockException
     */
    public function completeSale(User $actor, Order $order): Order
    {
        return DB::transaction(function () use ($actor, $order): Order {
            $locked = $this->lockOrderForActor($actor, $order);
            $this->assertOpen($locked);

            $hasItems = OrderItem::query()
                ->where('order_id', $locked->getKey())
                ->lockForUpdate()
                ->value('id') !== null;

            if (! $hasItems) {
                throw OrderException::emptyOrder();
            }

            $this->stock->recordSale($actor, $locked);

            $locked->forceFill([
                'status' => OrderStatus::Completed,
                'completed_at' => now(),
            ])->save();

            return $locked->refresh();
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Re-read the business and outlet from the database (never trust the passed
     * models' attributes), then check ownership and that both match.
     *
     * @return array{0: int, 1: int} business id and outlet id
     */
    private function resolveCreateScope(User $actor, Business $business, Outlet $outlet): array
    {
        $freshBusiness = Business::query()->find($business->getKey());
        $freshOutlet = Outlet::query()->find($outlet->getKey());

        if ($freshBusiness === null || $freshOutlet === null) {
            throw OrderException::notFound();
        }

        if ((int) $freshBusiness->user_id !== (int) $actor->getKey()) {
            throw OrderException::unauthorized();
        }

        if ((int) $freshOutlet->business_id !== (int) $freshBusiness->getKey()) {
            throw OrderException::businessMismatch();
        }

        return [(int) $freshBusiness->getKey(), (int) $freshOutlet->getKey()];
    }

    /**
     * Lock the order row, then check that the actor owns its business. Must run
     * inside the transaction, as the first statement of the critical section.
     */
    private function lockOrderForActor(User $actor, Order $order): Order
    {
        $locked = Order::query()
            ->whereKey($order->getKey())
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            throw OrderException::notFound();
        }

        $ownerId = Business::query()->whereKey($locked->business_id)->value('user_id');

        if ($ownerId === null || (int) $ownerId !== (int) $actor->getKey()) {
            throw OrderException::unauthorized();
        }

        return $locked;
    }

    private function assertOpen(Order $order): void
    {
        if ($order->status !== OrderStatus::Open) {
            throw OrderException::orderNotOpen();
        }
    }

    /**
     * Validate the quantity and return it as an int. Anything that is not a plain
     * whole number of at least 1 is rejected rather than coerced.
     */
    private function normalizeQuantity(string|int|float $quantity): int
    {
        if (is_float($quantity)) {
            throw OrderException::invalidQuantity('Quantities must be whole numbers, not floats.');
        }

        $text = (string) $quantity;

        if (! preg_match('/^[1-9]\d*$/D', $text)) {
            throw OrderException::invalidQuantity('The quantity must be a whole number of at least 1.');
        }

        // Digits only from here. A string with more digits than MAX_QUANTITY is
        // always above the limit, so it is rejected before the int cast.
        if (strlen($text) > strlen((string) self::MAX_QUANTITY) || (int) $text > self::MAX_QUANTITY) {
            throw OrderException::quantityOverflow();
        }

        return (int) $text;
    }
}
