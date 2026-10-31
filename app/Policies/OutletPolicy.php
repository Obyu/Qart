<?php

namespace App\Policies;

use App\Enums\BusinessAbility;
use App\Models\Outlet;
use App\Models\User;
use App\Support\BusinessAccess;

class OutletPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function deleteAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Outlet $outlet): bool
    {
        return $this->manages($user, $outlet);
    }

    public function update(User $user, Outlet $outlet): bool
    {
        return $this->manages($user, $outlet);
    }

    public function delete(User $user, Outlet $outlet): bool
    {
        return $this->manages($user, $outlet);
    }

    /**
     * ManageOutlet is a business-level ability, so the outlet id is deliberately
     * not passed: BusinessAccess grants it to a business admin only without one.
     */
    private function manages(User $user, Outlet $outlet): bool
    {
        return app(BusinessAccess::class)->can(
            $user,
            BusinessAbility::ManageOutlet,
            (int) $outlet->business_id,
        );
    }
}
