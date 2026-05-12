<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Facades\SuperAdmin;
use Codenzia\SuperAdmin\Support\SuperAdminManager;
use Codenzia\SuperAdmin\Tests\Fixtures\User;
use Codenzia\SuperAdmin\Tests\Fixtures\UserWithRoles;
use Illuminate\Support\Facades\Hash;

it('returns null email when not configured', function (): void {
    config()->set('superadmin.email', null);

    expect(SuperAdmin::email())->toBeNull();
});

it('returns the configured email in lowercase', function (): void {
    config()->set('superadmin.email', 'SuperAdmin@AQARKOM.test');

    expect(SuperAdmin::email())->toBe('superadmin@aqarkom.test');
});

it('matches the super admin by is_protected flag (DB-backed)', function (): void {
    configureSuperAdmin();
    $user = createProtectedSuperAdmin();

    expect(SuperAdmin::is($user))->toBeTrue();
});

it('matches the super admin by email even without is_protected flag', function (): void {
    configureSuperAdmin('match@aqarkom.test');

    $user = new User([
        'name' => 'No Flag User',
        'email' => 'match@aqarkom.test',
        'is_protected' => false,
    ]);

    expect(SuperAdmin::is($user))->toBeTrue();
});

it('matches the super admin by email regardless of casing', function (): void {
    configureSuperAdmin('superadmin@aqarkom.test');
    $user = createProtectedSuperAdmin('SUPERADMIN@aqarkom.TEST');

    expect(SuperAdmin::is($user))->toBeTrue();
});

it('does not match other users', function (): void {
    configureSuperAdmin();
    $other = createUser('someone@aqarkom.test');

    expect(SuperAdmin::is($other))->toBeFalse();
});

it('handles null user without erroring', function (): void {
    configureSuperAdmin();

    expect(SuperAdmin::is(null))->toBeFalse();
});

it('resolves user model from auth config', function (): void {
    expect(SuperAdmin::userModel())->toBe(User::class);
});

it('respects an explicit user_model override', function (): void {
    config()->set('superadmin.user_model', 'App\\Models\\Custom');

    expect(SuperAdmin::userModel())->toBe('App\\Models\\Custom');
});

it('isConfigured() requires email to be set', function (): void {
    config()->set('superadmin.email', null);
    expect(SuperAdmin::isConfigured())->toBeFalse();

    config()->set('superadmin.email', 'a@b.test');
    expect(SuperAdmin::isConfigured())->toBeTrue();
});

it('toggles protection bypass via withoutProtection', function (): void {
    $manager = app(SuperAdminManager::class);

    expect($manager->isProtectionBypassed())->toBeFalse();

    $result = $manager->withoutProtection(function () use ($manager): string {
        expect($manager->isProtectionBypassed())->toBeTrue();

        return 'ok';
    });

    expect($result)->toBe('ok');
    expect($manager->isProtectionBypassed())->toBeFalse();
});

it('restores the bypass flag after an exception', function (): void {
    $manager = app(SuperAdminManager::class);

    try {
        $manager->withoutProtection(function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($manager->isProtectionBypassed())->toBeFalse();
});

it('exists() reflects database state', function (): void {
    configureSuperAdmin();

    expect(SuperAdmin::exists())->toBeFalse();

    createProtectedSuperAdmin();

    expect(SuperAdmin::exists())->toBeTrue();
});

it('user() prefers the is_protected row over an email match', function (): void {
    configureSuperAdmin('configured@aqarkom.test');

    User::query()->create([
        'name' => 'Email Match',
        'email' => 'configured@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => false,
    ]);

    $flagged = User::query()->create([
        'name' => 'Flagged',
        'email' => 'other@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => true,
    ]);

    $found = SuperAdmin::user();

    expect($found)->not->toBeNull();
    expect($found->getKey())->toBe($flagged->getKey());
});

it('install() creates the user with is_protected = true when missing', function (): void {
    configureSuperAdmin('superadmin@aqarkom.test');

    $user = SuperAdmin::install('a-new-password-12345');

    expect($user->email)->toBe('superadmin@aqarkom.test');
    expect((bool) $user->is_protected)->toBeTrue();
    expect(Hash::check('a-new-password-12345', $user->password))->toBeTrue();
});

it('install() auto-assigns the configured role when User has HasRoles', function (): void {
    config()->set('auth.providers.users.model', UserWithRoles::class);
    config()->set('superadmin.user_model', UserWithRoles::class);
    config()->set('superadmin.role', 'super_admin');
    UserWithRoles::reset();
    UserWithRoles::$rolesInDatabase = ['super_admin'];
    configureSuperAdmin('superadmin@aqarkom.test');

    $user = SuperAdmin::install('a-password-12345', 'superadmin@aqarkom.test');

    expect($user->hasRole('super_admin'))->toBeTrue();
});

it('install() updates an existing protected user', function (): void {
    configureSuperAdmin();
    createProtectedSuperAdmin('superadmin@aqarkom.test', 'old-password-1234');

    SuperAdmin::install('brand-new-password-5678');

    $user = SuperAdmin::user();
    expect(Hash::check('brand-new-password-5678', $user->password))->toBeTrue();
    expect((bool) $user->is_protected)->toBeTrue();
});

it('resetPassword() rotates the password on the existing user', function (): void {
    configureSuperAdmin();
    createProtectedSuperAdmin('superadmin@aqarkom.test', 'original-pw-12345');

    SuperAdmin::resetPassword('rotated-pw-98765!');

    $user = SuperAdmin::user();
    expect(Hash::check('rotated-pw-98765!', $user->password))->toBeTrue();
});

it('resetPassword() throws if no protected user exists', function (): void {
    configureSuperAdmin();

    expect(fn () => SuperAdmin::resetPassword('any-password-12345'))
        ->toThrow(RuntimeException::class);
});
