<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Exceptions\StockException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only place where stock levels and stock movements are written.
 *
 * Quantities are decimal strings with at most 3 decimals and are handled with
 * BCMath; they never pass through PHP floats.
 */
class StockService
{
    private const SCALE = 3;

    /** Largest value a DECIMAL(14,3) column can hold. */
    private const MAX_BALANCE = '99999999999.999';

    /** Deadlock retries (only the outermost transaction retries). */
    private const TRANSACTION_ATTEMPTS = 5;

    /**
     * Record the initial stock of a variant in an outlet. Allowed once per pair.
     *
     * @param  string|int|float  $quantity  Positive decimal string (floats are rejected).
     */
    public function recordOpening(
        User $actor,
        Outlet $outlet,
        ProductVariant $variant,
        string|int|float $quantity,
        ?string $note = null,
    ): StockMovement {
        return $this->apply(StockMovementType::Opening, $actor, $outlet, $variant, $quantity, $note);
    }

    /**
     * Change the stock by a signed, non-zero delta. The result may not be negative.
     *
     * @param  string|int|float  $quantityDelta  Signed decimal string (floats are rejected).
     */
    public function adjust(
        User $actor,
        Outlet $outlet,
        ProductVariant $variant,
        string|int|float $quantityDelta,
        ?string $note = null,
    ): StockMovement {
        return $this->apply(StockMovementType::Adjustment, $actor, $outlet, $variant, $quantityDelta, $note);
    }

