<?php

namespace App\Services;

use App\Enums\BusinessAbility;
use App\Enums\StaffRole;
use App\Models\Outlet;
use App\Models\User;
use App\Support\BusinessAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StaffService
{
    private readonly BusinessAccess $access;

    public function __construct(?BusinessAccess $access = null)
    {
        $this->access = $access ?? new BusinessAccess;
    }

    /**
     * Create a staff account inside a business owned by $actor.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function create(User $actor, array $data): User
    {
        return DB::transaction(function () use ($actor, $data): User {
            $businessId = is_numeric($data['business_id'] ?? null)
                ? (int) $data['business_id']
                : 0;

            $this->authorizeBusiness($actor, $businessId);

            $data['email'] = $this->normalizeEmail($data['email'] ?? null);

            $validated = Validator::make($data, [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
                'password' => ['required', 'string', 'min:8', 'max:255'],
                'role' => ['required', Rule::enum(StaffRole::class)],
            ])->validate();

            $role = StaffRole::from($validated['role']);
            $outletId = $this->resolveOutletId($role, $businessId, $data['outlet_id'] ?? null);

            $staff = new User([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);

            $staff->forceFill([
                'role' => $role->value,
                'business_id' => $businessId,
                'outlet_id' => $outletId,
            ])->save();

            return $staff;
        });
    }

    /**
     * Update a staff account. The business of a staff member is fixed once created.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function update(User $actor, User $staff, array $data): User
    {
        return DB::transaction(function () use ($actor, $staff, $data): User {
            $staff = User::query()->lockForUpdate()->find($staff->getKey());

            if (
                $staff === null
                || $staff->role === null
                || $staff->business_id === null
                || $actor->is($staff)
            ) {
                throw new AuthorizationException('This account cannot be managed as staff.');
            }

            $businessId = (int) $staff->business_id;

            $this->authorizeBusiness($actor, $businessId);

            $data['email'] = $this->normalizeEmail($data['email'] ?? null);

            $validated = Validator::make($data, [
                'name' => ['required', 'string', 'max:255'],
                'email' => [
                    'required',
                    'email',
                    'max:255',
                    Rule::unique('users', 'email')->ignore($staff->getKey()),
                ],
                'password' => ['nullable', 'string', 'min:8', 'max:255'],
                'role' => ['required', Rule::enum(StaffRole::class)],
            ])->validate();

            $role = StaffRole::from($validated['role']);
            $outletId = $this->resolveOutletId($role, $businessId, $data['outlet_id'] ?? null);

            $staff->fill([
                'name' => $validated['name'],
                'email' => $validated['email'],
            ]);

            if (filled($validated['password'] ?? null)) {
                $staff->password = $validated['password'];
            }

            $staff->forceFill([
                'role' => $role->value,
                'outlet_id' => $outletId,
            ])->save();

            return $staff;
        });
    }

    private function authorizeBusiness(User $actor, int $businessId): void
    {
        if (! $this->access->can($actor, BusinessAbility::ManageBusiness, $businessId)) {
            throw new AuthorizationException('You are not allowed to manage staff for this business.');
        }
    }

    private function resolveOutletId(StaffRole $role, int $businessId, mixed $outletId): ?int
    {
        if ($role === StaffRole::BusinessAdmin) {
            if (filled($outletId)) {
                throw ValidationException::withMessages([
                    'outlet_id' => 'A Business Admin works across the whole business and cannot be tied to an outlet.',
                ]);
            }

            return null;
        }

        if (blank($outletId)) {
            throw ValidationException::withMessages([
                'outlet_id' => 'Choose an outlet for this role.',
            ]);
        }

        $belongsToBusiness = is_numeric($outletId)
            && Outlet::query()
                ->whereKey((int) $outletId)
                ->where('business_id', $businessId)
                ->exists();

        if (! $belongsToBusiness) {
            throw ValidationException::withMessages([
                'outlet_id' => 'The selected outlet does not belong to this business.',
            ]);
        }

        return (int) $outletId;
    }

    private function normalizeEmail(mixed $email): string
    {
        return is_string($email) ? Str::lower(trim($email)) : '';
    }
}
