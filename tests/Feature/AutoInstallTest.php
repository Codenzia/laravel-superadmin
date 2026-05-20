<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Facades\SuperAdmin;
use Codenzia\SuperAdmin\Tests\Fixtures\User;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Event;

it('auto-installs when MigrationsEnded fires with no protected user', function (): void {
    config()->set('superadmin.auto_install', true);
    config()->set('superadmin.email', null);
    config()->set('app.url', 'https://aqarkom.test');

    expect(SuperAdmin::exists())->toBeFalse();

    Event::dispatch(new MigrationsEnded('up', []));

    expect(SuperAdmin::exists())->toBeTrue();
    $user = SuperAdmin::user();
    expect($user->email)->toBe('superadmin@aqarkom.test');
    expect((bool) $user->is_protected)->toBeTrue();
});

it('auto-install is idempotent — second MigrationsEnded does not duplicate', function (): void {
    config()->set('superadmin.auto_install', true);
    config()->set('superadmin.email', 'superadmin@aqarkom.test');

    Event::dispatch(new MigrationsEnded('up', []));
    $first = SuperAdmin::user();
    expect($first)->not->toBeNull();

    Event::dispatch(new MigrationsEnded('up', []));
    $second = SuperAdmin::user();

    expect($second->getKey())->toBe($first->getKey());
    expect(User::query()->where('is_protected', true)->count())->toBe(1);
});

it('auto-install is a no-op when superadmin.auto_install is false', function (): void {
    config()->set('superadmin.auto_install', false);

    Event::dispatch(new MigrationsEnded('up', []));

    expect(SuperAdmin::exists())->toBeFalse();
});

it('auto-install is a no-op when a protected user already exists', function (): void {
    config()->set('superadmin.auto_install', true);
    configureSuperAdmin('superadmin@aqarkom.test');
    $original = createProtectedSuperAdmin('superadmin@aqarkom.test', 'do-not-touch');

    Event::dispatch(new MigrationsEnded('up', []));

    $reloaded = SuperAdmin::user();
    expect($reloaded->getKey())->toBe($original->getKey());
    expect($reloaded->password)->toBe($original->password); // not rotated
});

it('auto-install handler swallows install failures and lets migrate finish', function (): void {
    config()->set('superadmin.auto_install', true);
    config()->set('superadmin.user_model', 'NonExistent\\Model\\Class');

    // The hook should not throw — it logs to STDERR and returns.
    Event::dispatch(new MigrationsEnded('up', []));

    // No user created, no exception bubbled up.
    expect(true)->toBeTrue();
});
