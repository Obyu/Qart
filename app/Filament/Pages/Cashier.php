<?php

namespace App\Filament\Pages;

use App\Enums\OrderStatus;
use App\Exceptions\OrderException;
use App\Exceptions\StockException;
use App\Models\Business;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Services\OrderService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Cashier v0: a minimal point-of-sale screen on top of OrderService.
 *
 * Security model: every ID held in a public property (business, outlet, variant,
 * order) is client-controlled and is never trusted. Each action and each render
 * re-resolves them from the database, scoped to the authenticated user: the
 * business through the user's own businesses, the outlet through that business,
 * the variant through that business's products, and the order through the
 * selected business and outlet. An ID that does not resolve is treated as absent.
 *
 * All writes go through OrderService (createOrder, addItem, completeSale). The
 * page never writes stock, order status or order items itself, and the order is
 * created on the first successful add, never on render.
 */
class Cashier extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?string $navigationLabel = 'Cashier';

    protected static ?string $title = 'Cashier';

    protected static ?int $navigationSort = -1;

    protected string $view = 'filament.pages.cashier';

    /** Variants listed in the picker: a demo-sized catalogue, no search yet. */
    private const VARIANT_LIMIT = 500;

    public string|int|null $businessId = null;

    public string|int|null $outletId = null;

    public string|int|null $variantId = null;

    public string $quantity = '1';

    public string|int|null $orderId = null;

    /** Feedback lives for one request only: it is not synced to the browser. */
    protected ?string $feedbackType = null;

    protected ?string $feedbackMessage = null;

    public function mount(): void
    {
        $businesses = auth()->user()?->businesses()->limit(2)->get();

        if ($businesses !== null && $businesses->count() === 1) {
            $this->businessId = $businesses->first()->getKey();
            $this->preselectOutlet();
        }
    }

    public function updatedBusinessId(): void
    {
        $this->outletId = null;
        $this->resetSale();
        $this->preselectOutlet();
    }

    public function updatedOutletId(): void
    {
        $this->resetSale();
    }

    /**
     * Add the selected variant and quantity to the current order, creating the
     * order first if there is none yet.
     */
    public function addItem(): void
    {
        $user = auth()->user();
        $business = $this->resolveBusiness();
        $outlet = $this->resolveOutlet($business);

        if ($user === null || $business === null || $outlet === null) {
            $this->showError('Select one of your businesses and an outlet first.');

            return;
        }

        $variant = $this->resolveVariant($business);

        if ($variant === null) {
            $this->showError('Select a product variant from the list.');

            return;
        }

        // Checked before anything is written, so a typo cannot leave an empty
        // order behind. OrderService validates the quantity again regardless.
        $quantity = $this->submittedQuantity();

        if ($quantity === null) {
            $this->showError('Enter a whole-number quantity of at least 1.');

            return;
        }

        $order = null;

        try {
            $service = app(OrderService::class);
            $order = $this->resolveOrder($business, $outlet);

            if ($order === null) {
                $order = $service->createOrder($user, $business, $outlet);
                $this->orderId = $order->getKey();
            }

            $service->addItem($user, $order, $variant, $quantity);

            $this->variantId = null;
            $this->quantity = '1';
            $this->showSuccess("Added {$quantity} x {$variant->product?->name} - {$variant->name}.");
        } catch (OrderException|StockException $e) {
            $this->showError($this->describeFailure($e, $order));
        } catch (Throwable $e) {
            report($e);
            $this->showError('Something went wrong. Nothing was changed. Please try again.');
        }
    }

    /**
     * Complete the sale of the current order: stock deduction and completion
     * happen together inside OrderService::completeSale().
     */
    public function checkout(): void
    {
        $user = auth()->user();
        $business = $this->resolveBusiness();
        $outlet = $this->resolveOutlet($business);

        if ($user === null || $business === null || $outlet === null) {
            $this->showError('Select one of your businesses and an outlet first.');

            return;
        }

        $order = $this->resolveOrder($business, $outlet);

        if ($order === null) {
            $this->showError('There is no sale in progress. Add an item first.');

            return;
        }

        try {
            $completed = app(OrderService::class)->completeSale($user, $order);
            $total = $this->presentOrder($completed)['total'];

            $this->showSuccess("Sale completed. Total {$total}. Stock has been deducted.");
        } catch (OrderException|StockException $e) {
            $this->showError($this->describeFailure($e, $order));
        } catch (Throwable $e) {
            report($e);
            $this->showError('Something went wrong. Nothing was changed. Please try again.');
        }
    }

    /**
     * Leave the current order and start an empty screen. An open order is not
     * cancelled (OrderService has no cancel): it stays open and holds no stock.
     */
    public function newSale(): void
    {
        $this->resetSale();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $business = $this->resolveBusiness();
        $outlet = $this->resolveOutlet($business);
        $order = $this->resolveOrder($business, $outlet);

        return [
            'businessOptions' => auth()->user()?->businesses()->orderBy('name')->pluck('name', 'id')->all() ?? [],
            'outletOptions' => $business?->outlets()->orderBy('name')->pluck('name', 'id')->all() ?? [],
            'variantOptions' => $this->variantOptions($business, $outlet),
            'order' => $order === null ? null : $this->presentOrder($order),
            'feedback' => $this->feedbackMessage === null
                ? null
                : ['type' => $this->feedbackType, 'message' => $this->feedbackMessage],
        ];
    }

    private function resetSale(): void
    {
        $this->orderId = null;
        $this->variantId = null;
        $this->quantity = '1';
    }

    private function preselectOutlet(): void
    {
        $outlets = $this->resolveBusiness()?->outlets()->limit(2)->get();

        if ($outlets !== null && $outlets->count() === 1) {
            $this->outletId = $outlets->first()->getKey();
        }
    }

    private function resolveBusiness(): ?Business
    {
        $id = $this->positiveInt($this->businessId);
        $user = auth()->user();

        if ($id === null || $user === null) {
            return null;
        }

        return $user->businesses()->whereKey($id)->first();
    }

    private function resolveOutlet(?Business $business): ?Outlet
    {
        $id = $this->positiveInt($this->outletId);

        if ($business === null || $id === null) {
            return null;
        }

        return $business->outlets()->whereKey($id)->first();
    }

    private function resolveVariant(Business $business): ?ProductVariant
    {
        $id = $this->positiveInt($this->variantId);

        if ($id === null) {
            return null;
        }

        return ProductVariant::query()
            ->whereKey($id)
            ->whereHas('product', fn (Builder $query) => $query->where('business_id', $business->getKey()))
            ->with('product:id,name')
            ->first();
    }

    private function resolveOrder(?Business $business, ?Outlet $outlet): ?Order
    {
        $id = $this->positiveInt($this->orderId);

        if ($id === null || $business === null || $outlet === null) {
            return null;
        }

        return Order::query()
            ->whereKey($id)
            ->where('business_id', $business->getKey())
            ->where('outlet_id', $outlet->getKey())
            ->first();
    }

    /**
     * A client-supplied ID as a positive int, or null for anything else.
     */
    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^[1-9]\d{0,17}$/D', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function submittedQuantity(): string|int|null
    {
        $quantity = $this->quantity;

        if (is_int($quantity)) {
            return $quantity >= 1 ? $quantity : null;
        }

        if (is_string($quantity)) {
            $quantity = trim($quantity);

            return preg_match('/^[1-9]\d*$/D', $quantity) === 1 ? $quantity : null;
        }

        return null;
    }

    /**
     * @return list<array{id: int, label: string, price: string, stock: string|null}>
     */
    private function variantOptions(?Business $business, ?Outlet $outlet): array
    {
        if ($business === null) {
            return [];
        }

        $variants = ProductVariant::query()
            ->whereHas('product', fn (Builder $query) => $query->where('business_id', $business->getKey()))
            ->with('product:id,name')
            ->orderBy('id')
            ->limit(self::VARIANT_LIMIT)
            ->get();

        $stock = $outlet === null || $variants->isEmpty()
            ? collect()
            : StockLevel::query()
                ->where('outlet_id', $outlet->getKey())
                ->whereIn('product_variant_id', $variants->modelKeys())
                ->pluck('quantity', 'product_variant_id');

        return $variants
            ->sortBy(fn (ProductVariant $variant) => mb_strtolower($variant->product?->name.' '.$variant->name))
            ->map(fn (ProductVariant $variant) => [
                'id' => $variant->getKey(),
                'label' => $variant->product?->name.' - '.$variant->name.' ('.$variant->sku.')',
                'price' => $this->formatMoney((string) $variant->price),
                'stock' => $outlet === null
                    ? null
                    : $this->trimQuantity((string) ($stock[$variant->getKey()] ?? '0')),
            ])
            ->values()
            ->all();
    }

    /**
     * The order as plain display data. Line totals and the order total are
     * calculated here from the persisted price snapshots and quantities, using
     * BCMath strings, never floats.
     *
     * @return array{id: int, isOpen: bool, completedAt: string|null, hasItems: bool, lines: list<array<string, mixed>>, total: string}
     */
    private function presentOrder(Order $order): array
    {
        $total = '0.00';
        $lines = [];

        foreach ($order->items()->orderBy('id')->get() as $item) {
            $lineTotal = bcmul((string) $item->unit_price, (string) $item->quantity, 2);
            $total = bcadd($total, $lineTotal, 2);

            $lines[] = [
                'name' => $item->product_name.' - '.$item->variant_name,
                'sku' => $item->sku,
                'unitPrice' => $this->formatMoney((string) $item->unit_price),
                'quantity' => $item->quantity,
                'lineTotal' => $this->formatMoney($lineTotal),
            ];
        }

        return [
            'id' => $order->getKey(),
            'isOpen' => $order->status === OrderStatus::Open,
            'completedAt' => $order->completed_at?->format('Y-m-d H:i'),
            'hasItems' => $lines !== [],
            'lines' => $lines,
            'total' => $this->formatMoney($total),
        ];
    }

    /**
     * Format a non-negative decimal string such as "1250.5" as "1,250.50"
     * without converting it to a float.
     */
    private function formatMoney(string $amount): string
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '00');

        $whole = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);

        return $whole.'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }

    /**
     * "10.000" becomes "10" and "2.500" becomes "2.5".
     */
    private function trimQuantity(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }

    private function describeFailure(OrderException|StockException $e, ?Order $order): string
    {
        if ($e instanceof StockException) {
            return match ($e->reason) {
                StockException::REASON_INSUFFICIENT_STOCK => $this->insufficientStockMessage($e, $order),
                StockException::REASON_EMPTY_SALE => 'The order is empty. Add at least one item before completing the sale.',
                StockException::REASON_SALE_ALREADY_RECORDED => 'Stock for this order was already deducted. Nothing was changed.',
                default => 'The sale could not be completed. Nothing was changed.',
            };
        }

        return match ($e->reason) {
            OrderException::REASON_INVALID_QUANTITY => 'Enter a whole-number quantity of at least 1.',
            OrderException::REASON_QUANTITY_OVERFLOW => 'That quantity is too large. The maximum is '.number_format(OrderService::MAX_QUANTITY).' per item in one order.',
            OrderException::REASON_ORDER_NOT_OPEN => 'This sale is already completed and can no longer change. Start a new sale.',
            OrderException::REASON_EMPTY_ORDER => 'The order is empty. Add at least one item before completing the sale.',
            default => 'That action is not allowed for the selected business, outlet or product. Nothing was changed.',
        };
    }

    private function insufficientStockMessage(StockException $e, ?Order $order): string
    {
        $item = $order !== null && $e->productVariantId !== null
            ? $order->items()->where('product_variant_id', $e->productVariantId)->first()
            : null;

        $name = $item === null ? 'one of the items' : $item->product_name.' - '.$item->variant_name;

        return "Insufficient stock for {$name}. The sale was not completed and no stock was deducted.";
    }

    private function showSuccess(string $message): void
    {
        $this->feedbackType = 'success';
        $this->feedbackMessage = $message;
    }

    private function showError(string $message): void
    {
        $this->feedbackType = 'error';
        $this->feedbackMessage = $message;
    }
}
