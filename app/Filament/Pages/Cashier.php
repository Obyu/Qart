<?php

namespace App\Filament\Pages;

use App\Enums\OrderStatus;
use App\Exceptions\OrderException;
use App\Exceptions\PaymentException;
use App\Exceptions\StockException;
use App\Models\Business;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Support\Money;
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

    public string $paymentMode = PaymentService::MODE_DIRECT;

    public string $paymentMethod = PaymentService::METHOD_CASH;

    public string $cashReceived = '';

    public string $qrisReference = '';

    public string $splitCount = '2';

    public string $participantNames = '';

    public array $itemSplitParticipants = [];

    public string|int|null $selectedParticipantId = null;

    public string|int|null $pendingPaymentId = null;

    public string $cancellationReason = '';

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
     * Freeze the order contents and create its payment obligations.
     */
    public function configureBill(): void
    {
        $user = auth()->user();
        $business = $this->resolveBusiness();
        $outlet = $this->resolveOutlet($business);
        $order = $this->resolveOrder($business, $outlet);

        if ($user === null || $order === null) {
            $this->showError('Select a valid open sale first.');

            return;
        }

        $participants = [];

        if ($this->paymentMode === PaymentService::MODE_SPLIT_EQUAL) {
            $participants = array_values(array_filter(
                array_map('trim', preg_split('/\R/', $this->participantNames) ?: []),
                static fn (string $name): bool => $name !== '',
            ));
        } elseif ($this->paymentMode === PaymentService::MODE_SPLIT_ITEMS) {
            $this->showError('Item-based split configuration is not available in this cashier step.');

            return;
        } elseif ($this->paymentMode !== PaymentService::MODE_DIRECT) {
            $this->showError('Choose a valid payment mode.');

            return;
        }

        try {
            app(PaymentService::class)->createBill(
                $user,
                $order,
                $this->paymentMode,
                $participants,
            );

            $this->selectedParticipantId = null;
            $this->pendingPaymentId = null;
            $this->showSuccess('Bill created. Order items are now frozen.');
        } catch (PaymentException $e) {
            $this->showError($this->describePaymentFailure($e));
        } catch (Throwable $e) {
            report($e);
            $this->showError('The bill could not be created. Please try again.');
        }
    }

    /**
     * Receive cash for the direct obligation or selected split participant.
     */
    public function receiveCash(): void
    {
        $user = auth()->user();
        $business = $this->resolveBusiness();
        $outlet = $this->resolveOutlet($business);
        $order = $this->resolveOrder($business, $outlet);

        if ($user === null || $order === null) {
            $this->showError('Select a valid sale first.');

            return;
        }

        $participantId = $this->paymentParticipantId($order);

        if ($participantId === false) {
            $this->showError('Select a participant for this split payment.');

            return;
        }

        try {
            $payment = app(PaymentService::class)->payCash(
                $user,
                $order,
                $this->cashReceived,
                $participantId,
            );

            $this->cashReceived = '';
            $this->showSuccess(
                'Cash payment confirmed. Change: '.$this->formatMoney($payment->change_amount).'.',
            );
        } catch (PaymentException $e) {
            $this->showError($this->describePaymentFailure($e));
        } catch (OrderException|StockException $e) {
            $this->showError($this->describeFailure($e, $order));
        } catch (Throwable $e) {
            report($e);
            $this->showError('Cash payment could not be processed. Please check the sale and try again.');
        }
    }

    /**
     * Create a pending QRIS payment. This action does not confirm payment.
     */
    public function requestQris(): void
    {
        $user = auth()->user();
        $business = $this->resolveBusiness();
        $outlet = $this->resolveOutlet($business);
        $order = $this->resolveOrder($business, $outlet);

        if ($user === null || $order === null) {
            $this->showError('Select a valid sale first.');

            return;
        }

        $participantId = $this->paymentParticipantId($order);

        if ($participantId === false) {
            $this->showError('Select a participant for this split payment.');

            return;
        }

        try {
            $payment = app(PaymentService::class)->createQrisPayment(
                $user,
                $order,
                $participantId,
                $this->qrisReference !== '' ? $this->qrisReference : null,
            );

            $this->pendingPaymentId = $payment->getKey();
            $this->qrisReference = '';
            $this->showSuccess(
                'QRIS payment #'.$payment->getKey().' is pending. Verify the actual payment before confirming it.',
            );
        } catch (PaymentException $e) {
            $this->showError($this->describePaymentFailure($e));
        } catch (Throwable $e) {
            report($e);
            $this->showError('The QRIS payment request could not be created. Please try again.');
        }
    }

    /**
     * Confirm a pending QRIS payment only after the cashier verifies receipt.
     */
    public function confirmQris(mixed $paymentId): void
    {
        $user = auth()->user();
        $business = $this->resolveBusiness();
        $outlet = $this->resolveOutlet($business);
        $order = $this->resolveOrder($business, $outlet);
        $paymentId = $this->positiveInt($paymentId);

        if ($user === null || $order === null || $paymentId === null) {
            $this->showError('Select a valid pending QRIS payment first.');

            return;
        }

        try {
            app(PaymentService::class)->confirmQrisPayment($user, $order, $paymentId);
            $this->pendingPaymentId = null;
            $this->showSuccess('QRIS payment confirmed.');
        } catch (PaymentException $e) {
            $this->showError($this->describePaymentFailure($e));
        } catch (OrderException|StockException $e) {
            $this->showError($this->describeFailure($e, $order));
        } catch (Throwable $e) {
            report($e);
            $this->showError('QRIS confirmation failed. Verify the payment status before retrying.');
        }
    }

    /**
     * Reject a pending QRIS payment while preserving its audit record.
     */
    public function rejectQris(mixed $paymentId): void
    {
        $user = auth()->user();
        $business = $this->resolveBusiness();
        $outlet = $this->resolveOutlet($business);
        $order = $this->resolveOrder($business, $outlet);
        $paymentId = $this->positiveInt($paymentId);

        if ($user === null || $order === null || $paymentId === null) {
            $this->showError('Select a valid pending QRIS payment first.');

            return;
        }

        try {
            app(PaymentService::class)->rejectQrisPayment($user, $order, $paymentId);
            $this->pendingPaymentId = null;
            $this->showSuccess('QRIS payment rejected. The obligation can be retried.');
        } catch (PaymentException $e) {
            $this->showError($this->describePaymentFailure($e));
        } catch (Throwable $e) {
            report($e);
            $this->showError('QRIS rejection failed. Please refresh the sale and try again.');
        }
    }

    /**
     * Return false when a split participant is required but not selected.
     */
    private function paymentParticipantId(Order $order): int|null|false
    {
        if ($order->payment_mode === PaymentService::MODE_DIRECT) {
            return null;
        }

        $participantId = $this->positiveInt($this->selectedParticipantId);

        if (
            $participantId === null
            || ! $order->participants()->whereKey($participantId)->exists()
        ) {
            return false;
        }

        return $participantId;
    }

    private function describePaymentFailure(PaymentException $e): string
    {
        return match ($e->reason) {
            PaymentException::REASON_UNAUTHORIZED,
            PaymentException::REASON_NOT_FOUND => 'That payment action is not allowed for this sale.',
            PaymentException::REASON_ORDER_NOT_OPEN => 'This sale is no longer open for payment changes.',
            PaymentException::REASON_ORDER_ALREADY_BILLED => 'A bill already exists for this sale.',
            PaymentException::REASON_EMPTY_ORDER => 'Add at least one item before creating a bill.',
            PaymentException::REASON_INVALID_MODE => 'Choose a valid payment mode.',
            PaymentException::REASON_INVALID_METHOD => 'Choose cash or QRIS.',
            PaymentException::REASON_INVALID_AMOUNT => $e->getMessage(),
            PaymentException::REASON_INVALID_PARTICIPANT => 'Choose valid split participants.',
            PaymentException::REASON_INVALID_ALLOCATION,
            PaymentException::REASON_ALLOCATION_MISMATCH => 'The split allocation is invalid. Check the participants and quantities.',
            PaymentException::REASON_PAYMENT_ALREADY_EXISTS => 'An active payment already exists for this obligation.',
            PaymentException::REASON_PAYMENT_NOT_PENDING => 'This payment is no longer pending.',
            PaymentException::REASON_INSUFFICIENT_CASH => 'The cash received must be at least the amount due.',
            PaymentException::REASON_QRIS_CONFIRMATION_REQUIRED => 'Verify the actual QRIS payment before confirming it.',
            PaymentException::REASON_OBLIGATION_NOT_SETTLED,
            PaymentException::REASON_ORDER_NOT_SETTLED => 'The full bill has not been paid yet.',
            default => 'The payment could not be processed. Please refresh and try again.',
        };
    }

    /**
     * Complete the sale of the current order: stock deduction and completion
     * happen together inside OrderService::completeSale().
     */
    public function checkout(): void
    {
        $this->showError('Use the payment section to settle this sale. Direct checkout is disabled.');
    }

    /**
     * Cancel the current open order through the domain service.
     * The service owns status/payment writes and enforces cancellation rules.
     */
    public function cancelOrder(): void
    {
        $user = auth()->user();
        $business = $this->resolveBusiness();
        $outlet = $this->resolveOutlet($business);
        $order = $this->resolveOrder($business, $outlet);

        if ($user === null || $order === null) {
            $this->showError('Select a valid open sale before cancelling it.');

            return;
        }

        try {
            app(OrderService::class)->cancelOrder(
                $user,
                $order,
                $this->cancellationReason,
            );

            $this->cancellationReason = '';
            $this->showSuccess('Sale cancelled. Items and payment history have been preserved.');
        } catch (OrderException $e) {
            $message = match ($e->reason) {
                OrderException::REASON_INVALID_CANCELLATION_REASON =>
                    'Enter a cancellation reason of 1–500 characters.',
                OrderException::REASON_ORDER_HAS_CONFIRMED_PAYMENT =>
                    'This sale has a confirmed payment and cannot be cancelled through this flow.',
                OrderException::REASON_ORDER_NOT_OPEN =>
                    'This sale is no longer open and cannot be cancelled.',
                OrderException::REASON_UNAUTHORIZED,
                OrderException::REASON_NOT_FOUND,
                OrderException::REASON_BUSINESS_MISMATCH =>
                    'That cancellation is not allowed for the selected sale.',
                default => 'The sale could not be cancelled. Please refresh and try again.',
            };

            $this->showError($message);
        } catch (Throwable $e) {
            report($e);
            $this->showError('Cancellation failed. Verify the sale status before retrying.');
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

        $participants = [];
        $payments = [];

        if ($order !== null) {
            $participants = $order->participants()
                ->orderBy('position')
                ->get()
                ->map(fn ($participant) => [
                    'id' => $participant->getKey(),
                    'name' => $participant->name,
                    'amount' => $this->formatMoney((string) $participant->total_amount),
                ])
                ->all();

            $payments = $order->payments()
                ->with('participant:id,name')
                ->orderBy('id')
                ->get()
                ->map(fn ($payment) => [
                    'id' => $payment->getKey(),
                    'participant' => $payment->participant?->name,
                    'method' => (string) $payment->method,
                    'status' => (string) $payment->status,
                    'amount' => $this->formatMoney((string) $payment->amount),
                    'amountReceived' => $payment->amount_received === null
                        ? null
                        : $this->formatMoney((string) $payment->amount_received),
                    'change' => $this->formatMoney((string) $payment->change_amount),
                    'qrisReference' => $payment->qris_reference,
                    'confirmedAt' => $payment->confirmed_at?->format('Y-m-d H:i'),
                    'rejectedAt' => $payment->rejected_at?->format('Y-m-d H:i'),
                ])
                ->all();
        }

        return [
            'businessOptions' => auth()->user()?->businesses()->orderBy('name')->pluck('name', 'id')->all() ?? [],
            'outletOptions' => $business?->outlets()->orderBy('name')->pluck('name', 'id')->all() ?? [],
            'variantOptions' => $this->variantOptions($business, $outlet),
            'order' => $order === null ? null : $this->presentOrder($order),
            'participants' => $participants,
            'payments' => $payments,
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
        $this->paymentMode = PaymentService::MODE_DIRECT;
        $this->paymentMethod = PaymentService::METHOD_CASH;
        $this->cashReceived = '';
        $this->qrisReference = '';
        $this->splitCount = '2';
        $this->participantNames = '';
        $this->itemSplitParticipants = [];
        $this->selectedParticipantId = null;
        $this->pendingPaymentId = null;
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
     * @return array{id: int, isOpen: bool, completedAt: string|null, hasItems: bool, billConfigured: bool, paymentMode: string|null, billTotal: string|null, lines: list<array<string, mixed>>, total: string}
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

        $billConfigured = $order->payment_mode !== null && $order->bill_total !== null;

        return [
            'id' => $order->getKey(),
            'isOpen' => $order->status === OrderStatus::Open,
            'isCancelled' => $order->status === OrderStatus::Cancelled,
            'completedAt' => $order->completed_at?->format('Y-m-d H:i'),
            'cancelledAt' => $order->cancelled_at?->format('Y-m-d H:i'),
            'cancellationReason' => $order->cancellation_reason,
            'hasItems' => $lines !== [],
            'billConfigured' => $billConfigured,
            'paymentMode' => $order->payment_mode === null ? null : (string) $order->payment_mode,
            'billTotal' => $order->bill_total === null
                ? null
                : $this->formatMoney((string) $order->bill_total),
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
