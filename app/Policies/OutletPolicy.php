<?php

namespace App\Policies;

use App\Models\Outlet;
use App\Models\User;

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
        return $this->owns($user, $outlet);
    }

    public function update(User $user, Outlet $outlet): bool
    {
        return $this->owns($user, $outlet);
    }

    public function delete(User $user, Outlet $outlet): bool
    {
        return $this->owns($user, $outlet);
    }

    private function owns(User $user, Outlet $outlet): bool
    {
        return (int) $outlet->business?->user_id === (int) $user->getKey();
    }
}
