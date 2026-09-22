<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password as promptPassword;
use function Laravel\Prompts\text;

/**
 * Creates an admin account, or resets the password of an existing one.
 *
 * This is the supported way to get into the panel. It writes to the database
 * directly, so the password you type is the password that works — unlike a
 * generated SQL dump, where the plaintext exists only in the console of
 * whoever generated the file and is lost to anyone who merely imports it.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'admin:create
        {email? : Email address of the account}
        {--name= : Display name, for a new account}
        {--password= : Password. Omit to be prompted without it appearing on screen}
        {--role=super_admin : super_admin or editor}';

    protected $description = 'Create an admin account or reset an existing one\'s password';

    public function handle(): int
    {
        $this->showExistingAccounts();

        $email = $this->argument('email') ?: text(
            label: 'Email address',
            placeholder: 'you@example.com',
            required: true,
        );

        $role = UserRole::tryFrom((string) $this->option('role'));

        if ($role === null) {
            $this->error('Unknown role. Use super_admin or editor.');

            return self::FAILURE;
        }

        // Prompted rather than passed as an option by default: an option ends up
        // in shell history and in the process list, where other users on a
        // shared host can read it.
        $password = $this->option('password') ?: promptPassword(
            label: 'Password',
            hint: 'At least 12 characters. Nothing is echoed as you type.',
            required: true,
        );

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email'], 'password' => ['required', 'string', 'min:12']],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $existing = User::where('email', $email)->first();

        if ($existing !== null) {
            $existing->forceFill([
                'password' => $password, // Hashed by the model's 'hashed' cast.
                'role' => $role,
                'is_active' => true,
            ])->save();

            $this->newLine();
            $this->info("Password reset for {$email}.");
            $this->line('  Role:   '.$role->getLabel());
            $this->line('  Status: active');
            $this->newLine();
            $this->line('Sign in at '.rtrim(config('app.url'), '/').'/admin');

            return self::SUCCESS;
        }

        User::create([
            'name' => $this->option('name') ?: 'Super Admin',
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'is_active' => true,
        ]);

        $this->newLine();
        $this->info("Created {$email}.");
        $this->line('  Role: '.$role->getLabel());
        $this->newLine();
        $this->line('Sign in at '.rtrim(config('app.url'), '/').'/admin');

        return self::SUCCESS;
    }

    /**
     * Shows which accounts actually exist.
     *
     * Typing the wrong address is indistinguishable from typing the wrong
     * password — both give "These credentials do not match our records" — so
     * the list is printed before anything is asked.
     */
    protected function showExistingAccounts(): void
    {
        $users = User::orderBy('email')->get(['email', 'role', 'is_active', 'last_login_at']);

        if ($users->isEmpty()) {
            $this->warn('No accounts exist yet. This will create the first one.');
            $this->newLine();

            return;
        }

        $this->line('Existing accounts:');
        $this->table(
            ['Email', 'Role', 'Active', 'Last sign-in'],
            $users->map(fn (User $user): array => [
                $user->email,
                $user->role?->getLabel() ?? '—',
                $user->is_active ? 'yes' : 'no',
                $user->last_login_at?->diffForHumans() ?? 'never',
            ])->all(),
        );
        $this->newLine();
    }
}
