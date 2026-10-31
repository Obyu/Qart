<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Filament\Pages\StockManagement;
use App\Models\Business;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\StaffService;
use App\Services\StockService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A business admin opens the Stock page for their own business only, and nobody
 * else gains access. "sibling" is another business of the SAME owner, "foreign"
 * a business of a different owner: both must stay out of reach.
 *
 * Every "must not see" assertion sits next to a positive control (the admin does
 * see the own variant), so it cannot pass just because the page is closed.
 *
 * Adjusting and recording opening stock as a business admin is not covered here.
 */
class BusinessAdminStockAccessTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'demo-password-123';

    private const PATH = '/admin/stock-management';

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

    private function statusFor(User $user): int
    {
        // AuthenticateSession would redirect the second user of a test.
        $this->flushSession();

        return $this->actingAs($user)->get(self::PATH)->getStatusCode();
    }

    private function pageAs(User $user)
    {
        $this->flushSession();
        $this->actingAs($user);

        return Livewire::test(StockManagement::class);
    }

    public function test_the_stock_page_opens_for_owner_and_business_admin_only(): void
    {
        $world = $this->world();

        $expected = ['owner' => 200, 'admin' => 200, 'cashier' => 403, 'kitchen' => 403];

        foreach ($expected as $who => $status) {
            $this->assertSame($status, $this->statusFor($world[$who]), "{$who} on ".self::PATH);
        }
    }

    public function test_can_access_follows_the_manage_stock_ability(): void
    {
        $world = $this->world();
        $ownerWithoutBusiness = User::factory()->create();

        $cases = [
            'owner' => [$world['owner'], true],
            'owner without business' => [$ownerWithoutBusiness, true],
            'business admin' => [$world['admin'], true],
            'cashier' => [$world['cashier'], false],
            'kitchen' => [$world['kitchen'], false],
        ];

        foreach ($cases as $label => [$user, $expected]) {
            $this->flushSession();
            $this->actingAs($user);

            $this->assertSame($expected, StockManagement::canAccess(), $label);
        }
    }

    public function test_business_admin_sees_only_the_outlet_and_variants_of_their_business(): void
    {
        $world = $this->world();

        $this->pageAs($world['admin'])
            ->assertSet('outletId', $world['ownOutlet']->id)
            ->assertSee('Own Shop - Own Counter')
            ->assertCanSeeTableRecords([$world['ownVariant']])
            ->assertSee('2.500')
            ->assertCanNotSeeTableRecords([$world['siblingVariant'], $world['foreignVariant']])
            ->assertDontSee('Sibling Shop')
            ->assertDontSee('Sibling Counter')
            ->assertDontSee('Foreign Shop')
            ->assertDontSee('Foreign Counter')
            ->assertDontSee('Sibling Tea')
            ->assertDontSee('Foreign Cake')
            ->assertDontSee('7.000')
            ->assertDontSee('9.000');
    }

    public function test_business_admin_cannot_select_the_outlet_of_a_sibling_or_foreign_business(): void
    {
        $world = $this->world();

        $others = [
            'sibling' => ['Sibling Counter', 'SIB-C'],
            'foreign' => ['Foreign Counter', 'FOR-S'],
        ];

        foreach ($others as $key => [$outletName, $sku]) {
            $this->pageAs($world['admin'])
                ->assertSet('outletId', $world['ownOutlet']->id)
                ->assertCanSeeTableRecords([$world['ownVariant']])
                ->set('outletId', $world[$key.'Outlet']->id)
                ->assertSet('outletId', null)
                ->assertCountTableRecords(0)
                ->assertDontSee($outletName)
                ->assertDontSee($sku);
        }
    }

    public function test_business_admin_with_several_outlets_must_pick_one_of_their_own(): void
    {
        $world = $this->world();
        $back = Outlet::factory()->for($world['own'])->create(['name' => 'Back Counter']);

        $this->pageAs($world['admin'])
            ->assertSet('outletId', null)
            ->assertSee('Select an outlet to see its stock.')
            ->assertSee('Own Shop - Own Counter')
            ->assertSee('Own Shop - Back Counter')
            ->assertDontSee('Sibling Shop - Sibling Counter')
            ->assertDontSee('Foreign Shop - Foreign Counter')
            ->set('outletId', $back->id)
            ->assertSet('outletId', $back->id)
            ->assertCanSeeTableRecords([$world['ownVariant']])
            ->assertCanNotSeeTableRecords([$world['siblingVariant'], $world['foreignVariant']]);
    }

    public function test_the_owner_still_sees_every_own_business_but_no_foreign_one(): void
    {
        $world = $this->world();

        $this->pageAs($world['owner'])
            ->assertSet('outletId', null)
            ->assertSee('Own Shop - Own Counter')
            ->assertSee('Sibling Shop - Sibling Counter')
            ->assertDontSee('Foreign Shop')
            ->assertDontSee('Foreign Counter')
            ->set('outletId', $world['siblingOutlet']->id)
            ->assertCanSeeTableRecords([$world['siblingVariant']])
            ->assertCanNotSeeTableRecords([$world['ownVariant'], $world['foreignVariant']])
            ->set('outletId', $world['foreignOutlet']->id)
            ->assertSet('outletId', null)
            ->assertCountTableRecords(0);
    }
}
