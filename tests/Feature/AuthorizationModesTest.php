<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Facades\SuperAdmin;
use Codenzia\SuperAdmin\Support\RoleAssignmentResult;
use Codenzia\SuperAdmin\Tests\Fixtures\UserWithRoles;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

/**
 * Exercises the two authorization modes after the v0.2.0 simplification:
 *   1. gate_before = true  (default) — Gate::before authorizes everything;
 *      Spatie role is still best-effort assigned if HasRoles is present.
 *   2. gate_before = false           — Gate::before disabled; Spatie role
 *      is still best-effort assigned (caller's own policies must allow).
 *
 * The previous orthogonal `assign_role` config flag was removed — role
 * assignment is always best-effort whenever Spatie HasRoles is present.
 */
beforeEach(function (): void {
    UserWithRoles::reset();
    config()->set('auth.providers.users.model', UserWithRoles::class);
    config()->set('superadmin.user_model', UserWithRoles::class);
    config()->set('filament-shield.super_admin.name', 'super_admin');
    UserWithRoles::$rolesInDatabase = ['super_admin'];
    configureSuperAdmin('superadmin@aqarkom.test');

    Gate::define('do-anything', fn (): bool => false);
});

it('mode 1 (default): Gate::before authorizes AND role is assigned', function (): void {
    config()->set('superadmin.authorization.gate_before', true);

    $user = UserWithRoles::query()->create([
        'name' => 'Admin',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => true,
    ]);

    $result = SuperAdmin::assignRole($user);

    expect($result)->toBe(RoleAssignmentResult::Assigned);
    expect($user->hasRole('super_admin'))->toBeTrue();
    expect(Gate::forUser($user)->allows('do-anything'))->toBeTrue();
});

it('mode 2 (gate_before disabled): role is still assigned, but Gate::before does not authorize', function (): void {
    config()->set('superadmin.authorization.gate_before', false);

    $user = UserWithRoles::query()->create([
        'name' => 'Admin',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => true,
    ]);

    $result = SuperAdmin::assignRole($user);

    expect($result)->toBe(RoleAssignmentResult::Assigned);
    expect($user->hasRole('super_admin'))->toBeTrue();
    expect(Gate::forUser($user)->allows('do-anything'))->toBeFalse();
});

it('assignRole() returns AlreadyAssigned on a second call', function (): void {
    $user = UserWithRoles::query()->create([
        'name' => 'Admin',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => true,
    ]);

    SuperAdmin::assignRole($user);

    expect(SuperAdmin::assignRole($user))->toBe(RoleAssignmentResult::AlreadyAssigned);
});
