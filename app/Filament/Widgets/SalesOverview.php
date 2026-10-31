<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dashboard v1: four all-time sales figures across the authenticated user's own
 * businesses.
 *
 * Every query is scoped to businesses owned by the authenticated user.
 * Revenue uses order-item price snapshots and database decimal arithmetic.
 */
class SalesOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'Sales overview';

    protected ?string $description = 'All-time figures across your businesses.';

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $metrics = $this->salesMetrics();
        $best = $metrics['bestSeller'];

        return [
            Stat::make('Total revenue', $this->formatMoney($metrics['revenue']))
                ->description('Completed orders only'),
            Stat::make('Completed orders', number_format($metrics['completedOrders'])),
            Stat::make('Best-selling product', $best['name'] ?? 'No sales yet')
                ->description($best === null
                    ? 'Complete a sale to see this'
                    : number_format($best['quantity']).' sold'),
            Stat::make('Open orders', number_format($metrics['openOrders']))
                ->description('Not yet completed'),
        ];
    }

    /**
     * @return array{
     *     revenue: string,
     *     completedOrders: int,
     *     openOrders: int,
     *     bestSeller: array{id: int, name: string, quantity: int}|null
     * }
     */
    protected function salesMetrics(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return $this->emptyMetrics();
        }

        // Query 1: completed-order count and revenue.
        $totals = Order::query()
            ->leftJoin('order_items', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('orders.business_id', $this->ownedBusinessIds($user))
            ->where('orders.status', OrderStatus::Completed->value)
            ->selectRaw('COUNT(DISTINCT orders.id) as completed_orders')
            ->selectRaw('COALESCE(SUM(order_items.unit_price * order_items.quantity), 0) as revenue')
            ->toBase()
            ->first();

        // Query 2: open orders.
        $openOrders = Order::query()
            ->whereIn('orders.business_id', $this->ownedBusinessIds($user))
            ->where('orders.status', OrderStatus::Open->value)
            ->count();

        // Query 3: best-selling product by total quantity across its variants.
        // The product is reached through product_variants because order_items
        // stores product_variant_id rather than product_id.
        $best = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('product_variants', 'product_variants.id', '=', 'order_items.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereIn('orders.business_id', $this->ownedBusinessIds($user))
            ->where('orders.status', OrderStatus::Completed->value)
            ->whereColumn('products.business_id', 'orders.business_id')
            ->groupBy('products.id', 'products.name')
            ->selectRaw('products.id as product_id, products.name as product_name, SUM(order_items.quantity) as total_quantity')
            ->orderByDesc('total_quantity')
            ->orderBy('products.name')
            ->orderBy('products.id')
            ->toBase()
            ->first();

        return [
            'revenue' => $this->normalizeMoney((string) ($totals->revenue ?? '0')),
            'completedOrders' => (int) ($totals->completed_orders ?? 0),
            'openOrders' => $openOrders,
            'bestSeller' => $best === null ? null : [
                'id' => (int) $best->product_id,
                'name' => (string) $best->product_name,
                'quantity' => (int) $best->total_quantity,
            ],
        ];
    }

    /**
     * Return a query builder usable as a whereIn subquery.
     */
    private function ownedBusinessIds(User $user): Builder
    {
        return $user->businesses()
            ->getQuery()
            ->select('businesses.id');
    }

    /**
     * Normalize a database decimal string without BCMath or floating-point math.
     */
    private function normalizeMoney(string $amount): string
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        return $whole.'.'.$fraction;
    }

    /**
     * @return array{revenue: string, completedOrders: int, openOrders: int, bestSeller: null}
     */
    private function emptyMetrics(): array
    {
        return [
            'revenue' => '0.00',
            'completedOrders' => 0,
            'openOrders' => 0,
            'bestSeller' => null,
        ];
    }

    /**
     * Format a non-negative decimal string such as "1250.5" as "1,250.50".
     */
    private function formatMoney(string $amount): string
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '00');

        $whole = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);

        return $whole.'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isOwner();
    }
}
