<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Exceptions\ProtectedAccountException;
use Codenzia\SuperAdmin\Facades\SuperAdmin;

beforeEach(function (): void {
    configureSuperAdmin('superadmin@aqarkom.test');
});

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

it('allows password changes on the protected super admin', function (): void {
    $user = createProtectedSuperAdmin();
    $original = $user->password;

    $user->password = bcrypt('completely-new-password');
    $user->save();

    expect($user->fresh()->password)->not->toBe($original);
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

it('creating a user with is_protected = true is allowed (insert, not update)', function (): void {
    $user = createProtectedSuperAdmin('seeded@aqarkom.test');

    expect((bool) $user->fresh()->is_protected)->toBeTrue();
});
