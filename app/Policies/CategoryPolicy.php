<?php

namespace App\Policies;

use App\Enums\BusinessAbility;
use App\Models\Category;
use App\Models\User;
use App\Support\BusinessAccess;

class CategoryPolicy
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

    public function view(User $user, Category $category): bool
    {
        return $this->manages($user, $category);
    }

    public function update(User $user, Category $category): bool
    {
        return $this->manages($user, $category);
    }

    public function delete(User $user, Category $category): bool
    {
        return $this->manages($user, $category);
    }

    private function manages(User $user, Category $category): bool
    {
        return app(BusinessAccess::class)->can(
            $user,
            BusinessAbility::ManageCatalog,
            (int) $category->business_id,
        );
    }
}
