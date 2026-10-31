<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Enums\StockMovementType;
use App\Exceptions\StockException;
use App\Filament\Pages\StockManagement;
use App\Filament\Support\StockQuantity;
use App\Models\Business;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StaffService;
use App\Services\StockService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What a business admin can DO on the Stock page (opening the page is covered by
 * BusinessAdminStockAccessTest): adjust stock and record opening stock in their
 * own business, and nowhere else. "sibling" is another business of the SAME
 * owner, "foreign" a business of a different owner.
 *
 * Refusals are asserted twice: through the page (form validation, disabled
 * actions) and directly on StockService, which stays the final authority. The
 * service tests compare the exact refusal reason, so another StockException
 * (for example a duplicate opening) cannot pass for an authorization failure.
 * Every refusal sits next to a positive control.
 *
 * Cashier and kitchen are checked on the service only. Their HTTP access to the
 * page is covered by BusinessAdminStockAccessTest and RoleAccessTest.
 */
class BusinessAdminStockActionTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'demo-password-123';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        // Filament only skips the FilamentUser check when app.env is "local".
        config(['app.env' => 'production']);
    }

    /**
     * @return array<string, mixed>
     */
    private function world(): array
    {
        $owner = User::factory()->create(['password' => self::PASSWORD]);
        $foreignOwner = User::factory()->create();

        $own = Business::factory()->for($owner)->create(['name' => 'Own Shop']);
        $sibling = Business::factory()->for($owner)->create(['name' => 'Sibling Shop']);
        $foreign = Business::factory()->for($foreignOwner)->create(['name' => 'Foreign Shop']);

        $ownOutlet = Outlet::factory()->for($own)->create(['name' => 'Own Counter']);
        $siblingOutlet = Outlet::factory()->for($sibling)->create(['name' => 'Sibling Counter']);
        $foreignOutlet = Outlet::factory()->for($foreign)->create(['name' => 'Foreign Counter']);

        $ownVariant = $this->variantFor($own, 'Own Latte', 'Large', 'OWN-L');
        $ownFreshVariant = $this->variantFor($own, 'Own Mocha', 'Small', 'OWN-M');
        $siblingVariant = $this->variantFor($sibling, 'Sibling Tea', 'Cup', 'SIB-C');
        $foreignVariant = $this->variantFor($foreign, 'Foreign Cake', 'Slice', 'FOR-S');

        $this->stock($ownOutlet, $ownVariant, '2.5', $owner);
        $this->stock($siblingOutlet, $siblingVariant, '7', $owner);
        $this->stock($foreignOutlet, $foreignVariant, '9', $foreignOwner);

        $staff = app(StaffService::class);

        $make = fn (string $email, StaffRole $role, ?Outlet $outlet): User => $staff->create($owner, [
            'name' => ucfirst($role->value),
            'email' => $email,
            'password' => self::PASSWORD,
            'business_id' => $own->id,
            'role' => $role->value,
            'outlet_id' => $outlet?->id,
        ]);

        return [
            'owner' => $owner,
            'own' => $own,
            'ownOutlet' => $ownOutlet,
            'siblingOutlet' => $siblingOutlet,
            'foreignOutlet' => $foreignOutlet,
            'ownVariant' => $ownVariant,
            'ownFreshVariant' => $ownFreshVariant,
            'siblingVariant' => $siblingVariant,
            'foreignVariant' => $foreignVariant,
            'admin' => $make('admin@example.test', StaffRole::BusinessAdmin, null),
            'cashier' => $make('cashier@example.test', StaffRole::Cashier, $ownOutlet),
            'kitchen' => $make('kitchen@example.test', StaffRole::Kitchen, $ownOutlet),
        ];
    }

    private function variantFor(Business $business, string $product, string $name, string $sku): ProductVariant
    {
        $category = Category::factory()->for($business)->create();
        $productModel = Product::factory()->for($category)->create(['name' => $product]);

        return ProductVariant::factory()->for($productModel)->create([
            'name' => $name,
            'price' => '10.00',
            'sku' => $sku,
        ]);
    }

    private function stock(Outlet $outlet, ProductVariant $variant, string $quantity, User $actor): void
    {
        (new StockService)->recordOpening($actor, $outlet, $variant, $quantity);
    }

    private function balance(Outlet $outlet, ProductVariant $variant): ?string
    {
        return StockLevel::query()
            ->where('outlet_id', $outlet->id)
            ->where('product_variant_id', $variant->id)
            ->first()
            ?->quantity;
    }

    private function pageAs(User $user)
    {
        // AuthenticateSession would redirect the second user of a test.
        $this->flushSession();
        $this->actingAs($user);

        return Livewire::test(StockManagement::class);
    }

    private function adjust($component, ProductVariant $variant, string $direction, string $quantity)
    {
        return $component->callAction('adjustStock', data: [
            'variant_id' => $variant->id,
            'direction' => $direction,
            'quantity' => $quantity,
            'note' => 'Cycle count',
        ]);
    }

    private function open($component, ProductVariant $variant, string $quantity)
    {
        return $component->callAction('recordOpeningStock', data: [
            'variant_id' => $variant->id,
            'quantity' => $quantity,
            'note' => 'Initial count',
        ]);
    }

    /**
     * The attempt must be refused by StockService for lack of authorization.
     */
    private function assertRefused(callable $attempt, string $label): void
    {
        try {
            $attempt();
        } catch (StockException $e) {
            $this->assertSame(StockException::unauthorized()->reason, $e->reason, $label);

            return;
        }

        $this->fail("{$label}: StockService should have refused");
    }

    public function test_business_admin_can_add_stock_to_a_variant_of_their_business(): void
    {
        $world = $this->world();

        $this->adjust($this->pageAs($world['admin']), $world['ownVariant'], StockQuantity::DIRECTION_ADD, '5.25')
            ->assertHasNoActionErrors()
            ->assertNotified('Stock adjusted');

        $this->assertSame('7.750', $this->balance($world['ownOutlet'], $world['ownVariant']));

        $movement = StockMovement::query()->where('type', StockMovementType::Adjustment->value)->sole();
        $this->assertEquals($world['admin']->id, $movement->user_id);
        $this->assertEquals($world['ownOutlet']->id, $movement->outlet_id);
        $this->assertEquals($world['ownVariant']->id, $movement->product_variant_id);
        $this->assertSame('5.250', $movement->quantity_delta);
        $this->assertSame('7.750', $movement->quantity_after);

        $this->assertSame('7.000', $this->balance($world['siblingOutlet'], $world['siblingVariant']));
        $this->assertSame('9.000', $this->balance($world['foreignOutlet'], $world['foreignVariant']));
    }

    public function test_business_admin_can_remove_stock_from_a_variant_of_their_business(): void
    {
        $world = $this->world();

        $this->adjust($this->pageAs($world['admin']), $world['ownVariant'], StockQuantity::DIRECTION_REMOVE, '1')
            ->assertHasNoActionErrors()
            ->assertNotified('Stock adjusted');

        $this->assertSame('1.500', $this->balance($world['ownOutlet'], $world['ownVariant']));

        $movement = StockMovement::query()->where('type', StockMovementType::Adjustment->value)->sole();
        $this->assertEquals($world['admin']->id, $movement->user_id);
        $this->assertSame('-1.000', $movement->quantity_delta);
        $this->assertSame('1.500', $movement->quantity_after);

        $this->assertSame('7.000', $this->balance($world['siblingOutlet'], $world['siblingVariant']));
        $this->assertSame('9.000', $this->balance($world['foreignOutlet'], $world['foreignVariant']));
    }

    public function test_business_admin_can_record_opening_stock_for_a_variant_of_their_business(): void
    {
        $world = $this->world();

        $this->assertNull($this->balance($world['ownOutlet'], $world['ownFreshVariant']));

        $this->open($this->pageAs($world['admin']), $world['ownFreshVariant'], '4')
            ->assertHasNoActionErrors()
            ->assertNotified('Opening stock recorded');

        $this->assertSame('4.000', $this->balance($world['ownOutlet'], $world['ownFreshVariant']));

        $movement = StockMovement::query()
            ->where('product_variant_id', $world['ownFreshVariant']->id)
            ->where('type', StockMovementType::Opening->value)
            ->sole();
        $this->assertEquals($world['admin']->id, $movement->user_id);
        $this->assertEquals($world['ownOutlet']->id, $movement->outlet_id);
        $this->assertSame('4.000', $movement->quantity_delta);
        $this->assertSame('4.000', $movement->quantity_after);

        $this->assertSame('7.000', $this->balance($world['siblingOutlet'], $world['siblingVariant']));
        $this->assertSame('9.000', $this->balance($world['foreignOutlet'], $world['foreignVariant']));
    }

    public function test_business_admin_cannot_use_a_variant_of_another_business_through_the_actions(): void
    {
        $world = $this->world();

        $levels = StockLevel::query()->count();
        $movements = StockMovement::query()->count();

        $page = $this->pageAs($world['admin']);

        foreach (['siblingVariant', 'foreignVariant'] as $key) {
            $this->adjust($page, $world[$key], StockQuantity::DIRECTION_ADD, '3')
                ->assertHasActionErrors(['variant_id']);
            $page->unmountAction();

            $this->open($page, $world[$key], '3')
                ->assertHasActionErrors(['variant_id']);
            $page->unmountAction();
        }

        $this->assertSame('7.000', $this->balance($world['siblingOutlet'], $world['siblingVariant']));
        $this->assertSame('9.000', $this->balance($world['foreignOutlet'], $world['foreignVariant']));
        $this->assertNull($this->balance($world['ownOutlet'], $world['siblingVariant']));
        $this->assertNull($this->balance($world['ownOutlet'], $world['foreignVariant']));
        $this->assertSame($levels, StockLevel::query()->count());
        $this->assertSame($movements, StockMovement::query()->count());

        // Positive control: the same page and action accept the own variant.
        $this->adjust($page, $world['ownVariant'], StockQuantity::DIRECTION_ADD, '1')
            ->assertNotified('Stock adjusted');

        $this->assertSame('3.500', $this->balance($world['ownOutlet'], $world['ownVariant']));
    }

    public function test_the_actions_stay_disabled_for_an_outlet_that_is_not_the_admins(): void
    {
        $world = $this->world();
        Outlet::factory()->for($world['own'])->create(['name' => 'Back Counter']);

        $this->pageAs($world['admin'])
            ->assertSet('outletId', null)
            ->assertActionDisabled('adjustStock')
            ->assertActionDisabled('recordOpeningStock')
            ->set('outletId', $world['siblingOutlet']->id)
            ->assertSet('outletId', null)
            ->assertActionDisabled('adjustStock')
            ->assertActionDisabled('recordOpeningStock')
            ->set('outletId', $world['foreignOutlet']->id)
            ->assertSet('outletId', null)
            ->assertActionDisabled('adjustStock')
            ->assertActionDisabled('recordOpeningStock')
            ->set('outletId', $world['ownOutlet']->id)
            ->assertSet('outletId', $world['ownOutlet']->id)
            ->assertActionEnabled('adjustStock')
            ->assertActionEnabled('recordOpeningStock');
    }

    public function test_the_stock_service_refuses_a_business_admin_outside_their_business(): void
    {
        $world = $this->world();
        $admin = $world['admin'];
        $service = new StockService;

        $levels = StockLevel::query()->count();
        $movements = StockMovement::query()->count();

        foreach (['sibling', 'foreign'] as $key) {
            $outlet = $world[$key.'Outlet'];
            $variant = $world[$key.'Variant'];

            $this->assertRefused(
                fn () => $service->adjust($admin, $outlet, $variant, '1', 'Sneaky'),
                "adjust in the {$key} outlet",
            );
            $this->assertRefused(
                fn () => $service->recordOpening($admin, $outlet, $variant, '1', 'Sneaky'),
                "opening in the {$key} outlet",
            );
        }

        $this->assertSame('7.000', $this->balance($world['siblingOutlet'], $world['siblingVariant']));
        $this->assertSame('9.000', $this->balance($world['foreignOutlet'], $world['foreignVariant']));
        $this->assertSame($levels, StockLevel::query()->count());
        $this->assertSame($movements, StockMovement::query()->count());

        // Positive control: the same admin and service work in the own outlet.
        $service->adjust($admin, $world['ownOutlet'], $world['ownVariant'], '1', 'Allowed');

        $this->assertSame('3.500', $this->balance($world['ownOutlet'], $world['ownVariant']));
    }

    public function test_the_stock_service_refuses_cashier_and_kitchen_even_in_their_own_outlet(): void
    {
        $world = $this->world();
        $service = new StockService;

        $levels = StockLevel::query()->count();
        $movements = StockMovement::query()->count();

        foreach (['cashier', 'kitchen'] as $role) {
            $actor = $world[$role];

            $this->assertRefused(
                fn () => $service->adjust($actor, $world['ownOutlet'], $world['ownVariant'], '1', 'Sneaky'),
                "{$role} adjusting stock",
            );
            $this->assertRefused(
                fn () => $service->recordOpening($actor, $world['ownOutlet'], $world['ownFreshVariant'], '1', 'Sneaky'),
                "{$role} recording opening stock",
            );
        }

        $this->assertSame('2.500', $this->balance($world['ownOutlet'], $world['ownVariant']));
        $this->assertNull($this->balance($world['ownOutlet'], $world['ownFreshVariant']));
        $this->assertSame($levels, StockLevel::query()->count());
        $this->assertSame($movements, StockMovement::query()->count());

        // Positive control: a business admin may use the very same outlet and variant.
        $service->adjust($world['admin'], $world['ownOutlet'], $world['ownVariant'], '1', 'Allowed');

        $this->assertSame('3.500', $this->balance($world['ownOutlet'], $world['ownVariant']));
    }
}
