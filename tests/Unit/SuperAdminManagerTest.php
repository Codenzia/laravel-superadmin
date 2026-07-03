<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Facades\SuperAdmin;
use Codenzia\SuperAdmin\Support\SuperAdminManager;
use Codenzia\SuperAdmin\Tests\Fixtures\UncastUser;
use Codenzia\SuperAdmin\Tests\Fixtures\User;
use Codenzia\SuperAdmin\Tests\Fixtures\UserWithRoles;
use Illuminate\Support\Facades\Hash;

// ---------------------------------------------------------------------------
// email() — v0.4.0 always returns null (config key removed)
// ---------------------------------------------------------------------------

it('email() always returns null in v0.4.0 (config key removed)', function (): void {
    expect(SuperAdmin::email())->toBeNull();

    // Stale config writes are ignored — the method no longer reads config.
    config()->set('superadmin.email', 'CUSTOM@example.test');
    expect(SuperAdmin::email())->toBeNull();
});

// ---------------------------------------------------------------------------
// is() — protected-flag-based identity
// ---------------------------------------------------------------------------

it('matches the super admin by is_protected flag (DB-backed)', function (): void {
    $user = createProtectedSuperAdmin();

    expect(SuperAdmin::is($user))->toBeTrue();
});

it('does not match users without the is_protected flag', function (): void {
    $other = createUser('someone@aqarkom.test');

    expect(SuperAdmin::is($other))->toBeFalse();
});

it('handles null user without erroring', function (): void {
    expect(SuperAdmin::is(null))->toBeFalse();
});

it('matches the super admin even when the host model does not cast is_protected', function (): void {
    // Simulate a MySQL host where the un-cast boolean comes back as "1".
    $user = new UncastUser;
    $user->setRawAttributes(['is_protected' => '1']);

    expect(SuperAdmin::is($user))->toBeTrue();
});

it('does not match an un-cast model whose is_protected is "0"', function (): void {
    $user = new UncastUser;
    $user->setRawAttributes(['is_protected' => '0']);

    expect(SuperAdmin::is($user))->toBeFalse();
});

// ---------------------------------------------------------------------------
// isSuperAdmin() — protected account OR the configured super-admin role
// ---------------------------------------------------------------------------

it('isSuperAdmin() is true for the protected super admin', function (): void {
    expect(SuperAdmin::isSuperAdmin(createProtectedSuperAdmin()))->toBeTrue();
});

it('isSuperAdmin() is true for a user holding the configured role', function (): void {
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

    expect(SuperAdmin::isSuperAdmin($user))->toBeTrue();
});

it('isSuperAdmin() is false for a regular user with neither the flag nor the role', function (): void {
    expect(SuperAdmin::isSuperAdmin(createUser('regular@aqarkom.test')))->toBeFalse();
});

it('isSuperAdmin() handles null without erroring', function (): void {
    expect(SuperAdmin::isSuperAdmin(null))->toBeFalse();
});

// ---------------------------------------------------------------------------
// userModel() — auth config resolution
// ---------------------------------------------------------------------------

it('resolves user model from auth config', function (): void {
    expect(SuperAdmin::userModel())->toBe(User::class);
});

it('respects an explicit user_model override', function (): void {
    config()->set('superadmin.user_model', 'App\\Models\\Custom');

    expect(SuperAdmin::userModel())->toBe('App\\Models\\Custom');
});

// ---------------------------------------------------------------------------
// isConfigured() — v0.4.0 always returns true (defaults always available)
// ---------------------------------------------------------------------------

it('isConfigured() always returns true in v0.4.0', function (): void {
    expect(SuperAdmin::isConfigured())->toBeTrue();
});

// ---------------------------------------------------------------------------
// withoutProtection() — bypass scoping
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// exists() / user() — DB lookups
// ---------------------------------------------------------------------------

it('exists() reflects database state', function (): void {
    expect(SuperAdmin::exists())->toBeFalse();

    createProtectedSuperAdmin();

    expect(SuperAdmin::exists())->toBeTrue();
});

it('user() finds the is_protected row', function (): void {
    $flagged = SuperAdmin::withoutProtection(fn (): User => User::query()->create([
        'name' => 'Flagged',
        'email' => 'other@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => true,
    ]));

    $found = SuperAdmin::user();

    expect($found)->not->toBeNull();
    expect($found->getKey())->toBe($flagged->getKey());
});

