<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Facades\SuperAdmin;
use Codenzia\SuperAdmin\Support\RoleAssignmentResult;
use Codenzia\SuperAdmin\Tests\Fixtures\UserWithRoles;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

/**
 * Exercises the four authorization modes documented in the README:
 *   1. gate_before=true, assign_role=true  — default (zero-config)
 *   2. gate_before=true, assign_role=false — Gate::before only
 *   3. gate_before=false, assign_role=true — role only (Shield handles auth)
 *   4. gate_before=false, assign_role=false — manual (project owns auth)
 */
beforeEach(function (): void {
    UserWithRoles::reset();
    config()->set('auth.providers.users.model', UserWithRoles::class);
    config()->set('superadmin.user_model', UserWithRoles::class);
    config()->set('superadmin.role', 'super_admin');
    UserWithRoles::$rolesInDatabase = ['super_admin'];
    configureSuperAdmin('superadmin@aqarkom.test');

    Gate::define('do-anything', fn (): bool => false);
});

it('mode 1 (default): authorizes via Gate::before AND assigns the role', function (): void {
    config()->set('superadmin.authorization.gate_before', true);
    config()->set('superadmin.authorization.assign_role', true);

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

it('mode 2 (gate_before only): authorizes without assigning role', function (): void {
    config()->set('superadmin.authorization.gate_before', true);
    config()->set('superadmin.authorization.assign_role', false);

    $user = UserWithRoles::query()->create([
        'name' => 'Admin',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => true,
    ]);

    $result = SuperAdmin::assignRole($user);

    expect($result)->toBe(RoleAssignmentResult::Disabled);
    expect($user->hasRole('super_admin'))->toBeFalse();
    expect(Gate::forUser($user)->allows('do-anything'))->toBeTrue();
});

it('mode 3 (role only): assigns role, Gate::before does not authorize', function (): void {
    config()->set('superadmin.authorization.gate_before', false);
    config()->set('superadmin.authorization.assign_role', true);

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

it('mode 4 (manual): no Gate::before, no role assignment', function (): void {
    config()->set('superadmin.authorization.gate_before', false);
    config()->set('superadmin.authorization.assign_role', false);

    $user = UserWithRoles::query()->create([
        'name' => 'Admin',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => true,
    ]);

    $result = SuperAdmin::assignRole($user);

    expect($result)->toBe(RoleAssignmentResult::Disabled);
    expect($user->hasRole('super_admin'))->toBeFalse();
    expect(Gate::forUser($user)->allows('do-anything'))->toBeFalse();
});

it('superadmin:assign-role command always assigns even when flag is false', function (): void {
    // assign_role = false means install/reset SKIP role assignment.
    // But the explicit assign-role command should still honor a user invocation.
    config()->set('superadmin.authorization.assign_role', false);

    $user = UserWithRoles::query()->create([
        'name' => 'Admin',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => true,
    ]);

    expect($user->hasRole('super_admin'))->toBeFalse();

    $this->artisan('superadmin:assign-role', ['--confirm' => true])
        ->expectsOutputToContain('assigned successfully')
        ->assertExitCode(0);

    expect($user->fresh()->hasRole('super_admin'))->toBeTrue();
});
