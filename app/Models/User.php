<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\StaffRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * An owner account carries no staff assignment at all.
     */
    public function isOwner(): bool
    {
        return $this->role === null
            && $this->business_id === null
            && $this->outlet_id === null;
    }

    public function staffRole(): ?StaffRole
    {
        return $this->role === null ? null : StaffRole::tryFrom($this->role);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // Owners have no staff assignment. Staff must carry a valid role
        // assignment (the same rules the database enforces). Which pages a
        // role may open is decided per page and per resource.
        if ($this->isOwner()) {
            return true;
        }

        $role = $this->staffRole();

        if ($role === null || $this->business_id === null) {
            return false;
        }

        return $role === StaffRole::BusinessAdmin
            ? $this->outlet_id === null
            : $this->outlet_id !== null;
    }
}
