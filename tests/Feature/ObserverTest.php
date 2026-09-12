<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Exceptions\ProtectedAccountException;
use Codenzia\SuperAdmin\Facades\SuperAdmin;
use Codenzia\SuperAdmin\Tests\Fixtures\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

it('blocks deletion of the protected super admin', function (): void {
    $user = createProtectedSuperAdmin();

    expect(fn () => $user->delete())->toThrow(ProtectedAccountException::class);

    expect($user->fresh())->not->toBeNull();
});

it('allows deletion of non-protected users', function (): void {
    $user = createUser('other@aqarkom.test');

    $user->delete();

    expect($user->fresh())->toBeNull();
});

it('blocks email changes on the protected super admin', function (): void {
    $user = createProtectedSuperAdmin();

    $user->email = 'new-email@aqarkom.test';

    expect(fn () => $user->save())->toThrow(ProtectedAccountException::class);
});

it('blocks flipping is_protected from true to false', function (): void {
    $user = createProtectedSuperAdmin();

    $user->is_protected = false;

    expect(fn () => $user->save())->toThrow(ProtectedAccountException::class);
});

it('blocks flipping is_protected from false to true on a regular user', function (): void {
    $user = createUser('attacker@aqarkom.test');

    $user->is_protected = true;

    expect(fn () => $user->save())->toThrow(ProtectedAccountException::class);

    expect((bool) $user->fresh()->is_protected)->toBeFalse();
});

it('blocks password changes on the protected super admin through the attribute setter', function (): void {
    $user = createProtectedSuperAdmin();
    $original = $user->password;

    $user->password = bcrypt('completely-new-password');

    expect(fn () => $user->save())->toThrow(ProtectedAccountException::class);

    expect($user->fresh()->password)->toBe($original);
});

it('allows name changes on the protected super admin', function (): void {
    $user = createProtectedSuperAdmin();

    $user->name = 'New Display Name';
    $user->save();

    expect($user->fresh()->name)->toBe('New Display Name');
});

it('allows email changes on non-protected users', function (): void {
    $user = createUser('original@aqarkom.test');

    $user->email = 'changed@aqarkom.test';
    $user->save();

    expect($user->fresh()->email)->toBe('changed@aqarkom.test');
});

it('withoutProtection allows deletion', function (): void {
    $user = createProtectedSuperAdmin();

    SuperAdmin::withoutProtection(fn () => $user->delete());

    expect($user->fresh())->toBeNull();
});

it('withoutProtection allows email change', function (): void {
    $user = createProtectedSuperAdmin();

    SuperAdmin::withoutProtection(function () use ($user): void {
        $user->email = 'temporarily-different@aqarkom.test';
        $user->save();
    });

    expect($user->fresh()->email)->toBe('temporarily-different@aqarkom.test');
});

it('withoutProtection allows promoting a regular user to protected', function (): void {
    $user = createUser('promoted@aqarkom.test');

    SuperAdmin::withoutProtection(function () use ($user): void {
        $user->is_protected = true;
        $user->save();
    });

    expect((bool) $user->fresh()->is_protected)->toBeTrue();
});

it('blocks creating a user with is_protected = true outside withoutProtection', function (): void {
    expect(fn () => User::query()->create([
        'name' => 'Attacker',
        'email' => 'attacker-create@aqarkom.test',
        'password' => bcrypt('password-1234'),
        'email_verified_at' => now(),
        'is_protected' => true,
    ]))->toThrow(ProtectedAccountException::class);
});

it('allows creating a user with is_protected = true inside withoutProtection', function (): void {
    $user = createProtectedSuperAdmin('seeded@aqarkom.test');

    expect((bool) $user->fresh()->is_protected)->toBeTrue();
});

it('blocks a direct password write on the protected super admin', function (): void {
    // The escalation this closes: a host "reset password" action or bulk
    // update sets a password on the god account and signs in as it.
    $admin = createProtectedSuperAdmin(password: 'original-password-123');

    expect(fn () => $admin->forceFill(['password' => bcrypt('attacker-chosen-pw')])->save())
        ->toThrow(ProtectedAccountException::class);

    expect(Hash::check('original-password-123', $admin->fresh()->password))->toBeTrue();
});

it('lets the framework issue a remember token for the protected super admin', function (): void {
    // Locking remember_token broke "remember me" and social login: Laravel
    // cycles the column through the user provider on the way in.
    $admin = createProtectedSuperAdmin();

    Auth::login($admin, remember: true);

    expect(Auth::id())->toBe($admin->getKey())
        ->and($admin->fresh()->remember_token)->not->toBeEmpty();
});

