<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Support\SuperAdminManager;

function passwordManager(): SuperAdminManager
{
    return app(SuperAdminManager::class);
}

it('uses the configured password override when set', function (): void {
    config()->set('superadmin.password', 'demo-override-pw');

    expect(passwordManager()->configuredPassword())->toBe('demo-override-pw')
        ->and(passwordManager()->knownDefaultPassword())->toBe('demo-override-pw')
        ->and(passwordManager()->defaultPassword())->toBe('demo-override-pw');
});

it('honors the configured password override even in production', function (): void {
    config()->set('superadmin.password', 'live-demo-pw');
    app()->detectEnvironment(fn (): string => 'production');

    try {
        expect(passwordManager()->knownDefaultPassword())->toBe('live-demo-pw')
            ->and(passwordManager()->defaultPassword())->toBe('live-demo-pw');
    } finally {
        // Restore — Testbench's teardown runs migrate:rollback, which would
        // prompt for confirmation in a production environment.
        app()->detectEnvironment(fn (): string => 'testing');
    }
});

it('defaults to the literal superadmin in local/testing', function (): void {
    config()->set('superadmin.password', null);

    // The suite runs under APP_ENV=testing, one of the trusted dev envs.
    expect(passwordManager()->configuredPassword())->toBeNull()
        ->and(passwordManager()->knownDefaultPassword())->toBe('superadmin')
        ->and(passwordManager()->defaultPassword())->toBe('superadmin');
});

it('generates a random password in staging (not just production)', function (): void {
    config()->set('superadmin.password', null);
    app()->detectEnvironment(fn (): string => 'staging');

    try {
        $first = passwordManager()->defaultPassword();
        $second = passwordManager()->defaultPassword();

        expect(passwordManager()->knownDefaultPassword())->toBeNull()
            ->and($first)->not->toBe('superadmin')
            ->and(mb_strlen($first))->toBeGreaterThanOrEqual(40)
            ->and($first)->not->toBe($second);
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});

it('generates a random password in production with no override', function (): void {
    config()->set('superadmin.password', null);
    app()->detectEnvironment(fn (): string => 'production');

    try {
        $first = passwordManager()->defaultPassword();
        $second = passwordManager()->defaultPassword();

        expect(passwordManager()->knownDefaultPassword())->toBeNull()
            ->and($first)->not->toBe('superadmin')
            ->and(mb_strlen($first))->toBeGreaterThanOrEqual(40)
            ->and($first)->not->toBe($second);
    } finally {
        // Restore — Testbench's teardown runs migrate:rollback, which would
        // prompt for confirmation in a production environment.
        app()->detectEnvironment(fn (): string => 'testing');
    }
});

it('treats an empty env override as unset', function (): void {
    config()->set('superadmin.password', '');

    expect(passwordManager()->configuredPassword())->toBeNull()
        ->and(passwordManager()->knownDefaultPassword())->toBe('superadmin');
});

it('verifiedKnownPassword() returns the default only when it matches the stored hash', function (): void {
    createProtectedSuperAdmin(password: 'superadmin');

    expect(passwordManager()->verifiedKnownPassword())->toBe('superadmin');
});

it('verifiedKnownPassword() returns null for a rotated account', function (): void {
    createProtectedSuperAdmin(password: 'rotated-by-operator-99');

    expect(passwordManager()->verifiedKnownPassword())->toBeNull();
});

it('verifiedKnownPassword() verifies the env override against the hash', function (): void {
    config()->set('superadmin.password', 'demo-host-pw');
    createProtectedSuperAdmin(password: 'demo-host-pw');

    expect(passwordManager()->verifiedKnownPassword())->toBe('demo-host-pw');
});

it('verifiedKnownPassword() returns null when no account exists', function (): void {
    expect(passwordManager()->verifiedKnownPassword())->toBeNull();
});
