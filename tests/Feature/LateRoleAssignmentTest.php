<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Facades\SuperAdmin;
use Codenzia\SuperAdmin\Tests\Fixtures\FakeRole;
use Codenzia\SuperAdmin\Tests\Fixtures\UserWithRoles;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Regression: in a real Laravel install, `MigrationsEnded` fires *before*
 * any seeder runs. If the host app uses spatie/laravel-permission, the
 * Role row for `super_admin` doesn't exist yet at migration-end time, so
 * the package's best-effort assignRole() during auto-install silently
 * fails. The user ends up created and protected, but without the role.
 *
 * Fix: a wildcard `eloquent.created` listener that retroactively assigns
 * the configured role to the existing protected user the moment a role
 * row matching the configured name appears.
 */
beforeEach(function (): void {
    UserWithRoles::reset();
    config()->set('permission.models.role', FakeRole::class);
    config()->set('auth.providers.users.model', UserWithRoles::class);
    config()->set('superadmin.user_model', UserWithRoles::class);
    config()->set('filament-shield.super_admin.name', 'super_admin');
    config()->set('superadmin.late_role_assignment', true);

    Schema::dropIfExists('fake_roles');
    Schema::create('fake_roles', function ($table): void {
        $table->id();
        $table->string('name');
    });
});

it('retroactively assigns the configured role when it is created later', function (): void {
    // Simulate post-MigrationsEnded state: protected user exists, role does not.
    $user = UserWithRoles::query()->create([
        'name' => 'Super Admin',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => true,
    ]);
    UserWithRoles::$rolesInDatabase = [];

    expect($user->hasRole('super_admin'))->toBeFalse();

    // Role gets created later (seeder time).
    UserWithRoles::$rolesInDatabase = ['super_admin'];
    FakeRole::query()->create(['name' => 'super_admin']);

    expect($user->fresh()->hasRole('super_admin'))->toBeTrue();
});

it('ignores creation of unrelated roles', function (): void {
    $user = UserWithRoles::query()->create([
        'name' => 'Super Admin',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => true,
    ]);
    UserWithRoles::$rolesInDatabase = ['admin', 'editor'];

    FakeRole::query()->create(['name' => 'admin']);
    FakeRole::query()->create(['name' => 'editor']);

    expect($user->fresh()->hasRole('super_admin'))->toBeFalse();
});

it('no-ops when the protected user does not yet exist', function (): void {
    UserWithRoles::$rolesInDatabase = ['super_admin'];

    FakeRole::query()->create(['name' => 'super_admin']);

    expect(SuperAdmin::exists())->toBeFalse();
});

it('no-ops when late_role_assignment is disabled', function (): void {
    config()->set('superadmin.late_role_assignment', false);

    $user = UserWithRoles::query()->create([
        'name' => 'Super Admin',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => true,
    ]);
    UserWithRoles::$rolesInDatabase = ['super_admin'];

    FakeRole::query()->create(['name' => 'super_admin']);

    expect($user->fresh()->hasRole('super_admin'))->toBeFalse();
});

it('is idempotent — repeated role creates do not duplicate assignment', function (): void {
    $user = UserWithRoles::query()->create([
        'name' => 'Super Admin',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => true,
    ]);
    UserWithRoles::$rolesInDatabase = ['super_admin'];

    FakeRole::query()->create(['name' => 'super_admin']);
    FakeRole::query()->create(['name' => 'super_admin']);

    $fresh = $user->fresh();
    expect($fresh->hasRole('super_admin'))->toBeTrue();
    expect(UserWithRoles::$rolesByUserId[$user->getKey()])->toBe(['super_admin']);
});
