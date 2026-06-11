<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Console\Commands;

use Codenzia\SuperAdmin\Support\SuperAdminManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * `php artisan superadmin:status` — at-a-glance summary of the protected
 * super admin's configuration + DB row.
 *
 * Pass `-v` / `--verbose` for the full diagnostic matrix (used to live in
 * the now-removed `superadmin:doctor` command). Verbose mode exits
 * non-zero if any health check fails — suitable for CI / deploy checks.
 */
final class StatusCommand extends Command
{
    protected $signature = 'superadmin:status';

    protected $description = 'Show the current super admin status. Use -v for full health diagnostics.';

    public function handle(SuperAdminManager $manager): int
    {
        $exists = $manager->exists();
        $user = $exists ? $manager->user() : null;
        // Show the actual account's email when it exists; the creation
        // default is only meaningful while the account is still missing.
        $email = $user?->getAttribute('email') ?? ($manager->email() ?: $manager->defaultEmail());
        $verbose = $this->getOutput()->isVerbose();

        $rows = [
            ['Email', $email],
            ['User model', $manager->userModel() ?? '<not resolved>'],
            ['Account exists', $exists ? 'yes' : 'NO'],
        ];

        if ($user !== null) {
            $rows[] = ['User ID', (string) $user->getKey()];
            $rows[] = ['Password', $this->passwordRow($manager)];
            $rows[] = ['is_protected flag', $user->getAttribute('is_protected') ? 'true' : 'false'];

            $createdAt = $user->getAttribute('created_at');
            if ($createdAt !== null) {
                $rows[] = ['Created at', (string) $createdAt];
            }
        }

        $rows[] = ['Protection enabled', config('superadmin.protection.enabled', true) ? 'yes' : 'NO'];
        $rows[] = ['Role', (string) ($manager->configuredRole() ?? '<none>')];
        $rows[] = ['Auto-install on migrate', config('superadmin.auto_install', true) ? 'yes' : 'NO'];

        $this->table(['Setting', 'Value'], $rows);

        if (! $exists) {
            $this->line('');
            $this->warn('Super admin account is missing. Run `php artisan superadmin:ensure` (or just `php artisan migrate` with auto_install enabled).');

            return self::FAILURE;
        }

        if ($verbose) {
            return $this->runDiagnostics($manager, $user, $email);
        }

        return self::SUCCESS;
    }

    /**
     * The single place superadmin credentials are ever displayed on demand.
     * Shows the password only when it verifiably matches the stored hash
     * (env override or non-production default) — never a stale or random
     * value; otherwise points at the recovery paths.
     */
    private function passwordRow(SuperAdminManager $manager): string
    {
        $verified = $manager->verifiedKnownPassword();

        if ($verified !== null) {
            return $verified.($manager->configuredPassword() !== null ? ' (from SUPER_ADMIN_PASSWORD)' : ' (default)');
        }

        return 'rotated/unknown — reset via the /superadmin recovery route or `superadmin:ensure`';
    }

    /**
     * Full diagnostic matrix. Replaces the old `superadmin:doctor` command.
     * Returns FAILURE if any check fails, SUCCESS otherwise.
     */
    private function runDiagnostics(SuperAdminManager $manager, ?object $user, string $email): int
    {
        $this->line('');
        $this->info('--- Health diagnostics (verbose) ---');

        $rows = [];
        $problems = [];

        $model = $manager->userModel();
        if ($model === null) {
            $rows[] = ['User model', 'unresolved', '✗'];
            $problems[] = 'User model not resolvable from auth.providers.users.model.';
        } else {
            $rows[] = ['User model', $model, '✓'];
        }

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

        $protectionEnabled = (bool) config('superadmin.protection.enabled', true);
        $rows[] = ['Protection observer', $protectionEnabled ? 'enabled' : 'DISABLED', $protectionEnabled ? '✓' : '⚠'];
        if (! $protectionEnabled) {
            $problems[] = 'Protection is disabled (superadmin.protection.enabled = false). The observer will not block delete / email-change / flag-change.';
        }

        if ($user !== null && $columnExists) {
            $flag = (bool) $user->getAttribute('is_protected');
            $rows[] = ['Protected user is_protected = true', $flag ? 'yes' : 'NO', $flag ? '✓' : '✗'];
            if (! $flag) {
                $problems[] = 'The protected user has is_protected = false. Re-run `php artisan superadmin:ensure`.';
            }
        }

        $gateBefore = (bool) config('superadmin.authorization.gate_before', true);
        $rows[] = ['Gate::before auto-grant', $gateBefore ? 'enabled' : 'disabled', '✓'];

        $role = $manager->configuredRole();
        if ($role !== null && $user !== null) {
            if (! method_exists($user, 'hasRole')) {
                $rows[] = ['Role assignment', 'skipped — User has no hasRole()', '⚠'];
            } else {
                $hasRole = $manager->hasConfiguredRole($user);
                if ($hasRole === true) {
                    $rows[] = ['Role "'.$role.'" assigned', 'yes', '✓'];
                } elseif (! $gateBefore) {
                    $rows[] = ['Role "'.$role.'" assigned', 'NO', '✗'];
                    $problems[] = 'Protected user does not have role "'.$role.'" AND gate_before is disabled. The super admin cannot authorize anything. Re-run `php artisan superadmin:ensure`.';
                } else {
                    $rows[] = ['Role "'.$role.'" assigned', 'NO (Gate::before still authorizes)', '⚠'];
                }
            }
        }

        $this->table(['Check', 'Value', ''], $rows);

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
