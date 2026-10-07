<?php

namespace Tests\Feature;

use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentCategoryProductTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function ownBusiness(): Business
    {
        return Business::factory()->for($this->user)->create();
    }

    // Category: ownership

    public function test_user_sees_only_categories_of_their_own_businesses(): void
    {
        $own = Category::factory()->for($this->ownBusiness())->create();
        $other = Category::factory()->create();

        Livewire::test(ListCategories::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_user_cannot_open_the_edit_page_of_another_users_category(): void
    {
        $other = Category::factory()->create();

        Livewire::test(EditCategory::class, ['record' => $other->getKey()])
            ->assertStatus(404);
    }

    public function test_category_can_be_created_under_own_business(): void
    {
        $business = $this->ownBusiness();

        Livewire::test(CreateCategory::class)
            ->fillForm(['business_id' => $business->id, 'name' => 'Drinks'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('categories', [
            'name' => 'Drinks',
            'business_id' => $business->id,
        ]);
    }

    public function test_category_cannot_be_created_under_another_users_business(): void
    {
        $otherBusiness = Business::factory()->create();

        Livewire::test(CreateCategory::class)
            ->fillForm(['business_id' => $otherBusiness->id, 'name' => 'Sneaky'])
            ->call('create')
            ->assertHasFormErrors(['business_id']);

        $this->assertDatabaseCount('categories', 0);
    }

    // Category: validation and behavior

    public function test_category_name_and_business_are_required_and_name_has_a_maximum_length(): void
    {
        Livewire::test(CreateCategory::class)
            ->fillForm(['business_id' => null, 'name' => null])
            ->call('create')
            ->assertHasFormErrors(['business_id' => 'required', 'name' => 'required']);

        Livewire::test(CreateCategory::class)
            ->fillForm(['business_id' => $this->ownBusiness()->id, 'name' => str_repeat('a', 256)])
            ->call('create')
            ->assertHasFormErrors(['name' => 'max']);

        $this->assertDatabaseCount('categories', 0);
    }

    public function test_a_categorys_business_cannot_be_changed_when_editing(): void
    {
        $business = $this->ownBusiness();
        $anotherOwnBusiness = $this->ownBusiness();
        $category = Category::factory()->for($business)->create();

        Livewire::test(EditCategory::class, ['record' => $category->getKey()])
            ->fillForm(['name' => 'Renamed', 'business_id' => $anotherOwnBusiness->id])
            ->call('save');

        $fresh = $category->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame($business->id, $fresh->business_id);
    }

    public function test_a_category_without_products_can_be_deleted(): void
    {
        $category = Category::factory()->for($this->ownBusiness())->create();

        Livewire::test(EditCategory::class, ['record' => $category->getKey()])
            ->callAction(DeleteAction::class);

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    // Product: ownership

    public function test_user_sees_only_products_of_their_own_businesses(): void
    {
        $ownCategory = Category::factory()->for($this->ownBusiness())->create();
        $own = Product::factory()->for($ownCategory)->create();
        $other = Product::factory()->create();

        Livewire::test(ListProducts::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_user_cannot_open_the_edit_page_of_another_users_product(): void
    {
        $other = Product::factory()->create();

        Livewire::test(EditProduct::class, ['record' => $other->getKey()])
            ->assertStatus(404);
    }

    public function test_product_can_be_created_with_own_category_and_gets_its_business(): void
    {
        $business = $this->ownBusiness();
        $category = Category::factory()->for($business)->create();

        Livewire::test(CreateProduct::class)
            ->fillForm(['category_id' => $category->id, 'name' => 'Latte'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('products', [
            'name' => 'Latte',
            'category_id' => $category->id,
            'business_id' => $business->id,
        ]);
    }

    public function test_product_cannot_be_created_with_another_users_category(): void
    {
        $otherCategory = Category::factory()->create();

        Livewire::test(CreateProduct::class)
            ->fillForm(['category_id' => $otherCategory->id, 'name' => 'Sneaky'])
            ->call('create')
            ->assertHasFormErrors(['category_id']);

        $this->assertDatabaseCount('products', 0);
    }

    // Product: validation and behavior

    public function test_product_name_and_category_are_required(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm(['category_id' => null, 'name' => null])
            ->call('create')
            ->assertHasFormErrors(['category_id' => 'required', 'name' => 'required']);

        $this->assertDatabaseCount('products', 0);
    }

    public function test_product_name_has_a_maximum_length(): void
    {
        $category = Category::factory()->for($this->ownBusiness())->create();

        Livewire::test(CreateProduct::class)
            ->fillForm(['category_id' => $category->id, 'name' => str_repeat('a', 256)])
            ->call('create')
            ->assertHasFormErrors(['name' => 'max']);

        $this->assertDatabaseCount('products', 0);
    }

    public function test_changing_a_products_category_moves_it_to_that_categorys_business(): void
    {
        $businessA = $this->ownBusiness();
        $businessB = $this->ownBusiness();
        $categoryA = Category::factory()->for($businessA)->create();
        $categoryB = Category::factory()->for($businessB)->create();
        $product = Product::factory()->for($categoryA)->create();

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm(['category_id' => $categoryB->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $product->fresh();
        $this->assertSame($categoryB->id, $fresh->category_id);
        $this->assertSame($businessB->id, $fresh->business_id);
    }

    public function test_a_product_cannot_be_moved_to_another_users_category(): void
    {
        $category = Category::factory()->for($this->ownBusiness())->create();
        $product = Product::factory()->for($category)->create();
        $otherCategory = Category::factory()->create();

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm(['category_id' => $otherCategory->id])
            ->call('save')
            ->assertHasFormErrors(['category_id']);

        $this->assertSame($category->id, $product->fresh()->category_id);
    }

    public function test_a_product_can_be_deleted(): void
    {
        $category = Category::factory()->for($this->ownBusiness())->create();
        $product = Product::factory()->for($category)->create();

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->callAction(DeleteAction::class);

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }
}
