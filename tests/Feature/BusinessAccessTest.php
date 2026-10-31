<?php

namespace Tests\Feature;

use App\Enums\BusinessAbility;
use App\Enums\StaffRole;
use App\Models\Business;
use App\Models\Outlet;
use App\Models\User;
use App\Support\BusinessAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessAccessTest extends TestCase
{
    use RefreshDatabase;

    private BusinessAccess $access;

    protected function setUp(): void
    {
        parent::setUp();

        $this->access = app(BusinessAccess::class);
    }

    public function test_owner_can_access_their_business_and_not_another_business(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();

        $business = Business::factory()->for($owner)->create();
        $otherBusiness = Business::factory()->for($otherOwner)->create();

        $this->assertTrue(
            $this->access->can($owner, BusinessAbility::ManageCatalog, $business->id)
        );

        $this->assertFalse(
            $this->access->can($owner, BusinessAbility::ManageCatalog, $otherBusiness->id)
        );
    }

    public function test_business_admin_can_manage_catalog_only_in_assigned_business(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();

        $business = Business::factory()->for($owner)->create();
        $otherBusiness = Business::factory()->for($otherOwner)->create();

        $admin = User::factory()->create();

        $admin->forceFill([
            'role' => StaffRole::BusinessAdmin->value,
            'business_id' => $business->id,
            'outlet_id' => null,
        ])->save();

        $this->assertTrue(
            $this->access->can($admin, BusinessAbility::ManageCatalog, $business->id)
        );

        $this->assertFalse(
            $this->access->can($admin, BusinessAbility::ManageCatalog, $otherBusiness->id)
        );

        $this->assertFalse(
            $this->access->can($admin, BusinessAbility::ManagePayment, $business->id)
        );
    }

    public function test_cashier_is_limited_to_assigned_outlet_and_transaction_abilities(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->for($owner)->create();
        $assignedOutlet = Outlet::factory()->for($business)->create();
        $otherOutlet = Outlet::factory()->for($business)->create();

        $cashier = User::factory()->create();

        $cashier->forceFill([
            'role' => StaffRole::Cashier->value,
            'business_id' => $business->id,
            'outlet_id' => $assignedOutlet->id,
        ])->save();

        $this->assertTrue(
            $this->access->can(
                $cashier,
                BusinessAbility::CreateOrder,
                $business->id,
                $assignedOutlet->id,
            )
        );

        $this->assertFalse(
            $this->access->can(
                $cashier,
                BusinessAbility::CreateOrder,
                $business->id,
                $otherOutlet->id,
            )
        );

        $this->assertFalse(
            $this->access->can(
                $cashier,
                BusinessAbility::ManageStock,
                $business->id,
                $assignedOutlet->id,
            )
        );

        $this->assertFalse(
            $this->access->can($cashier, BusinessAbility::CreateOrder, $business->id)
        );
    }

    public function test_kitchen_can_view_kitchen_only_at_assigned_outlet(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->for($owner)->create();
        $assignedOutlet = Outlet::factory()->for($business)->create();
        $otherOutlet = Outlet::factory()->for($business)->create();

        $kitchen = User::factory()->create();

        $kitchen->forceFill([
            'role' => StaffRole::Kitchen->value,
            'business_id' => $business->id,
            'outlet_id' => $assignedOutlet->id,
        ])->save();

        $this->assertTrue(
            $this->access->can(
                $kitchen,
                BusinessAbility::ViewKitchen,
                $business->id,
                $assignedOutlet->id,
            )
        );

        $this->assertFalse(
            $this->access->can(
                $kitchen,
                BusinessAbility::ViewKitchen,
                $business->id,
                $otherOutlet->id,
            )
        );

        $this->assertFalse(
            $this->access->can(
                $kitchen,
                BusinessAbility::ManagePayment,
                $business->id,
                $assignedOutlet->id,
            )
        );

        $this->assertFalse(
            $this->access->can(
                $kitchen,
                BusinessAbility::ManageStock,
                $business->id,
                $assignedOutlet->id,
            )
        );
    }

    public function test_business_admin_can_access_outlets_in_their_business_only(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();

        $business = Business::factory()->for($owner)->create();
        $otherBusiness = Business::factory()->for($otherOwner)->create();

        $outlet = Outlet::factory()->for($business)->create();
        $otherOutlet = Outlet::factory()->for($otherBusiness)->create();

        $admin = User::factory()->create();

        $admin->forceFill([
            'role' => StaffRole::BusinessAdmin->value,
            'business_id' => $business->id,
            'outlet_id' => null,
        ])->save();

        $this->assertTrue(
            $this->access->can(
                $admin,
                BusinessAbility::ManageStock,
                $business->id,
                $outlet->id,
            )
        );

        $this->assertTrue(
            $this->access->can(
                $admin,
                BusinessAbility::ViewOrders,
                $business->id,
                $outlet->id,
            )
        );

        $this->assertFalse(
            $this->access->can(
                $admin,
                BusinessAbility::ManageStock,
                $otherBusiness->id,
                $otherOutlet->id,
            )
        );
    }

    public function test_owner_cannot_access_an_outlet_from_another_business(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();

        $business = Business::factory()->for($owner)->create();
        $otherBusiness = Business::factory()->for($otherOwner)->create();

        $otherOutlet = Outlet::factory()->for($otherBusiness)->create();

        $this->assertFalse(
            $this->access->can(
                $owner,
                BusinessAbility::ManageStock,
                $business->id,
                $otherOutlet->id,
            )
        );
    }

    public function test_owner_gets_owned_business_ids_for_allowed_abilities(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->for($owner)->create();

        $this->assertSame(
            [$business->id],
            $this->access->businessIdsFor($owner, BusinessAbility::ManageCatalog),
        );
    }

    public function test_staff_user_can_resolve_their_assigned_outlet(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->for($owner)->create();
        $outlet = Outlet::factory()->for($business)->create();

        $staff = User::factory()->create();
        $staff->forceFill([
            'role' => StaffRole::Cashier->value,
            'business_id' => $business->id,
            'outlet_id' => $outlet->id,
        ])->save();

        $this->assertTrue($staff->outlet->is($outlet));
        $this->assertSame($outlet->id, $staff->outlet->id);
    }

    public function test_user_who_owns_a_business_and_has_staff_attributes_is_denied_owner_access(): void
    {
        $owner = User::factory()->create();

        $business = Business::factory()->for($owner)->create();
        $outlet = Outlet::factory()->for($business)->create();

        $owner->forceFill([
            'role' => StaffRole::Cashier->value,
            'business_id' => $business->id,
            'outlet_id' => $outlet->id,
        ])->save();

        $this->assertFalse(
            $this->access->can(
                $owner,
                BusinessAbility::ManageCatalog,
                $business->id,
            ),
        );

        $this->assertSame(
            [],
            $this->access->businessIdsFor(
                $owner,
                BusinessAbility::ManageCatalog,
            ),
        );
    }
}
