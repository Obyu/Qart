<?php

namespace App\Filament\Pages;

use App\Enums\BusinessAbility;
use App\Exceptions\StockException;
use App\Filament\Support\StockFailureMessage;
use App\Filament\Support\StockQuantity;
use App\Models\Outlet;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\StockService;
use App\Support\BusinessAccess;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Throwable;

/**
 * Stock Management v1: a table of the stock balance of every product variant in
 * one outlet (Stage 2A), an "Adjust Stock" action (Stage 2B) and a "Record
 * Opening Stock" action (Stage 2C).
 *
 * Security model (same as Cashier): $outletId and the variant chosen in the
 * Adjust Stock and Record Opening Stock forms are client-controlled and are
 * never trusted. The outlet is
 * re-resolved from the database, scoped to outlets of the businesses in which
 * the authenticated user may manage stock (see accessibleOutlets()), when it
 * changes (updatedOutletId), on every render
 * (getViewData), whenever the table query is built (stockQuery), and again when
 * the action form is validated and submitted. The variant is re-resolved
 * through the outlet's business at validation and again at submission. An ID
 * that does not resolve is treated as absent.
 *
 * The page never writes stock levels or movements itself: every change goes
 * through StockService::adjust() or StockService::recordOpening(), which stay
 * the final authority for ownership, business consistency, locking, the
 * once-per-pair opening rule and non-negative balances.
 */
class StockManagement extends Page implements HasTable
{
    use InteractsWithTable;

    /** Variants offered per search in the variant selector of the stock forms. */
    private const VARIANT_SEARCH_LIMIT = 50;

