<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Outlets\OutletResource;
use App\Filament\Resources\Outlets\Pages\CreateOutlet;
use App\Filament\Resources\Outlets\Pages\EditOutlet;
use App\Filament\Resources\Outlets\Pages\ListOutlets;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\ProductVariants\Pages\CreateProductVariant;
use App\Filament\Resources\ProductVariants\Pages\EditProductVariant;
use App\Filament\Resources\ProductVariants\Pages\ListProductVariants;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Models\Business;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\StaffService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A business admin works the catalog (outlets, categories, products, variants)
 * of their own business only. "sibling" is another business of the SAME owner,
 * "foreign" a business of a different owner: both must stay out of reach.
 */
class BusinessAdminCatalogAccessTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'demo-password-123';

    private const CATALOG_PATHS = [
        '/admin/outlets',
        '/admin/outlets/create',
        '/admin/categories',
        '/admin/categories/create',
        '/admin/products',
        '/admin/products/create',
        '/admin/product-variants',
        '/admin/product-variants/create',
    ];

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
        $business = Business::factory()->for($owner)->create();
        $sibling = Business::factory()->for($owner)->create();
        $foreign = Business::factory()->create();

        $own = $this->chain($business);
        $staff = app(StaffService::class);

        $make = fn (string $email, StaffRole $role, ?Outlet $outlet): User => $staff->create($owner, [
            'name' => ucfirst($role->value),
            'email' => $email,
            'password' => self::PASSWORD,
            'business_id' => $business->id,
            'role' => $role->value,
            'outlet_id' => $outlet?->id,
        ]);

        return [
            'owner' => $owner,
            'business' => $business,
            'own' => $own,
            'sibling' => $this->chain($sibling),
            'foreign' => $this->chain($foreign),
            'admin' => $make('admin@example.test', StaffRole::BusinessAdmin, null),
            'cashier' => $make('cashier@example.test', StaffRole::Cashier, $own['outlet']),
            'kitchen' => $make('kitchen@example.test', StaffRole::Kitchen, $own['outlet']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function chain(Business $business): array
    {
        $outlet = Outlet::factory()->for($business)->create();
        $category = Category::factory()->for($business)->create();
        $product = Product::factory()->for($category)->create();
        $variant = ProductVariant::factory()->for($product)->create();

        return [
            'business' => $business,
            'outlet' => $outlet,
            'category' => $category,
            'product' => $product,
            'variant' => $variant,
        ];
    }

    /**
     * @param  array<string, mixed>  $chain
     * @return list<string>
     */
    private function editPaths(array $chain): array
    {
        return [
            '/admin/outlets/'.$chain['outlet']->getKey().'/edit',
            '/admin/categories/'.$chain['category']->getKey().'/edit',
            '/admin/products/'.$chain['product']->getKey().'/edit',
            '/admin/product-variants/'.$chain['variant']->getKey().'/edit',
        ];
    }

    private function statusFor(User $user, string $path): int
    {
        // AuthenticateSession would redirect the second user of a test.
        $this->flushSession();

        return $this->actingAs($user)->get($path)->getStatusCode();
    }

    public function test_business_admin_opens_every_catalog_list_and_create_page(): void
    {
        $w = $this->world();

        foreach (self::CATALOG_PATHS as $path) {
            $this->assertSame(200, $this->statusFor($w['admin'], $path), "business admin on {$path}");
        }
    }

    public function test_business_admin_opens_edit_pages_of_own_records_and_the_owner_controls_hold(): void
    {
        $w = $this->world();

        foreach ($this->editPaths($w['own']) as $path) {
            $this->assertSame(200, $this->statusFor($w['admin'], $path), "business admin on {$path}");
        }

        // Control: the owner reaches the sibling business, so a 403/404 for the
        // admin there is the access rule and not a broken URL.
        foreach ($this->editPaths($w['sibling']) as $path) {
            $this->assertSame(200, $this->statusFor($w['owner'], $path), "owner on {$path}");
        }

        foreach ($this->editPaths($w['foreign']) as $path) {
            $this->assertContains($this->statusFor($w['owner'], $path), [403, 404], "owner must not open {$path}");
        }
    }

    public function test_business_admin_cannot_open_edit_pages_of_other_businesses(): void
    {
        $w = $this->world();

        foreach (['sibling', 'foreign'] as $which) {
            foreach ($this->editPaths($w[$which]) as $path) {
                $status = $this->statusFor($w['admin'], $path);

                $this->assertContains($status, [403, 404], "business admin on {$which} {$path} got {$status}");
            }
        }
    }

    public function test_business_admin_lists_show_only_records_of_their_own_business(): void
    {
        $w = $this->world();
        $this->actingAs($w['admin']);

        foreach ([
            'outlet' => ListOutlets::class,
            'category' => ListCategories::class,
            'product' => ListProducts::class,
            'variant' => ListProductVariants::class,
        ] as $label => $page) {
            Livewire::test($page)
                ->assertCanSeeTableRecords([$w['own'][$label]])
                ->assertCanNotSeeTableRecords([$w['sibling'][$label], $w['foreign'][$label]]);
        }
    }

    public function test_business_admin_can_create_an_outlet_and_a_category_in_their_business(): void
    {
        $w = $this->world();
        $businessId = $w['business']->id;
        $this->actingAs($w['admin']);

        Livewire::test(CreateOutlet::class)
            ->fillForm(['business_id' => $businessId, 'name' => 'BA Outlet'])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateCategory::class)
            ->fillForm(['business_id' => $businessId, 'name' => 'BA Category'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('outlets', ['name' => 'BA Outlet', 'business_id' => $businessId]);
        $this->assertDatabaseHas('categories', ['name' => 'BA Category', 'business_id' => $businessId]);
    }

    public function test_business_admin_can_create_a_product_and_a_variant_in_their_business(): void
    {
        $w = $this->world();
        $this->actingAs($w['admin']);

        Livewire::test(CreateProduct::class)
            ->fillForm(['category_id' => $w['own']['category']->id, 'name' => 'BA Latte'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('products', ['name' => 'BA Latte', 'category_id' => $w['own']['category']->id]);

        Livewire::test(CreateProductVariant::class)
            ->fillForm([
                'product_id' => $w['own']['product']->id,
                'name' => 'BA Large',
                'sku' => 'BA-LARGE-1',
                'price' => '25.50',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('product_variants', [
            'product_id' => $w['own']['product']->id,
            'sku' => 'BA-LARGE-1',
        ]);
    }

    public function test_business_admin_cannot_create_records_in_other_businesses(): void
    {
        $w = $this->world();
        $this->actingAs($w['admin']);

        foreach (['sibling', 'foreign'] as $i => $which) {
            $target = $w[$which];

            Livewire::test(CreateOutlet::class)
                ->fillForm(['business_id' => $target['business']->id, 'name' => 'Sneaky'])
                ->call('create')
                ->assertHasFormErrors(['business_id']);

            Livewire::test(CreateCategory::class)
                ->fillForm(['business_id' => $target['business']->id, 'name' => 'Sneaky'])
                ->call('create')
                ->assertHasFormErrors(['business_id']);

            Livewire::test(CreateProduct::class)
                ->fillForm(['category_id' => $target['category']->id, 'name' => 'Sneaky'])
                ->call('create')
                ->assertHasFormErrors(['category_id']);

            Livewire::test(CreateProductVariant::class)
                ->fillForm([
                    'product_id' => $target['product']->id,
                    'name' => 'Sneaky',
                    'sku' => 'SNEAK-'.$i,
                    'price' => '10.00',
                ])
                ->call('create')
                ->assertHasFormErrors(['product_id']);
        }

        $this->assertDatabaseMissing('outlets', ['name' => 'Sneaky']);
        $this->assertDatabaseMissing('categories', ['name' => 'Sneaky']);
        $this->assertDatabaseMissing('products', ['name' => 'Sneaky']);
        $this->assertDatabaseMissing('product_variants', ['name' => 'Sneaky']);
    }

    public function test_business_admin_can_edit_records_of_their_business(): void
    {
        $w = $this->world();
        $this->actingAs($w['admin']);

        Livewire::test(EditOutlet::class, ['record' => $w['own']['outlet']->getKey()])
            ->fillForm(['name' => 'BA Renamed Outlet'])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(EditCategory::class, ['record' => $w['own']['category']->getKey()])
            ->fillForm(['name' => 'BA Renamed Category'])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(EditProduct::class, ['record' => $w['own']['product']->getKey()])
            ->fillForm(['name' => 'BA Renamed Product'])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(EditProductVariant::class, ['record' => $w['own']['variant']->getKey()])
            ->fillForm(['name' => 'BA Renamed Variant', 'sku' => 'BA-RENAMED-1', 'price' => '12.50'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('outlets', ['id' => $w['own']['outlet']->id, 'name' => 'BA Renamed Outlet']);
        $this->assertDatabaseHas('categories', ['id' => $w['own']['category']->id, 'name' => 'BA Renamed Category']);
        $this->assertDatabaseHas('products', ['id' => $w['own']['product']->id, 'name' => 'BA Renamed Product']);
        $this->assertDatabaseHas('product_variants', ['id' => $w['own']['variant']->id, 'sku' => 'BA-RENAMED-1']);
    }

    public function test_policies_allow_a_business_admin_only_on_records_of_their_business(): void
    {
        $w = $this->world();
        $admin = Gate::forUser($w['admin']);

        foreach (['outlet', 'category', 'product', 'variant'] as $label) {
            foreach (['view', 'update', 'delete'] as $ability) {
                $this->assertTrue($admin->allows($ability, $w['own'][$label]), "admin {$ability} own {$label}");
                $this->assertFalse($admin->allows($ability, $w['sibling'][$label]), "admin {$ability} sibling {$label}");
                $this->assertFalse($admin->allows($ability, $w['foreign'][$label]), "admin {$ability} foreign {$label}");

                foreach (['cashier', 'kitchen'] as $role) {
                    $this->assertFalse(
                        Gate::forUser($w[$role])->allows($ability, $w['own'][$label]),
                        "{$role} {$ability} own {$label}",
                    );
                }
            }
        }
    }

    public function test_resource_entry_points_follow_the_role(): void
    {
        $w = $this->world();
        $resources = [
            OutletResource::class,
            CategoryResource::class,
            ProductResource::class,
            ProductVariantResource::class,
        ];

        foreach (['admin' => true, 'cashier' => false, 'kitchen' => false] as $who => $allowed) {
            $this->actingAs($w[$who]);

            foreach ($resources as $resource) {
                $this->assertSame($allowed, $resource::canViewAny(), "{$who} canViewAny {$resource}");
                $this->assertSame($allowed, $resource::canCreate(), "{$who} canCreate {$resource}");
                $this->assertSame(
                    $allowed ? [$w['business']->id] : [],
                    $resource::accessibleBusinessIds(),
                    "{$who} accessibleBusinessIds {$resource}",
                );
            }
        }
    }
}
