<?php

namespace App\Filament\Support;

use App\Enums\BusinessAbility;
use App\Models\User;
use App\Support\BusinessAccess;

/**
 * Opens a resource to the accounts that BusinessAccess allows to use one
 * business-level ability, and to nobody else.
 *
 * BusinessAccess stays the single source of truth for which businesses an
 * account may act in. The one thing decided here is the entry point: an account
 * without staff attributes is an owner and may open the list and create pages
 * even before its first business exists (the lists are simply empty then).
 * Staff are admitted only through BusinessAccess. Record-level checks stay in
 * the policies and in getEloquentQuery().
 */
trait AuthorizesByBusinessAbility
{
    abstract protected static function requiredAbility(): BusinessAbility;

    /**
     * @return list<int>
     */
    public static function accessibleBusinessIds(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        return app(BusinessAccess::class)->businessIdsFor($user, static::requiredAbility());
    }

    public static function canViewAny(): bool
    {
        return static::mayEnter() && parent::canViewAny();
    }

    public static function canCreate(): bool
    {
        return static::mayEnter() && parent::canCreate();
    }

    public static function canDeleteAny(): bool
    {
        return static::mayEnter() && parent::canDeleteAny();
    }

    protected static function mayEnter(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->isOwner() || static::accessibleBusinessIds() !== [];
    }
}
