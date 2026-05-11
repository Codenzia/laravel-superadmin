<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Console\Commands;

use Codenzia\SuperAdmin\Console\Concerns\VendorCommandFriction;
use Codenzia\SuperAdmin\Support\SuperAdminManager;
use Illuminate\Console\Command;

final class AssignRoleCommand extends Command
{
    use VendorCommandFriction;

    protected $signature = 'superadmin:assign-role
        {--confirm : Explicit confirmation flag (required by default for vendor commands)}';

    protected $description = 'Re-attempt assigning the configured role to the protected super admin. Use after running shield:install or your role seeder when initial install ran before the role existed.';

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
        $this->info('=== Codenzia Super Admin: Assign Role ===');
        $this->warn('This is a vendor-only command. Every invocation is logged and notifies the package vendor.');
        $this->line('');

        $user = $manager->user();

        if ($user === null) {
            $this->error('No protected super admin exists. Run `php artisan superadmin:install` first.');

            return self::FAILURE;
        }

        $role = $manager->configuredRole();

        if ($role === null) {
            $this->error('No role is configured (superadmin.role is null). Nothing to assign.');

            return self::FAILURE;
        }

        if (! $this->applyFriction()) {
            return self::FAILURE;
        }

        // Explicit user invocation: honor it regardless of the
        // authorization.assign_role flag.
        $result = $manager->assignRole($user, respectFlag: false);

        $email = (string) $user->getAttribute('email');
        $this->announceInvocation($email);

        $this->line('');

        if ($result->isProblem()) {
            $this->error('✗ '.$result->describe($role));
            $this->warn('Check that the role "'.$role.'" exists in your roles table. Typical fixes:');
            $this->warn('  - php artisan shield:install');
            $this->warn('  - php artisan db:seed --class=RolesAndPermissionsSeeder');

            return self::FAILURE;
        }

        $this->info('✓ '.$result->describe($role));
        $this->line('  Email:    '.$email);
        $this->line('  User ID:  '.$user->getKey());

        return self::SUCCESS;
    }
}
