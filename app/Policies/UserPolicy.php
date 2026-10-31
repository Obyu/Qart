<?php

namespace App\Policies;

use App\Enums\BusinessAbility;
use App\Models\User;
use App\Support\BusinessAccess;

/**
 * Only used by the Staff resource: owners manage the staff of their own businesses.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->ownedBusinessIds($user) !== [];
    }

    public function create(User $user): bool
    {
        return $this->ownedBusinessIds($user) !== [];
    }

    public function view(User $user, User $staff): bool
    {
        return $this->manages($user, $staff);
    }

    public function update(User $user, User $staff): bool
    {
        return $this->manages($user, $staff);
    }

    public function delete(User $user, User $staff): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function manages(User $actor, User $staff): bool
    {
        if ($staff->role === null || $staff->business_id === null || $actor->is($staff)) {
            return false;
        }

        return app(BusinessAccess::class)->can(
            $actor,
            BusinessAbility::ManageBusiness,
            (int) $staff->business_id,
        );
    }

    /**
     * @return list<int>
     */
    private function ownedBusinessIds(User $user): array
    {
        return app(BusinessAccess::class)->businessIdsFor($user, BusinessAbility::ManageBusiness);
    }
}
