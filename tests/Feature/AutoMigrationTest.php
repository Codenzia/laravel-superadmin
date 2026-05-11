<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Facades\SuperAdmin;
use Codenzia\SuperAdmin\Tests\Fixtures\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    // Simulate a fresh project where the package migration hasn't run yet:
    // drop the column AND clear the migration record so migrate --path re-runs.
    if (Schema::hasColumn('users', 'is_protected')) {
        Schema::table('users', function ($table): void {
            $table->dropIndex(['is_protected']);
            $table->dropColumn('is_protected');
        });
    }

    DB::table('migrations')
        ->where('migration', 'like', '%add_is_protected_to_users_table%')
        ->delete();
});

it('install auto-runs the package migration when the column is missing', function (): void {
    expect(Schema::hasColumn('users', 'is_protected'))->toBeFalse();

    $this->artisan('superadmin:install', [
        '--email' => 'superadmin@aqarkom.test',
        '--password' => 'super-strong-pw-123!',
        '--confirm' => true,
    ])
        ->expectsOutputToContain('Package migration applied')
        ->expectsOutputToContain('Protected super admin installed')
        ->assertExitCode(0);

    expect(Schema::hasColumn('users', 'is_protected'))->toBeTrue();
    expect(SuperAdmin::exists())->toBeTrue();
    expect((bool) User::query()->where('email', 'superadmin@aqarkom.test')->first()->is_protected)->toBeTrue();
});

it('--skip-migration refuses to install when the column is missing', function (): void {
    $this->artisan('superadmin:install', [
        '--email' => 'superadmin@aqarkom.test',
        '--password' => 'super-strong-pw-123!',
        '--confirm' => true,
        '--skip-migration' => true,
    ])
        ->expectsOutputToContain('column is missing')
        ->assertExitCode(1);

    expect(Schema::hasColumn('users', 'is_protected'))->toBeFalse();
});

it('install proceeds normally when the column already exists', function (): void {
    // Re-add the column so the migration check passes immediately.
    Schema::table('users', function ($table): void {
        $table->boolean('is_protected')->default(false)->index();
    });

    $this->artisan('superadmin:install', [
        '--email' => 'superadmin@aqarkom.test',
        '--password' => 'super-strong-pw-123!',
        '--confirm' => true,
    ])
        ->doesntExpectOutput('users.is_protected column is missing')
        ->expectsOutputToContain('Protected super admin installed')
        ->assertExitCode(0);
});
