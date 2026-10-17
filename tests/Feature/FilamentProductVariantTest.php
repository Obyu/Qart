<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductVariants\Pages\CreateProductVariant;
use App\Filament\Resources\ProductVariants\Pages\EditProductVariant;
use App\Filament\Resources\ProductVariants\Pages\ListProductVariants;
use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FilamentProductVariantTest extends TestCase
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

    private function ownProduct(): Product
    {
        $business = Business::factory()->for($this->user)->create();
        $category = Category::factory()->for($business)->create();

        return Product::factory()->for($category)->create();
    }

    private function ownVariant(array $attributes = []): ProductVariant
    {
        return ProductVariant::factory()->for($this->ownProduct())->create($attributes);
    }

    // Ownership

    public function test_user_sees_only_variants_of_their_own_products(): void
    {
        $own = $this->ownVariant();
        $other = ProductVariant::factory()->create();

        Livewire::test(ListProductVariants::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_user_cannot_open_the_edit_page_of_another_users_variant(): void
    {
        $other = ProductVariant::factory()->create();

        Livewire::test(EditProductVariant::class, ['record' => $other->getKey()])
            ->assertStatus(404);
    }

    public function test_variant_can_be_created_with_own_product(): void
    {
        $product = $this->ownProduct();

        Livewire::test(CreateProductVariant::class)
            ->fillForm(['product_id' => $product->id, 'name' => 'Small', 'sku' => 'KS-S', 'price' => '10.00'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('product_variants', [
            'product_id' => $product->id,
            'name' => 'Small',
            'sku' => 'KS-S',
        ]);
    }

    public function test_variant_cannot_be_created_with_another_users_product(): void
    {
        $otherProduct = Product::factory()->create();

        Livewire::test(CreateProductVariant::class)
            ->fillForm(['product_id' => $otherProduct->id, 'name' => 'Sneaky', 'sku' => 'SNEAK-1', 'price' => '10.00'])
            ->call('create')
            ->assertHasFormErrors(['product_id']);

        $this->assertDatabaseCount('product_variants', 0);
    }

    // Validation

    public function test_product_is_required(): void
    {
        Livewire::test(CreateProductVariant::class)
            ->fillForm(['product_id' => null, 'name' => 'Small', 'sku' => 'KS-S', 'price' => '10.00'])
            ->call('create')
            ->assertHasFormErrors(['product_id' => 'required']);

        $this->assertDatabaseCount('product_variants', 0);
    }

    public function test_variant_name_is_required(): void
    {
        Livewire::test(CreateProductVariant::class)
            ->fillForm(['product_id' => $this->ownProduct()->id, 'name' => null, 'sku' => 'KS-S', 'price' => '10.00'])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required']);

        $this->assertDatabaseCount('product_variants', 0);
    }

    public function test_variant_name_has_a_maximum_length(): void
    {
        Livewire::test(CreateProductVariant::class)
            ->fillForm(['product_id' => $this->ownProduct()->id, 'name' => str_repeat('a', 256), 'sku' => 'KS-S', 'price' => '10.00'])
            ->call('create')
            ->assertHasFormErrors(['name' => 'max']);

        $this->assertDatabaseCount('product_variants', 0);
    }

    public function test_sku_is_required(): void
    {
        Livewire::test(CreateProductVariant::class)
            ->fillForm(['product_id' => $this->ownProduct()->id, 'name' => 'Small', 'sku' => null, 'price' => '10.00'])
            ->call('create')
            ->assertHasFormErrors(['sku' => 'required']);

        $this->assertDatabaseCount('product_variants', 0);
    }

    public function test_sku_has_a_maximum_length_of_100(): void
    {
        Livewire::test(CreateProductVariant::class)
            ->fillForm(['product_id' => $this->ownProduct()->id, 'name' => 'Small', 'sku' => str_repeat('A', 101), 'price' => '10.00'])
            ->call('create')
            ->assertHasFormErrors(['sku' => 'max']);

        $this->assertDatabaseCount('product_variants', 0);
    }

    public function test_sku_must_be_unique_even_against_another_users_variant(): void
    {
        ProductVariant::factory()->create(['sku' => 'AM-001']);

        Livewire::test(CreateProductVariant::class)
            ->fillForm(['product_id' => $this->ownProduct()->id, 'name' => 'Default', 'sku' => 'AM-001', 'price' => '10.00'])
            ->call('create')
            ->assertHasFormErrors(['sku' => 'unique']);

        $this->assertDatabaseCount('product_variants', 1);
    }

    // Price

    public function test_the_price_is_saved_to_the_database(): void
    {
        $product = $this->ownProduct();

        Livewire::test(CreateProductVariant::class)
            ->fillForm(['product_id' => $product->id, 'name' => 'Small', 'sku' => 'PRICE-1', 'price' => '12.50'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('product_variants', [
            'sku' => 'PRICE-1',
            'price' => '12.50',
        ]);

        // Read the stored value back instead of trusting the form state.
        $this->assertSame('12.50', ProductVariant::query()->where('sku', 'PRICE-1')->firstOrFail()->price);
    }

    public function test_a_price_without_decimals_is_stored_with_two_decimals(): void
    {
        Livewire::test(CreateProductVariant::class)
            ->fillForm(['product_id' => $this->ownProduct()->id, 'name' => 'Small', 'sku' => 'PRICE-2', 'price' => '7'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('7.00', ProductVariant::query()->where('sku', 'PRICE-2')->firstOrFail()->price);
    }

    public function test_a_price_of_zero_is_accepted(): void
    {
        Livewire::test(CreateProductVariant::class)
            ->fillForm(['product_id' => $this->ownProduct()->id, 'name' => 'Free', 'sku' => 'PRICE-0', 'price' => '0'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('0.00', ProductVariant::query()->where('sku', 'PRICE-0')->firstOrFail()->price);
    }

    public function test_the_maximum_price_is_accepted(): void
    {
        Livewire::test(CreateProductVariant::class)
            ->fillForm(['product_id' => $this->ownProduct()->id, 'name' => 'Max', 'sku' => 'PRICE-MAX', 'price' => '999999999999.99'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('999999999999.99', ProductVariant::query()->where('sku', 'PRICE-MAX')->firstOrFail()->price);
    }

    public function test_the_price_is_required(): void
    {
        Livewire::test(CreateProductVariant::class)
            ->fillForm(['product_id' => $this->ownProduct()->id, 'name' => 'Small', 'sku' => 'NO-PRICE', 'price' => null])
            ->call('create')
            ->assertHasFormErrors(['price' => 'required']);

        $this->assertDatabaseCount('product_variants', 0);
    }

    #[DataProvider('invalidPrices')]
    public function test_an_invalid_price_is_rejected(string $price): void
    {
        Livewire::test(CreateProductVariant::class)
            ->fillForm(['product_id' => $this->ownProduct()->id, 'name' => 'Small', 'sku' => 'BAD-PRICE', 'price' => $price])
            ->call('create')
            ->assertHasFormErrors(['price']);

        $this->assertDatabaseCount('product_variants', 0);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPrices(): array
    {
        return [
            'negative' => ['-0.01'],
            'negative zero' => ['-0'],
            'three decimals' => ['1.005'],
            'not a number' => ['abc'],
            'above the maximum' => ['1000000000000.00'],
            'exponent notation' => ['1e3'],
            'thousands separator' => ['1,000.00'],
            'trailing dot' => ['10.'],
            'leading dot' => ['.5'],
        ];
    }

    public function test_the_price_is_shown_in_the_table(): void
    {
        $variant = $this->ownVariant(['price' => '12.50']);

        Livewire::test(ListProductVariants::class)
            ->assertCanSeeTableRecords([$variant])
            ->assertTableColumnStateSet('price', '12.50', $variant);
    }

    // Edit behavior

    public function test_a_variant_can_keep_its_own_sku_when_editing(): void
    {
        $variant = $this->ownVariant(['name' => 'Small', 'sku' => 'KS-S']);

        Livewire::test(EditProductVariant::class, ['record' => $variant->getKey()])
            ->fillForm(['name' => 'Renamed', 'sku' => 'KS-S'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $variant->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame('KS-S', $fresh->sku);
    }

    public function test_a_variant_cannot_take_another_variants_sku_when_editing(): void
    {
        $this->ownVariant(['sku' => 'TAKEN-1']);
        $variant = $this->ownVariant(['sku' => 'MINE-1']);

        Livewire::test(EditProductVariant::class, ['record' => $variant->getKey()])
            ->fillForm(['sku' => 'TAKEN-1'])
            ->call('save')
            ->assertHasFormErrors(['sku' => 'unique']);

        $this->assertSame('MINE-1', $variant->fresh()->sku);
    }

    public function test_a_variants_product_cannot_be_changed_when_editing(): void
    {
        $product = $this->ownProduct();
        $anotherOwnProduct = $this->ownProduct();
        $variant = ProductVariant::factory()->for($product)->create();

        Livewire::test(EditProductVariant::class, ['record' => $variant->getKey()])
            ->fillForm(['name' => 'Renamed', 'product_id' => $anotherOwnProduct->id])
            ->call('save');

        $fresh = $variant->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame($product->id, $fresh->product_id);
    }

    public function test_the_price_can_be_changed_when_editing(): void
    {
        $variant = $this->ownVariant(['price' => '5.00']);

        Livewire::test(EditProductVariant::class, ['record' => $variant->getKey()])
            ->fillForm(['price' => '7.25'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('7.25', $variant->fresh()->price);
    }

    public function test_an_invalid_price_is_rejected_when_editing(): void
    {
        $variant = $this->ownVariant(['price' => '5.00']);

        Livewire::test(EditProductVariant::class, ['record' => $variant->getKey()])
            ->fillForm(['price' => '-1'])
            ->call('save')
            ->assertHasFormErrors(['price']);

        $this->assertSame('5.00', $variant->fresh()->price);
    }

    public function test_a_variant_without_a_price_must_be_given_one_when_edited(): void
    {
        // Existing variants predate the price column, so they start with NULL.
        $variant = $this->ownVariant(['price' => null]);

        Livewire::test(EditProductVariant::class, ['record' => $variant->getKey()])
            ->fillForm(['price' => null])
            ->call('save')
            ->assertHasFormErrors(['price' => 'required']);

        $this->assertNull($variant->fresh()->price);

        Livewire::test(EditProductVariant::class, ['record' => $variant->getKey()])
            ->fillForm(['price' => '15.00'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('15.00', $variant->fresh()->price);
    }

    public function test_a_variant_can_be_deleted_and_its_product_remains(): void
    {
        $variant = $this->ownVariant();

        Livewire::test(EditProductVariant::class, ['record' => $variant->getKey()])
            ->callAction(DeleteAction::class);

        $this->assertDatabaseMissing('product_variants', ['id' => $variant->id]);
        $this->assertDatabaseHas('products', ['id' => $variant->product_id]);
    }
}
