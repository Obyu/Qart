<?php

namespace Tests\Feature;

use App\Filament\Resources\Outlets\Pages\CreateOutlet;
use App\Filament\Resources\Outlets\Pages\EditOutlet;
use App\Models\Business;
use App\Models\Outlet;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OutletOwnershipTest extends TestCase
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

    public function test_outlet_business_id_is_not_mass_assignable(): void
    {
        $outlet = (new Outlet)->fill(['name' => 'A', 'business_id' => 123]);

        $this->assertNull($outlet->business_id);
        $this->assertSame('A', $outlet->name);
    }

    public function test_outlet_is_created_under_the_selected_one_of_several_own_businesses(): void
    {
        Business::factory()->for($this->user)->create();
        $second = Business::factory()->for($this->user)->create();

        Livewire::test(CreateOutlet::class)
            ->fillForm(['business_id' => $second->id, 'name' => 'Branch'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('outlets', ['name' => 'Branch', 'business_id' => $second->id]);
    }

    public function test_an_outlets_business_cannot_be_changed_when_editing(): void
    {
        $business = Business::factory()->for($this->user)->create();
        $anotherOwnBusiness = Business::factory()->for($this->user)->create();
        $outlet = Outlet::factory()->for($business)->create();

        Livewire::test(EditOutlet::class, ['record' => $outlet->getKey()])
            ->fillForm(['name' => 'Renamed', 'business_id' => $anotherOwnBusiness->id])
            ->call('save');

        $fresh = $outlet->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame($business->id, $fresh->business_id);
    }

    public function test_an_outlet_cannot_be_moved_to_another_users_business_by_editing(): void
    {
        $business = Business::factory()->for($this->user)->create();
        $outlet = Outlet::factory()->for($business)->create();
        $foreignBusiness = Business::factory()->create();

        Livewire::test(EditOutlet::class, ['record' => $outlet->getKey()])
            ->fillForm(['name' => 'Renamed', 'business_id' => $foreignBusiness->id])
            ->call('save');

        $this->assertSame($business->id, $outlet->fresh()->business_id);
    }
}
