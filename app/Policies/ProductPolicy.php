<?php

namespace App\Policies;

use App\Enums\BusinessAbility;
use App\Models\Product;
use App\Models\User;
use App\Support\BusinessAccess;

class ProductPolicy
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

    public function view(User $user, Product $product): bool
    {
        return $this->manages($user, $product);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->manages($user, $product);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->manages($user, $product);
    }

    private function manages(User $user, Product $product): bool
    {
        return app(BusinessAccess::class)->can(
            $user,
            BusinessAbility::ManageCatalog,
            (int) $product->business_id,
        );
    }
}
