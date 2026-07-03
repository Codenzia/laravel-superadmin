<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Facades\SuperAdmin;
use Codenzia\SuperAdmin\Support\RoleAssignmentResult;
use Codenzia\SuperAdmin\Support\SuperAdminManager;
use Codenzia\SuperAdmin\Tests\Fixtures\User;
use Codenzia\SuperAdmin\Tests\Fixtures\UserWithRoles;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    UserWithRoles::reset();
    config()->set('auth.providers.users.model', UserWithRoles::class);
    config()->set('superadmin.user_model', UserWithRoles::class);
    configureSuperAdmin('superadmin@aqarkom.test');
});

it('returns NotSupported when User model has no assignRole method', function (): void {
    config()->set('filament-shield.super_admin.name', 'super_admin');
    config()->set('auth.providers.users.model', User::class);
    config()->set('superadmin.user_model', User::class);

    $user = SuperAdmin::withoutProtection(fn (): User => User::query()->create([
        'name' => 'Admin',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => true,
    ]));

    expect(SuperAdmin::assignRole($user))->toBe(RoleAssignmentResult::NotSupported);
});

it('returns Assigned when role exists and was not previously assigned', function (): void {
    config()->set('filament-shield.super_admin.name', 'super_admin');
    UserWithRoles::$rolesInDatabase = ['super_admin'];

    $user = UserWithRoles::query()->create([
        'name' => 'Admin',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => true,
    ]);

    expect(SuperAdmin::assignRole($user))->toBe(RoleAssignmentResult::Assigned);
    expect($user->hasRole('super_admin'))->toBeTrue();
});

it('returns AlreadyAssigned on subsequent calls', function (): void {
    config()->set('filament-shield.super_admin.name', 'super_admin');

    $user = UserWithRoles::query()->create([
        'name' => 'Admin',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => true,
    ]);

    SuperAdmin::assignRole($user);

    expect(SuperAdmin::assignRole($user))->toBe(RoleAssignmentResult::AlreadyAssigned);
});

it('returns Failed when the role does not exist', function (): void {
    config()->set('filament-shield.super_admin.name', 'super_admin');
    UserWithRoles::$rolesInDatabase = []; // role table is empty

    $user = UserWithRoles::query()->create([
        'name' => 'Admin',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => true,
    ]);

    expect(SuperAdmin::assignRole($user))->toBe(RoleAssignmentResult::Failed);
});

it('configuredRole() returns the Shield-discovered value', function (): void {
    config()->set('filament-shield.super_admin.name', 'custom_admin');

    expect(app(SuperAdminManager::class)->configuredRole())
        ->toBe('custom_admin');
});

it('configuredRole() falls back to "super_admin" literal when Shield not configured', function (): void {
    config()->set('filament-shield.super_admin.name', '');

    expect(app(SuperAdminManager::class)->configuredRole())
        ->toBe('super_admin');
});

it('hasConfiguredRole() returns false when user lacks the role', function (): void {
    config()->set('filament-shield.super_admin.name', 'super_admin');

    $user = UserWithRoles::query()->create([
        'name' => 'Admin',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => true,
    ]);

    expect(app(SuperAdminManager::class)->hasConfiguredRole($user))
        ->toBeFalse();
});

it('hasConfiguredRole() returns true once role is assigned', function (): void {
    config()->set('filament-shield.super_admin.name', 'super_admin');

    $user = UserWithRoles::query()->create([
        'name' => 'Admin',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => true,
    ]);

    SuperAdmin::assignRole($user);

    expect(app(SuperAdminManager::class)->hasConfiguredRole($user))
        ->toBeTrue();
});
