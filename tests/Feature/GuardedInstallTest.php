<?php

declare(strict_types=1);

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

it('ensure() claims an existing non-protected row holding the default email', function (): void {
    // A host where the flag was dropped (or someone created the address):
    // a plain user already owns superadmin@aqarkom.test.
    $orphan = User::query()->create([
        'name' => 'Orphan',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('old-password-1234'),
        'is_protected' => false,
    ]);

    $user = SuperAdmin::ensure();

    expect($user->getKey())->toBe($orphan->getKey())
        ->and((bool) $user->fresh()->is_protected)->toBeTrue()
        ->and(User::query()->where('email', 'superadmin@aqarkom.test')->count())->toBe(1);
});

it('ensure([...]) with a password claims and re-credentials the existing email row', function (): void {
    $orphan = User::query()->create([
        'name' => 'Orphan',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('old-password-1234'),
        'is_protected' => false,
    ]);

    $user = SuperAdmin::ensure(['password' => 'reclaimed-password-99']);

    expect($user->getKey())->toBe($orphan->getKey())
        ->and((bool) $user->is_protected)->toBeTrue()
        ->and(Hash::check('reclaimed-password-99', $user->password))->toBeTrue();
});
