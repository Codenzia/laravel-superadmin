<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin;

use Codenzia\SuperAdmin\Console\Commands\AssignRoleCommand;
use Codenzia\SuperAdmin\Console\Commands\DoctorCommand;
use Codenzia\SuperAdmin\Console\Commands\InstallCommand;
use Codenzia\SuperAdmin\Console\Commands\ResetCommand;
use Codenzia\SuperAdmin\Console\Commands\StatusCommand;
use Codenzia\SuperAdmin\Observers\SuperAdminObserver;
use Codenzia\SuperAdmin\Support\SuperAdminManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
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
                InstallCommand::class,
                ResetCommand::class,
                AssignRoleCommand::class,
                DoctorCommand::class,
                StatusCommand::class,
            ]);
        }

        $this->registerObserver();
        $this->registerGateBefore();
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
}
