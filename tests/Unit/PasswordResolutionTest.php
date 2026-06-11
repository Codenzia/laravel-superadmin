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

it('defaults to the literal superadmin outside production', function (): void {
    config()->set('superadmin.password', null);

    expect(passwordManager()->configuredPassword())->toBeNull()
        ->and(passwordManager()->knownDefaultPassword())->toBe('superadmin')
        ->and(passwordManager()->defaultPassword())->toBe('superadmin');
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
