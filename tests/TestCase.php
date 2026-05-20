<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Tests;

use Codenzia\SuperAdmin\SuperAdminServiceProvider;
use Codenzia\SuperAdmin\Tests\Fixtures\User;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (! Schema::hasColumn('users', 'is_protected')) {
            Schema::table('users', function ($table): void {
                $table->boolean('is_protected')->default(false)->index();
            });
        }
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SuperAdminServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('auth.providers.users.model', User::class);

        $app['config']->set('app.name', 'Codenzia SuperAdmin Tests');
        $app['config']->set('app.url', 'https://aqarkom.test');

        // Tests opt out of auto-install — most cases set up explicit state
        // and the migration-event handler would race with that. Individual
        // tests that exercise the hook re-enable it locally.
        $app['config']->set('superadmin.auto_install', false);
    }
}
