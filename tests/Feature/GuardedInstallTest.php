<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Exceptions\ProtectedAccountException;
use Codenzia\SuperAdmin\Facades\SuperAdmin;
use Codenzia\SuperAdmin\Tests\Fixtures\GuardedUser;
use Codenzia\SuperAdmin\Tests\Fixtures\User;
use Illuminate\Support\Facades\Hash;

it('install() sets is_protected on a model that guards it against mass assignment', function (): void {
    config()->set('auth.providers.users.model', GuardedUser::class);
    config()->set('superadmin.user_model', GuardedUser::class);

    $user = SuperAdmin::install('a-strong-password-123');

    expect((bool) $user->is_protected)->toBeTrue()
        ->and($user->email)->toBe('superadmin@aqarkom.test')
        ->and(Hash::check('a-strong-password-123', $user->password))->toBeTrue();
});

it('ensure() refuses to adopt an existing non-protected row holding the default email', function (): void {
    // A plain registration already owns superadmin@aqarkom.test. Promoting it
    // implicitly would hand the god identity to whoever knows its password.
    $orphan = User::query()->create([
        'name' => 'Orphan',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('old-password-1234'),
        'remember_token' => 'old-remember-token',
        'is_protected' => false,
    ]);

    expect(fn () => SuperAdmin::ensure())->toThrow(ProtectedAccountException::class);

    $orphan->refresh();
    expect((bool) $orphan->is_protected)->toBeFalse()
        ->and(Hash::check('old-password-1234', $orphan->password))->toBeTrue()
        ->and($orphan->remember_token)->toBe('old-remember-token');
});

it('ensure([adopt]) claims the existing row and rotates its credentials', function (): void {
    $orphan = User::query()->create([
        'name' => 'Orphan',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('old-password-1234'),
        'remember_token' => 'old-remember-token',
        'is_protected' => false,
    ]);

    $user = SuperAdmin::ensure(['adopt' => true]);

    expect($user->getKey())->toBe($orphan->getKey())
        ->and((bool) $user->is_protected)->toBeTrue()
        ->and(User::query()->where('email', 'superadmin@aqarkom.test')->count())->toBe(1)
        // The previous owner's password and persistent login no longer work.
        ->and(Hash::check('old-password-1234', $user->password))->toBeFalse()
        ->and($user->remember_token)->not->toBe('old-remember-token');
});

it('ensure([password, adopt]) claims and re-credentials the existing email row', function (): void {
    $orphan = User::query()->create([
        'name' => 'Orphan',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('old-password-1234'),
        'remember_token' => 'old-remember-token',
        'is_protected' => false,
    ]);

    $user = SuperAdmin::ensure(['password' => 'reclaimed-password-99', 'adopt' => true]);

    expect($user->getKey())->toBe($orphan->getKey())
        ->and((bool) $user->is_protected)->toBeTrue()
        ->and(Hash::check('reclaimed-password-99', $user->password))->toBeTrue()
        ->and($user->remember_token)->not->toBe('old-remember-token');
});

it('ensure() ignores a same-email row once the protected account exists', function (): void {
    createProtectedSuperAdmin('superadmin@aqarkom.test');

    $returned = SuperAdmin::ensure();

    expect((bool) $returned->is_protected)->toBeTrue();
});
