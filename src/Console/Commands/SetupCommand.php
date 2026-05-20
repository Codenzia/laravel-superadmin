<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Console\Commands;

use Codenzia\SuperAdmin\Support\EnvWriter;
use Codenzia\SuperAdmin\Support\SuperAdminManager;
use Illuminate\Console\Command;

/**
 * `php artisan superadmin:setup` — interactive create/update of the
 * protected super admin. Writes to .env AND updates the DB row.
 *
 * Replaces the old install + reset commands. No friction layer: no
 * --confirm flag, no typed phrase, no vendor notification. This is the
 * one-and-only credential management entry point.
 */
final class SetupCommand extends Command
{
    protected $signature = 'superadmin:setup
        {--email= : Set the super admin email (skips the email prompt)}
        {--password= : Set the super admin password (skips the password prompt)}';

    protected $description = 'Create or update the protected super admin; writes credentials to .env.';

    public function handle(SuperAdminManager $manager): int
    {
        $currentEmail = $manager->email() ?: $manager->defaultEmail();

        // ---- Email ----
        $email = $this->option('email');
        if (! is_string($email) || $email === '') {
            $email = $this->ask('Super admin email', $currentEmail);
        }
        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('A valid email is required.');

            return self::INVALID;
        }
        $email = mb_strtolower($email);

        // ---- Password ----
        $password = $this->option('password');
        if (! is_string($password) || $password === '') {
            // Blank to keep current behavior — only meaningful when an
            // account already exists. For a brand-new account a blank
            // here falls back to the package default (or env override).
            $prompt = $manager->exists()
                ? 'Super admin password (leave blank to keep current)'
                : 'Super admin password (leave blank to use default: '.$manager->defaultPassword().')';
            $entered = $this->secret($prompt);
            $password = is_string($entered) ? $entered : '';
        }

        $keepPassword = $password === '';
        if ($keepPassword && ! $manager->exists()) {
            // Brand new account, no password supplied → use default.
            $password = $manager->defaultPassword();
            $keepPassword = false;
        }

        // ---- Write .env ----
        $envPath = $this->envPath();
        $envChanges = [];

        $envChanges['SUPER_ADMIN_EMAIL'] = $email;
        if (! $keepPassword) {
            $envChanges['SUPER_ADMIN_PASSWORD'] = $password;
        }

        try {
            $written = EnvWriter::setMany($envPath, $envChanges);
        } catch (\Throwable $e) {
            $this->error('Failed to write .env: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach ($written as $key) {
            $this->info("  ✓ Saved {$key} to .env");
        }
        if ($written === []) {
            $this->line('  · .env already in sync; no changes needed.');
        }

        // Push the new values into the live config so the manager picks
        // them up for the DB update below without requiring a process
        // restart.
        config([
            'superadmin.email' => $email,
        ]);
        if (! $keepPassword) {
            config(['superadmin.password' => $password]);
        }

        // ---- Update DB ----
        try {
            $user = $keepPassword
                ? $manager->install(null, $email)
                : $manager->install($password, $email);
        } catch (\Throwable $e) {
            $this->error('Failed to update DB row: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('  ✓ Updated DB row for '.$user->getAttribute('email'));

        if ($keepPassword) {
            $this->line('  · Password unchanged.');
        }

        return self::SUCCESS;
    }

    private function envPath(): string
    {
        // Laravel's Application::environmentFilePath() resolves to .env (or
        // whatever's configured); base_path('.env') is a sane fallback.
        if (function_exists('app') && app()->bound('path.base')) {
            $appInstance = app();
            if (method_exists($appInstance, 'environmentFilePath')) {
                return $appInstance->environmentFilePath();
            }
        }

        return base_path('.env');
    }
}