// ---------------------------------------------------------------------------
// install() — direct creation/update path (still used by ensure() internally)
// ---------------------------------------------------------------------------

it('install() creates the user with is_protected = true when missing', function (): void {
    $user = SuperAdmin::install('a-new-password-12345', 'superadmin@aqarkom.test');

    expect($user->email)->toBe('superadmin@aqarkom.test');
    expect((bool) $user->is_protected)->toBeTrue();
    expect(Hash::check('a-new-password-12345', $user->password))->toBeTrue();
});

it('install() auto-assigns the Shield-configured role when User has HasRoles', function (): void {
    config()->set('auth.providers.users.model', UserWithRoles::class);
    config()->set('superadmin.user_model', UserWithRoles::class);
    config()->set('filament-shield.super_admin.name', 'super_admin');
    UserWithRoles::reset();
    UserWithRoles::$rolesInDatabase = ['super_admin'];

    $user = SuperAdmin::install('a-password-12345', 'superadmin@aqarkom.test');

    expect($user->hasRole('super_admin'))->toBeTrue();
});

it('install() updates an existing protected user', function (): void {
    createProtectedSuperAdmin('superadmin@aqarkom.test', 'old-password-1234');

    SuperAdmin::install('brand-new-password-5678');

    $user = SuperAdmin::user();
    expect(Hash::check('brand-new-password-5678', $user->password))->toBeTrue();
    expect((bool) $user->is_protected)->toBeTrue();
});

// ---------------------------------------------------------------------------
// ensure() — no-args, idempotent get-or-create (auto-install path)
// ---------------------------------------------------------------------------

it('ensure() creates the superadmin from defaults when none exists', function (): void {
    config()->set('app.url', 'https://myshop.test');

    $user = SuperAdmin::ensure();

    expect($user->email)->toBe('superadmin@myshop.test');
    expect((bool) $user->is_protected)->toBeTrue();
    expect(Hash::check('superadmin', $user->password))->toBeTrue();
});

it('ensure() returns the existing superadmin untouched without re-hashing the password', function (): void {
    $original = createProtectedSuperAdmin('superadmin@aqarkom.test', 'do-not-rotate-me');
    $originalHash = $original->password;

    $returned = SuperAdmin::ensure();

    expect($returned->getKey())->toBe($original->getKey());
    expect($returned->password)->toBe($originalHash);
    expect(Hash::check('do-not-rotate-me', $returned->password))->toBeTrue();
});

