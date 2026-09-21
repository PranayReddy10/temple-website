<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates the first super admin.
 *
 * The password is never hard-coded. Either supply one through the environment
 * (ADMIN_PASSWORD) or let the seeder generate a random one and print it once.
 * An existing account is left untouched, so re-running the seeder on a live
 * site cannot reset a password that has already been changed.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@example.com');

        if (User::where('email', $email)->exists()) {
            $this->command?->info("Admin user {$email} already exists. Left unchanged.");

            return;
        }

        $password = env('ADMIN_PASSWORD') ?: Str::password(16);

        User::create([
            'name' => env('ADMIN_NAME', 'Super Admin'),
            'email' => $email,
            'password' => $password, // Hashed by the model's 'hashed' cast.
            'role' => UserRole::SuperAdmin,
            'is_active' => true,
        ]);

        $this->command?->newLine();
        $this->command?->info('Super admin created.');
        $this->command?->line("  Email:    {$email}");

        if (env('ADMIN_PASSWORD')) {
            $this->command?->line('  Password: (taken from ADMIN_PASSWORD)');
        } else {
            $this->command?->line("  Password: {$password}");
            $this->command?->warn('  This password is shown once. Sign in and change it now.');
        }

        $this->command?->newLine();
    }
}
