<?php

namespace App\Filament\Support;

use App\Models\User;

/**
 * Keeps a resource closed to everyone except owner accounts.
 *
 * Record-level checks stay in the policies and in getEloquentQuery(); this only
 * closes the list, create and bulk-delete entry points that the policies leave
 * open for any authenticated user.
 */
trait OwnerOnlyResource
{
    public static function canViewAny(): bool
    {
        return static::isOwnerAccount() && parent::canViewAny();
    }

    public static function canCreate(): bool
    {
        return static::isOwnerAccount() && parent::canCreate();
    }

    public static function canDeleteAny(): bool
    {
        return static::isOwnerAccount() && parent::canDeleteAny();
    }

    protected static function isOwnerAccount(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isOwner();
    }
}