it('ensure() is idempotent across repeated calls', function (): void {
    $a = SuperAdmin::ensure();
    $b = SuperAdmin::ensure();
    $c = SuperAdmin::ensure();

    expect($a->getKey())->toBe($b->getKey())->toBe($c->getKey());
    expect(User::query()->where('is_protected', true)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// ensure(?array) — seeder override path (v0.4.0)
// ---------------------------------------------------------------------------

it('ensure([password]) creates a new user with the supplied password', function (): void {
    config()->set('app.url', 'https://acme.test');

    $user = SuperAdmin::ensure(['password' => 'seeder-supplied-pw']);

    expect((bool) $user->is_protected)->toBeTrue();
    expect(Hash::check('seeder-supplied-pw', $user->password))->toBeTrue();
    expect($user->email)->toBe('superadmin@acme.test');
    expect($user->name)->toBe('Super Admin');
});

it('ensure([password]) updates the password on an existing user', function (): void {
    $original = createProtectedSuperAdmin('superadmin@aqarkom.test', 'old-password-1234');

    SuperAdmin::ensure(['password' => 'brand-new-pw-5678']);

    $user = SuperAdmin::user();
    expect($user->getKey())->toBe($original->getKey());
    expect(Hash::check('brand-new-pw-5678', $user->password))->toBeTrue();
});

it('ensure([email]) updates the email and preserves the password on an existing user', function (): void {
    createProtectedSuperAdmin('original@aqarkom.test', 'keep-this-password');

    SuperAdmin::ensure(['email' => 'rotated@aqarkom.test']);

    $user = SuperAdmin::user();
    expect($user->email)->toBe('rotated@aqarkom.test');
    expect(Hash::check('keep-this-password', $user->password))->toBeTrue();
});

it('ensure([name]) updates only the name', function (): void {
    createProtectedSuperAdmin('superadmin@aqarkom.test', 'keep-this');

    SuperAdmin::ensure(['name' => 'Custom Admin Name']);

    $user = SuperAdmin::user();
    expect($user->name)->toBe('Custom Admin Name');
    expect(Hash::check('keep-this', $user->password))->toBeTrue();
});

it('ensure([name, email, password]) applies all three on create', function (): void {
    $user = SuperAdmin::ensure([
        'name' => 'Seeder Admin',
        'email' => 'seed@acme.test',
        'password' => 'seed-pw-1234',
    ]);

    expect($user->name)->toBe('Seeder Admin');
    expect($user->email)->toBe('seed@acme.test');
    expect(Hash::check('seed-pw-1234', $user->password))->toBeTrue();
    expect((bool) $user->is_protected)->toBeTrue();
});

// ---------------------------------------------------------------------------
// defaultEmail() — config-first (one stable vendor address), derived fallback
// ---------------------------------------------------------------------------

it('defaultEmail() derives from the host APP_URL when no email is configured', function (): void {
    config()->set('superadmin.email', null);
    config()->set('app.url', 'https://aqarkom.test');

    expect(SuperAdmin::defaultEmail())->toBe('superadmin@aqarkom.test');
});

it('defaultEmail() never returns a codenzia.com address when SUPER_ADMIN_EMAIL is unset', function (): void {
    config()->set('superadmin.email', null);
    config()->set('app.url', 'https://some-customer-app.test');

    expect(SuperAdmin::defaultEmail())
        ->not->toContain('codenzia.com')
        ->toBe('superadmin@some-customer-app.test');
});

it('defaultEmail() honors a superadmin.email override, lowercased', function (): void {
    config()->set('superadmin.email', 'Ops@MyVendor.test');

    expect(SuperAdmin::defaultEmail())->toBe('ops@myvendor.test');
});

it('defaultEmail() derives from APP_URL host when the config is empty', function (): void {
    config()->set('superadmin.email', null);
    config()->set('app.url', 'https://MyShop.example.com:8080/path');

    expect(SuperAdmin::defaultEmail())->toBe('superadmin@myshop.example.com');
});

it('defaultEmail() falls back to app.name slug when no config or APP_URL host is available', function (): void {
    config()->set('superadmin.email', '');
    config()->set('app.url', '');
    config()->set('app.name', 'My Awesome Shop');

    expect(SuperAdmin::defaultEmail())->toBe('superadmin@my-awesome-shop.local');
});

// ---------------------------------------------------------------------------
// defaultPassword() / defaultName() — see also Unit/PasswordResolutionTest
// ---------------------------------------------------------------------------

it('defaultPassword() returns the literal "superadmin" outside production', function (): void {
    expect(SuperAdmin::defaultPassword())->toBe('superadmin');
});

it('defaultPassword() honors the superadmin.password override', function (): void {
    config()->set('superadmin.password', 'operator-override');

    expect(SuperAdmin::defaultPassword())->toBe('operator-override');
});

it('defaultName() returns the literal "Super Admin"', function (): void {
    expect(SuperAdmin::defaultName())->toBe('Super Admin');
});

it('install() with no arguments uses defaultEmail() + defaultPassword()', function (): void {
    config()->set('app.url', 'https://aqarkom.test');

    $user = SuperAdmin::install();

    expect($user->email)->toBe('superadmin@aqarkom.test');
    expect(Hash::check('superadmin', $user->password))->toBeTrue();
});

// ---------------------------------------------------------------------------
// configuredRole() — Shield bridge + hardcoded fallback (v0.4.0)
// ---------------------------------------------------------------------------

it('configuredRole() reads filament-shield.super_admin.name when set', function (): void {
    config()->set('filament-shield.super_admin.name', 'shield-defined-role');

    $manager = app(SuperAdminManager::class);

    expect($manager->configuredRole())->toBe('shield-defined-role');
});

it('configuredRole() falls back to literal "super_admin" when Shield is not configured', function (): void {
    config()->set('filament-shield.super_admin.name', null);

    $manager = app(SuperAdminManager::class);

    expect($manager->configuredRole())->toBe('super_admin');
});

it('configuredRole() ignores any stale superadmin.role config in v0.4.0', function (): void {
    config()->set('superadmin.role', 'legacy-role');
    config()->set('filament-shield.super_admin.name', null);

    $manager = app(SuperAdminManager::class);

    expect($manager->configuredRole())->toBe('super_admin');
});
