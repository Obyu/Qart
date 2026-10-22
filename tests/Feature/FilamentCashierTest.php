<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Filament\Pages\Cashier;
use App\Models\Business;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\OrderService;
use App\Services\StockService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Cashier v0 (Filament page).
 *
 * All writes go through OrderService and StockService, whose own behaviour is
 * covered elsewhere. These tests cover what the page adds: scoping of every
 * client-supplied ID, feedback messages, rendered totals, and that the service
 * protections (no double sale, no partial stock deduction) stay in force when
 * driven from the page.
 */
class FilamentCashierTest extends TestCase
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

        $this->actingAs($this->user);
    }

    /**
     * The Cashier page with a business and an outlet selected, as the browser would.
     */
    private function cashier(?Business $business = null, ?Outlet $outlet = null)
    {
        return Livewire::test(Cashier::class)
            ->set('businessId', ($business ?? $this->business)->id)
            ->set('outletId', ($outlet ?? $this->outlet)->id);
    }

    private function variantFor(Business $business, string $product = 'Latte', string $name = 'Large', string $price = '10.00'): ProductVariant
    {
        $category = Category::factory()->for($business)->create();
        $productModel = Product::factory()->for($category)->create(['name' => $product]);

        return ProductVariant::factory()->for($productModel)->create(['name' => $name, 'price' => $price]);
    }

    private function stock(Outlet $outlet, ProductVariant $variant, string $quantity, ?User $actor = null): void
    {
        (new StockService)->recordOpening($actor ?? $this->user, $outlet, $variant, $quantity);
    }

    private function balance(Outlet $outlet, ProductVariant $variant): string
    {
        return StockLevel::query()
            ->where('outlet_id', $outlet->id)
            ->where('product_variant_id', $variant->id)
            ->firstOrFail()
            ->quantity;
    }

    // Access and ownership

    public function test_an_authenticated_user_can_open_the_cashier(): void
    {
        Livewire::test(Cashier::class)
            ->assertStatus(200)
            ->assertSee('Sale setup')
            ->assertSee('Kopi Qart');
    }

    public function test_a_user_without_a_business_sees_guidance_and_nobody_elses_data(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Cashier::class)
            ->assertStatus(200)
            ->assertSee('You do not own a business yet.')
            ->assertDontSee('Kopi Qart');
    }

    public function test_only_own_businesses_and_outlets_are_offered(): void
    {
        $rival = Business::factory()->create(['name' => 'Rival Bakery']);
        Outlet::factory()->for($rival)->create(['name' => 'Rival Counter']);
        $second = Business::factory()->for($this->user)->create(['name' => 'Second Shop']);
        Outlet::factory()->for($second)->create(['name' => 'Second Counter']);

        Livewire::test(Cashier::class)
            ->assertSee('Kopi Qart')
            ->assertSee('Second Shop')
            ->assertDontSee('Rival Bakery')
            ->set('businessId', $this->business->id)
            ->assertSee('Main Counter')
            ->assertDontSee('Second Counter')
            ->assertDontSee('Rival Counter');
    }

    public function test_a_foreign_business_id_is_ignored(): void
    {
        $foreign = Business::factory()->create(['name' => 'Rival Bakery']);
        $foreignOutlet = Outlet::factory()->for($foreign)->create(['name' => 'Rival Counter']);
        $foreignVariant = $this->variantFor($foreign, 'Rival Cake', 'Slice');

        Livewire::test(Cashier::class)
            ->set('businessId', $foreign->id)
            ->set('outletId', $foreignOutlet->id)
            ->set('variantId', $foreignVariant->id)
            ->assertDontSee('Rival Counter')
            ->assertDontSee('Rival Cake')
            ->call('addItem')
            ->assertSee('Select one of your businesses and an outlet first.');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_an_outlet_that_does_not_belong_to_the_selected_business_is_ignored(): void
    {
        $foreignOutlet = Outlet::factory()->create(['name' => 'Rival Counter']);
        $second = Business::factory()->for($this->user)->create();
        $siblingOutlet = Outlet::factory()->for($second)->create(['name' => 'Second Counter']);
        $variant = $this->variantFor($this->business);

        $component = Livewire::test(Cashier::class)
            ->set('businessId', $this->business->id)
            ->set('variantId', $variant->id);

        foreach ([$foreignOutlet, $siblingOutlet] as $outlet) {
            $component
                ->set('outletId', $outlet->id)
                ->call('addItem')
                ->assertSee('Select one of your businesses and an outlet first.');
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_a_foreign_or_sibling_variant_id_is_rejected(): void
    {
        $rivalBusiness = Business::factory()->create();
        $rivalVariant = $this->variantFor($rivalBusiness, 'Rival Cake', 'Slice');
        $second = Business::factory()->for($this->user)->create();
        $siblingVariant = $this->variantFor($second, 'Tea', 'Cup');

        $component = $this->cashier()->assertDontSee('Rival Cake');

        foreach ([$rivalVariant, $siblingVariant] as $variant) {
            $component
                ->set('variantId', $variant->id)
                ->set('quantity', '1')
                ->call('addItem')
                ->assertSee('Select a product variant from the list.');
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_a_foreign_order_id_is_ignored(): void
    {
        $foreignOwner = User::factory()->create();
        $foreignBusiness = Business::factory()->for($foreignOwner)->create();
        $foreignOutlet = Outlet::factory()->for($foreignBusiness)->create();
        $foreignVariant = $this->variantFor($foreignBusiness, 'Rival Cake', 'Slice');
        $this->stock($foreignOutlet, $foreignVariant, '10', $foreignOwner);

        $service = new OrderService;
        $foreignOrder = $service->createOrder($foreignOwner, $foreignBusiness, $foreignOutlet);
        $service->addItem($foreignOwner, $foreignOrder, $foreignVariant, '2');

        // Checkout of someone else's order is a no-op.
        $this->cashier()
            ->set('orderId', $foreignOrder->id)
            ->call('checkout')
            ->assertSee('There is no sale in progress.')
            ->assertDontSee('Rival Cake');

        $this->assertSame(OrderStatus::Open, $foreignOrder->fresh()->status);
        $this->assertSame('10.000', $this->balance($foreignOutlet, $foreignVariant));

        // Adding with a forged order ID never touches the foreign order: the
        // forged ID is dropped and a new order of our own business is created.
        $own = $this->variantFor($this->business);

        $this->cashier()
            ->set('orderId', $foreignOrder->id)
            ->set('variantId', $own->id)
            ->set('quantity', '1')
            ->call('addItem');

        $this->assertSame(1, OrderItem::query()->where('order_id', $foreignOrder->id)->count());
        $this->assertSame(2, OrderItem::query()->where('order_id', $foreignOrder->id)->sole()->quantity);
        $this->assertSame(1, Order::query()->where('business_id', $this->business->id)->count());
    }

    public function test_an_order_of_another_outlet_is_ignored(): void
    {
        $otherOutlet = Outlet::factory()->for($this->business)->create(['name' => 'Back Counter']);
        $variant = $this->variantFor($this->business);
        $this->stock($otherOutlet, $variant, '10');

        $service = new OrderService;
        $order = $service->createOrder($this->user, $this->business, $otherOutlet);
        $service->addItem($this->user, $order, $variant, '2');

        $this->cashier()
            ->set('orderId', $order->id)
            ->call('checkout')
            ->assertSee('There is no sale in progress.');

        $this->assertSame(OrderStatus::Open, $order->fresh()->status);
        $this->assertSame('10.000', $this->balance($otherOutlet, $variant));
    }

    public function test_switching_the_outlet_clears_the_current_sale(): void
    {
        $variant = $this->variantFor($this->business);
        $otherOutlet = Outlet::factory()->for($this->business)->create(['name' => 'Back Counter']);

        $this->cashier()
            ->set('variantId', $variant->id)
            ->call('addItem')
            ->assertNotSet('orderId', null)
            ->set('outletId', $otherOutlet->id)
            ->assertSet('orderId', null);
    }

    // Adding items

    public function test_rendering_never_creates_an_order(): void
    {
        $this->variantFor($this->business);

        $this->cashier()
            ->set('quantity', '2')
            ->assertSee('No sale in progress.');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_a_valid_variant_is_added_to_a_new_open_order(): void
    {
        $variant = $this->variantFor($this->business, 'Latte', 'Large', '10.00');

        $this->cashier()
            ->set('variantId', $variant->id)
            ->set('quantity', '2')
            ->call('addItem')
            ->assertSee('Added 2 x Latte - Large.')
            ->assertSet('variantId', null)
            ->assertSet('quantity', '1');

        $order = Order::query()->sole();
        $this->assertSame(OrderStatus::Open, $order->status);
        $this->assertEquals($this->business->id, $order->business_id);
        $this->assertEquals($this->outlet->id, $order->outlet_id);

        $item = OrderItem::query()->sole();
        $this->assertEquals($order->id, $item->order_id);
        $this->assertEquals($variant->id, $item->product_variant_id);
        $this->assertSame('Latte', $item->product_name);
        $this->assertSame('Large', $item->variant_name);
        $this->assertSame('10.00', $item->unit_price);
        $this->assertSame(2, $item->quantity);
    }

    public function test_adding_again_reuses_the_same_order(): void
    {
        $variant = $this->variantFor($this->business);

        $component = $this->cashier();
        $component->set('variantId', $variant->id)->set('quantity', '2')->call('addItem');
        $component->set('variantId', $variant->id)->set('quantity', '3')->call('addItem');

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(5, OrderItem::query()->sole()->quantity);
    }

    #[DataProvider('invalidQuantities')]
    public function test_an_invalid_quantity_is_rejected_before_anything_is_written(string $quantity): void
    {
        $variant = $this->variantFor($this->business);

        $this->cashier()
            ->set('variantId', $variant->id)
            ->set('quantity', $quantity)
            ->call('addItem')
            ->assertSee('Enter a whole-number quantity of at least 1.');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidQuantities(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-1'],
            'decimal' => ['1.5'],
            'text' => ['abc'],
            'empty' => [''],
            'spaces' => ['  '],
            'exponent notation' => ['1e3'],
            'leading zero' => ['01'],
        ];
    }

    public function test_an_oversized_quantity_is_rejected_by_the_service_and_a_retry_works(): void
    {
        $variant = $this->variantFor($this->business);

        $component = $this->cashier()
            ->set('variantId', $variant->id)
            ->set('quantity', '1000001')
            ->call('addItem')
            ->assertSee('That quantity is too large.');

        $this->assertDatabaseCount('order_items', 0);

        $component
            ->set('variantId', $variant->id)
            ->set('quantity', '1')
            ->call('addItem')
            ->assertSee('Added 1 x');

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
    }

    // Totals

    public function test_totals_are_calculated_from_the_persisted_price_snapshots(): void
    {
        $latte = $this->variantFor($this->business, 'Latte', 'Large', '3.30');
        $cake = $this->variantFor($this->business, 'Cake', 'Slice', '1250.25');

        $component = $this->cashier();
        $component->set('variantId', $latte->id)->set('quantity', '3')->call('addItem');
        $component->set('variantId', $cake->id)->set('quantity', '2')->call('addItem')
            ->assertSee('3.30')
            ->assertSee('9.90')
            ->assertSee('1,250.25')
            ->assertSee('2,500.50')
            ->assertSee('2,510.40');

        // A price change after the sale was started must not change the order:
        // any round trip re-renders from the persisted snapshots.
        $latte->update(['price' => '99.00']);

        $component->set('quantity', '1')
            ->assertSee('2,510.40')
            ->assertDontSee('2,797.50');
    }

    // Checkout

    public function test_checkout_completes_the_sale_and_deducts_stock_through_the_service(): void
    {
        $variant = $this->variantFor($this->business, 'Latte', 'Large', '3.30');
        $this->stock($this->outlet, $variant, '10');

        $this->cashier()
            ->set('variantId', $variant->id)
            ->set('quantity', '3')
            ->call('addItem')
            ->call('checkout')
            ->assertSee('Sale completed. Total 9.90. Stock has been deducted.')
            ->assertSee('Completed')
            ->assertSee('New sale')
            ->assertDontSee('Add to sale')
            ->assertDontSee('Complete sale');

        $order = Order::query()->sole();
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertNotNull($order->completed_at);

        $this->assertSame('7.000', $this->balance($this->outlet, $variant));
        // The opening movement plus one sale movement for the single item.
        $this->assertSame(2, StockMovement::query()->count());
    }

    public function test_insufficient_stock_neither_completes_the_sale_nor_deducts_anything(): void
    {
        $plenty = $this->variantFor($this->business, 'Latte', 'Large');
        $scarce = $this->variantFor($this->business, 'Cake', 'Slice');
        $this->stock($this->outlet, $plenty, '10');
        $this->stock($this->outlet, $scarce, '1');

        $component = $this->cashier();
        $component->set('variantId', $plenty->id)->set('quantity', '2')->call('addItem');
        $component->set('variantId', $scarce->id)->set('quantity', '5')->call('addItem');

        $component->call('checkout')
            ->assertSee('Insufficient stock for Cake - Slice')
            ->assertSee('no stock was deducted')
            ->assertSee('Complete sale');

        $order = Order::query()->sole();
        $this->assertSame(OrderStatus::Open, $order->status);
        $this->assertNull($order->completed_at);

        $this->assertSame('10.000', $this->balance($this->outlet, $plenty));
        $this->assertSame('1.000', $this->balance($this->outlet, $scarce));
        // Only the two opening movements: no sale movement was written.
        $this->assertSame(2, StockMovement::query()->count());
    }

    public function test_checkout_without_a_sale_in_progress_is_rejected(): void
    {
        $this->cashier()
            ->call('checkout')
            ->assertSee('There is no sale in progress.');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_of_an_empty_order_is_rejected(): void
    {
        $variant = $this->variantFor($this->business);
        $this->stock($this->outlet, $variant, '10');
        $order = (new OrderService)->createOrder($this->user, $this->business, $this->outlet);

        // The button is disabled for an empty order, but the server must not rely on it.
        $this->cashier()
            ->set('orderId', $order->id)
            ->call('checkout')
            ->assertSee('The order is empty.');

        $this->assertSame(OrderStatus::Open, $order->fresh()->status);
        $this->assertSame('10.000', $this->balance($this->outlet, $variant));
        $this->assertSame(1, StockMovement::query()->count());
    }

    public function test_a_completed_order_is_not_sold_twice_and_cannot_be_edited(): void
    {
        $variant = $this->variantFor($this->business);
        $this->stock($this->outlet, $variant, '10');

        $component = $this->cashier();
        $component->set('variantId', $variant->id)->set('quantity', '3')->call('addItem');
        $component->call('checkout')->assertSee('Sale completed.');

        $component->call('checkout')->assertSee('already completed');

        $component
            ->set('variantId', $variant->id)
            ->set('quantity', '1')
            ->call('addItem')
            ->assertSee('already completed');

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(3, OrderItem::query()->sole()->quantity);
        $this->assertSame('7.000', $this->balance($this->outlet, $variant));
        $this->assertSame(2, StockMovement::query()->count());
    }

    public function test_a_new_sale_after_checkout_starts_a_fresh_order(): void
    {
        $variant = $this->variantFor($this->business);
        $this->stock($this->outlet, $variant, '10');

        $component = $this->cashier();
        $component->set('variantId', $variant->id)->call('addItem');
        $component->call('checkout')
            ->call('newSale')
            ->assertSet('orderId', null)
            ->assertSee('No sale in progress.');

        $component->set('variantId', $variant->id)->call('addItem');

        $this->assertDatabaseCount('orders', 2);
        $this->assertSame(1, Order::query()->where('status', OrderStatus::Open->value)->count());
        $this->assertSame('9.000', $this->balance($this->outlet, $variant));
    }
}
