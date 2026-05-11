<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Console\Commands;

use Codenzia\SuperAdmin\Support\SuperAdminManager;
use Illuminate\Console\Command;

final class StatusCommand extends Command
{
    protected $signature = 'superadmin:status';

    protected $description = 'Show the current super admin configuration and account status.';

    public function handle(SuperAdminManager $manager): int
    {
        $email = $manager->email();
        $exists = $manager->exists();
        $user = $exists ? $manager->user() : null;

        $rows = [
            ['Configured email', $email ?? '<not set>'],
            ['User model', $manager->userModel() ?? '<not resolved>'],
            ['Account exists', $exists ? 'yes' : 'NO'],
        ];

        if ($user !== null) {
            $rows[] = ['User ID', (string) $user->getKey()];
            $rows[] = ['is_protected flag', $user->getAttribute('is_protected') ? 'true' : 'false'];

            $createdAt = $user->getAttribute('created_at');
            if ($createdAt !== null) {
                $rows[] = ['Created at', (string) $createdAt];
            }
        }

        $rows[] = ['Protection enabled', config('superadmin.protection.enabled', true) ? 'yes' : 'NO'];
        $rows[] = ['Role', (string) (config('superadmin.role') ?? '<none>')];

        $this->table(['Setting', 'Value'], $rows);

        if (! $manager->isConfigured()) {
            $this->line('');
            $this->warn('Super admin is not yet installed. Run `php artisan superadmin:install`.');

            return self::FAILURE;
        }

        if (! $exists) {
            $this->line('');
            $this->warn('Super admin is configured but the user record is missing. Run `php artisan superadmin:install`.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
