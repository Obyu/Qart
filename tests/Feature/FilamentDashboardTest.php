<?php

namespace Tests\Feature;

use App\Filament\Widgets\SalesOverview;
use App\Models\Business;
use App\Models\Category;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderService;
use App\Services\StockService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentDashboardTest extends TestCase
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
        $this->business = Business::factory()
            ->for($this->user)
            ->create(['name' => 'Kopi Qart']);
        $this->outlet = Outlet::factory()
            ->for($this->business)
            ->create(['name' => 'Main Counter']);

        $this->actingAs($this->user);
    }

    /**
     * Access the protected metrics method without exposing it as a public
     * Livewire action.
     */
    private function metrics(): array
    {
        return (fn () => $this->salesMetrics())->call(new SalesOverview);
    }

    private function product(Business $business, string $name): Product
    {
        $category = Category::factory()->for($business)->create();

        return Product::factory()
            ->for($category)
            ->create(['name' => $name]);
    }

    private function variant(
        Product $product,
        Outlet $outlet,
        User $owner,
        string $name = 'Regular',
        string $price = '10.00',
    ): ProductVariant {
        $variant = ProductVariant::factory()
            ->for($product)
            ->create(['name' => $name, 'price' => $price]);

        (new StockService)->recordOpening($owner, $outlet, $variant, '1000');

        return $variant;
    }

    /**
     * @param  list<array{ProductVariant, int}>  $lines
     */
    private function sale(
        User $owner,
        Business $business,
        Outlet $outlet,
        array $lines,
        bool $complete = true,
    ): Order {
        $service = new OrderService;
        $order = $service->createOrder($owner, $business, $outlet);

        foreach ($lines as [$variant, $quantity]) {
            $service->addItem($owner, $order, $variant, (string) $quantity);
        }

        return $complete
            ? $service->completeSale($owner, $order)
            : $order;
    }

    /**
     * @return array{User, Business, Outlet, ProductVariant}
     */
    private function rival(string $price = '777.00'): array
    {
        $owner = User::factory()->create();
        $business = Business::factory()
            ->for($owner)
            ->create(['name' => 'Rival Bakery']);
        $outlet = Outlet::factory()->for($business)->create();
        $variant = $this->variant(
            $this->product($business, 'Rival Cake'),
            $outlet,
            $owner,
            'Slice',
            $price,
        );

        return [$owner, $business, $outlet, $variant];
    }

    private function queryCount(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_the_widget_is_registered_on_the_admin_panel_dashboard(): void
    {
        $this->assertContains(SalesOverview::class, Filament::getWidgets());
    }

    public function test_the_dashboard_shows_the_figures_of_the_users_business(): void
    {
        $latte = $this->product($this->business, 'Latte');
        $large = $this->variant($latte, $this->outlet, $this->user, 'Large', '3.30');
        $cake = $this->product($this->business, 'Cake');
        $slice = $this->variant($cake, $this->outlet, $this->user, 'Slice', '1250.25');

        $this->sale($this->user, $this->business, $this->outlet, [[$large, 3], [$slice, 2]]);
        $this->sale($this->user, $this->business, $this->outlet, [[$large, 1]]);
        $this->sale($this->user, $this->business, $this->outlet, [[$large, 4]], complete: false);

        $this->assertSame([
            'revenue' => '2513.70',
            'completedOrders' => 2,
            'openOrders' => 1,
            'bestSeller' => ['id' => $latte->id, 'name' => 'Latte', 'quantity' => 4],
        ], $this->metrics());

        Livewire::test(SalesOverview::class)
            ->assertSee('Total revenue')
            ->assertSee('2,513.70')
            ->assertSee('Completed orders')
            ->assertSee('Best-selling product')
            ->assertSee('Latte')
            ->assertSee('4 sold')
            ->assertSee('Open orders');
    }

    public function test_every_business_of_the_user_is_included(): void
    {
        $second = Business::factory()
            ->for($this->user)
            ->create(['name' => 'Second Shop']);
        $secondOutlet = Outlet::factory()->for($second)->create();

        $first = $this->variant(
            $this->product($this->business, 'Latte'),
            $this->outlet,
            $this->user,
            'Large',
            '10.00',
        );
        $other = $this->variant(
            $this->product($second, 'Tea'),
            $secondOutlet,
            $this->user,
            'Cup',
            '5.50',
        );

        $this->sale($this->user, $this->business, $this->outlet, [[$first, 2]]);
        $this->sale($this->user, $second, $secondOutlet, [[$other, 3]]);
        $this->sale($this->user, $second, $secondOutlet, [[$other, 1]], complete: false);

        $metrics = $this->metrics();

        $this->assertSame('36.50', $metrics['revenue']);
        $this->assertSame(2, $metrics['completedOrders']);
        $this->assertSame(1, $metrics['openOrders']);
        $this->assertSame('Tea', $metrics['bestSeller']['name']);
        $this->assertSame(3, $metrics['bestSeller']['quantity']);
    }

    public function test_revenue_uses_the_price_snapshot_not_the_current_price(): void
    {
        $variant = $this->variant(
            $this->product($this->business, 'Latte'),
            $this->outlet,
            $this->user,
            'Large',
            '3.30',
        );

        $this->sale($this->user, $this->business, $this->outlet, [[$variant, 3]]);

        $variant->update(['price' => '99.00']);

        $this->assertSame('9.90', $this->metrics()['revenue']);
    }

    public function test_another_users_data_is_excluded_from_every_metric(): void
    {
        $latte = $this->product($this->business, 'Latte');
        $large = $this->variant($latte, $this->outlet, $this->user, 'Large', '10.00');

        $this->sale($this->user, $this->business, $this->outlet, [[$large, 2]]);
        $this->sale($this->user, $this->business, $this->outlet, [[$large, 1]], complete: false);

        [$rivalOwner, $rivalBusiness, $rivalOutlet, $rivalVariant] = $this->rival();
        $this->sale($rivalOwner, $rivalBusiness, $rivalOutlet, [[$rivalVariant, 50]]);
        $this->sale($rivalOwner, $rivalBusiness, $rivalOutlet, [[$rivalVariant, 7]]);
        $this->sale($rivalOwner, $rivalBusiness, $rivalOutlet, [[$rivalVariant, 1]], complete: false);
        $this->sale($rivalOwner, $rivalBusiness, $rivalOutlet, [[$rivalVariant, 1]], complete: false);

        $this->assertSame([
            'revenue' => '20.00',
            'completedOrders' => 1,
            'openOrders' => 1,
            'bestSeller' => ['id' => $latte->id, 'name' => 'Latte', 'quantity' => 2],
        ], $this->metrics());

        Livewire::test(SalesOverview::class)
            ->assertSee('Latte')
            ->assertDontSee('Rival Cake')
            ->assertDontSee('44,289.00')
            ->assertDontSee('38,850.00');
    }

    public function test_a_user_without_a_business_sees_empty_figures_and_nobody_elses_data(): void
    {
        [$rivalOwner, $rivalBusiness, $rivalOutlet, $rivalVariant] = $this->rival();

        $this->sale($rivalOwner, $rivalBusiness, $rivalOutlet, [[$rivalVariant, 5]]);
        $this->sale($rivalOwner, $rivalBusiness, $rivalOutlet, [[$rivalVariant, 2]], complete: false);

        $this->actingAs(User::factory()->create());

        $this->assertSame([
            'revenue' => '0.00',
            'completedOrders' => 0,
            'openOrders' => 0,
            'bestSeller' => null,
        ], $this->metrics());

        Livewire::test(SalesOverview::class)
            ->assertSee('No sales yet')
            ->assertDontSee('Rival Cake');
    }

    public function test_open_orders_do_not_contribute_to_revenue_or_the_completed_count(): void
    {
        $variant = $this->variant(
            $this->product($this->business, 'Latte'),
            $this->outlet,
            $this->user,
            'Large',
            '10.00',
        );

        $this->sale($this->user, $this->business, $this->outlet, [[$variant, 5]], complete: false);
        $this->sale($this->user, $this->business, $this->outlet, [[$variant, 2]], complete: false);
        $this->sale($this->user, $this->business, $this->outlet, [], complete: false);

        $this->assertSame([
            'revenue' => '0.00',
            'completedOrders' => 0,
            'openOrders' => 3,
            'bestSeller' => null,
        ], $this->metrics());
    }

    public function test_the_best_seller_is_the_product_with_the_most_units_across_variants_and_orders(): void
    {
        $latte = $this->product($this->business, 'Latte');
        $large = $this->variant($latte, $this->outlet, $this->user, 'Large', '4.00');
        $small = $this->variant($latte, $this->outlet, $this->user, 'Small', '3.00');
        $cake = $this->product($this->business, 'Cake');
        $slice = $this->variant($cake, $this->outlet, $this->user, 'Slice', '6.00');

        $this->sale($this->user, $this->business, $this->outlet, [[$large, 2], [$slice, 3]]);
        $this->sale($this->user, $this->business, $this->outlet, [[$large, 1], [$small, 2]]);
        $this->sale($this->user, $this->business, $this->outlet, [[$small, 2], [$slice, 2]]);

        $this->assertSame(
            ['id' => $latte->id, 'name' => 'Latte', 'quantity' => 7],
            $this->metrics()['bestSeller'],
        );
    }

    public function test_open_orders_do_not_count_towards_the_best_seller(): void
    {
        $latte = $this->product($this->business, 'Latte');
        $cake = $this->product($this->business, 'Cake');
        $latteVariant = $this->variant($latte, $this->outlet, $this->user);
        $cakeVariant = $this->variant($cake, $this->outlet, $this->user);

        $this->sale($this->user, $this->business, $this->outlet, [[$cakeVariant, 2]]);
        $this->sale($this->user, $this->business, $this->outlet, [[$latteVariant, 50]], complete: false);

        $this->assertSame(
            ['id' => $cake->id, 'name' => 'Cake', 'quantity' => 2],
            $this->metrics()['bestSeller'],
        );
    }

    public function test_a_tie_is_broken_alphabetically_by_product_name(): void
    {
        $latte = $this->product($this->business, 'Latte');
        $americano = $this->product($this->business, 'Americano');
        $latteVariant = $this->variant($latte, $this->outlet, $this->user);
        $americanoVariant = $this->variant($americano, $this->outlet, $this->user);

        $this->sale($this->user, $this->business, $this->outlet, [[$latteVariant, 2]]);
        $this->sale($this->user, $this->business, $this->outlet, [[$americanoVariant, 2]]);

        $this->assertSame(
            ['id' => $americano->id, 'name' => 'Americano', 'quantity' => 2],
            $this->metrics()['bestSeller'],
        );
    }

    public function test_a_tie_between_products_with_the_same_name_goes_to_the_lowest_id(): void
    {
        $first = $this->product($this->business, 'Tea');
        $second = $this->product($this->business, 'Tea');
        $firstVariant = $this->variant($first, $this->outlet, $this->user);
        $secondVariant = $this->variant($second, $this->outlet, $this->user);

        $this->sale($this->user, $this->business, $this->outlet, [[$secondVariant, 3]]);
        $this->sale($this->user, $this->business, $this->outlet, [[$firstVariant, 3]]);

        $this->assertSame(
            ['id' => $first->id, 'name' => 'Tea', 'quantity' => 3],
            $this->metrics()['bestSeller'],
        );
    }

    public function test_the_empty_state_is_safe(): void
    {
        $this->assertSame([
            'revenue' => '0.00',
            'completedOrders' => 0,
            'openOrders' => 0,
            'bestSeller' => null,
        ], $this->metrics());

        Livewire::test(SalesOverview::class)
            ->assertSee('Total revenue')
            ->assertSee('0.00')
            ->assertSee('No sales yet')
            ->assertSee('Open orders');
    }

    public function test_the_number_of_queries_does_not_depend_on_the_amount_of_data(): void
    {
        $variant = $this->variant(
            $this->product($this->business, 'Latte'),
            $this->outlet,
            $this->user,
            'Large',
            '3.30',
        );

        $this->sale($this->user, $this->business, $this->outlet, [[$variant, 1]]);

        $few = $this->queryCount(fn () => $this->metrics());

        for ($i = 0; $i < 5; $i++) {
            $this->sale($this->user, $this->business, $this->outlet, [[$variant, 2]]);
            $this->sale($this->user, $this->business, $this->outlet, [[$variant, 1]], complete: false);
        }

        $many = $this->queryCount(fn () => $this->metrics());

        $this->assertSame(3, $few);
        $this->assertSame($few, $many);
    }
}
