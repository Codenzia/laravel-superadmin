<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Console\Commands;

use Codenzia\SuperAdmin\Support\SuperAdminManager;
use Illuminate\Console\Command;

/**
 * `php artisan superadmin:ensure` — interactive create/update of the
 * protected super admin. DB-only: never reads or writes `.env`. Replaces
 * the v0.3.x `superadmin:setup` command.
 *
 * Usage modes:
 *
 *   - Interactive (no flags): prompts for name, email, password with the
 *     current values (or package defaults for a brand-new account) as
 *     suggested defaults.
 *   - Flag-only: any subset of `--name --email --password` skips the
 *     corresponding prompt.
 *   - Mixed: prompts only for the missing pieces.
 *   - `--from-env`: fully non-interactive. Applies the configured credentials
 *     (`config('superadmin.email')` / `config('superadmin.password')` — i.e.
 *     `SUPER_ADMIN_EMAIL` / `SUPER_ADMIN_PASSWORD` via config, respecting
 *     config caching) to the protected account so deploy pipelines can push
 *     `.env` credential changes without shell-parsing the `.env` themselves.
 *
 * Role is NOT prompted — it is auto-resolved via
 * `SuperAdminManager::configuredRole()` which reads
 * `filament-shield.super_admin.name` when present and falls back to the
 * literal `'super_admin'`.
 */
final class EnsureCommand extends Command
{
    protected $signature = 'superadmin:ensure
        {--name= : Set the super admin name (skips the name prompt)}
        {--email= : Set the super admin email (skips the email prompt; wins over config with --from-env)}
        {--password= : Set the super admin password (skips the password prompt; wins over config with --from-env)}
        {--from-env : Apply the configured SUPER_ADMIN_EMAIL / SUPER_ADMIN_PASSWORD (via config) non-interactively — for deploy pipelines}';

    protected $description = 'Create or update the protected super admin in the database. Never touches .env.';

    public function handle(SuperAdminManager $manager): int
    {
        if ($this->option('from-env') === true) {
            return $this->handleFromEnv($manager);
        }

        $existing = $manager->user();

        // ---- Name ----
        $name = $this->option('name');
        if (! is_string($name) || $name === '') {
            $currentName = $existing?->getAttribute('name');
            $defaultName = is_string($currentName) && $currentName !== ''
                ? $currentName
                : $manager->defaultName();
            $entered = $this->ask('Super admin name', $defaultName);
            $name = is_string($entered) ? $entered : $defaultName;
        }

        // ---- Email ----
        $email = $this->option('email');
        if (! is_string($email) || $email === '') {
            $currentEmail = $existing?->getAttribute('email');
            $defaultEmail = is_string($currentEmail) && $currentEmail !== ''
                ? $currentEmail
                : $manager->defaultEmail();
            $entered = $this->ask('Super admin email', $defaultEmail);
            $email = is_string($entered) ? $entered : $defaultEmail;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('A valid email is required.');

            return self::INVALID;
        }
        $email = mb_strtolower($email);

        // ---- Password ----
        $password = $this->option('password');
        if (! is_string($password) || $password === '') {
            if ($existing !== null) {
                $prompt = 'Super admin password (leave blank to keep current)';
            } else {
                $knownDefault = $manager->knownDefaultPassword();
                $prompt = $knownDefault !== null
                    ? 'Super admin password (leave blank to use default: '.$knownDefault.')'
                    : 'Super admin password (leave blank to generate a random one — claim later via the recovery route)';
            }
            $entered = $this->secret($prompt);
            $password = is_string($entered) ? $entered : '';
        }

        // Brand-new account + blank password → use package default.
        // Existing account + blank password → keep current (null tells
        // SuperAdminManager::install() to skip the password attribute).
        $passwordForEnsure = $password === ''
            ? ($existing === null ? $manager->defaultPassword() : null)
            : $password;

        // ---- Apply ----
        try {
            $user = $manager->ensure([
                'name' => $name,
                'email' => $email,
                'password' => $passwordForEnsure,
            ]);
        } catch (\Throwable $e) {
            $this->error('Failed to update super admin: '.$e->getMessage());

            return self::FAILURE;
        }

        $verb = $existing === null ? 'Created' : 'Updated';
        $this->info('  ✓ '.$verb.' protected super admin: '.$user->getAttribute('email').$this->roleSuffix($manager, $user));

        if ($passwordForEnsure === null) {
            $this->line('  · Password unchanged.');
        }

        return self::SUCCESS;
    }

    /**
     * A `" (role: <name>)"` suffix describing the configured super-admin role
     * and whether the protected account actually holds it — surfaced so the
     * CLI reflects the package's role-ensure behavior. Empty when no role is
     * resolvable / the User model has no Spatie roles.
     */
    private function roleSuffix(SuperAdminManager $manager, object $user): string
    {
        $role = $manager->configuredRole();

        if ($role === null) {
            return '';
        }

        $held = $manager->hasConfiguredRole($user);

        if ($held === null) {
            return '';
        }

        return ' (role: '.$role.($held ? '' : ' — NOT assigned').')';
    }

    /**
     * `--from-env`: apply the configured credentials to the protected account,
     * non-interactively. Create when missing, otherwise UPDATE email + password
     * to match config. Explicit `--email` / `--password` options win over
     * config. Never prompts. Never prints the password.
     */
    private function handleFromEnv(SuperAdminManager $manager): int
    {
        $existing = $manager->user();

        // ---- Email — explicit --email wins over config ----
        $emailOption = $this->option('email');
        $email = is_string($emailOption) && $emailOption !== ''
            ? $emailOption
            : (string) $this->laravel['config']->get('superadmin.email', '');

        if ($email === '') {
            $this->error('No super admin email configured. Set SUPER_ADMIN_EMAIL (config superadmin.email) or pass --email.');

            return self::FAILURE;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('A valid email is required.');

            return self::INVALID;
        }
        $email = mb_strtolower($email);

        // ---- Password — explicit --password wins over config ----
        $passwordOption = $this->option('password');
        $password = is_string($passwordOption) && $passwordOption !== ''
            ? $passwordOption
            : $manager->configuredPassword();

        // Empty password: new account → default-password path; existing
        // account → keep the current hash (null skips the password attribute).
        $passwordForEnsure = ($password === null || $password === '')
            ? ($existing === null ? $manager->defaultPassword() : null)
            : $password;

        // ---- Name — keep existing, else the package default ----
        $currentName = $existing?->getAttribute('name');
        $name = is_string($currentName) && $currentName !== ''
            ? $currentName
            : $manager->defaultName();

        // ---- Apply ----
        try {
            $user = $manager->ensure([
                'name' => $name,
                'email' => $email,
                'password' => $passwordForEnsure,
            ]);
        } catch (\Throwable $e) {
            $this->error('Failed to update super admin: '.$e->getMessage());

            return self::FAILURE;
        }

        $verb = $existing === null
            ? 'created'
            : ($passwordForEnsure === null ? 'unchanged (password kept)' : 'updated');

        $this->info('  ✓ superadmin:ensure --from-env: '.$verb.' — '.$user->getAttribute('email').$this->roleSuffix($manager, $user));

        return self::SUCCESS;
    }
}
