<?php

namespace App\Policies;

use App\Enums\BusinessAbility;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\BusinessAccess;

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
        return $this->manages($user, $variant);
    }

    public function update(User $user, ProductVariant $variant): bool
    {
        return $this->manages($user, $variant);
    }

    public function delete(User $user, ProductVariant $variant): bool
    {
        return $this->manages($user, $variant);
    }

    private function manages(User $user, ProductVariant $variant): bool
    {
        return app(BusinessAccess::class)->can(
            $user,
            BusinessAbility::ManageCatalog,
            (int) $variant->product?->business_id,
        );
    }
}
