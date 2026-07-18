<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Exceptions\ProtectedAccountException;
use Codenzia\SuperAdmin\Facades\SuperAdmin;
use Codenzia\SuperAdmin\SuperAdminServiceProvider;
use Codenzia\SuperAdmin\Tests\Fixtures\SpatieUser;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Models\Role;

/**
 * Exercises `SuperAdminServiceProvider::registerRolePromotionGuard()` against a
 * REAL `assignRole()` / `syncRoles()` write — the previous suite only passed
 * because it hand-dispatched a phantom `eloquent.pivotAttaching` event that
 * Laravel core never fires. The guard now hooks Spatie's `RoleAttachedEvent`
 * (the only event that fires on a genuine pivot write) and detaches + throws.
 */
beforeEach(function (): void {
    config()->set('superadmin.protection.prevent_role_promotion', true);
    Role::query()->create(['name' => 'super_admin', 'guard_name' => 'web']);
});

function makeSpatieUser(string $email = 'attacker@aqarkom.test', bool $protected = false): SpatieUser
{
    return SuperAdmin::withoutProtection(fn (): SpatieUser => SpatieUser::query()->create([
        'name' => 'User',
        'email' => $email,
        'password' => Hash::make('password-1234'),
        'is_protected' => $protected,
    ]));
}

it('blocks assigning the super_admin role to a non-protected user via real assignRole()', function (): void {
    $user = makeSpatieUser();

    expect(fn () => $user->assignRole('super_admin'))
        ->toThrow(ProtectedAccountException::class);

    // The just-written pivot row was rolled back — the user does NOT hold it.
    expect($user->fresh()->hasRole('super_admin'))->toBeFalse();
});

it('blocks the super_admin role via real syncRoles()', function (): void {
    $user = makeSpatieUser();

    expect(fn () => $user->syncRoles(['super_admin']))
        ->toThrow(ProtectedAccountException::class);

    expect($user->fresh()->hasRole('super_admin'))->toBeFalse();
});

it('allows assigning the super_admin role to the protected user', function (): void {
    $user = makeSpatieUser('superadmin@aqarkom.test', protected: true);

    $user->assignRole('super_admin');

    expect($user->fresh()->hasRole('super_admin'))->toBeTrue();
});

it('allows assigning any other role to a non-protected user', function (): void {
    Role::query()->create(['name' => 'editor', 'guard_name' => 'web']);
    $user = makeSpatieUser();

    $user->assignRole('editor');

    expect($user->fresh()->hasRole('editor'))->toBeTrue();
});

it('does not block when prevent_role_promotion is disabled', function (): void {
    // The guard reads the flag once, at registration (boot) time. Drop the
    // listener and re-register the provider under the flipped config.
    Event::forget(RoleAttachedEvent::class);
    config()->set('superadmin.protection.prevent_role_promotion', false);
    app()->register(SuperAdminServiceProvider::class, force: true);

    $user = makeSpatieUser();

    $user->assignRole('super_admin');

    expect($user->fresh()->hasRole('super_admin'))->toBeTrue();
});

it('lets the package own role-ensure assign the configured role to the protected user (guard on)', function (): void {
    // Reconcile: the guard must not fight the package's authorized role-ensure.
    config()->set('superadmin.role', 'super_admin');

    $user = SuperAdmin::ensure();

    expect($user->fresh()->hasRole('super_admin'))->toBeTrue();
});
