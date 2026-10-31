<?php

namespace Database\Seeders;

use App\Enums\StaffRole;
use App\Models\User;
use App\Services\StaffService;
use Illuminate\Database\Seeder;

/**
 * Demo staff accounts for the "Kopi Senja" business created by DemoSeeder.
 * Safe to run more than once: existing emails are skipped. Demo use only.
 */
class DemoStaffSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::query()->where('email', 'joby@gmail.com')->first();
        $business = $owner?->businesses()->where('name', 'Kopi Senja')->first();
        $outlet = $business?->outlets()->where('name', 'Outlet Utama')->first();

        if ($owner === null || $business === null || $outlet === null) {
            $this->command?->error('Owner joby@gmail.com / Kopi Senja / Outlet Utama not found. Run DemoSeeder first.');

            return;
        }

        $accounts = [
            ['Admin Senja', 'admin@kopisenja.test', StaffRole::BusinessAdmin, null],
            ['Kasir Senja', 'kasir@kopisenja.test', StaffRole::Cashier, $outlet->id],
            ['Dapur Senja', 'dapur@kopisenja.test', StaffRole::Kitchen, $outlet->id],
        ];

        $service = new StaffService;

        foreach ($accounts as [$name, $email, $role, $outletId]) {
            if (User::query()->where('email', $email)->exists()) {
                $this->command?->info("Skipped (exists): {$email}");

                continue;
            }

            $service->create($owner, [
                'name' => $name,
                'email' => $email,
                'password' => 'password',
                'business_id' => $business->id,
                'role' => $role->value,
                'outlet_id' => $outletId,
            ]);

            $this->command?->info("Created: {$email} ({$role->value})");
        }
    }
}
