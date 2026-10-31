<?php

namespace App\Support;

use App\Enums\BusinessAbility;
use App\Enums\StaffRole;
use App\Models\Business;
use App\Models\Outlet;
use App\Models\User;

class BusinessAccess
{
    /**
     * Determine whether a user may perform an ability within a business
     * and, when supplied, a specific outlet.
     */
    public function can(
        User $user,
        BusinessAbility $ability,
        int $businessId,
        ?int $outletId = null,
    ): bool {
        $ownsBusiness = Business::query()
            ->whereKey($businessId)
            ->where('user_id', $user->getKey())
            ->exists();

        if ($outletId !== null) {
            $outletBelongsToBusiness = Outlet::query()
                ->whereKey($outletId)
                ->where('business_id', $businessId)
                ->exists();

            if (! $outletBelongsToBusiness) {
                return false;
            }
        }

        if ($ownsBusiness) {
            if (
                $user->role !== null
                || $user->business_id !== null
                || $user->outlet_id !== null
            ) {
                return false;
            }

            return match ($ability) {
                BusinessAbility::ManageBusiness,
                BusinessAbility::ManageCatalog,
                BusinessAbility::ManageOutlet,
                BusinessAbility::ManageStock,
                BusinessAbility::CreateOrder,
                BusinessAbility::ManagePayment,
                BusinessAbility::ViewOrders,
                BusinessAbility::CancelOrder,
                BusinessAbility::ViewKitchen,
                BusinessAbility::ViewAnalytics => true,
            };
        }

        if (
            $user->business_id === null
            || (int) $user->business_id !== $businessId
            || $user->role === null
        ) {
            return false;
        }

        $role = StaffRole::tryFrom($user->role);

        if ($role === null) {
            return false;
        }

        if ($role === StaffRole::BusinessAdmin) {
            return match ($ability) {
                BusinessAbility::ManageCatalog,
                BusinessAbility::ManageOutlet => $outletId === null,

                BusinessAbility::ManageStock,
                BusinessAbility::ViewOrders,
                BusinessAbility::CancelOrder => true,

                default => false,
            };
        }

        if ($outletId === null || $user->outlet_id === null) {
            return false;
        }

        if ((int) $user->outlet_id !== $outletId) {
            return false;
        }

        return match ($role) {
            StaffRole::Cashier => in_array($ability, [
                BusinessAbility::CreateOrder,
                BusinessAbility::ManagePayment,
                BusinessAbility::ViewOrders,
                BusinessAbility::CancelOrder,
            ], true),

            StaffRole::Kitchen => $ability === BusinessAbility::ViewKitchen,

            StaffRole::BusinessAdmin => false,
        };
    }

    /**
     * Return business IDs where the user has a business-level ability.
     *
     * Outlet-scoped abilities should use can() with a concrete outlet ID.
     *
     * @return list<int>
     */
    public function businessIdsFor(
        User $user,
        BusinessAbility $ability,
    ): array {
        $ownedBusinessIds = Business::query()
            ->where('user_id', $user->getKey())
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($ownedBusinessIds !== []) {
            if (
                $user->role !== null
                || $user->business_id !== null
                || $user->outlet_id !== null
            ) {
                return [];
            }

            $ownerAbilities = [
                BusinessAbility::ManageBusiness,
                BusinessAbility::ManageCatalog,
                BusinessAbility::ManageOutlet,
                BusinessAbility::ManageStock,
                BusinessAbility::CreateOrder,
                BusinessAbility::ManagePayment,
                BusinessAbility::ViewOrders,
                BusinessAbility::CancelOrder,
                BusinessAbility::ViewKitchen,
                BusinessAbility::ViewAnalytics,
            ];

            return in_array($ability, $ownerAbilities, true)
                ? $ownedBusinessIds
                : [];
        }

        if ($user->business_id === null || $user->role === null) {
            return [];
        }

        $role = StaffRole::tryFrom($user->role);

        if ($role !== StaffRole::BusinessAdmin) {
            return [];
        }

        $allowed = match ($ability) {
            BusinessAbility::ManageCatalog,
            BusinessAbility::ManageOutlet,
            BusinessAbility::ManageStock,
            BusinessAbility::ViewOrders,
            BusinessAbility::CancelOrder => true,

            default => false,
        };

        return $allowed ? [(int) $user->business_id] : [];
    }
}
