<?php

namespace App\Policies;

use App\Models\StockLevel;
use App\Models\User;

/**
 * Stock levels are read-only for resources: StockService is the only writer.
 */
class StockLevelPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, StockLevel $level): bool
    {
        return $this->owns($user, $level);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, StockLevel $level): bool
    {
        return false;
    }

    public function delete(User $user, StockLevel $level): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function owns(User $user, StockLevel $level): bool
    {
        return (int) $level->outlet?->business?->user_id === (int) $user->getKey();
    }
}
