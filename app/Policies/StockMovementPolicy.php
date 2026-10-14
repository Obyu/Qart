<?php

namespace App\Policies;

use App\Models\StockMovement;
use App\Models\User;

/**
 * The movement ledger is immutable: it is read-only for resources and is
 * written only by StockService.
 */
class StockMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, StockMovement $movement): bool
    {
        return $this->owns($user, $movement);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, StockMovement $movement): bool
    {
        return false;
    }

    public function delete(User $user, StockMovement $movement): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function owns(User $user, StockMovement $movement): bool
    {
        return (int) $movement->outlet?->business?->user_id === (int) $user->getKey();
    }
}
