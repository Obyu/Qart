<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Exceptions\StockException;
use App\Filament\Pages\StockManagement;
use App\Filament\Support\StockFailureMessage;
use App\Models\Business;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Stock Management v1, Stage 2C (Record Opening Stock action on the Filament page).
 *
 * The opening rules themselves (once per outlet and variant, locking, ledger
 * rows) are covered by the StockService and StockConcurrency tests. These tests
 * cover what the page adds: form validation, server-side scoping of the outlet
 * and the variant, safe failure messages, and that a rejected submission changes
 * nothing.
 */
class FilamentOpeningStockTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Business $business;

    private Outlet $outlet;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $this->user = User::factory()->create();
        $this->business = Business::factory()->for($this->user)->create(['name' => 'Kopi Qart']);
        $this->outlet = Outlet::factory()->for($this->business)->create(['name' => 'Main Counter']);
        $this->variant = $this->variantFor($this->business, 'Latte', 'Large', 'LAT-L');
    }

    /**
     * The Stock page as the authenticated owner, with an outlet selected the way
     * the browser would.
     */
    private function stockPage(?Outlet $outlet = null)
    {
        $this->actingAs($this->user);

        return Livewire::test(StockManagement::class)->set('outletId', ($outlet ?? $this->outlet)->id);
    }

    /**
     * Submit the Record Opening Stock form with the given values (defaults are valid).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function submitOpening($component, array $overrides = [])
    {
        return $component->callAction('recordOpeningStock', data: array_merge([
            'variant_id' => $this->variant->id,
            'quantity' => '10',
            'note' => 'Initial count',
        ], $overrides));
    }

    private function variantFor(Business $business, string $product, string $name, string $sku): ProductVariant
    {
        $category = Category::factory()->for($business)->create();
        $productModel = Product::factory()->for($category)->create(['name' => $product]);

        return ProductVariant::factory()->for($productModel)->create(['name' => $name, 'sku' => $sku, 'price' => '10.00']);
    }

    private function stock(Outlet $outlet, ProductVariant $variant, string $quantity, ?User $actor = null): void
    {
        (new StockService)->recordOpening($actor ?? $this->user, $outlet, $variant, $quantity);
    }

    private function balance(Outlet $outlet, ProductVariant $variant): ?string
    {
        return StockLevel::query()
            ->where('outlet_id', $outlet->id)
            ->where('product_variant_id', $variant->id)
            ->first()
            ?->quantity;
    }

    private function openingMovement(): StockMovement
    {
        return StockMovement::query()->where('type', StockMovementType::Opening->value)->sole();
    }

    private function openingCount(): int
    {
        return StockMovement::query()->where('type', StockMovementType::Opening->value)->count();
    }

    private function assertNothingWasWritten(): void
    {
        $this->assertDatabaseCount('stock_levels', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    /**
     * Replace StockService with one whose recordOpening() always throws.
     */
    private function stubServiceFailingWith(Throwable $failure): void
    {
        $service = new class extends StockService
        {
            public ?Throwable $failure = null;

            public function recordOpening(
                User $actor,
                Outlet $outlet,
                ProductVariant $variant,
                string|int|float $quantity,
                ?string $note = null,
            ): StockMovement {
                throw $this->failure;
            }
        };

        $service->failure = $failure;

        $this->app->instance(StockService::class, $service);
    }

    // The action

    public function test_the_action_is_shown_next_to_adjust_stock_and_enabled_for_an_owned_outlet(): void
    {
        $this->stockPage()
            ->assertSee('Record Opening Stock')
            ->assertSee('Adjust Stock')
            ->assertActionEnabled('recordOpeningStock')
            ->assertActionEnabled('adjustStock');
    }

    public function test_the_action_is_disabled_until_an_outlet_is_selected(): void
    {
        Outlet::factory()->for($this->business)->create(['name' => 'Back Counter']);
        $this->actingAs($this->user);

        Livewire::test(StockManagement::class)
            ->assertSet('outletId', null)
            ->assertActionDisabled('recordOpeningStock');
    }

    public function test_another_users_outlet_cannot_be_selected_so_nothing_can_be_recorded(): void
    {
        $rival = Business::factory()->create();
        $rivalOutlet = Outlet::factory()->for($rival)->create();
        $rivalVariant = $this->variantFor($rival, 'Rival Cake', 'Slice', 'RIV-S');

        $this->stockPage()
            ->set('outletId', $rivalOutlet->id)
            ->assertSet('outletId', null)
            ->assertActionDisabled('recordOpeningStock');

        $this->assertNull($this->balance($rivalOutlet, $rivalVariant));
        $this->assertNothingWasWritten();
    }

    public function test_the_action_is_disabled_when_ownership_of_the_selected_outlet_is_lost(): void
    {
        $newOwner = User::factory()->create();

        $component = $this->stockPage()->assertActionEnabled('recordOpeningStock');

        DB::table('businesses')->where('id', $this->business->id)->update(['user_id' => $newOwner->id]);

        $component->call('$refresh')->assertActionDisabled('recordOpeningStock');

        $this->assertNothingWasWritten();
    }

    // Successful openings

    public function test_an_authorized_user_can_record_opening_stock(): void
    {
        $this->submitOpening($this->stockPage(), ['quantity' => '10.5', 'note' => 'Initial count'])
            ->assertHasNoActionErrors()
            ->assertNotified('Opening stock recorded')
            ->assertSee('10.500');

        $this->assertSame('10.500', $this->balance($this->outlet, $this->variant));
        $this->assertSame(1, StockLevel::query()->count());
        $this->assertSame(1, StockMovement::query()->count());

        $movement = $this->openingMovement();
        $this->assertSame(StockMovementType::Opening, $movement->type);
        $this->assertEquals($this->outlet->id, $movement->outlet_id);
        $this->assertEquals($this->variant->id, $movement->product_variant_id);
        $this->assertEquals($this->user->id, $movement->user_id);
        $this->assertSame('10.500', $movement->quantity_delta);
        $this->assertSame('10.500', $movement->quantity_after);
        $this->assertSame('Initial count', $movement->note);
    }

    public function test_the_success_notification_names_the_variant_the_quantity_and_the_balance(): void
    {
        $this->submitOpening($this->stockPage(), ['quantity' => '10.5'])
            ->assertNotified(
                Notification::make()
                    ->success()
                    ->title('Opening stock recorded')
                    ->body('Latte - Large (LAT-L): opening stock 10.5 recorded. New balance: 10.5.')
            );
    }

    #[DataProvider('validQuantities')]
    public function test_valid_quantities_are_stored_exactly_with_three_decimals(string $typed, string $stored): void
    {
        $this->submitOpening($this->stockPage(), ['quantity' => $typed])
            ->assertHasNoActionErrors()
            ->assertNotified('Opening stock recorded');

        $this->assertSame($stored, $this->balance($this->outlet, $this->variant));
        $this->assertSame($stored, $this->openingMovement()->quantity_delta);
        $this->assertSame($stored, $this->openingMovement()->quantity_after);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function validQuantities(): array
    {
        return [
            'whole number' => ['1', '1.000'],
            'smallest amount' => ['0.001', '0.001'],
            'decimals' => ['12.5', '12.500'],
            'largest amount' => ['99999999999.999', '99999999999.999'],
            'surrounding spaces' => [' 7 ', '7.000'],
        ];
    }

    #[DataProvider('acceptedNotes')]
    public function test_the_note_is_optional_and_stored_trimmed(?string $typed, ?string $stored): void
    {
        $this->submitOpening($this->stockPage(), ['note' => $typed])
            ->assertHasNoActionErrors()
            ->assertNotified('Opening stock recorded');

        $this->assertSame($stored, $this->openingMovement()->note);
    }

    /**
     * @return array<string, array{string|null, string|null}>
     */
    public static function acceptedNotes(): array
    {
        return [
            'no note' => [null, null],
            'empty' => ['', null],
            'spaces only' => ['   ', null],
            'trimmed' => ["  Counted \n", 'Counted'],
            'maximum length' => [str_repeat('a', 255), str_repeat('a', 255)],
        ];
    }

    public function test_a_note_longer_than_255_characters_is_rejected_without_changes(): void
    {
        $this->submitOpening($this->stockPage(), ['note' => str_repeat('a', 256)])
            ->assertHasActionErrors(['note']);

        $this->assertNothingWasWritten();
    }

    public function test_opening_is_allowed_once_for_each_outlet_and_variant_pair(): void
    {
        $back = Outlet::factory()->for($this->business)->create(['name' => 'Back Counter']);
        $cake = $this->variantFor($this->business, 'Cake', 'Slice', 'CAK-S');

        $this->submitOpening($this->stockPage(), ['quantity' => '1'])->assertHasNoActionErrors();
        $this->submitOpening($this->stockPage(), ['variant_id' => $cake->id, 'quantity' => '2'])->assertHasNoActionErrors();
        $this->submitOpening($this->stockPage($back), ['quantity' => '3'])->assertHasNoActionErrors();

        $this->assertSame('1.000', $this->balance($this->outlet, $this->variant));
        $this->assertSame('2.000', $this->balance($this->outlet, $cake));
        $this->assertSame('3.000', $this->balance($back, $this->variant));
        $this->assertSame(3, $this->openingCount());
    }

    public function test_an_opening_after_an_adjustment_is_added_to_the_existing_balance(): void
    {
        // An adjustment can create a balance before any opening exists.
        (new StockService)->adjust($this->user, $this->outlet, $this->variant, '4.25');

        $this->submitOpening($this->stockPage(), ['quantity' => '10'])
            ->assertHasNoActionErrors()
            ->assertNotified('Opening stock recorded')
            ->assertSee('14.250');

        $this->assertSame('14.250', $this->balance($this->outlet, $this->variant));

        $movement = $this->openingMovement();
        $this->assertSame('10.000', $movement->quantity_delta);
        $this->assertSame('14.250', $movement->quantity_after);
        $this->assertSame(2, StockMovement::query()->count());
    }

    // Duplicate openings

    public function test_a_duplicate_opening_is_rejected_and_points_to_adjust_stock(): void
    {
        $page = $this->stockPage();

        $this->submitOpening($page, ['quantity' => '10', 'note' => 'First count'])->assertHasNoActionErrors();

        $message = StockFailureMessage::describe(StockException::duplicateOpening());

        $this->submitOpening($page, ['quantity' => '5', 'note' => 'Second count'])
            ->assertNotified($message);

        $this->assertStringContainsString('Adjust stock', $message);

        $this->assertSame('10.000', $this->balance($this->outlet, $this->variant));
        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame(1, $this->openingCount());
        $this->assertSame('First count', $this->openingMovement()->note);
    }

    public function test_a_duplicate_is_still_rejected_after_the_balance_has_changed(): void
    {
        $this->stock($this->outlet, $this->variant, '10');
        (new StockService)->adjust($this->user, $this->outlet, $this->variant, '5');

        $this->submitOpening($this->stockPage(), ['quantity' => '1'])
            ->assertNotified(StockFailureMessage::describe(StockException::duplicateOpening()));

        $this->assertSame('15.000', $this->balance($this->outlet, $this->variant));
        $this->assertSame(2, StockMovement::query()->count());
        $this->assertSame(1, $this->openingCount());
    }

    // Rejected quantities

    #[DataProvider('invalidQuantities')]
    public function test_an_invalid_quantity_is_rejected_without_changes(string $quantity): void
    {
        $this->submitOpening($this->stockPage(), ['quantity' => $quantity])
            ->assertHasActionErrors(['quantity']);

        $this->assertNothingWasWritten();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidQuantities(): array
    {
        return [
            'zero' => ['0'],
            'zero with decimals' => ['0.000'],
            'negative' => ['-5'],
            'plus sign' => ['+5'],
            'four decimals' => ['1.2345'],
            'text' => ['abc'],
            'decimal comma' => ['1,5'],
            'exponent notation' => ['1e3'],
            'trailing dot' => ['5.'],
            'leading dot' => ['.5'],
            'twelve whole digits' => ['123456789012'],
            'empty' => [''],
            'spaces' => ['   '],
        ];
    }

    public function test_an_opening_that_overflows_an_existing_balance_is_rejected_without_changes(): void
    {
        (new StockService)->adjust($this->user, $this->outlet, $this->variant, '99999999999.999');

        $this->submitOpening($this->stockPage(), ['quantity' => '0.001'])
            ->assertNotified(StockFailureMessage::describe(StockException::quantityOverflow()));

        $this->assertSame('99999999999.999', $this->balance($this->outlet, $this->variant));
        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame(0, $this->openingCount());
    }

    // Scoping

    public function test_a_variant_of_another_business_cannot_receive_opening_stock(): void
    {
        $rival = Business::factory()->create();
        $rivalVariant = $this->variantFor($rival, 'Rival Cake', 'Slice', 'RIV-S');

        $second = Business::factory()->for($this->user)->create(['name' => 'Second Shop']);
        $siblingVariant = $this->variantFor($second, 'Green Tea', 'Cup', 'TEA-C');

        $page = $this->stockPage();

        foreach ([$rivalVariant, $siblingVariant] as $variant) {
            $this->submitOpening($page, ['variant_id' => $variant->id, 'quantity' => '3'])
                ->assertHasActionErrors(['variant_id']);

            $page->unmountAction();
        }

        $this->assertNull($this->balance($this->outlet, $rivalVariant));
        $this->assertNull($this->balance($this->outlet, $siblingVariant));
        $this->assertNothingWasWritten();
    }

    public function test_the_variant_is_scoped_to_the_selected_outlets_business(): void
    {
        $second = Business::factory()->for($this->user)->create(['name' => 'Second Shop']);
        $secondOutlet = Outlet::factory()->for($second)->create(['name' => 'Second Counter']);
        $tea = $this->variantFor($second, 'Green Tea', 'Cup', 'TEA-C');

        $page = $this->stockPage();

        // The tea belongs to the user, but not to the business of the selected outlet.
        $this->submitOpening($page, ['variant_id' => $tea->id])->assertHasActionErrors(['variant_id']);
        $this->assertNull($this->balance($this->outlet, $tea));
        $this->assertNull($this->balance($secondOutlet, $tea));

        // Close the action halted by the previous validation error.
        $page->unmountAction();

        // With the second business's outlet selected, the same variant is valid.
        $page->set('outletId', $secondOutlet->id);

        $this->submitOpening($page, ['variant_id' => $tea->id, 'quantity' => '4'])
            ->assertHasNoActionErrors();

        $this->assertSame('4.000', $this->balance($secondOutlet, $tea));
        $this->assertNull($this->balance($this->outlet, $tea));
    }

    #[DataProvider('invalidVariantIds')]
    public function test_a_forged_or_malformed_variant_id_is_rejected(mixed $variantId): void
    {
        $this->submitOpening($this->stockPage(), ['variant_id' => $variantId])
            ->assertHasActionErrors(['variant_id']);

        $this->assertNothingWasWritten();
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidVariantIds(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'zero' => [0],
            'negative' => [-1],
            'text' => ['abc'],
            'sql fragment' => ['1 OR 1=1'],
            'non-existent id' => [999999999],
        ];
    }

    public function test_a_variant_that_disappeared_after_the_page_was_opened_is_rejected(): void
    {
        $page = $this->stockPage();
        $staleId = $this->variant->id;

        $this->variant->delete();

        $this->submitOpening($page, ['variant_id' => $staleId])
            ->assertHasActionErrors(['variant_id']);

        $this->assertNothingWasWritten();
    }

    // Write path and failure handling

    public function test_the_stock_is_written_only_through_stock_service_with_the_resolved_models(): void
    {
        $spy = new class extends StockService
        {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function recordOpening(
                User $actor,
                Outlet $outlet,
                ProductVariant $variant,
                string|int|float $quantity,
                ?string $note = null,
            ): StockMovement {
                $this->calls[] = [
                    'actor' => $actor->getKey(),
                    'outlet' => $outlet->getKey(),
                    'variant' => $variant->getKey(),
                    'quantity' => $quantity,
                    'note' => $note,
                ];

                return parent::recordOpening($actor, $outlet, $variant, $quantity, $note);
            }
        };

        $this->app->instance(StockService::class, $spy);

        $this->submitOpening($this->stockPage(), ['quantity' => ' 7 ', 'note' => 'Counted'])
            ->assertHasNoActionErrors();

        $this->assertCount(1, $spy->calls);
        $this->assertEquals($this->user->id, $spy->calls[0]['actor']);
        $this->assertEquals($this->outlet->id, $spy->calls[0]['outlet']);
        $this->assertEquals($this->variant->id, $spy->calls[0]['variant']);
        $this->assertSame('7', $spy->calls[0]['quantity']);
        $this->assertSame('Counted', $spy->calls[0]['note']);
        $this->assertSame('7.000', $this->balance($this->outlet, $this->variant));
    }

    #[DataProvider('unavailableFailures')]
    public function test_an_ownership_or_lookup_failure_from_the_service_shows_the_unavailable_message(string $factory): void
    {
        $this->stubServiceFailingWith(StockException::{$factory}());

        $this->submitOpening($this->stockPage())
            ->assertNotified(StockFailureMessage::UNAVAILABLE);

        $this->assertNothingWasWritten();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unavailableFailures(): array
    {
        return [
            'not found' => ['notFound'],
            'unauthorized' => ['unauthorized'],
            'business mismatch' => ['businessMismatch'],
        ];
    }

    public function test_the_exceptions_own_message_is_never_shown(): void
    {
        $this->stubServiceFailingWith(StockException::invalidQuantity('secret balance 12345'));

        $this->submitOpening($this->stockPage())
            ->assertNotified(StockFailureMessage::describe(StockException::invalidQuantity('anything')))
            ->assertDontSee('secret balance 12345');

        $this->assertNothingWasWritten();
    }

    public function test_an_unexpected_failure_is_reported_and_shows_only_a_generic_message(): void
    {
        Exceptions::fake();

        $this->stubServiceFailingWith(new RuntimeException('secret database detail'));

        $this->submitOpening($this->stockPage())
            ->assertNotified(StockFailureMessage::FALLBACK)
            ->assertDontSee('secret database detail');

        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'secret database detail');

        $this->assertNothingWasWritten();
    }
}
