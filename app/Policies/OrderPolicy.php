<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/**
 * Ownership only: a user may act on the orders of the businesses they own.
 *
 * This is not the only defence. OrderService re-validates the business, outlet and
 * variant on the server, and enforces the order status rules. Orders cannot be
 * deleted in this task, so delete and deleteAny are denied to everyone.
 */
class OrderPolicy
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
        return false;
    }

    public function view(User $user, Order $order): bool
    {
        return $this->owns($user, $order);
    }

    public function update(User $user, Order $order): bool
    {
        return $this->owns($user, $order);
    }

    public function complete(User $user, Order $order): bool
    {
        return $this->owns($user, $order);
    }

    public function delete(User $user, Order $order): bool
    {
        return false;
    }

    private function owns(User $user, Order $order): bool
    {
        return (int) $order->business?->user_id === (int) $user->getKey();
    }
}
