<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin;

use Codenzia\SuperAdmin\Console\Commands\EnsureCommand;
use Codenzia\SuperAdmin\Console\Commands\StatusCommand;
use Codenzia\SuperAdmin\Http\Controllers\RecoveryController;
use Codenzia\SuperAdmin\Observers\SuperAdminObserver;
use Codenzia\SuperAdmin\Support\SuperAdminManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

final class SuperAdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/superadmin.php', 'superadmin');

        $this->app->singleton(SuperAdminManager::class, fn (Container $app): SuperAdminManager => new SuperAdminManager($app));
        $this->app->alias(SuperAdminManager::class, 'superadmin');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/superadmin.php' => config_path('superadmin.php'),
            ], 'superadmin-config');

            $this->publishes([
                __DIR__.'/../database/migrations/' => database_path('migrations'),
            ], 'superadmin-migrations');

            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/superadmin'),
            ], 'superadmin-views');

            $this->commands([
                EnsureCommand::class,
                StatusCommand::class,
            ]);
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'superadmin');

        $this->registerObserver();
        $this->registerGateBefore();
        $this->registerAutoInstall();
        $this->registerLateRoleAssignment();
        $this->registerRolePromotionGuard();
        $this->registerRecoveryRoutes();
    }

    /**
     * Break-glass recovery routes (see config `superadmin.recovery`).
     * Registered on the `web` middleware group for sessions/CSRF. The path
     * is configurable per app; the whole feature can be disabled.
     */
    private function registerRecoveryRoutes(): void
    {
        if (! (bool) config('superadmin.recovery.enabled', false)) {
            return;
        }

        $path = trim((string) config('superadmin.recovery.path', 'superadmin'), '/');

        if ($path === '') {
            return;
        }

        Route::middleware('web')->group(function () use ($path): void {
            Route::get($path, [RecoveryController::class, 'show'])->name('superadmin.recovery.show');
            Route::post($path, [RecoveryController::class, 'send'])->name('superadmin.recovery.send');
            Route::get($path.'/reset/{token}', [RecoveryController::class, 'form'])->name('superadmin.recovery.form');
            Route::post($path.'/reset', [RecoveryController::class, 'update'])->name('superadmin.recovery.update');
        });
    }

    private function registerObserver(): void
    {
        if (! config('superadmin.protection.enabled', true)) {
            return;
        }

        $model = $this->app->make(SuperAdminManager::class)->userModel();

        if ($model !== null && class_exists($model)) {
            $model::observe(SuperAdminObserver::class);
        }
    }

    private function registerGateBefore(): void
    {
        // Always register; the closure itself checks the config at call time
        // so the flag can be toggled per-request (and per-test) without
        // re-booting the provider.
        Gate::before(function (?Model $user, string $ability): ?bool {
            if (! config('superadmin.authorization.gate_before', true)) {
                return null;
            }

            if ($user === null) {
                return null;
            }

            return $this->app->make(SuperAdminManager::class)->is($user) ? true : null;
        });
    }

    /**
     * Auto-install the protected super admin after migrations complete.
     *
     * Listens to MigrationsEnded (fired by Laravel's migrator after every
     * `php artisan migrate` / `migrate:fresh` / `migrate:refresh`). When the
     * `is_protected` column exists AND no protected user is found, creates
     * one using SuperAdminManager::ensure() and prints a one-line
     * confirmation so the operator sees what happened.
     *
     * Gated by `superadmin.auto_install` (default true). Idempotent: a
     * subsequent migrate that finds an existing protected user is a no-op.
     */
    private function registerAutoInstall(): void
    {
        Event::listen(MigrationsEnded::class, function (): void {
            if (! (bool) config('superadmin.auto_install', true)) {
                return;
            }

            /** @var SuperAdminManager $manager */
            $manager = $this->app->make(SuperAdminManager::class);

            $model = $manager->userModel();
            if ($model === null || ! class_exists($model)) {
                return;
            }

            // is_protected column may not exist yet on the very first migrate
            // batch if the host hasn't published this package's migration; in
            // that case we silently no-op and let the next migrate (which
            // will have the column) handle it.
            try {
                /** @var Model $instance */
                $instance = new $model;
                if (! Schema::hasColumn($instance->getTable(), 'is_protected')) {
                    return;
                }
            } catch (\Throwable) {
                return;
            }

            if ($manager->exists()) {
                return;
            }

            try {
                $user = $manager->ensure();
            } catch (\Throwable $e) {
                // Don't break `migrate` over an install failure — surface
                // it to the operator but let the migration command finish.
                if (PHP_SAPI === 'cli') {
                    fwrite(STDERR, '  ✗ Super admin auto-install failed: '.$e->getMessage().PHP_EOL);
                }

                return;
            }

            if (PHP_SAPI === 'cli') {
                $email = (string) $user->getAttribute('email');
                $password = $manager->knownDefaultPassword();

                if ($password !== null && $manager->configuredPassword() !== null) {
                    fwrite(STDOUT, "  ✓ Created protected super admin: {$email} (password: set via SUPER_ADMIN_PASSWORD)".PHP_EOL);
                } elseif ($password !== null) {
                    fwrite(STDOUT, "  ✓ Created protected super admin: {$email} (password: {$password})".PHP_EOL);
                } else {
                    $path = trim((string) config('superadmin.recovery.path', 'superadmin'), '/');
                    fwrite(STDOUT, "  ✓ Created protected super admin: {$email} (random password — claim the account at /{$path} or via `php artisan superadmin:ensure`)".PHP_EOL);
                }

                fwrite(STDOUT, '    Override defaults in your seeder via SuperAdmin::ensure([...]). Change later with `php artisan superadmin:ensure`.'.PHP_EOL);
            }
        });
    }

    /**
     * Retroactively assign the configured role to the protected super admin
     * the moment that role is created in the database.
     *
     * Solves a real race: `MigrationsEnded` fires during `migrate`, which
     * is *before* any seeder runs. If spatie/laravel-permission is in use,
     * the Role row for `super_admin` doesn't exist yet at that point, so
     * `install()`'s best-effort assignRole() silently fails. Without this
     * listener the protected user never gets the role, even though both
     * the role and the user exist by the end of `migrate --seed`.
     *
     * Implemented as an Eloquent wildcard listener (`eloquent.created: *`)
     * so the role model class is resolved lazily at event time. That lets
     * host apps swap `permission.models.role` without re-booting the
     * provider, and lets the package keep working when Spatie is not
     * installed at all (the listener body short-circuits early).
     *
     * Idempotent: if the user already has the role, the call is a no-op.
     * Never throws — assignRole() is best-effort and swallows failures.
     *
     * Disabled via `superadmin.late_role_assignment = false` for hosts
     * that want strict control.
     */
    private function registerLateRoleAssignment(): void
    {
        Event::listen('eloquent.created: *', function (string $event, array $payload): void {
            if (! (bool) $this->app['config']->get('superadmin.late_role_assignment', true)) {
                return;
            }

            $model = $payload[0] ?? null;
            if (! $model instanceof Model) {
                return;
            }

            $roleClass = $this->resolveRoleModel();
            if ($roleClass === null || ! ($model instanceof $roleClass)) {
                return;
            }

            $manager = $this->app->make(SuperAdminManager::class);

            $configured = $manager->configuredRole();
            if ($configured === null) {
                return;
            }

            $name = $model->getAttribute('name');
            if (! is_string($name) || $name !== $configured) {
                return;
            }

            $user = $manager->user();
            if ($user === null) {
                return;
            }

            // Best-effort: assignRole() handles "already has it" and
            // swallows failures (RoleAssignmentResult::Failed). Safe to call.
            $manager->assignRole($user);
        });
    }

    /**
     * Prevent any user other than the protected super admin from being assigned
     * the super_admin role via Eloquent pivot operations (syncRoles, assignRole,
     * roles()->attach(), etc.).
     *
     * Hooks into the `eloquent.pivotAttaching` wildcard event which fires
     * before Spatie's BelongsToMany writes the model_has_roles pivot row.
     * Throws ProtectedAccountException so the DB is never left in a bad state.
     *
     * Protected by `superadmin.protection.prevent_role_promotion` (default true)
     * and skipped when the protection bypass is active (e.g. during install).
     */
    private function registerRolePromotionGuard(): void
    {
        if (! config('superadmin.protection.prevent_role_promotion', true)) {
            return;
        }

        /** @var SuperAdminManager $manager */
        $manager = $this->app->make(SuperAdminManager::class);

        // Resolved super-admin role id, memoized in the closure scope so a
        // bulk syncRoles across many users does not re-query per pivot attach.
        // `false` = not yet resolved; null/int = resolved value.
        $superAdminRoleId = false;

        Event::listen('eloquent.pivotAttaching: *', function (string $event, array $payload) use ($manager, &$superAdminRoleId): void {
            if ($manager->isProtectionBypassed()) {
                return;
            }

            /** @var Model $user */
            $user = $payload[0] ?? null;
            $relationName = $payload[1] ?? null;

            if (! $user instanceof Model || $relationName !== 'roles') {
                return;
            }

            // Already the protected account — allowed (e.g. late role assignment).
            if ((bool) $user->getAttribute('is_protected')) {
                return;
            }

            $roleClass = $this->resolveRoleModel();
            if ($roleClass === null) {
                return;
            }

            $configuredRole = $manager->configuredRole();
            if ($configuredRole === null) {
                return;
            }

            if ($superAdminRoleId === false || $superAdminRoleId === null) {
                $superAdminRoleId = $roleClass::where('name', $configuredRole)->value('id');
            }

            $attachingIds = (array) ($payload[2] ?? []);

            if ($superAdminRoleId !== null && in_array($superAdminRoleId, $attachingIds, false)) {
                throw \Codenzia\SuperAdmin\Exceptions\ProtectedAccountException::cannotAssignSuperAdminRole();
            }
        });
    }

    /**
     * Resolve the Spatie Role model class. Returns null when
     * spatie/laravel-permission is not installed in the host app, so the
     * package degrades gracefully.
     *
     * @return class-string<Model>|null
     */
    private function resolveRoleModel(): ?string
    {
        $configured = $this->app['config']->get('permission.models.role');

        if (is_string($configured) && $configured !== '' && class_exists($configured)) {
            return $configured;
        }

        $canonical = 'Spatie\\Permission\\Models\\Role';

        return class_exists($canonical) ? $canonical : null;
    }
}
