<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Facades\SuperAdmin;
use Codenzia\SuperAdmin\Support\SuperAdminManager;
use Codenzia\SuperAdmin\Tests\Fixtures\SpatieUser;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Exercises the opt-in `superadmin.role` role-ensure feature against a REAL
 * spatie/laravel-permission stack: when the role is configured the protected
 * super admin is created-and-assigned the role (creating the role row if it
 * does not exist yet); when it is null, no role is touched.
 */
it('creates the role from absent and assigns it to a new protected user', function (): void {
    config()->set('superadmin.role', 'god');

    expect(Role::query()->where('name', 'god')->exists())->toBeFalse();

    $user = SuperAdmin::ensure();

    expect(Role::query()->where('name', 'god')->exists())->toBeTrue();
    expect($user->fresh()->hasRole('god'))->toBeTrue();
});

it('assigns the configured role to an already-existing protected user (ensure path)', function (): void {
    config()->set('superadmin.role', 'god');

    // Protected user already exists without the role.
    $user = createSpatieProtectedSuperAdmin();
    expect($user->hasRole('god'))->toBeFalse();

    SuperAdmin::install(null, $user->email, $user->name);

    expect($user->fresh()->hasRole('god'))->toBeTrue();
});

it('creates the role with the configured role_guard', function (): void {
    config()->set('superadmin.role', 'god');
    config()->set('superadmin.role_guard', 'api');

    SuperAdmin::ensure();

    $role = Role::query()->where('name', 'god')->first();
    expect($role)->not->toBeNull();
    expect($role->guard_name)->toBe('api');
});

it('does not touch roles when superadmin.role is null (unchanged behavior)', function (): void {
    config()->set('superadmin.role', null);

    $user = SuperAdmin::ensure();

    // No role row created, and the user holds no roles: assignRole() is
    // best-effort and the fallback "super_admin" role does not exist.
    expect(Role::query()->count())->toBe(0);
    expect($user->fresh()->roles()->count())->toBe(0);
});

it('is idempotent — a second ensure does not duplicate the role assignment', function (): void {
    config()->set('superadmin.role', 'god');

    $user = SuperAdmin::ensure();
    SuperAdmin::install(null, $user->email, $user->name);

    $fresh = $user->fresh();
    expect($fresh->hasRole('god'))->toBeTrue();
    expect($fresh->roles()->where('name', 'god')->count())->toBe(1);
    expect(Role::query()->where('name', 'god')->count())->toBe(1);
});

it('applies the role through the superadmin:ensure --from-env path', function (): void {
    config()->set('superadmin.role', 'god');
    config()->set('superadmin.email', 'env-admin@aqarkom.test');
    config()->set('superadmin.password', 'env-password-123');

    $this->artisan('superadmin:ensure', ['--from-env' => true])->assertSuccessful();

    $user = SuperAdmin::user();
    expect($user)->not->toBeNull();
    expect($user->hasRole('god'))->toBeTrue();
});

it('does not create a role when superadmin.role is null even if a fallback role name resolves', function (): void {
    config()->set('superadmin.role', null);
    config()->set('filament-shield.super_admin.name', 'super_admin');

    // configuredRole() still resolves to "super_admin", but the ensure logic
    // must not CREATE it (create is gated on the explicit opt-in).
    expect(app(SuperAdminManager::class)->configuredRole())->toBe('super_admin');

    SuperAdmin::ensure();

    expect(Role::query()->where('name', 'super_admin')->exists())->toBeFalse();
});

function createSpatieProtectedSuperAdmin(string $email = 'superadmin@aqarkom.test'): SpatieUser
{
    return SuperAdmin::withoutProtection(fn (): SpatieUser => SpatieUser::query()->create([
        'name' => 'Super Admin',
        'email' => $email,
        'password' => Hash::make('super-secret-pw-12345'),
        'email_verified_at' => now(),
        'is_protected' => true,
    ]));
}
