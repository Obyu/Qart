<?php

namespace App\Filament\Pages;

use App\Enums\BusinessAbility;
use App\Enums\StaffRole;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Support\BusinessAccess;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TransactionHistory extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Transaction History';

    protected static ?string $title = 'Transaction History';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.transaction-history';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->transactionsQuery())
            ->columns([
                TextColumn::make('id')
                    ->label('Transaction')
                    ->formatStateUsing(fn ($state): string => '#'.$state)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                TextColumn::make('outlet.name')
                    ->label('Outlet')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('items')
                    ->label('Items')
                    ->state(fn (Order $record): string => (string) $record->items->sum('quantity')),

                TextColumn::make('bill_total')
                    ->label('Total')
                    ->state(fn (Order $record): string => $this->formatRupiah(
                        $record->bill_total ?? $this->itemsTotal($record),
                    )),

                TextColumn::make('status')
                    ->label('Transaction Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => match ($state instanceof BackedEnum ? $state->value : $state) {
                        'open' => 'Open',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                        default => (string) ($state instanceof BackedEnum ? $state->value : $state),
                    })
                    ->color(fn ($state): string => match ($state instanceof BackedEnum ? $state->value : $state) {
                        'completed' => 'success',
                        'open' => 'warning',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('payment_summary')
                    ->label('Payments')
                    ->state(fn (Order $record): string => $this->paymentSummary($record)),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Transaction Status')
                    ->options([
                        'open' => 'Open',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                    ]),

                SelectFilter::make('payments.method')
                    ->label('Payment Method')
                    ->options([
                        'cash' => 'Cash',
                        'qris' => 'QRIS',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $method = $data['value'] ?? null;

                        if (! in_array($method, ['cash', 'qris'], true)) {
                            return $query;
                        }

                        return $query->whereHas(
                            'payments',
                            fn (Builder $payments): Builder => $payments->where('method', $method),
                        );
                    }),
            ])
            ->recordActions([
                Action::make('viewDetails')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading(fn (Order $record): string => 'Transaction #'.$record->getKey())
                    ->modalDescription('Transaction items and payment details.')
                    ->modalWidth('7xl')
                    ->modalContent(fn (Order $record) => view(
                        'filament.pages.transaction-detail-modal',
                        [
                            'order' => $record->load([
                                'business:id,name',
                                'outlet:id,name',
                                'items:id,order_id,product_variant_id,product_name,variant_name,sku,unit_price,quantity',
                                'payments:id,order_id,order_participant_id,method,status,amount,amount_received,change_amount,qris_reference,confirmed_at,rejected_at',
                                'participants:id,order_id,position,total_amount',
                                'participants.itemAllocations:id,order_id,order_participant_id,order_item_id,quantity',
                                'participants.itemAllocations.orderItem:id,product_name,variant_name',
                                'participants.payments:id,order_id,order_participant_id,method,status,amount,amount_received,change_amount,qris_reference,confirmed_at,rejected_at',
                            ]),
                        ],
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No transactions yet')
            ->emptyStateDescription('Transactions from your businesses will appear here.');
    }

    /**
     * Only the orders this user may see: every outlet of their businesses (owner,
     * business admin) or the assigned outlet only (cashier). Nobody else sees any.
     *
     * @return Builder<Order>
     */
    private function transactionsQuery(): Builder
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return Order::query()->whereKey([]);
        }

        $scope = self::historyScopeFor($user);

        if ($scope === null) {
            return Order::query()->whereKey([]);
        }

        $query = Order::query()->whereIn('orders.business_id', $scope['business_ids']);

        if ($scope['outlet_id'] !== null) {
            $query->where('orders.outlet_id', $scope['outlet_id']);
        }

        return $query
            ->with([
                'outlet:id,name',
                'items:id,order_id,unit_price,quantity',
                'payments:id,order_id,method,status',
            ]);
    }

    private function paymentSummary(Order $order): string
    {
        if ($order->payments->isEmpty()) {
            return 'No payments';
        }

        return $order->payments
            ->map(function (Payment $payment): string {
                $method = strtoupper($payment->method);
                $status = ucfirst($payment->status);

                return "{$method}: {$status}";
            })
            ->unique()
            ->implode(', ');
    }

    private function itemsTotal(Order $order): string
    {
        if ($order->items->isEmpty()) {
            return '0';
        }

        return Money::sum($order->items->map(
            fn ($item): string => Money::mulQuantity((string) $item->unit_price, $item->quantity),
        ));
    }

    private function formatRupiah(string|int|float $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->isOwner() || self::historyScopeFor($user) !== null;
    }

    /**
     * Which orders a user may see in the history: every outlet of the businesses
     * they own or administer (owner, business admin), or only their assigned
     * outlet (cashier). Null means no access: kitchen, unassigned or invalid
     * accounts. The abilities come from BusinessAccess; nothing is decided here.
     *
     * @return array{business_ids: list<int>, outlet_id: int|null}|null
     */
    private static function historyScopeFor(User $user): ?array
    {
        $access = app(BusinessAccess::class);

        $businessIds = $access->businessIdsFor($user, BusinessAbility::ViewOrders);

        if ($businessIds !== []) {
            return ['business_ids' => $businessIds, 'outlet_id' => null];
        }

        if (
            $user->staffRole() === StaffRole::Cashier
            && $user->business_id !== null
            && $user->outlet_id !== null
            && $access->can(
                $user,
                BusinessAbility::ViewOrders,
                (int) $user->business_id,
                (int) $user->outlet_id,
            )
        ) {
            return [
                'business_ids' => [(int) $user->business_id],
                'outlet_id' => (int) $user->outlet_id,
            ];
        }

        return null;
    }
}
