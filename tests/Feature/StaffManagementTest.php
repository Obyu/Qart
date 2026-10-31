<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Filament\Resources\Staff\Pages\CreateStaff;
use App\Filament\Resources\Staff\Pages\EditStaff;
use App\Filament\Resources\Staff\Pages\ListStaff;
use App\Filament\Resources\Staff\StaffResource;
use App\Models\Business;
use App\Models\Outlet;
use App\Models\User;
use App\Services\StaffService;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;
use Throwable;

class StaffManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    private function staffUser(StaffRole $role, Business $business, ?Outlet $outlet = null): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'role' => $role->value,
            'business_id' => $business->id,
            'outlet_id' => $outlet?->id,
        ])->save();

        return $user;
    }

    /**
     * Key order matters for the Livewire form: business, then role, then outlet.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Business $business, StaffRole $role, ?Outlet $outlet = null, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Siti Kasir',
            'email' => 'siti@example.test',
            'password' => 'secret-password',
            'business_id' => $business->id,
            'role' => $role->value,
            'outlet_id' => $outlet?->id,
        ], $overrides);
    }

    private function thrownBy(string $exceptionClass, Closure $callback): Throwable
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            $this->assertInstanceOf($exceptionClass, $exception);

            return $exception;
        }

        $this->fail("Expected {$exceptionClass} to be thrown.");
    }

    // ---------------------------------------------------------------- UI

    public function test_owner_sees_only_staff_of_own_businesses(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->for($owner)->create();
        $outlet = Outlet::factory()->for($business)->create();
        $cashier = $this->staffUser(StaffRole::Cashier, $business, $outlet);

        $otherOwner = User::factory()->create();
        $otherBusiness = Business::factory()->for($otherOwner)->create();
        $otherOutlet = Outlet::factory()->for($otherBusiness)->create();
        $otherCashier = $this->staffUser(StaffRole::Cashier, $otherBusiness, $otherOutlet);

        $this->actingAs($owner);

        Livewire::test(ListStaff::class)
            ->assertCanSeeTableRecords([$cashier])
            ->assertCanNotSeeTableRecords([$otherCashier, $otherOwner, $owner]);
    }

    public function test_owner_can_create_a_cashier_through_the_form(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->for($owner)->create();
        $outlet = Outlet::factory()->for($business)->create();

        $this->actingAs($owner);

        Livewire::test(CreateStaff::class)
            ->fillForm($this->payload($business, StaffRole::Cashier, $outlet))
            ->call('create')
            ->assertHasNoFormErrors();

        $staff = User::query()->where('email', 'siti@example.test')->firstOrFail();

        $this->assertSame('cashier', $staff->role);
        $this->assertSame($business->id, (int) $staff->business_id);
        $this->assertSame($outlet->id, (int) $staff->outlet_id);
        $this->assertNotSame('secret-password', $staff->password);
        $this->assertTrue(Hash::check('secret-password', $staff->password));
    }

    public function test_owner_can_create_a_business_admin_without_an_outlet(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->for($owner)->create();
        Outlet::factory()->for($business)->create();

        $this->actingAs($owner);

        Livewire::test(CreateStaff::class)
            ->fillForm($this->payload($business, StaffRole::BusinessAdmin))
            ->call('create')
            ->assertHasNoFormErrors();

        $staff = User::query()->where('email', 'siti@example.test')->firstOrFail();

        $this->assertSame('business_admin', $staff->role);
        $this->assertNull($staff->outlet_id);
    }

    public function test_form_rejects_a_business_owned_by_someone_else(): void
    {
        $owner = User::factory()->create();
        Business::factory()->for($owner)->create();

        $otherOwner = User::factory()->create();
        $otherBusiness = Business::factory()->for($otherOwner)->create();
        $otherOutlet = Outlet::factory()->for($otherBusiness)->create();

        $this->actingAs($owner);

        Livewire::test(CreateStaff::class)
            ->fillForm($this->payload($otherBusiness, StaffRole::Cashier, $otherOutlet))
            ->call('create')
            ->assertHasFormErrors(['business_id']);

        $this->assertDatabaseMissing('users', ['email' => 'siti@example.test']);
    }

    public function test_form_rejects_an_outlet_from_a_different_business(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->for($owner)->create();
        $secondBusiness = Business::factory()->for($owner)->create();
        $foreignOutlet = Outlet::factory()->for($secondBusiness)->create();

        $this->actingAs($owner);

        Livewire::test(CreateStaff::class)
            ->fillForm($this->payload($business, StaffRole::Cashier, $foreignOutlet))
            ->call('create')
            ->assertHasFormErrors(['outlet_id']);

        $this->assertDatabaseMissing('users', ['email' => 'siti@example.test']);
    }

    public function test_form_requires_an_outlet_for_a_cashier(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->for($owner)->create();
        Outlet::factory()->for($business)->create();

        $this->actingAs($owner);

        Livewire::test(CreateStaff::class)
            ->fillForm($this->payload($business, StaffRole::Cashier))
            ->call('create')
            ->assertHasFormErrors(['outlet_id' => 'required']);
    }

    public function test_form_rejects_a_duplicate_email(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->for($owner)->create();
        $outlet = Outlet::factory()->for($business)->create();
        User::factory()->create(['email' => 'siti@example.test']);

        $this->actingAs($owner);

        Livewire::test(CreateStaff::class)
            ->fillForm($this->payload($business, StaffRole::Cashier, $outlet))
            ->call('create')
            ->assertHasFormErrors(['email' => 'unique']);
    }

    public function test_editing_without_a_password_keeps_the_existing_hash(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->for($owner)->create();
        $outlet = Outlet::factory()->for($business)->create();
        $cashier = $this->staffUser(StaffRole::Cashier, $business, $outlet);
        $hashBefore = $cashier->password;

        $this->actingAs($owner);

        Livewire::test(EditStaff::class, ['record' => $cashier->getRouteKey()])
            ->fillForm(['name' => 'Budi Baru'])
            ->call('save')
            ->assertHasNoFormErrors();

        $cashier->refresh();

        $this->assertSame('Budi Baru', $cashier->name);
        $this->assertSame($hashBefore, $cashier->password);
    }

    public function test_editing_with_a_new_password_replaces_the_hash(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->for($owner)->create();
        $outlet = Outlet::factory()->for($business)->create();
        $cashier = $this->staffUser(StaffRole::Cashier, $business, $outlet);
        $hashBefore = $cashier->password;

        $this->actingAs($owner);

        Livewire::test(EditStaff::class, ['record' => $cashier->getRouteKey()])
            ->fillForm(['password' => 'brand-new-password'])
            ->call('save')
            ->assertHasNoFormErrors();

        $cashier->refresh();

        $this->assertNotSame($hashBefore, $cashier->password);
        $this->assertNotSame('brand-new-password', $cashier->password);
        $this->assertTrue(Hash::check('brand-new-password', $cashier->password));
    }

    public function test_owner_cannot_open_staff_of_another_owner(): void
    {
        $owner = User::factory()->create();
        Business::factory()->for($owner)->create();

        $otherOwner = User::factory()->create();
        $otherBusiness = Business::factory()->for($otherOwner)->create();
        $otherOutlet = Outlet::factory()->for($otherBusiness)->create();
        $otherCashier = $this->staffUser(StaffRole::Cashier, $otherBusiness, $otherOutlet);

        $this->actingAs($owner)
            ->get(StaffResource::getUrl('edit', ['record' => $otherCashier]))
            ->assertNotFound();
    }

    public function test_owner_cannot_open_an_owner_account_as_staff(): void
    {
        $owner = User::factory()->create();
        Business::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->get(StaffResource::getUrl('edit', ['record' => $owner]))
            ->assertNotFound();
    }

    public function test_business_admin_cannot_access_staff_management(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->for($owner)->create();
        $admin = $this->staffUser(StaffRole::BusinessAdmin, $business);

        $this->actingAs($admin)->get(StaffResource::getUrl('index'))->assertForbidden();
        $this->actingAs($admin)->get(StaffResource::getUrl('create'))->assertForbidden();
    }

    public function test_cashier_cannot_access_staff_management(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->for($owner)->create();
        $outlet = Outlet::factory()->for($business)->create();
        $cashier = $this->staffUser(StaffRole::Cashier, $business, $outlet);

        $this->actingAs($cashier)->get(StaffResource::getUrl('index'))->assertForbidden();
        $this->actingAs($cashier)->get(StaffResource::getUrl('create'))->assertForbidden();
    }

    public function test_kitchen_cannot_access_staff_management(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->for($owner)->create();
        $outlet = Outlet::factory()->for($business)->create();
        $kitchen = $this->staffUser(StaffRole::Kitchen, $business, $outlet);

        $this->actingAs($kitchen)->get(StaffResource::getUrl('index'))->assertForbidden();
        $this->actingAs($kitchen)->get(StaffResource::getUrl('create'))->assertForbidden();
    }

    // ------------------------------------------------- service (UI bypassed)

    public function test_service_rejects_a_business_the_actor_does_not_own(): void
    {
        $owner = User::factory()->create();
        Business::factory()->for($owner)->create();

        $otherOwner = User::factory()->create();
        $otherBusiness = Business::factory()->for($otherOwner)->create();
        $otherOutlet = Outlet::factory()->for($otherBusiness)->create();

        $this->thrownBy(AuthorizationException::class, fn () => app(StaffService::class)->create(
            $owner,
            $this->payload($otherBusiness, StaffRole::Cashier, $otherOutlet),
        ));

        $this->assertDatabaseCount('users', 2);
    }

    public function test_service_refuses_staff_actors_even_for_their_own_business(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->for($owner)->create();
        $outlet = Outlet::factory()->for($business)->create();

        $actors = [
            $this->staffUser(StaffRole::BusinessAdmin, $business),
            $this->staffUser(StaffRole::Cashier, $business, $outlet),
            $this->staffUser(StaffRole::Kitchen, $business, $outlet),
        ];

        foreach ($actors as $actor) {
            $this->thrownBy(AuthorizationException::class, fn () => app(StaffService::class)->create(
                $actor,
                $this->payload($business, StaffRole::Cashier, $outlet),
            ));
        }

        $this->assertDatabaseCount('users', 4);
    }

    public function test_service_rejects_invalid_role_and_assignment_combinations(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->for($owner)->create();
        $outlet = Outlet::factory()->for($business)->create();
        $foreignOutlet = Outlet::factory()
            ->for(Business::factory()->for($owner)->create())
            ->create();

        $invalid = [
            'cashier without outlet' => $this->payload($business, StaffRole::Cashier),
            'kitchen without outlet' => $this->payload($business, StaffRole::Kitchen),
            'cashier with outlet of another business' => $this->payload($business, StaffRole::Cashier, $foreignOutlet),
            'kitchen with outlet of another business' => $this->payload($business, StaffRole::Kitchen, $foreignOutlet),
            'business admin with an outlet' => $this->payload($business, StaffRole::BusinessAdmin, $outlet),
        ];

        foreach ($invalid as $label => $payload) {
            $exception = $this->thrownBy(
                ValidationException::class,
                fn () => app(StaffService::class)->create($owner, $payload),
            );

            $this->assertArrayHasKey('outlet_id', $exception->errors(), $label);
        }

        $this->assertDatabaseCount('users', 1);
    }

    public function test_service_rejects_unknown_roles(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->for($owner)->create();
        $outlet = Outlet::factory()->for($business)->create();

        foreach (['owner', 'admin', '', null] as $role) {
            $exception = $this->thrownBy(
                ValidationException::class,
                fn () => app(StaffService::class)->create(
                    $owner,
                    $this->payload($business, StaffRole::Cashier, $outlet, ['role' => $role]),
                ),
            );

            $this->assertArrayHasKey('role', $exception->errors());
        }

        $this->assertDatabaseCount('users', 1);
    }

    public function test_service_rejects_a_duplicate_email_case_insensitively(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->for($owner)->create();
        $outlet = Outlet::factory()->for($business)->create();
        User::factory()->create(['email' => 'siti@example.test']);

        $exception = $this->thrownBy(
            ValidationException::class,
            fn () => app(StaffService::class)->create(
                $owner,
                $this->payload($business, StaffRole::Cashier, $outlet, ['email' => ' SITI@example.test ']),
            ),
        );

        $this->assertArrayHasKey('email', $exception->errors());
    }

    public function test_service_hashes_the_password(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->for($owner)->create();
        $outlet = Outlet::factory()->for($business)->create();

        $staff = app(StaffService::class)->create(
            $owner,
            $this->payload($business, StaffRole::Kitchen, $outlet),
        );

        $this->assertNotSame('secret-password', $staff->password);
        $this->assertTrue(Hash::check('secret-password', $staff->fresh()->password));
        $this->assertSame('kitchen', $staff->role);
    }

    public function test_service_update_cannot_move_staff_to_another_business(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->for($owner)->create();
        $outlet = Outlet::factory()->for($business)->create();
        $secondBusiness = Business::factory()->for($owner)->create();
        $cashier = $this->staffUser(StaffRole::Cashier, $business, $outlet);

        app(StaffService::class)->update($owner, $cashier, [
            'name' => 'Renamed',
            'email' => $cashier->email,
            'role' => 'cashier',
            'outlet_id' => $outlet->id,
            'business_id' => $secondBusiness->id,
        ]);

        $cashier->refresh();

        $this->assertSame($business->id, (int) $cashier->business_id);
        $this->assertSame('Renamed', $cashier->name);
    }

    public function test_service_update_to_business_admin_clears_the_outlet(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->for($owner)->create();
        $outlet = Outlet::factory()->for($business)->create();
        $cashier = $this->staffUser(StaffRole::Cashier, $business, $outlet);

        app(StaffService::class)->update($owner, $cashier, [
            'name' => $cashier->name,
            'email' => $cashier->email,
            'role' => 'business_admin',
        ]);

        $cashier->refresh();

        $this->assertSame('business_admin', $cashier->role);
        $this->assertNull($cashier->outlet_id);
    }

    public function test_service_update_rejects_staff_of_another_owner(): void
    {
        $owner = User::factory()->create();
        Business::factory()->for($owner)->create();

        $otherOwner = User::factory()->create();
        $otherBusiness = Business::factory()->for($otherOwner)->create();
        $otherOutlet = Outlet::factory()->for($otherBusiness)->create();
        $otherCashier = $this->staffUser(StaffRole::Cashier, $otherBusiness, $otherOutlet);

        $this->thrownBy(AuthorizationException::class, fn () => app(StaffService::class)->update($owner, $otherCashier, [
            'name' => 'Hijacked',
            'email' => $otherCashier->email,
            'role' => 'cashier',
            'outlet_id' => $otherOutlet->id,
        ]));

        $this->assertNotSame('Hijacked', $otherCashier->fresh()->name);
    }

    public function test_service_update_rejects_owner_accounts_and_staff_actors(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->for($owner)->create();
        $outlet = Outlet::factory()->for($business)->create();
        $admin = $this->staffUser(StaffRole::BusinessAdmin, $business);
        $cashier = $this->staffUser(StaffRole::Cashier, $business, $outlet);

        $this->thrownBy(AuthorizationException::class, fn () => app(StaffService::class)->update($owner, $owner, [
            'name' => 'Self',
            'email' => $owner->email,
            'role' => 'business_admin',
        ]));

        $this->thrownBy(AuthorizationException::class, fn () => app(StaffService::class)->update($admin, $cashier, [
            'name' => 'By admin',
            'email' => $cashier->email,
            'role' => 'cashier',
            'outlet_id' => $outlet->id,
        ]));

        $this->assertNull($owner->fresh()->role);
        $this->assertNotSame('By admin', $cashier->fresh()->name);
    }
}
