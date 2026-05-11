<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Console\Commands;

use Codenzia\SuperAdmin\Support\SuperAdminManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class DoctorCommand extends Command
{
    protected $signature = 'superadmin:doctor';

    protected $description = 'Diagnose super admin configuration health. Exits non-zero if anything is misconfigured.';

    public function handle(SuperAdminManager $manager): int
    {
        $rows = [];
        $problems = [];

        // 1. Email configured?
        $email = $manager->email();
        if ($email === null) {
            $rows[] = ['SUPER_ADMIN_EMAIL', 'MISSING', '✗'];
            $problems[] = 'SUPER_ADMIN_EMAIL is not set. The observer cannot identify the protected account.';
        } else {
            $rows[] = ['SUPER_ADMIN_EMAIL', $email, '✓'];
        }

        // 2. User model resolvable?
        $model = $manager->userModel();
        if ($model === null) {
            $rows[] = ['User model', 'unresolved', '✗'];
            $problems[] = 'User model not resolvable from auth.providers.users.model.';
        } else {
            $rows[] = ['User model', $model, '✓'];
        }

        // 3. is_protected column exists?
        $columnExists = false;
        if ($model !== null) {
            try {
                $instance = new $model;
                $columnExists = Schema::hasColumn($instance->getTable(), 'is_protected');
            } catch (\Throwable) {
                $columnExists = false;
            }
        }

        if (! $columnExists) {
            $rows[] = ['is_protected column on users', 'MISSING', '✗'];
            $problems[] = 'users.is_protected column missing. Run `php artisan migrate` to add it.';
        } else {
            $rows[] = ['is_protected column on users', 'exists', '✓'];
        }

        // 4. Protection enabled?
        $protectionEnabled = (bool) config('superadmin.protection.enabled', true);
        $rows[] = ['Protection enabled', $protectionEnabled ? 'yes' : 'NO', $protectionEnabled ? '✓' : '✗'];

        if (! $protectionEnabled) {
            $problems[] = 'Protection is disabled (superadmin.protection.enabled = false). The observer will not run.';
        }

        // 5. Protected user exists?
        $user = null;
        if ($email !== null && $model !== null) {
            $user = $manager->user();
        }

        if ($user === null) {
            $rows[] = ['Protected user exists', 'NO', '✗'];
            $problems[] = 'No protected user found. Run `php artisan superadmin:install`.';
        } else {
            $rows[] = ['Protected user exists', 'yes (ID '.$user->getKey().')', '✓'];

            // 6. Is the protected user's is_protected flag set?
            if ($columnExists) {
                $flag = (bool) $user->getAttribute('is_protected');
                $rows[] = ['Protected user is_protected = true', $flag ? 'yes' : 'NO', $flag ? '✓' : '✗'];

                if (! $flag) {
                    $problems[] = 'The protected user has is_protected = false. Re-run install or update the column manually.';
                }
            }

            // 7. Email match?
            if ($email !== null) {
                $userEmail = mb_strtolower((string) $user->getAttribute('email'));
                $matches = $userEmail === $email;
                $rows[] = ['Email matches SUPER_ADMIN_EMAIL', $matches ? 'yes' : 'NO', $matches ? '✓' : '✗'];

                if (! $matches) {
                    $problems[] = 'The protected user email does not match SUPER_ADMIN_EMAIL. Identification will rely on the is_protected flag only.';
                }
            }
        }

        // 8. Authorization mode
        $gateBefore = (bool) config('superadmin.authorization.gate_before', true);
        $autoAssignRole = (bool) config('superadmin.authorization.assign_role', true);

        $mode = match (true) {
            $gateBefore && $autoAssignRole => 'gate_before + assign_role (default)',
            $gateBefore && ! $autoAssignRole => 'gate_before only (no Spatie integration)',
            ! $gateBefore && $autoAssignRole => 'assign_role only (Shield/policies handle auth)',
            default => 'manual (project owns authorization)',
        };
        $rows[] = ['Authorization mode', $mode, '✓'];

        // 9. Role assignment status — only relevant when assign_role is enabled
        $role = $manager->configuredRole();

        if ($autoAssignRole && $role !== null && $user !== null) {
            if (! method_exists($user, 'hasRole')) {
                $rows[] = ['Role assignment', 'skipped — User has no hasRole()', '⚠'];
            } else {
                $hasRole = $manager->hasConfiguredRole($user);

                if ($hasRole === true) {
                    $rows[] = ['Role "'.$role.'" assigned', 'yes', '✓'];
                } else {
                    $rows[] = ['Role "'.$role.'" assigned', 'NO', '✗'];

                    if ($gateBefore) {
                        // Gate::before still authorizes — role mismatch is cosmetic
                        $problems[] = 'Protected user does not have role "'.$role.'". Authorization still works via Gate::before, but Spatie/Shield integration (display, hasRole() checks) will be off. Fix: ensure the role exists, then `php artisan superadmin:assign-role --confirm`.';
                    } else {
                        // No Gate::before fallback — role is load-bearing
                        $problems[] = 'Protected user does not have role "'.$role.'" AND gate_before is disabled. The super admin cannot authorize anything. Fix: ensure the role exists, then `php artisan superadmin:assign-role --confirm`.';
                    }
                }
            }
        }

        // 9. Notifications configured?
        $notifyEnabled = (bool) config('superadmin.notifications.enabled', true);
        $mailTo = config('superadmin.notifications.mail_to');
        $slackHook = config('superadmin.notifications.slack_webhook');
        $hasRecipient = (is_string($mailTo) && $mailTo !== '') || (is_string($slackHook) && $slackHook !== '');

        $rows[] = ['Vendor notifications', $notifyEnabled && $hasRecipient ? 'configured' : 'NOT configured', $notifyEnabled && $hasRecipient ? '✓' : '⚠'];

        if (! ($notifyEnabled && $hasRecipient)) {
            $problems[] = 'No notification recipient configured. You will not be alerted if a vendor command is invoked. Set SUPER_ADMIN_NOTIFY_MAIL and/or SUPER_ADMIN_NOTIFY_SLACK.';
        }

        $this->table(['Setting', 'Value', ''], $rows);

        if ($problems !== []) {
            $this->line('');
            $this->error('Problems detected:');
            foreach ($problems as $i => $problem) {
                $this->line('  '.($i + 1).'. '.$problem);
            }
            $this->line('');

            return self::FAILURE;
        }

        $this->line('');
        $this->info('✓ All checks passed.');

        return self::SUCCESS;
    }
}
