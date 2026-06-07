<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Tests\Fixtures\User;
use Codenzia\SuperAdmin\Tests\Fixtures\UserWithRoles;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    configureSuperAdmin('superadmin@aqarkom.test');
});

it('isSuperAdmin() returns true for a flagged user', function (): void {
    $user = createProtectedSuperAdmin();

    expect($user->isSuperAdmin())->toBeTrue();
});

it('isSuperAdmin() returns false for other users', function (): void {
    $user = createUser('other@aqarkom.test');

    expect($user->isSuperAdmin())->toBeFalse();
});

it('isSuperAdmin() is role-aware: true for a non-protected user holding the super-admin role', function (): void {
    config()->set('auth.providers.users.model', UserWithRoles::class);
    config()->set('superadmin.user_model', UserWithRoles::class);
    config()->set('filament-shield.super_admin.name', 'super_admin');
    UserWithRoles::reset();

    $user = UserWithRoles::query()->create([
        'name' => 'Role Admin',
        'email' => 'role-admin@aqarkom.test',
        'password' => Hash::make('password-1234'),
        'email_verified_at' => now(),
        'is_protected' => false,
    ]);
    $user->assignRole('super_admin');

    expect($user->isSuperAdmin())->toBeTrue();
});

it('superAdmin scope returns only flagged rows', function (): void {
    createProtectedSuperAdmin();
    createUser('a@aqarkom.test');
    createUser('b@aqarkom.test');

    $results = User::query()->superAdmin()->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->email)->toBe('superadmin@aqarkom.test');
});

it('exceptSuperAdmin scope excludes the protected account', function (): void {
    createProtectedSuperAdmin();
    createUser('a@aqarkom.test');
    createUser('b@aqarkom.test');

    $results = User::query()->exceptSuperAdmin()->get();

    expect($results)->toHaveCount(2);
    expect($results->pluck('email')->all())->not->toContain('superadmin@aqarkom.test');
});
