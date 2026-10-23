<?php

namespace Tests\Feature;

use App\Filament\Pages\StockManagement;
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
use Tests\TestCase;

/**
 * Stock Management v1, Stage 2A (Filament page, read-only table).
 *
 * The page only reads. What these tests cover is what the page adds: scoping of
 * the client-supplied outlet ID, which variants are listed, how balances are
 * shown (including variants without a stock level), search and pagination, and
 * that rendering never writes anything.
 */
class FilamentStockManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Business $business;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $this->user = User::factory()->create();
        $this->business = Business::factory()->for($this->user)->create(['name' => 'Kopi Qart']);
        $this->outlet = Outlet::factory()->for($this->business)->create(['name' => 'Main Counter']);
    }

    /**
     * The Stock page as the authenticated owner, optionally with an outlet
     * selected the way the browser would.
     */
    private function stockPage(?Outlet $outlet = null)
    {
        $this->actingAs($this->user);

        $component = Livewire::test(StockManagement::class);

        return $outlet === null ? $component : $component->set('outletId', $outlet->id);
    }

    private function variantFor(Business $business, string $product = 'Latte', string $name = 'Large', ?string $sku = null): ProductVariant
    {
        $category = Category::factory()->for($business)->create();
        $productModel = Product::factory()->for($category)->create(['name' => $product]);

        $attributes = ['name' => $name, 'price' => '10.00'];

        if ($sku !== null) {
            $attributes['sku'] = $sku;
        }

        return ProductVariant::factory()->for($productModel)->create($attributes);
    }

    private function stock(Outlet $outlet, ProductVariant $variant, string $quantity, ?User $actor = null): void
    {
        (new StockService)->recordOpening($actor ?? $this->user, $outlet, $variant, $quantity);
    }

    // Access

    public function test_an_authenticated_user_can_open_the_stock_page(): void
    {
        $this->actingAs($this->user);

        $this->get('/admin/stock-management')->assertOk();

        $this->stockPage()
            ->assertStatus(200)
            ->assertSee('Kopi Qart - Main Counter');
    }

    public function test_a_guest_is_redirected_to_the_login_page(): void
    {
        $this->get('/admin/stock-management')->assertRedirectContains('/admin/login');
    }

    public function test_a_user_without_an_outlet_sees_guidance_and_nobody_elses_data(): void
    {
        $this->variantFor($this->business, 'Latte', 'Large', 'LAT-L');

        $this->actingAs(User::factory()->create());

        Livewire::test(StockManagement::class)
            ->assertStatus(200)
            ->assertSee('You do not own an outlet yet.')
            ->assertDontSee('Kopi Qart')
            ->assertDontSee('Main Counter')
            ->assertDontSee('LAT-L');
    }

    // Outlet scoping

    public function test_only_own_outlets_are_offered(): void
    {
        $second = Business::factory()->for($this->user)->create(['name' => 'Second Shop']);
        Outlet::factory()->for($second)->create(['name' => 'Second Counter']);

        $rival = Business::factory()->create(['name' => 'Rival Bakery']);
        Outlet::factory()->for($rival)->create(['name' => 'Rival Counter']);

        $this->stockPage()
            ->assertSee('Kopi Qart - Main Counter')
            ->assertSee('Second Shop - Second Counter')
            ->assertDontSee('Rival Bakery')
            ->assertDontSee('Rival Counter');
    }

    public function test_the_only_outlet_is_preselected_but_one_of_several_is_not(): void
    {
        $this->stockPage()->assertSet('outletId', $this->outlet->id);

        Outlet::factory()->for($this->business)->create(['name' => 'Back Counter']);

        $this->stockPage()
            ->assertSet('outletId', null)
            ->assertSee('Select an outlet to see its stock.')
            ->assertCountTableRecords(0);
    }

    public function test_another_users_outlet_cannot_be_selected_or_inspected(): void
    {
        $rival = Business::factory()->create(['name' => 'Rival Bakery']);
        $rivalOutlet = Outlet::factory()->for($rival)->create(['name' => 'Rival Counter']);
        $rivalVariant = $this->variantFor($rival, 'Rival Cake', 'Slice', 'RIV-S');
        $this->stock($rivalOutlet, $rivalVariant, '5', $rival->user);

        $this->variantFor($this->business, 'Latte', 'Large', 'LAT-L');

        $this->stockPage()
            ->set('outletId', $rivalOutlet->id)
            ->assertSet('outletId', null)
            ->assertCountTableRecords(0)
            ->assertDontSee('Rival Cake')
            ->assertDontSee('RIV-S')
            ->assertDontSee('Rival Counter')
            ->assertDontSee('5.000');
    }

    #[DataProvider('invalidOutletIds')]
    public function test_a_malformed_outlet_id_selects_nothing(string $outletId): void
    {
        $this->variantFor($this->business, 'Latte', 'Large', 'LAT-L');

        $this->stockPage()
            ->assertSet('outletId', $this->outlet->id)
            ->set('outletId', $outletId)
            ->assertSet('outletId', null)
            ->assertCountTableRecords(0)
            ->assertDontSee('LAT-L');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidOutletIds(): array
    {
        return [
            'empty' => [''],
            'zero' => ['0'],
            'negative' => ['-1'],
            'text' => ['abc'],
            'exponent notation' => ['1e3'],
            'leading zero' => ['01'],
            'sql fragment' => ['1 OR 1=1'],
            'too long' => ['99999999999999999999'],
        ];
    }

    public function test_ownership_is_checked_again_when_the_table_is_queried(): void
    {
        $variant = $this->variantFor($this->business, 'Latte', 'Large', 'LAT-L');
        $newOwner = User::factory()->create();

        $component = $this->stockPage($this->outlet)
            ->assertCanSeeTableRecords([$variant]);

        // The outlet stays selected in the browser, but is no longer the user's.
        DB::table('businesses')->where('id', $this->business->id)->update(['user_id' => $newOwner->id]);

        $component
            ->call('$refresh')
            ->assertCountTableRecords(0)
            ->assertDontSee('LAT-L');
    }

    // Which variants are listed

    public function test_only_variants_of_the_selected_outlets_business_are_listed(): void
    {
        $second = Business::factory()->for($this->user)->create(['name' => 'Second Shop']);
        $secondOutlet = Outlet::factory()->for($second)->create(['name' => 'Second Counter']);
        $rival = Business::factory()->create(['name' => 'Rival Bakery']);

        $latte = $this->variantFor($this->business, 'Latte', 'Large', 'LAT-L');
        $tea = $this->variantFor($second, 'Green Tea', 'Cup', 'TEA-C');
        $cake = $this->variantFor($rival, 'Rival Cake', 'Slice', 'RIV-S');

        $component = $this->stockPage($this->outlet)
            ->assertCountTableRecords(1)
            ->assertCanSeeTableRecords([$latte])
            ->assertCanNotSeeTableRecords([$tea, $cake])
            ->assertSee('Latte')
            ->assertDontSee('Green Tea')
            ->assertDontSee('Rival Cake');

        $component
            ->set('outletId', $secondOutlet->id)
            ->assertCountTableRecords(1)
            ->assertCanSeeTableRecords([$tea])
            ->assertCanNotSeeTableRecords([$latte, $cake])
            ->assertSee('Green Tea')
            ->assertDontSee('Latte')
            ->assertDontSee('Rival Cake');
    }

    // Balances

    public function test_a_variant_without_a_stock_level_is_listed_with_a_zero_balance(): void
    {
        $variant = $this->variantFor($this->business, 'Latte', 'Large', 'LAT-L');

        $this->stockPage($this->outlet)
            ->assertCanSeeTableRecords([$variant])
            ->assertSee('0.000');

        // Rendering never creates a stock level or a movement.
        $this->assertDatabaseCount('stock_levels', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_balances_are_shown_per_outlet_with_three_decimals(): void
    {
        $back = Outlet::factory()->for($this->business)->create(['name' => 'Back Counter']);
        $latte = $this->variantFor($this->business, 'Latte', 'Large', 'LAT-L');
        $cake = $this->variantFor($this->business, 'Cake', 'Slice', 'CAK-S');

        $this->stock($this->outlet, $latte, '12.5');
        $this->stock($back, $latte, '7');
        $this->stock($back, $cake, '3');

        $component = $this->stockPage($this->outlet)
            ->assertSeeInOrder(['Latte', 'Large', 'LAT-L', '12.500', 'Cake', 'Slice', 'CAK-S', '0.000'])
            ->assertDontSee('7.000')
            ->assertDontSee('3.000');

        $component
            ->set('outletId', $back->id)
            ->assertSeeInOrder(['Latte', 'Large', 'LAT-L', '7.000', 'Cake', 'Slice', 'CAK-S', '3.000'])
            ->assertDontSee('12.500')
            ->assertDontSee('0.000');
    }

    public function test_a_balance_created_by_an_adjustment_without_an_opening_is_shown(): void
    {
        $variant = $this->variantFor($this->business, 'Latte', 'Large', 'LAT-L');

        (new StockService)->adjust($this->user, $this->outlet, $variant, '4.25');

        $this->stockPage($this->outlet)
            ->assertSeeInOrder(['Latte', 'Large', 'LAT-L', '4.250']);
    }

    // Search and pagination

    public function test_the_table_can_be_searched_by_product_variant_and_sku_within_the_outlets_business(): void
    {
        $rival = Business::factory()->create();

        $latte = $this->variantFor($this->business, 'Latte', 'Large', 'LAT-L');
        $cake = $this->variantFor($this->business, 'Chocolate Cake', 'Slice', 'CAK-S');
        $rivalLatte = $this->variantFor($rival, 'Latte', 'Large', 'RIV-L');

        $component = $this->stockPage($this->outlet);

        foreach (['Latte', 'Large', 'LAT-L'] as $term) {
            $component
                ->searchTable($term)
                ->assertCountTableRecords(1)
                ->assertCanSeeTableRecords([$latte])
                ->assertCanNotSeeTableRecords([$cake, $rivalLatte]);
        }

        $component
            ->searchTable('Chocolate')
            ->assertCanSeeTableRecords([$cake])
            ->assertCanNotSeeTableRecords([$latte]);
    }

    public function test_the_table_is_paginated(): void
    {
        $category = Category::factory()->for($this->business)->create();
        $product = Product::factory()->for($category)->create(['name' => 'Bulk Item']);

        $variants = ProductVariant::factory()
            ->count(11)
            ->for($product)
            ->create(['price' => '1.00']);

        $component = $this->stockPage($this->outlet)
            ->set('tableRecordsPerPage', 10)
            ->assertCountTableRecords(11)
            ->assertCanSeeTableRecords($variants->take(10));

        $component
            ->call('gotoPage', 2, 'page')
            ->assertCanSeeTableRecords([$variants->last()]);
    }

    // Read-only

    public function test_the_page_never_writes_stock(): void
    {
        $back = Outlet::factory()->for($this->business)->create(['name' => 'Back Counter']);
        $stocked = $this->variantFor($this->business, 'Latte', 'Large', 'LAT-L');
        $this->variantFor($this->business, 'Cake', 'Slice', 'CAK-S');
        $this->stock($this->outlet, $stocked, '5');

        $levels = StockLevel::query()->count();
        $movements = StockMovement::query()->count();

        $this->stockPage($this->outlet)
            ->searchTable('Cake')
            ->set('outletId', $back->id)
            ->set('outletId', $this->outlet->id)
            ->call('$refresh');

        $this->assertSame($levels, StockLevel::query()->count());
        $this->assertSame($movements, StockMovement::query()->count());
        $this->assertSame('5.000', StockLevel::query()->sole()->quantity);
    }
}
