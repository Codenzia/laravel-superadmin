<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Tests;

use Codenzia\SuperAdmin\SuperAdminServiceProvider;
use Codenzia\SuperAdmin\Tests\Fixtures\SpatieUser;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionServiceProvider;

/**
 * Base test case that boots a REAL spatie/laravel-permission stack (provider,
 * config, and pivot tables) so the role-ensure feature and the role-promotion
 * guard can be exercised against genuine `assignRole()` / `syncRoles()` writes
 * — not a hand-dispatched event.
 */
abstract class SpatieTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createPermissionTables();

        config()->set('auth.providers.users.model', SpatieUser::class);
        config()->set('superadmin.user_model', SpatieUser::class);
        config()->set('permission.models.role', Role::class);
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PermissionServiceProvider::class,
            SuperAdminServiceProvider::class,
        ];
    }

    /**
     * Create the subset of Spatie's default permission tables the tests use.
     * (Testbench can't run Spatie's publish-stub migration directly.)
     */
    private function createPermissionTables(): void
    {
        Schema::create('roles', function ($table): void {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('permissions', function ($table): void {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('model_has_roles', function ($table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type']);
            $table->primary(['role_id', 'model_id', 'model_type']);
        });

        Schema::create('model_has_permissions', function ($table): void {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type']);
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });

        Schema::create('role_has_permissions', function ($table): void {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });
    }
}