    /** Failures the user can fix without leaving the modal. */
    private const CORRECTABLE_REASONS = [
        StockException::REASON_INVALID_QUANTITY,
        StockException::REASON_ZERO_QUANTITY,
        StockException::REASON_QUANTITY_OVERFLOW,
        StockException::REASON_INSUFFICIENT_STOCK,
        StockException::REASON_INVALID_NOTE,
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $navigationLabel = 'Stock';

    protected static ?string $title = 'Stock';

    protected string $view = 'filament.pages.stock-management';

    public string|int|null $outletId = null;

    public function mount(): void
    {
        $ids = $this->accessibleOutlets()->limit(2)->pluck('id');

        if ($ids->count() === 1) {
            $this->outletId = $ids->first();
        }
    }

    /**
     * Runs when the browser changes the outlet. A value that is not one of the
     * user's own outlets is dropped, and the table starts again from a clean
     * state (search, page) for the new outlet.
     */
    public function updatedOutletId(): void
    {
        if ($this->resolveOutlet() === null) {
            $this->outletId = null;
        }

        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->stockQuery())
            ->columns([
                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Variant')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('stock_quantity')
                    ->label('Balance')
                    ->alignEnd()
                    ->state(fn ($record): string => $this->balanceOf($record)),
            ])
            ->defaultSort('id')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No product variants')
            ->emptyStateDescription('This business has no product variants yet.');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('adjustStock')
                ->label('Adjust Stock')
                ->modalHeading('Adjust stock')
                ->modalDescription('Enter how much to add or remove. This is the change to the balance, not the new balance.')
                ->modalSubmitActionLabel('Adjust stock')
                ->disabled(fn (): bool => $this->resolveOutlet() === null)
                ->schema([
                    $this->variantSelect(),
                    Radio::make('direction')
                        ->label('Direction')
                        ->options([
                            StockQuantity::DIRECTION_ADD => 'Add stock (increase the balance)',
                            StockQuantity::DIRECTION_REMOVE => 'Remove stock (decrease the balance)',
                        ])
                        ->required(),
                    TextInput::make('quantity')
                        ->label('Amount to add or remove')
                        ->helperText('A positive amount with up to 3 decimals. It is added to or removed from the current balance.')
                        ->required()
                        ->inputMode('decimal')
                        ->rules([
                            fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (StockQuantity::normalize($value) === null) {
                                    $fail('Enter a positive number with at most 11 whole digits and 3 decimal places.');
                                }
                            },
                        ]),
                    Textarea::make('note')
                        ->label('Reason')
                        ->required()
                        ->maxLength(255)
                        ->rows(3),
                ])
                ->action(fn (array $data, Action $action) => $this->adjustStock($data, $action)),
            Action::make('recordOpeningStock')
                ->label('Record Opening Stock')
                ->modalHeading('Record opening stock')
                ->modalDescription('Record the initial stock of a product variant in this outlet. Opening stock can be recorded only once for each variant; use "Adjust Stock" for later changes.')
                ->modalSubmitActionLabel('Record opening stock')
                ->disabled(fn (): bool => $this->resolveOutlet() === null)
                ->schema([
                    $this->variantSelect(),
                    TextInput::make('quantity')
                        ->label('Opening quantity')
                        ->helperText('A positive amount with up to 3 decimals. If this variant already has a balance in the outlet, the amount is added to that balance.')
                        ->required()
                        ->inputMode('decimal')
                        ->rules([
                            fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (StockQuantity::normalize($value) === null) {
                                    $fail('Enter a positive number with at most 11 whole digits and 3 decimal places.');
                                }
                            },
                        ]),
                    Textarea::make('note')
                        ->label('Note')
                        ->maxLength(255)
                        ->rows(3),
                ])
                ->action(fn (array $data, Action $action) => $this->recordOpeningStock($data, $action)),
        ];
    }

    /**
     * The product-variant selector shared by the stock forms. The submitted ID is
     * only a candidate: the options, the label and the validation rule all go
     * through the outlet-scoped helpers, so a variant of another business is
     * never offered and never accepted.
     */
    private function variantSelect(): Select
    {
        return Select::make('variant_id')
            ->label('Product variant')
            ->helperText('Type to search by product, variant or SKU.')
            ->required()
            ->searchable()
            ->getSearchResultsUsing(fn (string $search): array => $this->variantSearchResults($search))
            ->getOptionLabelUsing(fn (mixed $value): ?string => $this->variantLabelFor($value))
            ->rules([
                fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    $outlet = $this->resolveOutlet();

                    if ($outlet === null || $this->resolveVariant($outlet, $value) === null) {
                        $fail('Select a product variant from the list.');
                    }
                },
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'outletOptions' => $this->outletOptions(),
            'hasSelectedOutlet' => $this->resolveOutlet() !== null,
        ];
    }

    /**
     * Apply the submitted adjustment. Outlet and variant are resolved again here
     * from the database; the form values are only candidates. The change itself
     * is made by StockService::adjust() and nothing else.
     *
     * Failures the user can fix keep the modal open (halt). Anything else closes
     * it (cancel). Only safe, fixed messages are ever shown; the exception's own
     * message is never displayed.
     *
     * @param  array<string, mixed>  $data
     */
    private function adjustStock(array $data, Action $action): void
    {
        $user = auth()->user();
        $outlet = $this->resolveOutlet();
        $variant = $outlet === null ? null : $this->resolveVariant($outlet, $data['variant_id'] ?? null);

        if ($user === null || $outlet === null || $variant === null) {
            $this->notifyFailure(StockFailureMessage::UNAVAILABLE);
            $action->cancel();

            return;
        }

        try {
            $delta = StockQuantity::signed((string) ($data['direction'] ?? ''), (string) ($data['quantity'] ?? ''));

            $movement = app(StockService::class)->adjust($user, $outlet, $variant, $delta, $data['note'] ?? null);
        } catch (StockException $e) {
            $this->notifyFailure(StockFailureMessage::describe($e));

            if (in_array($e->reason, self::CORRECTABLE_REASONS, true)) {
                $action->halt();
            }

            $action->cancel();

            return;
        } catch (InvalidArgumentException) {
            $this->notifyFailure(StockFailureMessage::FALLBACK);
            $action->halt();

            return;
        } catch (Throwable $e) {
            report($e);

            $this->notifyFailure(StockFailureMessage::FALLBACK);
            $action->cancel();

            return;
        }

        Notification::make()
            ->success()
            ->title('Stock adjusted')
            ->body(
                $this->variantLabel($variant).': '
                .StockQuantity::formatSigned((string) $movement->quantity_delta)
                .'. New balance: '.StockQuantity::format((string) $movement->quantity_after).'.'
            )
            ->send();
    }

    /**
     * Record the submitted opening stock. Outlet and variant are resolved again
     * here from the database; the form values are only candidates. The movement
     * itself is written by StockService::recordOpening() and nothing else, which
     * also enforces the once-per-pair rule, so a duplicate leaves the balance
     * untouched. An existing balance (for example one created by an adjustment)
     * is not an error: the opening quantity is added to it.
     *
     * Failure handling matches adjustStock(): failures the user can fix keep the
     * modal open (halt), anything else closes it (cancel), and only safe, fixed
     * messages are ever shown.
     *
     * @param  array<string, mixed>  $data
     */
    private function recordOpeningStock(array $data, Action $action): void
    {
        $user = auth()->user();
        $outlet = $this->resolveOutlet();
        $variant = $outlet === null ? null : $this->resolveVariant($outlet, $data['variant_id'] ?? null);

        if ($user === null || $outlet === null || $variant === null) {
            $this->notifyFailure(StockFailureMessage::UNAVAILABLE);
            $action->cancel();

            return;
        }

        try {
            $quantity = StockQuantity::normalize($data['quantity'] ?? null)
                ?? throw new InvalidArgumentException('The quantity must be a positive decimal string.');

            $movement = app(StockService::class)->recordOpening($user, $outlet, $variant, $quantity, $data['note'] ?? null);
        } catch (StockException $e) {
            $this->notifyFailure(StockFailureMessage::describe($e));

            if (in_array($e->reason, self::CORRECTABLE_REASONS, true)) {
                $action->halt();
            }

            $action->cancel();

            return;
        } catch (InvalidArgumentException) {
            $this->notifyFailure(StockFailureMessage::FALLBACK);
            $action->halt();

            return;
        } catch (Throwable $e) {
            report($e);

            $this->notifyFailure(StockFailureMessage::FALLBACK);
            $action->cancel();

            return;
        }

        Notification::make()
            ->success()
            ->title('Opening stock recorded')
            ->body(
                $this->variantLabel($variant).': opening stock '
                .StockQuantity::format((string) $movement->quantity_delta)
                .' recorded. New balance: '.StockQuantity::format((string) $movement->quantity_after).'.'
            )
            ->send();
    }

    private function notifyFailure(string $message): void
    {
        Notification::make()
            ->danger()
            ->title($message)
            ->send();
    }

    /**
     * Every product variant of the selected outlet's business, with the balance
     * of that one outlet. A variant without a stock level has a NULL sum, which
     * balanceOf() shows as 0.000. Nothing is created for it.
     *
     * @return Builder<ProductVariant>
     */
    private function stockQuery(): Builder
    {
        $outlet = $this->resolveOutlet();

        if ($outlet === null) {
            return ProductVariant::query()->whereKey([]);
        }

        return $this->variantsOf($outlet)
            ->withSum(
                ['stockLevels as stock_quantity' => fn (Builder $query) => $query->where('outlet_id', $outlet->getKey())],
                'quantity',
            );
    }

    /**
     * The product variants that belong to the outlet's business: the only
     * variants that may be listed or adjusted for that outlet.
     *
     * @return Builder<ProductVariant>
     */
    private function variantsOf(Outlet $outlet): Builder
    {
        return ProductVariant::query()
            ->whereHas('product', fn (Builder $query) => $query->where('business_id', $outlet->business_id));
    }

    private function resolveVariant(Outlet $outlet, mixed $id): ?ProductVariant
    {
        $id = $this->positiveInt($id);

        if ($id === null) {
            return null;
        }

        return $this->variantsOf($outlet)
            ->with('product:id,name')
            ->whereKey($id)
            ->first();
    }

    /**
     * @return array<int, string> variant id => label
     */
    private function variantSearchResults(string $search): array
    {
        $outlet = $this->resolveOutlet();

        if ($outlet === null) {
            return [];
        }

        $term = '%'.addcslashes(trim($search), '%_\\').'%';

        return $this->variantsOf($outlet)
            ->with('product:id,name')
            ->where(fn (Builder $query) => $query
                ->where('name', 'like', $term)
                ->orWhere('sku', 'like', $term)
                ->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', $term)))
            ->orderBy('id')
            ->limit(self::VARIANT_SEARCH_LIMIT)
            ->get()
            ->mapWithKeys(fn (ProductVariant $variant): array => [$variant->getKey() => $this->variantLabel($variant)])
            ->all();
    }

    private function variantLabelFor(mixed $id): ?string
    {
        $outlet = $this->resolveOutlet();
        $variant = $outlet === null ? null : $this->resolveVariant($outlet, $id);

        return $variant === null ? null : $this->variantLabel($variant);
    }

    private function variantLabel(ProductVariant $variant): string
    {
        return $variant->product?->name.' - '.$variant->name.' ('.$variant->sku.')';
    }

    /**
     * The balance as a decimal string with exactly 3 decimals, never a float.
     */
    private function balanceOf(ProductVariant $record): string
    {
        return bcadd((string) ($record->getAttribute('stock_quantity') ?? '0'), '0', 3);
    }

    /**
     * Outlets of the businesses in which the authenticated user may manage
     * stock: every business of an owner, or the one business of a business
     * admin. BusinessAccess decides; nothing here widens it. The only source of
     * outlets for the dropdown, the preselection and the outlet check.
     *
     * @return Builder<Outlet>
     */
    private function accessibleOutlets(): Builder
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return Outlet::query()->whereKey([]);
        }

        $businessIds = app(BusinessAccess::class)->businessIdsFor($user, BusinessAbility::ManageStock);

        return Outlet::query()->whereIn('business_id', $businessIds);
    }

    private function resolveOutlet(): ?Outlet
    {
        $id = $this->positiveInt($this->outletId);

        if ($id === null) {
            return null;
        }

        return $this->accessibleOutlets()->whereKey($id)->first();
    }

    /**
     * @return array<int, string> outlet id => "Business - Outlet"
     */
    private function outletOptions(): array
    {
        return $this->accessibleOutlets()
            ->with('business:id,name')
            ->get()
            ->sortBy(fn (Outlet $outlet): string => mb_strtolower($outlet->business?->name.' '.$outlet->name))
            ->mapWithKeys(fn (Outlet $outlet): array => [$outlet->getKey() => $outlet->business?->name.' - '.$outlet->name])
            ->all();
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

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        // An owner (no staff attributes) may always open the page, even before the
        // first business exists. Staff are admitted only through BusinessAccess.
        return $user->isOwner()
            || app(BusinessAccess::class)->businessIdsFor($user, BusinessAbility::ManageStock) !== [];
    }
}
