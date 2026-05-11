<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Console\Commands;

use Codenzia\SuperAdmin\Console\Concerns\VendorCommandFriction;
use Codenzia\SuperAdmin\Support\RoleAssignmentResult;
use Codenzia\SuperAdmin\Support\SuperAdminManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class InstallCommand extends Command
{
    use VendorCommandFriction;

    protected $signature = 'superadmin:install
        {--email= : The protected super admin email address}
        {--password= : The super admin password (interactive prompt if omitted)}
        {--confirm : Explicit confirmation flag (required by default for vendor commands)}
        {--skip-migration : Do not auto-run the package migration if users.is_protected is missing}';

    protected $description = 'Install the protected super admin account. Vendor-only: refuses to re-run if already installed.';

    public function __construct()
    {
        parent::__construct();

        if ((bool) config('superadmin.vendor_commands.hide_from_list', true)) {
            $this->setHidden(true);
        }
    }

    public function handle(SuperAdminManager $manager): int
    {
        $this->line('');
        $this->info('=== Codenzia Super Admin Installer ===');
        $this->warn('This is a vendor-only command. Every invocation is logged and notifies the package vendor.');
        $this->line('');

        if (! $this->ensurePackageMigrationApplied()) {
            return self::FAILURE;
        }

        if ($manager->exists()) {
            $this->error('A protected super admin already exists. Use `php artisan superadmin:reset` to reset its password.');
            $this->warn('To replace the protected account entirely, the existing user must first be unprotected at the database level (this is an intentional friction barrier).');

            return self::FAILURE;
        }

        if (! $this->applyFriction()) {
            return self::FAILURE;
        }

        $email = $this->resolveEmail();

        if ($email === null) {
            $this->error('A valid email is required.');

            return self::FAILURE;
        }

        $password = $this->resolvePassword();

        if ($password === null) {
            $this->error('A password of at least 12 characters is required.');

            return self::FAILURE;
        }

        config()->set('superadmin.email', $email);

        $user = $manager->install($password, $email);
        $roleResult = $manager->assignRole($user);

        $this->announceInvocation($email);

        $this->line('');
        $this->info('✓ Protected super admin installed.');
        $this->line('  Email:    '.$email);
        $this->line('  User ID:  '.$user->getKey());

        $this->reportRoleResult($manager, $roleResult);

        $this->line('');
        $this->warn('Add this to your .env (so the package can identify the protected account across requests):');
        $this->line('  SUPER_ADMIN_EMAIL='.$email);
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Ensure the package's migration has been applied — specifically the
     * users.is_protected column. If missing, auto-run ONLY the package's
     * own migration (not any other pending application migrations). Set
     * --skip-migration to opt out and handle it manually.
     */
    private function ensurePackageMigrationApplied(): bool
    {
        if (! Schema::hasTable('users')) {
            $this->error('The users table does not exist. Run `php artisan migrate` first to set up your application schema.');

            return false;
        }

        if (Schema::hasColumn('users', 'is_protected')) {
            return true;
        }

        if ($this->option('skip-migration')) {
            $this->error('The users.is_protected column is missing and --skip-migration was passed. Run `php artisan migrate` and try again.');

            return false;
        }

        $this->warn('users.is_protected column is missing — applying the package migration now.');
        $this->line('');

        $migrationsPath = realpath(__DIR__.'/../../../database/migrations');

        if ($migrationsPath === false) {
            $this->error('Could not resolve the package migrations directory. Run `php artisan migrate` manually.');

            return false;
        }

        $exitCode = $this->call('migrate', [
            '--path' => $migrationsPath,
            '--realpath' => true,
            '--force' => true,
        ]);

        if ($exitCode !== 0 || ! Schema::hasColumn('users', 'is_protected')) {
            $this->error('Failed to apply the package migration. Inspect the output above and run `php artisan migrate` manually.');

            return false;
        }

        $this->line('');
        $this->info('✓ Package migration applied.');
        $this->line('');

        return true;
    }

    private function reportRoleResult(SuperAdminManager $manager, RoleAssignmentResult $result): void
    {
        $role = $manager->configuredRole() ?? '';

        $message = $result->describe($role);

        if ($result->isProblem()) {
            $this->line('');
            $this->warn('  Role: '.$message);
            $this->warn('  Run your role seeder (e.g. `php artisan shield:install`), then re-run:');
            $this->warn('     php artisan superadmin:assign-role --confirm');
        } else {
            $this->line('  Role: '.$message);
        }
    }

    private function resolveEmail(): ?string
    {
        $email = $this->option('email');

        if (! is_string($email) || $email === '') {
            $default = $this->guessDefaultEmail();
            $email = $this->ask('Super admin email', $default);
        }

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    private function resolvePassword(): ?string
    {
        $password = $this->option('password');

        if (! is_string($password) || $password === '') {
            $password = $this->secret('Super admin password (min 12 chars)');

            if (! is_string($password) || $password === '') {
                return null;
            }

            $confirm = $this->secret('Confirm password');

            if ($confirm !== $password) {
                $this->error('Passwords do not match.');

                return null;
            }
        }

        if (mb_strlen($password) < 12) {
            return null;
        }

        return $password;
    }

    private function guessDefaultEmail(): string
    {
        $appUrl = (string) config('app.url', 'http://localhost');
        $host = parse_url($appUrl, PHP_URL_HOST) ?: 'example.com';
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        return 'superadmin@'.$host;
    }
}
