<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin;

use Codenzia\SuperAdmin\Console\Commands\EnsureCommand;
use Codenzia\SuperAdmin\Console\Commands\StatusCommand;
use Codenzia\SuperAdmin\Exceptions\ProtectedAccountException;
use Codenzia\SuperAdmin\Http\Controllers\RecoveryController;
use Codenzia\SuperAdmin\Observers\SuperAdminObserver;
use Codenzia\SuperAdmin\Support\SuperAdminManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Collection;
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
     * the super-admin (privileged) role through Spatie's `assignRole()` /
     * `syncRoles()`.
     *
     * Mechanism: Laravel core fires NO "pivot attaching" event, so the pivot
     * write cannot be intercepted before it happens. Spatie's `assignRole()`
     * does `$user->roles()->attach(...)` (a direct pivot insert) and then, when
     * `permission.events_enabled` is on, dispatches `RoleAttachedEvent`. That
     * post-write event is the only reliable hook that fires on a REAL
     * `assignRole()`, so the guard:
     *   1. force-enables `permission.events_enabled` (benign — extra Spatie
     *      events only) so the event is guaranteed to fire, then
     *   2. on `RoleAttachedEvent`, if a non-protected user was just given the
     *      configured super-admin role, DETACHES that row (so the DB is never
     *      left privileged) and throws ProtectedAccountException.
     *
     * The protected account and the package's own bypassed provisioning
     * (install / ensure / late role assignment) are always allowed to hold the
     * role. No-op without spatie/laravel-permission. Gated by
     * `superadmin.protection.prevent_role_promotion` (default true).
     */
    private function registerRolePromotionGuard(): void
    {
        if (! config('superadmin.protection.prevent_role_promotion', true)) {
            return;
        }

        $eventClass = 'Spatie\\Permission\\Events\\RoleAttachedEvent';

        // Degrade gracefully when spatie/laravel-permission is absent.
        if (! class_exists($eventClass)) {
            return;
        }

        // Spatie only dispatches its role/permission events when this flag is
        // on (default off). Enable it so the guard can fire; harmless to hosts
        // that don't listen for those events.
        $this->app['config']->set('permission.events_enabled', true);

        Event::listen($eventClass, function (object $event): void {
            /** @var SuperAdminManager $manager */
            $manager = $this->app->make(SuperAdminManager::class);

            // The package's own provisioning wraps writes in withoutProtection().
            if ($manager->isProtectionBypassed()) {
                return;
            }

            $user = $event->model ?? null;
            if (! $user instanceof Model) {
                return;
            }

            // The protected account is allowed to hold the role.
            if ((bool) $user->getAttribute('is_protected')) {
                return;
            }

            $configuredRole = $manager->configuredRole();
            if ($configuredRole === null) {
                return;
            }

            $roleClass = $this->resolveRoleModel();
            if ($roleClass === null) {
                return;
            }

            $superAdminRoleId = $roleClass::query()->where('name', $configuredRole)->value('id');
            if ($superAdminRoleId === null) {
                return;
            }

            if (! $this->attachedRolesInclude($event->rolesOrIds ?? [], $superAdminRoleId)) {
                return;
            }

            // Undo the just-written privileged pivot row, then signal the
            // violation. Detaching does not re-enter this listener (only
            // attach fires RoleAttachedEvent).
            if (method_exists($user, 'roles')) {
                $user->roles()->detach($superAdminRoleId);
                $user->unsetRelation('roles');
            }

            throw ProtectedAccountException::cannotAssignSuperAdminRole();
        });
    }

    /**
     * Whether the roles just attached (as reported by Spatie's
     * RoleAttachedEvent — an array/Collection of ids or Role models) include
     * the given super-admin role id.
     */
    private function attachedRolesInclude(mixed $rolesOrIds, int|string $superAdminRoleId): bool
    {
        $items = $rolesOrIds instanceof Collection
            ? $rolesOrIds->all()
            : (array) $rolesOrIds;

        foreach ($items as $item) {
            $id = $item instanceof Model ? $item->getKey() : $item;

            if ((string) $id === (string) $superAdminRoleId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the Spatie Role model class (delegates to the manager). Returns
     * null when spatie/laravel-permission is not installed, so the package
     * degrades gracefully.
     *
     * @return class-string<Model>|null
     */
    private function resolveRoleModel(): ?string
    {
        return $this->app->make(SuperAdminManager::class)->roleModel();
    }
}
