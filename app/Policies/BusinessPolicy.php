<?php

namespace App\Policies;

use App\Models\Business;
use App\Models\User;

class BusinessPolicy
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
}