    /**
     * Deduct an order's quantities from the stock of the order's outlet, writing
     * one immutable negative "sale" movement per order item.
     *
     * Called by OrderService::completeSale() inside the transaction that already
     * holds the order lock. It is public only because PHP has no package-private
     * visibility. It opens its own transaction, which joins the caller's, so even
     * when called alone it is all-or-nothing. Lock order, for every caller: the
     * order row, then its items, then the stock levels by ascending variant id,
     * which keeps two sales from deadlocking each other.
     *
     * Everything is re-read from the database and validated before anything is
     * written. A variant without a stock level counts as having no stock: a sale
     * never creates a balance (a missing row is gap-locked only until the sale is
     * rejected right away). Reads that are not locking reads run after the order
     * lock, so they see every earlier completion. The unique key
     * (order_item_id, type) is the database backstop against deducting an item twice.
     *
     * @return list<StockMovement> one movement per order item, in ascending variant id order
     *
     * @throws StockException
     */
    public function recordSale(User $actor, Order $order): array
    {
        return DB::transaction(function () use ($actor, $order): array {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();
            $outlet = $locked === null ? null : Outlet::query()->find($locked->outlet_id);

            if ($locked === null || $outlet === null) {
                throw StockException::notFound();
            }

            if ((int) $outlet->business_id !== (int) $locked->business_id) {
                throw StockException::businessMismatch();
            }

            $items = OrderItem::query()
                ->with('productVariant')
                ->where('order_id', $locked->getKey())
                ->orderBy('product_variant_id')
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                throw StockException::emptySale();
            }

            foreach ($items as $item) {
                if ($item->productVariant === null) {
                    throw StockException::notFound();
                }

                $this->resolveScope($actor, $outlet, $item->productVariant);
            }

            $alreadySold = StockMovement::query()
                ->where('type', StockMovementType::Sale->value)
                ->whereIn('order_item_id', $items->modelKeys())
                ->exists();

            if ($alreadySold) {
                throw StockException::saleAlreadyRecorded();
            }

            $outletId = (int) $outlet->getKey();

            /** @var list<array{item_id: int, variant_id: int, level: StockLevel, delta: string, after: string}> $plan */
            $plan = [];

            foreach ($items as $item) {
                $variantId = (int) $item->product_variant_id;
                $required = bcadd((string) $item->quantity, '0', self::SCALE);
                $delta = bcsub('0', $required, self::SCALE);

                $level = $this->lockLevel($outletId, $variantId)
                    ?? throw StockException::insufficientStock('0.000', $delta, $variantId);

                $plan[] = [
                    'item_id' => (int) $item->getKey(),
                    'variant_id' => $variantId,
                    'level' => $level,
                    'delta' => $delta,
                    'after' => $this->nextBalance($this->balanceOf($level), $delta, $variantId),
                ];
            }

            $movements = [];

            foreach ($plan as $line) {
                $movements[] = $this->insertMovement(
                    StockMovementType::Sale,
                    $outletId,
                    $line['variant_id'],
                    $line['delta'],
                    $line['after'],
                    (int) $actor->getKey(),
                    null,
                    $line['item_id'],
                );

                $this->writeBalance($line['level'], $line['after']);
            }

            return $movements;
        }, self::TRANSACTION_ATTEMPTS);
    }

    private function apply(
        StockMovementType $type,
        User $actor,
        Outlet $outlet,
        ProductVariant $variant,
        string|int|float $quantity,
        ?string $note,
    ): StockMovement {
        $delta = $this->normalizeDelta($type, $quantity);
        $note = $this->normalizeNote($note);

        [$outletId, $variantId] = $this->resolveScope($actor, $outlet, $variant);

        return DB::transaction(function () use ($type, $actor, $outletId, $variantId, $delta, $note): StockMovement {
            $level = $this->lockOrCreateLevel($outletId, $variantId);

            if ($type === StockMovementType::Opening && $this->openingExists($outletId, $variantId)) {
                throw StockException::duplicateOpening();
            }

            $after = $this->nextBalance($this->balanceOf($level), $delta);

            $movement = $this->insertMovement(
                $type,
                $outletId,
                $variantId,
                $delta,
                $after,
                (int) $actor->getKey(),
                $note,
            );

            $this->writeBalance($level, $after);

            return $movement;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * The balance of a locked level as a decimal string at scale 3.
     */
    private function balanceOf(StockLevel $level): string
    {
        return bcadd((string) $level->quantity, '0', self::SCALE);
    }

    /**
     * Add a signed delta to a balance and enforce the balance rules: never
     * negative, never above the column maximum. Shared by every stock write.
     */
    private function nextBalance(string $current, string $delta, ?int $variantId = null): string
    {
        $after = bcadd($current, $delta, self::SCALE);

        if (bccomp($after, '0', self::SCALE) < 0) {
            throw StockException::insufficientStock($current, $delta, $variantId);
        }

        if (bccomp($after, self::MAX_BALANCE, self::SCALE) > 0) {
            throw StockException::quantityOverflow();
        }

        return $after;
    }

    /**
     * Insert one immutable ledger row. Shared by every stock write.
     */
    private function insertMovement(
        StockMovementType $type,
        int $outletId,
        int $variantId,
        string $delta,
        string $after,
        int $actorId,
        ?string $note,
        ?int $orderItemId = null,
    ): StockMovement {
        $attributes = [
            'outlet_id' => $outletId,
            'product_variant_id' => $variantId,
            'type' => $type,
            'quantity_delta' => $delta,
            'quantity_after' => $after,
            'user_id' => $actorId,
            'note' => $note,
        ];

        if ($orderItemId !== null) {
            $attributes['order_item_id'] = $orderItemId;
        }

        $movement = new StockMovement;
        $movement->forceFill($attributes)->save();

        return $movement;
    }

    /**
     * Store the new balance of a locked level. Shared by every stock write.
     */
    private function writeBalance(StockLevel $level, string $after): void
    {
        StockLevel::query()
            ->whereKey($level->getKey())
            ->update(['quantity' => $after, 'updated_at' => now()]);
    }

    /**
     * Validate the quantity format and return it as a signed decimal string at scale 3.
     */
    private function normalizeDelta(StockMovementType $type, string|int|float $quantity): string
    {
        if (is_float($quantity)) {
            throw StockException::invalidQuantity('Quantities must be decimal strings, not floats.');
        }

        $quantity = (string) $quantity;

        // Optional minus, up to 11 integer digits (DECIMAL(14,3)), up to 3 decimals.
        // More than 3 decimals is rejected rather than silently rounded.
        if (! preg_match('/^-?\d{1,11}(\.\d{1,3})?$/D', $quantity)) {
            throw StockException::invalidQuantity('The quantity must be a decimal number with at most 3 decimal places.');
        }

        $delta = bcadd($quantity, '0', self::SCALE);
        $sign = bccomp($delta, '0', self::SCALE);

        if ($sign === 0) {
            throw StockException::zeroQuantity();
        }

        if ($type === StockMovementType::Opening && $sign < 0) {
            throw StockException::invalidQuantity('The opening stock quantity must be positive.');
        }

        return $delta;
    }

    private function normalizeNote(?string $note): ?string
    {
        $note = $note === null ? null : trim($note);

        if ($note === null || $note === '') {
            return null;
        }

        if (mb_strlen($note) > 255) {
            throw StockException::invalidNote();
        }

        return $note;
    }

    /**
     * Re-read the outlet and variant from the database (never trust the passed
     * models' attributes), then check ownership and that both share one business.
     *
     * @return array{0: int, 1: int} outlet id and variant id
     */
    private function resolveScope(User $actor, Outlet $outlet, ProductVariant $variant): array
    {
        $freshOutlet = Outlet::query()->with('business:id,user_id')->find($outlet->getKey());
        $freshVariant = ProductVariant::query()->with('product:id,business_id')->find($variant->getKey());

        if ($freshOutlet?->business === null || $freshVariant?->product === null) {
            throw StockException::notFound();
        }

        if ((int) $freshOutlet->business->user_id !== (int) $actor->getKey()) {
            throw StockException::unauthorized();
        }

        if ((int) $freshVariant->product->business_id !== (int) $freshOutlet->business_id) {
            throw StockException::businessMismatch();
        }

        return [(int) $freshOutlet->getKey(), (int) $freshVariant->getKey()];
    }

    /**
     * Return the locked stock-level row, creating it first if it does not exist.
     *
     * The lock is attempted first: for an existing row (the common case) this
     * avoids the shared-lock upgrade deadlock that INSERT IGNORE + FOR UPDATE
     * causes between two concurrent writers. Only when the row is missing do we
     * insert it, relying on the unique (outlet_id, product_variant_id) key to
     * make concurrent first writes safe, then lock it again.
     */
    private function lockOrCreateLevel(int $outletId, int $variantId): StockLevel
    {
        $level = $this->lockLevel($outletId, $variantId);

        if ($level !== null) {
            return $level;
        }

        $now = now();

        DB::table('stock_levels')->insertOrIgnore([
            'outlet_id' => $outletId,
            'product_variant_id' => $variantId,
            'quantity' => '0.000',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // INSERT IGNORE also swallows foreign-key errors, so a still-missing row
        // means the outlet or variant vanished meanwhile.
        return $this->lockLevel($outletId, $variantId) ?? throw StockException::notFound();
    }

    private function lockLevel(int $outletId, int $variantId): ?StockLevel
    {
        return StockLevel::query()
            ->where('outlet_id', $outletId)
            ->where('product_variant_id', $variantId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Called while holding the stock-level row lock, so writers of the same pair
     * are serialized. A locking read always sees the latest committed rows, even
     * if the surrounding transaction already holds an older snapshot.
     */
    private function openingExists(int $outletId, int $variantId): bool
    {
        return StockMovement::query()
            ->where('outlet_id', $outletId)
            ->where('product_variant_id', $variantId)
            ->where('type', StockMovementType::Opening->value)
            ->lockForUpdate()
            ->value('id') !== null;
    }
}
