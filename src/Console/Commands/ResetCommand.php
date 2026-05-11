<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Console\Commands;

use Codenzia\SuperAdmin\Console\Concerns\VendorCommandFriction;
use Codenzia\SuperAdmin\Support\SuperAdminManager;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class ResetCommand extends Command
{
    use VendorCommandFriction;

    protected $signature = 'superadmin:reset
        {--password= : New password (random 24-char password generated if omitted)}
        {--confirm : Explicit confirmation flag (required by default for vendor commands)}';

    protected $description = 'Reset the protected super admin password. Vendor-only: requires --confirm and a typed phrase. Every invocation is loudly audited.';

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
        $this->info('=== Codenzia Super Admin Reset ===');
        $this->warn('This is a vendor-only command. Every invocation is logged and notifies the package vendor.');
        $this->line('');

        if (! $manager->exists()) {
            $this->error('No protected super admin exists. Run `php artisan superadmin:install` first.');

            return self::FAILURE;
        }

        if (! $this->applyFriction()) {
            return self::FAILURE;
        }

        $password = $this->option('password');
        $generated = false;

        if (! is_string($password) || $password === '') {
            $password = Str::password(24, symbols: true);
            $generated = true;
        }

        if (mb_strlen($password) < 12) {
            $this->error('Password must be at least 12 characters.');

            return self::FAILURE;
        }

        $user = $manager->resetPassword($password);
        $roleResult = $manager->assignRole($user);

        $email = (string) $user->getAttribute('email');
        $this->announceInvocation($email);

        $this->line('');
        $this->info('✓ Super admin password reset.');
        $this->line('  Email:    '.$email);
        $this->line('  User ID:  '.$user->getKey());

        $role = $manager->configuredRole() ?? '';
        if ($roleResult->isProblem()) {
            $this->line('');
            $this->warn('  Role: '.$roleResult->describe($role));
            $this->warn('  Run your role seeder, then: php artisan superadmin:assign-role --confirm');
        } else {
            $this->line('  Role: '.$roleResult->describe($role));
        }

        if ($generated) {
            $this->line('');
            $this->warn('Generated password (copy now — not shown again):');
            $this->line('  '.$password);
            $this->line('');
        }

        return self::SUCCESS;
    }
}
