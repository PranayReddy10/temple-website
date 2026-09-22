<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * The post-pull sequence, in the right order, with the easy mistakes checked.
 *
 * Every step here is one a deploy needs anyway. Having them in one command
 * exists because the failure modes are silent and confusing: a pending
 * migration surfaces as "table doesn't exist" on a page that worked
 * yesterday, and a stale autoloader as "undefined function" on a helper that
 * is plainly in the repository.
 */
class DeployCommand extends Command
{
    protected $signature = 'app:deploy
        {--force : Skip the confirmation prompt, for scripted deploys}
        {--check : Report what would happen and change nothing}';

    protected $description = 'Run pending migrations and rebuild caches after pulling new code';

    public function handle(Migrator $migrator): int
    {
        $this->line('');
        $this->components->info('Deploy check for '.config('app.env'));

        if (! $this->autoloaderIsCurrent()) {
            $this->components->error('The autoloader is stale.');
            $this->line('  A helper this app calls at render time is missing, so pages will');
            $this->line('  fail with "undefined function" even though the file is present.');
            $this->line('');
            $this->line('  Run this first, then try again:');
            $this->line('    composer install --no-dev --optimize-autoloader');
            $this->line('');

            return self::FAILURE;
        }

        $pending = $this->pendingMigrations($migrator);

        if ($pending === null) {
            $this->components->error('Could not reach the database. Check the DB_ settings in .env.');

            return self::FAILURE;
        }

        if ($pending === []) {
            $this->components->info('Database is up to date.');
        } else {
            $this->components->warn(count($pending).' migration(s) pending:');
            foreach ($pending as $migration) {
                $this->line('    '.$migration);
            }
        }

        if ($this->option('check')) {
            $this->line('');
            $this->components->info('Check only. Nothing was changed.');

            return self::SUCCESS;
        }

        if ($pending !== [] && ! $this->option('force') && ! $this->confirm('Run these migrations now?', true)) {
            $this->components->warn('Stopped. Caches were not rebuilt either.');

            return self::FAILURE;
        }

        if ($pending !== []) {
            $this->call('migrate', ['--force' => true]);
        }

        $this->rebuildCaches();
        $this->ensureStorageLink();

        $this->line('');
        $this->components->info('Deploy complete.');

        return self::SUCCESS;
    }

    /**
     * Whether composer's autoloader knows about this app's helper functions.
     *
     * A `git pull` alone does not regenerate it, so a newly added entry in
     * composer.json's autoload.files is absent until composer runs.
     */
    protected function autoloaderIsCurrent(): bool
    {
        return function_exists('setting');
    }

    /**
     * @return array<int, string>|null  Null when the database is unreachable.
     */
    protected function pendingMigrations(Migrator $migrator): ?array
    {
        try {
            if (! $migrator->repositoryExists()) {
                // A database with no migrations table at all: everything is
                // pending, which `migrate` handles by creating it first.
                return ['(all — the migrations table does not exist yet)'];
            }

            $run = $migrator->getRepository()->getRan();

            return collect($migrator->getMigrationFiles($migrator->paths() ?: [database_path('migrations')]))
                ->keys()
                ->reject(fn (string $migration): bool => in_array($migration, $run, true))
                ->values()
                ->all();
        } catch (Throwable) {
            return null;
        }
    }

    protected function rebuildCaches(): void
    {
        // Cleared before caching: a stale config cache is the usual reason a
        // .env change appears to do nothing.
        foreach (['config:clear', 'route:clear', 'view:clear'] as $command) {
            $this->callSilently($command);
        }

        if (app()->environment('production')) {
            foreach (['config:cache', 'route:cache', 'view:cache'] as $command) {
                $this->callSilently($command);
            }

            $this->components->info('Caches rebuilt.');

            return;
        }

        // Caching config outside production makes local .env edits confusing.
        $this->components->info('Caches cleared (not re-cached outside production).');
    }

    protected function ensureStorageLink(): void
    {
        if (File::exists(public_path('storage'))) {
            return;
        }

        try {
            $this->callSilently('storage:link');
            $this->components->info('Created the public/storage link.');
        } catch (Throwable) {
            // Some shared hosts disallow symlinks. Not fatal: it only affects
            // locally stored media, and production serves media from Spaces.
            $this->components->warn('Could not create public/storage. Only affects locally stored media.');
        }
    }
}
