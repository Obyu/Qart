<?php

namespace Tests\Feature;

use App\Filament\Resources\Businesses\BusinessResource;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Outlets\OutletResource;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Business;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class OwnershipPoliciesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
    }

    /**
     * One full ownership chain for the given business: outlet, category, product, variant.
     *
     * @return array<string, Model>
     */
    private function chainFor(Business $business): array
    {
        $category = Category::factory()->for($business)->create();
        $product = Product::factory()->for($category)->create();

        return [
            'business' => $business,
            'outlet' => Outlet::factory()->for($business)->create(),
            'category' => $category,
            'product' => $product,
            'variant' => ProductVariant::factory()->for($product)->create(),
        ];
    }

    private function ownedChain(): array
    {
        return $this->chainFor(Business::factory()->for($this->owner)->create());
    }

    private function assertOwnershipAbilities(Model $record, string $label): void
    {
        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertTrue(
                Gate::forUser($this->owner)->allows($ability, $record),
                "The owner should be allowed to {$ability} the {$label}."
            );
            $this->assertFalse(
                Gate::forUser($this->other)->allows($ability, $record),
                "Another user must not be allowed to {$ability} the {$label}."
            );
        }
    }

    // One test per policy

    public function test_business_policy_limits_record_access_to_the_owner(): void
    {
        $this->assertOwnershipAbilities($this->ownedChain()['business'], 'business');
    }

    public function test_outlet_policy_limits_record_access_to_the_owner(): void
    {
        $this->assertOwnershipAbilities($this->ownedChain()['outlet'], 'outlet');
    }

    public function test_category_policy_limits_record_access_to_the_owner(): void
    {
        $this->assertOwnershipAbilities($this->ownedChain()['category'], 'category');
    }

    public function test_product_policy_limits_record_access_to_the_owner(): void
    {
        $this->assertOwnershipAbilities($this->ownedChain()['product'], 'product');
    }

    public function test_product_variant_policy_limits_record_access_to_the_owner(): void
    {
        $this->assertOwnershipAbilities($this->ownedChain()['variant'], 'variant');
    }

    // Abilities that are open to any authenticated user

    public function test_view_any_create_and_delete_any_are_allowed_for_any_authenticated_user(): void
    {
        $models = [Business::class, Outlet::class, Category::class, Product::class, ProductVariant::class];

        foreach ([$this->owner, $this->other] as $user) {
            foreach ($models as $model) {
                foreach (['viewAny', 'create', 'deleteAny'] as $ability) {
                    $this->assertTrue(
                        Gate::forUser($user)->allows($ability, $model),
                        "{$ability} should be allowed on {$model} for any authenticated user."
                    );
                }
            }
        }
    }

    // Ownership follows the business chain, across several businesses

    public function test_an_owner_of_several_businesses_is_allowed_on_records_in_any_of_them(): void
    {
        $this->ownedChain();
        $secondChain = $this->chainFor(Business::factory()->for($this->owner)->create());

        foreach ($secondChain as $label => $record) {
            $this->assertOwnershipAbilities($record, $label);
        }
    }

    // Filament consults the policies

    public function test_filament_resources_deny_record_actions_to_users_who_do_not_own_the_record(): void
    {
        $chain = $this->ownedChain();
        $this->actingAs($this->other);

        $resources = [
            'business' => BusinessResource::class,
            'outlet' => OutletResource::class,
            'category' => CategoryResource::class,
            'product' => ProductResource::class,
            'variant' => ProductVariantResource::class,
        ];

        foreach ($resources as $label => $resource) {
            $this->assertFalse($resource::canView($chain[$label]), "view must be denied for the {$label}.");
            $this->assertFalse($resource::canEdit($chain[$label]), "edit must be denied for the {$label}.");
            $this->assertFalse($resource::canDelete($chain[$label]), "delete must be denied for the {$label}.");
            $this->assertTrue($resource::canViewAny(), "viewAny must stay allowed for the {$label} resource.");
            $this->assertTrue($resource::canCreate(), "create must stay allowed for the {$label} resource.");
        }
    }

    public function test_filament_resources_allow_record_actions_to_the_owner(): void
    {
        $chain = $this->ownedChain();
        $this->actingAs($this->owner);

        $resources = [
            'business' => BusinessResource::class,
            'outlet' => OutletResource::class,
            'category' => CategoryResource::class,
            'product' => ProductResource::class,
            'variant' => ProductVariantResource::class,
        ];

        foreach ($resources as $label => $resource) {
            $this->assertTrue($resource::canView($chain[$label]), "view must be allowed for the {$label}.");
            $this->assertTrue($resource::canEdit($chain[$label]), "edit must be allowed for the {$label}.");
            $this->assertTrue($resource::canDelete($chain[$label]), "delete must be allowed for the {$label}.");
        }
    }
}
