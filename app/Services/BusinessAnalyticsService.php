<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;

class BusinessAnalyticsService
{
    /**
     * Return sales and payment metrics limited to businesses owned by the user.
     *
     * Revenue uses order-item price snapshots, not current catalog prices.
     *
     * @return array{
     *     total_revenue: string,
     *     completed_orders: int,
     *     open_orders: int,
     *     confirmed_payments: string,
     *     pending_qris_payments: int
     * }
     */
    public function salesSummary(User $user): array
    {
        $ownedBusinessIds = $user->businesses()->select('businesses.id');

        $revenue = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.business_id', $ownedBusinessIds)
            ->where('orders.status', OrderStatus::Completed->value)
            ->selectRaw(
                'CAST(COALESCE(SUM(order_items.unit_price * order_items.quantity), 0) AS DECIMAL(20, 2)) AS total_revenue'
            )
            ->value('total_revenue');

        $completedOrders = Order::query()
            ->whereIn('business_id', $ownedBusinessIds)
            ->where('status', OrderStatus::Completed->value)
            ->count();

        $openOrders = Order::query()
            ->whereIn('business_id', $ownedBusinessIds)
            ->where('status', OrderStatus::Open->value)
            ->count();

        $confirmedPayments = Payment::query()
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->whereIn('orders.business_id', $ownedBusinessIds)
            ->where('payments.status', 'confirmed')
            ->selectRaw(
                'CAST(COALESCE(SUM(payments.amount), 0) AS DECIMAL(20, 2)) AS confirmed_payments'
            )
            ->value('confirmed_payments');

        $pendingQrisPayments = Payment::query()
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->whereIn('orders.business_id', $ownedBusinessIds)
            ->where('payments.method', 'qris')
            ->where('payments.status', 'pending')
            ->count();

        return [
            'total_revenue' => (string) ($revenue ?? '0.00'),
            'completed_orders' => $completedOrders,
            'open_orders' => $openOrders,
            'confirmed_payments' => (string) ($confirmedPayments ?? '0.00'),
            'pending_qris_payments' => $pendingQrisPayments,
        ];
    }
}
