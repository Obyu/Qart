<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\StockMovementType;
use App\Exceptions\PaymentException;
use App\Models\Business;
use App\Models\Category;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private OrderService $orders;

    private StockService $stock;

    private PaymentService $payments;

    private User $owner;

    private Business $business;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orders = new OrderService;
        $this->stock = new StockService;
        $this->payments = new PaymentService($this->orders);

        $this->owner = User::factory()->create();
        $this->business = Business::factory()->for($this->owner)->create();
        $this->outlet = Outlet::factory()->for($this->business)->create();
    }

    /**
     * Create a variant with a deterministic price inside the owner's business.
     */
    private function variant(string $price = '10.00'): ProductVariant
    {
        $category = Category::factory()->for($this->business)->create();
        $product = Product::factory()->for($category)->create();

        return ProductVariant::factory()
            ->for($product)
            ->create(['price' => $price]);
    }

    private function orderWith(ProductVariant $variant, int $quantity = 1): Order
    {
        $order = $this->orders->createOrder(
            $this->owner,
            $this->business,
            $this->outlet,
        );

        $this->orders->addItem(
            $this->owner,
            $order,
            $variant,
            $quantity,
        );

        return $order->refresh();
    }

    private function openStock(ProductVariant $variant, string $quantity = '10'): void
    {
        $this->stock->recordOpening(
            $this->owner,
            $this->outlet,
            $variant,
            $quantity,
        );
    }

    private function balance(ProductVariant $variant): ?string
    {
        return StockLevel::query()
            ->where('outlet_id', $this->outlet->id)
            ->where('product_variant_id', $variant->id)
            ->value('quantity');
    }

    private function saleCount(): int
    {
        return StockMovement::query()
            ->where('type', StockMovementType::Sale->value)
            ->count();
    }

    private function assertRejectedWith(string $reason, callable $operation): PaymentException
    {
        try {
            $operation();
        } catch (PaymentException $exception) {
            $this->assertSame($reason, $exception->reason);

            return $exception;
        }

        $this->fail("Expected a PaymentException with reason {$reason}.");
    }

    public function test_cash_payment_calculates_change_and_completes_sale_once(): void
    {
        $variant = $this->variant('10.00');
        $this->openStock($variant, '10');
        $order = $this->orderWith($variant, 2);

        $this->payments->createBill(
            $this->owner,
            $order,
            PaymentService::MODE_DIRECT,
        );

        $payment = $this->payments->payCash(
            $this->owner,
            $order,
            '25.00',
        );

        $this->assertSame(PaymentService::METHOD_CASH, $payment->method);
        $this->assertSame(PaymentService::STATUS_CONFIRMED, $payment->status);
        $this->assertSame('20.00', $payment->amount);
        $this->assertSame('25.00', $payment->amount_received);
        $this->assertSame('5.00', $payment->change_amount);
        $this->assertSame($this->owner->id, $payment->confirmed_by);
        $this->assertNotNull($payment->confirmed_at);

        $storedOrder = Order::query()->findOrFail($order->id);

        $this->assertSame(OrderStatus::Completed, $storedOrder->status);
        $this->assertNotNull($storedOrder->completed_at);
        $this->assertSame('8.000', $this->balance($variant));
        $this->assertSame(1, $this->saleCount());
        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->count());
    }

    public function test_insufficient_cash_is_rejected_without_payment_or_stock_changes(): void
    {
        $variant = $this->variant('10.00');
        $this->openStock($variant, '10');
        $order = $this->orderWith($variant, 2);

        $this->payments->createBill(
            $this->owner,
            $order,
            PaymentService::MODE_DIRECT,
        );

        $this->assertRejectedWith(
            PaymentException::REASON_INSUFFICIENT_CASH,
            fn () => $this->payments->payCash(
                $this->owner,
                $order,
                '19.99',
            ),
        );

        $this->assertSame(0, Payment::query()->where('order_id', $order->id)->count());
        $this->assertSame('10.000', $this->balance($variant));
        $this->assertSame(0, $this->saleCount());

        $this->assertSame(
            OrderStatus::Open,
            Order::query()->findOrFail($order->id)->status,
        );
    }

    public function test_pending_qris_does_not_complete_order_or_deduct_stock(): void
    {
        $variant = $this->variant('10.00');
        $this->openStock($variant, '10');
        $order = $this->orderWith($variant, 2);

        $this->payments->createBill(
            $this->owner,
            $order,
            PaymentService::MODE_DIRECT,
        );

        $payment = $this->payments->createQrisPayment(
            $this->owner,
            $order,
            reference: 'QR-TEST-001',
        );

        $this->assertSame(PaymentService::METHOD_QRIS, $payment->method);
        $this->assertSame(PaymentService::STATUS_PENDING, $payment->status);
        $this->assertSame('20.00', $payment->amount);
        $this->assertSame('QR-TEST-001', $payment->qris_reference);
        $this->assertNull($payment->confirmed_at);

        $this->assertSame(
            OrderStatus::Open,
            Order::query()->findOrFail($order->id)->status,
        );
        $this->assertSame('10.000', $this->balance($variant));
        $this->assertSame(0, $this->saleCount());
    }

    public function test_confirmed_qris_completes_sale_and_records_confirmation_audit(): void
    {
        $variant = $this->variant('10.00');
        $this->openStock($variant, '10');
        $order = $this->orderWith($variant, 2);

        $this->payments->createBill(
            $this->owner,
            $order,
            PaymentService::MODE_DIRECT,
        );

        $pending = $this->payments->createQrisPayment(
            $this->owner,
            $order,
            reference: 'QR-TEST-002',
        );

        $confirmed = $this->payments->confirmQrisPayment(
            $this->owner,
            $order,
            (int) $pending->id,
        );

        $this->assertSame(PaymentService::STATUS_CONFIRMED, $confirmed->status);
        $this->assertSame($this->owner->id, $confirmed->confirmed_by);
        $this->assertNotNull($confirmed->confirmed_at);
        $this->assertNull($confirmed->rejected_at);

        $storedOrder = Order::query()->findOrFail($order->id);

        $this->assertSame(OrderStatus::Completed, $storedOrder->status);
        $this->assertNotNull($storedOrder->completed_at);
        $this->assertSame('8.000', $this->balance($variant));
        $this->assertSame(1, $this->saleCount());
        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->count());
    }



    public function test_rejected_qris_can_be_retried_without_losing_audit_history(): void
    {
        $variant = $this->variant('10.00');
        $this->openStock($variant, '10');
        $order = $this->orderWith($variant, 2);

        $billedOrder = $this->payments->createBill(
            $this->owner,
            $order,
            PaymentService::MODE_DIRECT,
        );

        $firstPayment = $this->payments->createQrisPayment(
            $this->owner,
            $billedOrder,
            reference: 'QR-REJECTED-001',
        );

        $rejectedPayment = $this->payments->rejectQrisPayment(
            $this->owner,
            $billedOrder,
            (int) $firstPayment->id,
        );

        $this->assertSame(PaymentService::STATUS_REJECTED, $rejectedPayment->status);
        $this->assertSame($this->owner->id, $rejectedPayment->rejected_by);
        $this->assertNotNull($rejectedPayment->rejected_at);

        $retryPayment = $this->payments->createQrisPayment(
            $this->owner,
            $billedOrder,
            reference: 'QR-RETRY-002',
        );

        $this->assertSame(PaymentService::STATUS_PENDING, $retryPayment->status);
        $this->assertSame('QR-RETRY-002', $retryPayment->qris_reference);
        $this->assertSame(
            2,
            Payment::query()->where('order_id', $order->id)->count(),
        );
        $this->assertSame(
            PaymentService::STATUS_REJECTED,
            $firstPayment->fresh()->status,
        );
        $this->assertSame(OrderStatus::Open, $order->fresh()->status);
        $this->assertSame('10.000', $this->balance($variant));
        $this->assertSame(0, $this->saleCount());
    }

    public function test_split_items_completes_only_after_every_participant_pays(): void
    {
        $variant = $this->variant('10.00');
        $this->openStock($variant, '10');
        $order = $this->orderWith($variant, 3);

        $items = $order->items()->orderBy('id')->get();
        $this->assertCount(1, $items);

        $orderItemId = (int) $items->first()->id;

        $billedOrder = $this->payments->createBill(
            $this->owner,
            $order,
            PaymentService::MODE_SPLIT_ITEMS,
            [
                [
                    'name' => 'Person A',
                    'items' => [
                        ['order_item_id' => $orderItemId, 'quantity' => 2],
                    ],
                ],
                [
                    'name' => 'Person B',
                    'items' => [
                        ['order_item_id' => $orderItemId, 'quantity' => 1],
                    ],
                ],
            ],
        );

        $this->assertSame('30.00', $billedOrder->bill_total);

        $participants = $billedOrder->participants()
            ->with('itemAllocations')
            ->orderBy('position')
            ->get();

        $this->assertCount(2, $participants);
        $this->assertSame('20.00', $participants[0]->total_amount);
        $this->assertSame('10.00', $participants[1]->total_amount);
        $this->assertSame(2, (int) $participants[0]->itemAllocations->first()->quantity);
        $this->assertSame(1, (int) $participants[1]->itemAllocations->first()->quantity);

        $firstPayment = $this->payments->payCash(
            $this->owner,
            $billedOrder,
            '20.00',
            (int) $participants[0]->id,
        );

        $this->assertSame(PaymentService::STATUS_CONFIRMED, $firstPayment->status);
        $this->assertSame(
            OrderStatus::Open,
            Order::query()->findOrFail($order->id)->status,
        );
        $this->assertSame('10.000', $this->balance($variant));
        $this->assertSame(0, $this->saleCount());

        $secondPayment = $this->payments->payCash(
            $this->owner,
            $billedOrder,
            '10.00',
            (int) $participants[1]->id,
        );

        $this->assertSame(PaymentService::STATUS_CONFIRMED, $secondPayment->status);

        $storedOrder = Order::query()->findOrFail($order->id);

        $this->assertSame(OrderStatus::Completed, $storedOrder->status);
        $this->assertNotNull($storedOrder->completed_at);
        $this->assertSame('7.000', $this->balance($variant));
        $this->assertSame(1, $this->saleCount());
        $this->assertSame(
            2,
            Payment::query()->where('order_id', $order->id)->count(),
        );
    }

    public function test_split_equal_completes_only_after_every_participant_pays(): void
    {
        $variant = $this->variant('10.00');
        $this->openStock($variant, '10');
        $order = $this->orderWith($variant, 2);

        $billedOrder = $this->payments->createBill(
            $this->owner,
            $order,
            PaymentService::MODE_SPLIT_EQUAL,
            [
                'Person A',
                'Person B',
            ],
        );

        $this->assertSame('20.00', $billedOrder->bill_total);

        $participants = $billedOrder->participants()
            ->orderBy('position')
            ->get();

        $this->assertCount(2, $participants);
        $this->assertSame('10.00', $participants[0]->total_amount);
        $this->assertSame('10.00', $participants[1]->total_amount);

        $firstPayment = $this->payments->payCash(
            $this->owner,
            $billedOrder,
            '10.00',
            (int) $participants[0]->id,
        );

        $this->assertSame(PaymentService::STATUS_CONFIRMED, $firstPayment->status);
        $this->assertSame(
            OrderStatus::Open,
            Order::query()->findOrFail($order->id)->status,
        );
        $this->assertSame('10.000', $this->balance($variant));
        $this->assertSame(0, $this->saleCount());

        $secondPayment = $this->payments->payCash(
            $this->owner,
            $billedOrder,
            '10.00',
            (int) $participants[1]->id,
        );

        $this->assertSame(PaymentService::STATUS_CONFIRMED, $secondPayment->status);

        $storedOrder = Order::query()->findOrFail($order->id);

        $this->assertSame(OrderStatus::Completed, $storedOrder->status);
        $this->assertNotNull($storedOrder->completed_at);
        $this->assertSame('8.000', $this->balance($variant));
        $this->assertSame(1, $this->saleCount());
        $this->assertSame(
            2,
            Payment::query()->where('order_id', $order->id)->count(),
        );
    }

}
