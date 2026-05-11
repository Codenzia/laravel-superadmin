<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Tests\Fixtures\User;

beforeEach(function (): void {
    configureSuperAdmin('superadmin@aqarkom.test');
});

it('isSuperAdmin() returns true for a flagged user', function (): void {
    $user = createProtectedSuperAdmin();

    expect($user->isSuperAdmin())->toBeTrue();
});

it('isSuperAdmin() returns false for other users', function (): void {
    $user = createUser('other@aqarkom.test');

    expect($user->isSuperAdmin())->toBeFalse();
});

it('superAdmin scope returns only flagged rows', function (): void {
    createProtectedSuperAdmin();
    createUser('a@aqarkom.test');
    createUser('b@aqarkom.test');

    $results = User::query()->superAdmin()->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->email)->toBe('superadmin@aqarkom.test');
});

it('exceptSuperAdmin scope excludes the protected account', function (): void {
    createProtectedSuperAdmin();
    createUser('a@aqarkom.test');
    createUser('b@aqarkom.test');

    $results = User::query()->exceptSuperAdmin()->get();

    expect($results)->toHaveCount(2);
    expect($results->pluck('email')->all())->not->toContain('superadmin@aqarkom.test');
});
