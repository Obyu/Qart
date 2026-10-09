<?php

namespace Tests\Feature;

use App\Filament\Resources\Businesses\BusinessResource;
use App\Filament\Resources\Businesses\Pages\CreateBusiness;
use App\Filament\Resources\Businesses\Pages\EditBusiness;
use App\Filament\Resources\Businesses\Pages\ListBusinesses;
use App\Filament\Resources\Outlets\Pages\CreateOutlet;
use App\Filament\Resources\Outlets\Pages\EditOutlet;
use App\Filament\Resources\Outlets\Pages\ListOutlets;
use App\Models\Business;
use App\Models\Outlet;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentBusinessOutletTest extends TestCase
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

    // Business: ownership

    public function test_user_sees_only_their_own_businesses_in_the_list(): void
    {
        $own = Business::factory()->for($this->user)->create();
        $other = Business::factory()->create();

        Livewire::test(ListBusinesses::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_business_resource_query_excludes_other_users_businesses(): void
    {
        $own = Business::factory()->for($this->user)->create();
        $other = Business::factory()->create();

        $ids = BusinessResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains($own->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_user_cannot_open_the_edit_page_of_another_users_business(): void
    {
        $other = Business::factory()->create();

        Livewire::test(EditBusiness::class, ['record' => $other->getKey()])
            ->assertStatus(404);
    }

    public function test_created_business_is_owned_by_the_logged_in_user(): void
    {
        Livewire::test(CreateBusiness::class)
            ->fillForm(['name' => 'Kopi Senja'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('businesses', [
            'name' => 'Kopi Senja',
            'user_id' => $this->user->id,
        ]);
    }

    // Business: validation and delete

    public function test_business_name_is_required_and_has_a_maximum_length(): void
    {
        Livewire::test(CreateBusiness::class)
            ->fillForm(['name' => null])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required']);

        Livewire::test(CreateBusiness::class)
            ->fillForm(['name' => str_repeat('a', 256)])
            ->call('create')
            ->assertHasFormErrors(['name' => 'max']);

        $this->assertDatabaseCount('businesses', 0);
    }

    public function test_deleting_a_business_in_filament_removes_it_and_its_outlets(): void
    {
        $business = Business::factory()->for($this->user)->create();
        Outlet::factory()->count(2)->for($business)->create();

        Livewire::test(EditBusiness::class, ['record' => $business->getKey()])
            ->callAction(DeleteAction::class);

        $this->assertDatabaseMissing('businesses', ['id' => $business->id]);
        $this->assertDatabaseCount('outlets', 0);
    }

    // Outlet: ownership

    public function test_user_sees_only_outlets_of_their_own_businesses(): void
    {
        $ownOutlet = Outlet::factory()->for(Business::factory()->for($this->user))->create();
        $otherOutlet = Outlet::factory()->create();

        Livewire::test(ListOutlets::class)
            ->assertCanSeeTableRecords([$ownOutlet])
            ->assertCanNotSeeTableRecords([$otherOutlet]);
    }

    public function test_user_cannot_open_the_edit_page_of_another_users_outlet(): void
    {
        $otherOutlet = Outlet::factory()->create();

        Livewire::test(EditOutlet::class, ['record' => $otherOutlet->getKey()])
            ->assertStatus(404);
    }

    public function test_outlet_can_be_created_under_own_business(): void
    {
        $business = Business::factory()->for($this->user)->create();

        Livewire::test(CreateOutlet::class)
            ->fillForm(['business_id' => $business->id, 'name' => 'Main Outlet'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('outlets', [
            'name' => 'Main Outlet',
            'business_id' => $business->id,
        ]);
    }

    public function test_outlet_cannot_be_created_under_another_users_business(): void
    {
        $otherBusiness = Business::factory()->create();

        Livewire::test(CreateOutlet::class)
            ->fillForm(['business_id' => $otherBusiness->id, 'name' => 'Sneaky Outlet'])
            ->call('create')
            ->assertHasFormErrors(['business_id']);

        $this->assertDatabaseCount('outlets', 0);
    }

    // Outlet: validation

    public function test_outlet_business_and_name_are_required(): void
    {
        Livewire::test(CreateOutlet::class)
            ->fillForm(['business_id' => null, 'name' => null])
            ->call('create')
            ->assertHasFormErrors(['business_id' => 'required', 'name' => 'required']);

        $this->assertDatabaseCount('outlets', 0);
    }
}
