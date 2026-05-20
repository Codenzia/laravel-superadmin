<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin;

use Codenzia\SuperAdmin\Console\Commands\SetupCommand;
use Codenzia\SuperAdmin\Console\Commands\StatusCommand;
use Codenzia\SuperAdmin\Observers\SuperAdminObserver;
use Codenzia\SuperAdmin\Support\SuperAdminManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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

            $this->commands([
                SetupCommand::class,
                StatusCommand::class,
            ]);
        }

        $this->registerObserver();
        $this->registerGateBefore();
        $this->registerAutoInstall();
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
                $password = $manager->defaultPassword();
                fwrite(STDOUT, "  ✓ Created protected super admin: {$email} (password: {$password})".PHP_EOL);
                fwrite(STDOUT, '    Override anytime with `php artisan superadmin:setup` or by editing .env.'.PHP_EOL);
            }
        });
    }
}
