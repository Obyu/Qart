<?php

namespace App\Policies;

use App\Models\Business;
use App\Models\User;

class BusinessPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->ownsAnyBusiness($user) || $this->canCreateBusiness($user);
    }

    public function create(User $user): bool
    {
        return $this->canCreateBusiness($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->ownsAnyBusiness($user);
    }

    public function view(User $user, Business $business): bool
    {
        return $this->owns($user, $business);
    }

    public function update(User $user, Business $business): bool
    {
        return $this->owns($user, $business);
    }

    public function delete(User $user, Business $business): bool
    {
        return $this->owns($user, $business);
    }

    private function owns(User $user, Business $business): bool
    {
        return (int) $business->user_id === (int) $user->getKey();
    }

    private function ownsAnyBusiness(User $user): bool
    {
        return Business::query()
            ->where('user_id', $user->getKey())
            ->exists();
    }

    private function canCreateBusiness(User $user): bool
    {
        return $user->role === null
            && $user->business_id === null
            && $user->outlet_id === null;
    }
}
