<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Exceptions\StockException;
use App\Filament\Pages\StockManagement;
use App\Filament\Support\StockFailureMessage;
use App\Filament\Support\StockQuantity;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Stock Management v1, Stage 2B (Adjust Stock action on the Filament page).
 *
 * The balance rules themselves (locking, non-negative balances, ledger rows) are
 * covered by the StockService tests. These tests cover what the page adds: form
 * validation, server-side scoping of the outlet and the variant, safe failure
 * messages, and that a rejected submission changes nothing.
 */
class FilamentStockAdjustmentTest extends TestCase
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
     * Submit the Adjust Stock form with the given values (defaults are valid).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function adjust($component, array $overrides = [])
    {
        return $component->callAction('adjustStock', data: array_merge([
            'variant_id' => $this->variant->id,
            'direction' => StockQuantity::DIRECTION_ADD,
            'quantity' => '1',
            'note' => 'Cycle count',
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

    private function adjustmentMovement(): StockMovement
    {
        return StockMovement::query()->where('type', StockMovementType::Adjustment->value)->sole();
    }

    private function insufficientStockMessage(): string
    {
        return StockFailureMessage::describe(StockException::insufficientStock('0.000', '-1.000'));
    }

    // Successful adjustments

    public function test_an_authorized_user_can_increase_stock(): void
    {
        $this->stock($this->outlet, $this->variant, '10');

        $this->adjust($this->stockPage(), ['quantity' => '5.25', 'note' => 'Found extra stock'])
            ->assertHasNoActionErrors()
            ->assertNotified('Stock adjusted')
            ->assertSee('15.250');

        $this->assertSame('15.250', $this->balance($this->outlet, $this->variant));

        $movement = $this->adjustmentMovement();
        $this->assertEquals($this->outlet->id, $movement->outlet_id);
        $this->assertEquals($this->variant->id, $movement->product_variant_id);
        $this->assertEquals($this->user->id, $movement->user_id);
        $this->assertSame('5.250', $movement->quantity_delta);
        $this->assertSame('15.250', $movement->quantity_after);
        $this->assertSame('Found extra stock', $movement->note);
        $this->assertSame(2, StockMovement::query()->count());
    }

    public function test_an_authorized_user_can_decrease_stock(): void
    {
        $this->stock($this->outlet, $this->variant, '10');

        $this->adjust($this->stockPage(), ['direction' => StockQuantity::DIRECTION_REMOVE, 'quantity' => '2.5', 'note' => 'Spillage'])
            ->assertHasNoActionErrors()
            ->assertNotified('Stock adjusted')
            ->assertSee('7.500');

        $this->assertSame('7.500', $this->balance($this->outlet, $this->variant));

        $movement = $this->adjustmentMovement();
        $this->assertSame('-2.500', $movement->quantity_delta);
        $this->assertSame('7.500', $movement->quantity_after);
        $this->assertSame('Spillage', $movement->note);
        $this->assertSame(2, StockMovement::query()->count());
    }

    public function test_the_whole_balance_can_be_removed_but_not_more(): void
    {
        $this->stock($this->outlet, $this->variant, '4');

        $this->adjust($this->stockPage(), ['direction' => StockQuantity::DIRECTION_REMOVE, 'quantity' => '4'])
            ->assertNotified('Stock adjusted');

        $this->assertSame('0.000', $this->balance($this->outlet, $this->variant));
    }

    public function test_adding_to_a_variant_without_a_stock_level_creates_its_balance_through_the_service(): void
    {
        $this->adjust($this->stockPage(), ['quantity' => '2.5'])
            ->assertNotified('Stock adjusted')
            ->assertSee('2.500');

        $this->assertSame('2.500', $this->balance($this->outlet, $this->variant));
        $this->assertSame('2.500', $this->adjustmentMovement()->quantity_after);
    }

    public function test_the_reason_is_trimmed_and_a_255_character_reason_is_accepted(): void
    {
        $reason = str_repeat('a', 255);

        $page = $this->stockPage();
        $this->adjust($page, ['note' => "  Recount \n"])->assertNotified('Stock adjusted');
        $this->adjust($page, ['note' => $reason])->assertHasNoActionErrors();

        $notes = StockMovement::query()->orderBy('id')->pluck('note')->all();

        $this->assertSame(['Recount', $reason], $notes);
    }

    // Rejected adjustments

    public function test_removing_more_than_the_available_stock_is_rejected_without_changes(): void
    {
        $this->stock($this->outlet, $this->variant, '3');

        $this->adjust($this->stockPage(), ['direction' => StockQuantity::DIRECTION_REMOVE, 'quantity' => '3.001'])
            ->assertNotified($this->insufficientStockMessage());

        $this->assertSame('3.000', $this->balance($this->outlet, $this->variant));
        // Only the opening movement exists.
        $this->assertSame(1, StockMovement::query()->count());
    }

    public function test_removing_from_a_variant_without_a_stock_level_leaves_no_trace(): void
    {
        $this->adjust($this->stockPage(), ['direction' => StockQuantity::DIRECTION_REMOVE, 'quantity' => '1'])
            ->assertNotified($this->insufficientStockMessage());

        $this->assertDatabaseCount('stock_levels', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_an_adjustment_that_overflows_the_balance_is_rejected(): void
    {
        $this->stock($this->outlet, $this->variant, '99999999999.999');

        $this->adjust($this->stockPage(), ['quantity' => '0.001'])
            ->assertNotified(StockFailureMessage::describe(StockException::quantityOverflow()));

        $this->assertSame('99999999999.999', $this->balance($this->outlet, $this->variant));
        $this->assertSame(1, StockMovement::query()->count());
    }

    #[DataProvider('invalidQuantities')]
    public function test_an_invalid_quantity_is_rejected_without_changes(string $quantity): void
    {
        $this->stock($this->outlet, $this->variant, '10');

        $this->adjust($this->stockPage(), ['quantity' => $quantity])
            ->assertHasActionErrors(['quantity']);

        $this->assertSame('10.000', $this->balance($this->outlet, $this->variant));
        $this->assertSame(1, StockMovement::query()->count());
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

    #[DataProvider('invalidReasons')]
    public function test_a_blank_or_oversized_reason_is_rejected_without_changes(?string $note): void
    {
        $this->stock($this->outlet, $this->variant, '10');

        $this->adjust($this->stockPage(), ['note' => $note])
            ->assertHasActionErrors(['note']);

        $this->assertSame('10.000', $this->balance($this->outlet, $this->variant));
        $this->assertSame(1, StockMovement::query()->count());
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function invalidReasons(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'spaces' => ['   '],
            'whitespace characters' => ["\n \t "],
            'too long' => [str_repeat('a', 256)],
        ];
    }

    public function test_an_unknown_direction_changes_nothing(): void
    {
        $this->stock($this->outlet, $this->variant, '10');

        $this->adjust($this->stockPage(), ['direction' => 'sideways', 'quantity' => '5']);

        $this->assertSame('10.000', $this->balance($this->outlet, $this->variant));
        $this->assertSame(1, StockMovement::query()->count());
    }

    // Scoping

    public function test_another_users_outlet_cannot_be_selected_so_nothing_can_be_adjusted(): void
    {
        $rival = Business::factory()->create();
        $rivalOutlet = Outlet::factory()->for($rival)->create();
        $rivalVariant = $this->variantFor($rival, 'Rival Cake', 'Slice', 'RIV-S');
        $this->stock($rivalOutlet, $rivalVariant, '5', $rival->user);
        $this->stock($this->outlet, $this->variant, '10');

        $this->stockPage()
            ->set('outletId', $rivalOutlet->id)
            ->assertSet('outletId', null)
            ->assertActionDisabled('adjustStock');

        $this->assertSame('5.000', $this->balance($rivalOutlet, $rivalVariant));
        $this->assertSame('10.000', $this->balance($this->outlet, $this->variant));
        $this->assertSame(2, StockMovement::query()->count());
    }

    public function test_the_action_is_disabled_when_ownership_of_the_selected_outlet_is_lost(): void
    {
        $this->stock($this->outlet, $this->variant, '10');
        $newOwner = User::factory()->create();

        $component = $this->stockPage()->assertActionEnabled('adjustStock');

        DB::table('businesses')->where('id', $this->business->id)->update(['user_id' => $newOwner->id]);

        $component->call('$refresh')->assertActionDisabled('adjustStock');

        $this->assertSame('10.000', $this->balance($this->outlet, $this->variant));
        $this->assertSame(1, StockMovement::query()->count());
    }

    public function test_a_variant_of_another_business_cannot_be_adjusted(): void
    {
        $rival = Business::factory()->create();
        $rivalOutlet = Outlet::factory()->for($rival)->create();
        $rivalVariant = $this->variantFor($rival, 'Rival Cake', 'Slice', 'RIV-S');
        $this->stock($rivalOutlet, $rivalVariant, '5', $rival->user);

        $second = Business::factory()->for($this->user)->create(['name' => 'Second Shop']);
        $siblingVariant = $this->variantFor($second, 'Green Tea', 'Cup', 'TEA-C');

        $this->stock($this->outlet, $this->variant, '10');

        $page = $this->stockPage();

        foreach ([$rivalVariant, $siblingVariant] as $variant) {
            $this->adjust($page, ['variant_id' => $variant->id, 'quantity' => '3'])
                ->assertHasActionErrors(['variant_id']);

            $page->unmountAction();
        }

        $this->assertSame('5.000', $this->balance($rivalOutlet, $rivalVariant));
        $this->assertNull($this->balance($this->outlet, $siblingVariant));
        $this->assertSame('10.000', $this->balance($this->outlet, $this->variant));
        $this->assertSame(2, StockMovement::query()->count());
    }

    public function test_the_variant_is_scoped_to_the_selected_outlets_business(): void
    {
        $second = Business::factory()->for($this->user)->create(['name' => 'Second Shop']);
        $secondOutlet = Outlet::factory()->for($second)->create(['name' => 'Second Counter']);
        $tea = $this->variantFor($second, 'Green Tea', 'Cup', 'TEA-C');

        $page = $this->stockPage();

        // The tea belongs to the user, but not to the business of the selected outlet.
        $this->adjust($page, ['variant_id' => $tea->id])->assertHasActionErrors(['variant_id']);
        $this->assertNull($this->balance($this->outlet, $tea));
        $this->assertNull($this->balance($secondOutlet, $tea));

        // Close the action halted by the previous validation error.
        $page->unmountAction();

        // With the second business's outlet selected, the same variant is valid.
        $page->set('outletId', $secondOutlet->id);

        $this->adjust($page, ['variant_id' => $tea->id, 'quantity' => '4'])
            ->assertNotified('Stock adjusted');

        $this->assertSame('4.000', $this->balance($secondOutlet, $tea));
        $this->assertNull($this->balance($this->outlet, $tea));
    }

    #[DataProvider('invalidVariantIds')]
    public function test_a_forged_or_malformed_variant_id_is_rejected(mixed $variantId): void
    {
        $this->adjust($this->stockPage(), ['variant_id' => $variantId])
            ->assertHasActionErrors(['variant_id']);

        $this->assertDatabaseCount('stock_levels', 0);
        $this->assertDatabaseCount('stock_movements', 0);
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

        $this->adjust($page, ['variant_id' => $staleId])
            ->assertHasActionErrors(['variant_id']);

        $this->assertDatabaseCount('stock_levels', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    // Failure handling

    public function test_an_unexpected_failure_shows_only_a_generic_message(): void
    {
        $this->stock($this->outlet, $this->variant, '10');

        $this->app->bind(StockService::class, fn () => new class extends StockService
        {
            public function adjust(
                User $actor,
                Outlet $outlet,
                ProductVariant $variant,
                string|int|float $quantityDelta,
                ?string $note = null,
            ): StockMovement {
                throw new RuntimeException('secret database detail');
            }
        });

        $this->adjust($this->stockPage(), ['quantity' => '5'])
            ->assertNotified(StockFailureMessage::FALLBACK)
            ->assertDontSee('secret database detail');

        $this->assertSame('10.000', $this->balance($this->outlet, $this->variant));
        $this->assertSame(1, StockMovement::query()->count());
    }
}
