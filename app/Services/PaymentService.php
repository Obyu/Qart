<?php

namespace App\Services;

use App\Exceptions\PaymentException;
use App\Models\Order;
use App\Models\OrderParticipant;
use App\Models\OrderParticipantItem;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Coordinates bill configuration and payment settlement.
 *
 * Stock is never written directly here. A fully settled order must be completed
 * through OrderService::completeSale() in the same database transaction.
 */
class PaymentService
{
    public const MODE_DIRECT = 'direct';

    public const MODE_SPLIT_ITEMS = 'split_items';

    public const MODE_SPLIT_EQUAL = 'split_equal';

    public const METHOD_CASH = 'cash';

    public const METHOD_QRIS = 'qris';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    private const TRANSACTION_ATTEMPTS = 5;

    public function __construct(
        private readonly ?OrderService $orders = null,
    ) {}

    /**
     * Configure a bill for an order.
     *
     * The direct mode creates one obligation for the whole order.
     * Split modes create participant obligations.
     *
     * Split item allocations use:
     * [
     *     ['name' => 'Person A', 'items' => [['order_item_id' => 1, 'quantity' => 2]]],
     *     ...
     * ]
     *
     * For split_equal, provide participant names:
     * ['Person A', 'Person B']
     *
     * @param array<int, mixed> $participants
     */
    public function createBill(
        User $actor,
        Order $order,
        string $mode,
        array $participants = [],
    ): Order {
        return DB::transaction(function () use ($actor, $order, $mode, $participants): Order {
            $locked = $this->lockOrderForActor($actor, $order);

            if ($locked->status->value !== 'open') {
                throw PaymentException::orderNotOpen();
            }

            if ($locked->payment_mode !== null || $locked->bill_total !== null) {
                throw PaymentException::orderAlreadyBilled();
            }

            if (! in_array($mode, [
                self::MODE_DIRECT,
                self::MODE_SPLIT_ITEMS,
                self::MODE_SPLIT_EQUAL,
            ], true)) {
                throw PaymentException::invalidMode();
            }

            $items = $locked->items()->orderBy('id')->lockForUpdate()->get();

            if ($items->isEmpty()) {
                throw PaymentException::emptyOrder();
            }

            $total = Money::sum($items->map(
                fn ($item): string => Money::mulQuantity(
                    $item->unit_price,
                    $item->quantity,
                ),
            ));

            if (Money::compare($total, '0.00') <= 0) {
                throw PaymentException::invalidAmount(
                    'The bill total must be greater than zero.',
                );
            }

            if ($mode === self::MODE_DIRECT) {
                if ($participants !== []) {
                    throw PaymentException::invalidParticipant();
                }

                $locked->forceFill([
                    'payment_mode' => self::MODE_DIRECT,
                    'bill_total' => $total,
                ])->save();

                return $locked->refresh();
            }

            if ($participants === [] || count($participants) > Money::MAX_PARTS) {
                throw PaymentException::invalidParticipant();
            }

            if ($mode === self::MODE_SPLIT_EQUAL) {
                $names = array_map(
                    fn ($participant): string => is_string($participant)
                        ? trim($participant)
                        : '',
                    $participants,
                );

                $shares = Money::allocateEqually($total, count($names));

                foreach ($names as $index => $name) {
                    if ($name === '' || mb_strlen($name) > 100) {
                        throw PaymentException::invalidParticipant();
                    }

                    if (Money::compare($shares[$index], '0.00') <= 0) {
                        throw PaymentException::invalidAmount(
                            'Every participant must owe a positive amount.',
                        );
                    }
                }

                foreach ($names as $index => $name) {
                    $locked->participants()->create([
                        'position' => $index + 1,
                        'name' => $name,
                        'total_amount' => $shares[$index],
                    ]);
                }
            } else {
                $this->createItemSplit($locked, $items, $participants);
            }

            $participantTotal = Money::sum(
                $locked->participants()->pluck('total_amount')->all(),
            );

            if (Money::compare($participantTotal, $total) !== 0) {
                throw PaymentException::allocationMismatch();
            }

            $locked->forceFill([
                'payment_mode' => $mode,
                'bill_total' => $total,
            ])->save();

            return $locked->refresh();
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Settle an obligation with cash and calculate change without floats.
     *
     * Direct mode uses a null participant ID. Split modes require a participant.
     */
    public function payCash(
        User $actor,
        Order $order,
        string|int $amountReceived,
        ?int $participantId = null,
    ): Payment {
        return DB::transaction(function () use (
            $actor,
            $order,
            $amountReceived,
            $participantId,
        ): Payment {
            $locked = $this->lockOrderForActor($actor, $order);
            $this->assertPayableOrder($locked);

            $due = $this->resolveObligation($locked, $participantId);
            $received = Money::normalize($amountReceived);

            if (Money::compare($received, $due) < 0) {
                throw PaymentException::insufficientCash();
            }

            $change = Money::sub($received, $due);
            $this->assertNoActivePayment($locked, $participantId);

            $payment = $locked->payments()->create([
                'order_participant_id' => $participantId,
                'method' => self::METHOD_CASH,
                'status' => self::STATUS_CONFIRMED,
                'amount' => $due,
                'amount_received' => $received,
                'change_amount' => $change,
                'confirmed_by' => $actor->getKey(),
                'confirmed_at' => now(),
            ]);

            $this->completeIfSettled($actor, $locked);

            return $payment->refresh();
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Register a QRIS payment awaiting explicit cashier confirmation.
     */
    public function createQrisPayment(
        User $actor,
        Order $order,
        ?int $participantId = null,
        ?string $reference = null,
    ): Payment {
        return DB::transaction(function () use (
            $actor,
            $order,
            $participantId,
            $reference,
        ): Payment {
            $locked = $this->lockOrderForActor($actor, $order);
            $this->assertPayableOrder($locked);

            $due = $this->resolveObligation($locked, $participantId);

            if ($reference !== null) {
                $reference = trim($reference);

                if ($reference === '' || mb_strlen($reference) > 150) {
                    throw PaymentException::invalidAmount(
                        'The QRIS reference must contain 1 to 150 characters.',
                    );
                }
            }

            $this->assertNoActivePayment($locked, $participantId);

            return $locked->payments()->create([
                'order_participant_id' => $participantId,
                'method' => self::METHOD_QRIS,
                'status' => self::STATUS_PENDING,
                'amount' => $due,
                'qris_reference' => $reference,
            ]);
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Confirm a pending QRIS payment after independently verifying receipt.
     */
    public function confirmQrisPayment(
        User $actor,
        Order $order,
        int $paymentId,
    ): Payment {
        return DB::transaction(function () use ($actor, $order, $paymentId): Payment {
            $locked = $this->lockOrderForActor($actor, $order);
            $this->assertPayableOrder($locked);

            $payment = Payment::query()
                ->where('order_id', $locked->getKey())
                ->whereKey($paymentId)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                throw PaymentException::notFound();
            }

            if (
                $payment->method !== self::METHOD_QRIS
                || $payment->status !== self::STATUS_PENDING
            ) {
                throw PaymentException::paymentNotPending();
            }

            $due = $this->resolveObligation(
                $locked,
                $payment->order_participant_id === null
                    ? null
                    : (int) $payment->order_participant_id,
            );

            if (Money::compare($payment->amount, $due) !== 0) {
                throw PaymentException::obligationNotSettled();
            }

            $payment->forceFill([
                'status' => self::STATUS_CONFIRMED,
                'confirmed_by' => $actor->getKey(),
                'confirmed_at' => now(),
            ])->save();

            $this->completeIfSettled($actor, $locked);

            return $payment->refresh();
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Reject an unverified QRIS payment while retaining its audit history.
     */
    public function rejectQrisPayment(
        User $actor,
        Order $order,
        int $paymentId,
    ): Payment {
        return DB::transaction(function () use ($actor, $order, $paymentId): Payment {
            $locked = $this->lockOrderForActor($actor, $order);
            $this->assertPayableOrder($locked);

            $payment = Payment::query()
                ->where('order_id', $locked->getKey())
                ->whereKey($paymentId)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                throw PaymentException::notFound();
            }

            if (
                $payment->method !== self::METHOD_QRIS
                || $payment->status !== self::STATUS_PENDING
            ) {
                throw PaymentException::paymentNotPending();
            }

            $payment->forceFill([
                'status' => self::STATUS_REJECTED,
                'rejected_by' => $actor->getKey(),
                'rejected_at' => now(),
            ])->save();

            return $payment->refresh();
        }, self::TRANSACTION_ATTEMPTS);
    }

    private function assertPayableOrder(Order $order): void
    {
        if ($order->status->value !== 'open') {
            throw PaymentException::orderNotOpen();
        }

        if ($order->payment_mode === null || $order->bill_total === null) {
            throw PaymentException::orderAlreadyBilled();
        }
    }

    /**
     * Resolve and validate the amount owed by the direct bill or a participant.
     */
    private function resolveObligation(Order $order, ?int $participantId): string
    {
        if ($order->payment_mode === self::MODE_DIRECT) {
            if ($participantId !== null) {
                throw PaymentException::invalidParticipant();
            }

            return Money::normalize($order->bill_total);
        }

        if ($participantId === null) {
            throw PaymentException::invalidParticipant();
        }

        $participant = $order->participants()
            ->whereKey($participantId)
            ->lockForUpdate()
            ->first();

        if ($participant === null) {
            throw PaymentException::invalidParticipant();
        }

        return Money::normalize($participant->total_amount);
    }

    private function assertNoActivePayment(
        Order $order,
        ?int $participantId,
    ): void {
        $query = $order->payments()
            ->where('status', '!=', self::STATUS_REJECTED);

        if ($participantId === null) {
            $query->whereNull('order_participant_id');
        } else {
            $query->where('order_participant_id', $participantId);
        }

        if ($query->lockForUpdate()->exists()) {
            throw PaymentException::paymentAlreadyExists();
        }
    }

    /**
     * Complete the sale only when every bill obligation is confirmed.
     * OrderService remains the only path used here to deduct stock.
     */
    private function completeIfSettled(User $actor, Order $order): void
    {
        $billTotal = Money::normalize($order->bill_total);

        if ($order->payment_mode === self::MODE_DIRECT) {
            $confirmedPayments = $order->payments()
                ->whereNull('order_participant_id')
                ->where('status', self::STATUS_CONFIRMED)
                ->lockForUpdate()
                ->get();

            if (
                $confirmedPayments->count() !== 1
                || Money::compare($confirmedPayments->first()->amount, $billTotal) !== 0
            ) {
                throw PaymentException::orderNotSettled();
            }

            ($this->orders ?? new OrderService)->completeSale($actor, $order);

            return;
        }

        $participants = $order->participants()
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($participants->isEmpty()) {
            throw PaymentException::orderNotSettled();
        }

        $participantTotal = Money::sum(
            $participants->map(
                fn ($participant): string => Money::normalize($participant->total_amount),
            )->all(),
        );

        if (Money::compare($participantTotal, $billTotal) !== 0) {
            throw PaymentException::orderNotSettled();
        }

        foreach ($participants as $participant) {
            $confirmedPayments = $order->payments()
                ->where('order_participant_id', $participant->getKey())
                ->where('status', self::STATUS_CONFIRMED)
                ->lockForUpdate()
                ->get();

            // Peserta yang belum membayar tidak menggagalkan pembayaran peserta lain.
            if ($confirmedPayments->isEmpty()) {
                return;
            }

            // Pembayaran ganda atau nominal yang tidak sesuai tetap ditolak.
            if (
                $confirmedPayments->count() !== 1
                || Money::compare(
                    $confirmedPayments->first()->amount,
                    $participant->total_amount,
                ) !== 0
            ) {
                throw PaymentException::orderNotSettled();
            }
        }

        // Penjualan selesai hanya setelah seluruh peserta membayar penuh.
        ($this->orders ?? new OrderService)->completeSale($actor, $order);
    }

    /**
     * Create an item-based split and verify every item quantity is allocated
     * exactly once across all participants.
     *
     * @param iterable<\App\Models\OrderItem> $items
     * @param array<int, mixed> $participants
     */
    private function createItemSplit(Order $order, iterable $items, array $participants): void
    {
        $itemById = collect($items)->keyBy(
            fn ($item): int => (int) $item->getKey(),
        );

        $allocatedByItem = [];

        foreach ($participants as $index => $participantData) {
            if (! is_array($participantData)) {
                throw PaymentException::invalidParticipant();
            }

            $name = $participantData['name'] ?? null;
            $allocations = $participantData['items'] ?? null;

            if (
                ! is_string($name)
                || trim($name) === ''
                || mb_strlen(trim($name)) > 100
                || ! is_array($allocations)
                || $allocations === []
            ) {
                throw PaymentException::invalidParticipant();
            }

            $participant = $order->participants()->create([
                'position' => $index + 1,
                'name' => trim($name),
                'total_amount' => '0.00',
            ]);

            $participantTotal = '0.00';

            foreach ($allocations as $allocation) {
                if (! is_array($allocation)) {
                    throw PaymentException::invalidAllocation(
                        'Each item allocation must specify an order item and quantity.',
                    );
                }

                $itemId = filter_var(
                    $allocation['order_item_id'] ?? null,
                    FILTER_VALIDATE_INT,
                );

                $quantity = filter_var(
                    $allocation['quantity'] ?? null,
                    FILTER_VALIDATE_INT,
                );

                if (
                    $itemId === false
                    || $itemId === null
                    || $quantity === false
                    || $quantity === null
                    || $quantity < 1
                    || ! $itemById->has($itemId)
                ) {
                    throw PaymentException::invalidAllocation(
                        'The allocated order item or quantity is invalid.',
                    );
                }

                $item = $itemById->get($itemId);
                $allocatedByItem[$itemId] = ($allocatedByItem[$itemId] ?? 0) + $quantity;

                if ($allocatedByItem[$itemId] > $item->quantity) {
                    throw PaymentException::allocationMismatch();
                }

                $participantTotal = Money::add(
                    $participantTotal,
                    Money::mulQuantity($item->unit_price, $quantity),
                );

                $participant->itemAllocations()->create([
                    'order_id' => $order->getKey(),
                    'order_item_id' => $itemId,
                    'quantity' => $quantity,
                ]);
            }

            if (Money::compare($participantTotal, '0.00') <= 0) {
                throw PaymentException::invalidAmount(
                    'Every participant must owe a positive amount.',
                );
            }

            $participant->forceFill([
                'total_amount' => $participantTotal,
            ])->save();
        }

        foreach ($itemById as $itemId => $item) {
            if (($allocatedByItem[$itemId] ?? 0) !== (int) $item->quantity) {
                throw PaymentException::allocationMismatch();
            }
        }
    }

    /**
     * Re-read and lock the order before checking business ownership.
     */
    private function lockOrderForActor(User $actor, Order $order): Order
    {
        $locked = Order::query()
            ->whereKey($order->getKey())
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            throw PaymentException::notFound();
        }

        $ownerId = DB::table('businesses')
            ->where('id', $locked->business_id)
            ->value('user_id');

        if ($ownerId === null) {
            throw PaymentException::notFound();
        }

        if ((int) $ownerId !== (int) $actor->getKey()) {
            throw PaymentException::unauthorized();
        }

        return $locked;
    }
}