it('lets the framework cycle the remember token on logout', function (): void {
    $admin = createProtectedSuperAdmin();

    Auth::login($admin, remember: true);
    $issued = $admin->fresh()->remember_token;

    Auth::logout();

    expect($admin->fresh()->remember_token)->not->toBeEmpty()
        ->and($admin->fresh()->remember_token)->not->toBe($issued);
});

it('blocks a remember_token write when the host locks it explicitly', function (): void {
    config()->set('superadmin.protection.locked_attributes', ['password', 'remember_token']);
    $admin = createProtectedSuperAdmin();

    expect(fn () => $admin->forceFill(['remember_token' => 'attacker-token'])->save())
        ->toThrow(ProtectedAccountException::class);
});

it('allows a password write on the protected super admin inside withoutProtection', function (): void {
    $admin = createProtectedSuperAdmin(password: 'original-password-123');

    SuperAdmin::withoutProtection(fn () => $admin->forceFill(['password' => Hash::make('rotated-by-cli-99')])->save());

    expect(Hash::check('rotated-by-cli-99', $admin->fresh()->password))->toBeTrue();
});

it('leaves password writes on ordinary users alone', function (): void {
    $user = createUser('ordinary@aqarkom.test');

    $user->forceFill(['password' => Hash::make('their-own-new-pw')])->save();

    expect(Hash::check('their-own-new-pw', $user->fresh()->password))->toBeTrue();
});

it('honors an empty locked_attributes list', function (): void {
    config()->set('superadmin.protection.locked_attributes', []);
    $admin = createProtectedSuperAdmin(password: 'original-password-123');

    $admin->forceFill(['password' => Hash::make('host-managed-pw')])->save();

    expect(Hash::check('host-managed-pw', $admin->fresh()->password))->toBeTrue();
});

it('lets the framework rehash the protected super admin password on login when the cost drifted', function (): void {
    // The lockout this closes: an account provisioned under a different
    // BCRYPT_ROUNDS could not sign in at all, because Laravel's rehash-on-login
    // re-stores the same secret at the app's current cost and that write hit
    // the password lock.
    config()->set('hashing.bcrypt.rounds', 12);
    Hash::forgetDrivers();

    $admin = createProtectedSuperAdmin(password: 'cost-drift-pw-12345');
    $stale = password_hash('cost-drift-pw-12345', PASSWORD_BCRYPT, ['cost' => 4]);

    SuperAdmin::withoutProtection(fn () => $admin->forceFill(['password' => $stale])->save());

    expect(Hash::needsRehash($admin->fresh()->password))->toBeTrue();

    expect(Auth::attempt(['email' => $admin->email, 'password' => 'cost-drift-pw-12345']))->toBeTrue();

    $stored = $admin->fresh()->password;

    expect($stored)->not->toBe($stale)
        ->and(Hash::needsRehash($stored))->toBeFalse()
        ->and(Hash::check('cost-drift-pw-12345', $stored))->toBeTrue();
});

it('still refuses an arbitrary password write on the protected super admin when the stored hash is stale', function (): void {
    // A stale hash must not become a window: only the framework's own rehash
    // path is exempt, never a host action that happens to write a valid hash.
    config()->set('hashing.bcrypt.rounds', 12);
    Hash::forgetDrivers();

    $admin = createProtectedSuperAdmin(password: 'original-password-123');
    $stale = password_hash('original-password-123', PASSWORD_BCRYPT, ['cost' => 4]);

    SuperAdmin::withoutProtection(fn () => $admin->forceFill(['password' => $stale])->save());

    expect(fn () => $admin->forceFill(['password' => Hash::make('attacker-chosen-pw')])->save())
        ->toThrow(ProtectedAccountException::class);

    expect($admin->fresh()->password)->toBe($stale);
});

it('rehashes and issues a remember token on the same login', function (): void {
    config()->set('hashing.bcrypt.rounds', 12);
    Hash::forgetDrivers();

    $admin = createProtectedSuperAdmin(password: 'cost-drift-pw-12345');
    $stale = password_hash('cost-drift-pw-12345', PASSWORD_BCRYPT, ['cost' => 4]);

    SuperAdmin::withoutProtection(fn () => $admin->forceFill(['password' => $stale])->save());

    expect(Auth::attempt(['email' => $admin->email, 'password' => 'cost-drift-pw-12345'], remember: true))->toBeTrue();

    expect(Hash::needsRehash($admin->fresh()->password))->toBeFalse()
        ->and($admin->fresh()->remember_token)->not->toBeEmpty();
});
