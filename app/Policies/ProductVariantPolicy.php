<?php

namespace App\Policies;

use App\Models\ProductVariant;
use App\Models\User;

class ProductVariantPolicy
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

    public function view(User $user, ProductVariant $variant): bool
    {
        return $this->owns($user, $variant);
    }

    public function update(User $user, ProductVariant $variant): bool
    {
        return $this->owns($user, $variant);
    }

    public function delete(User $user, ProductVariant $variant): bool
    {
        return $this->owns($user, $variant);
    }

    private function owns(User $user, ProductVariant $variant): bool
    {
        return (int) $variant->product?->business?->user_id === (int) $user->getKey();
    }
}
