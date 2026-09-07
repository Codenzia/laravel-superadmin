<?php

declare(strict_types=1);

it('reports missing-account state and exits non-zero', function (): void {
    $this->artisan('superadmin:status')
        ->expectsOutputToContain('account is missing')
        ->assertExitCode(1);
});

it('reports healthy state when the protected user exists', function (): void {
    createProtectedSuperAdmin();

    $this->artisan('superadmin:status')
        ->expectsOutputToContain('superadmin@aqarkom.test')
        ->assertExitCode(0);
});

it('reports the package default password as configured without printing it', function (): void {
    createProtectedSuperAdmin(password: 'superadmin');

    $this->artisan('superadmin:status')
        ->expectsOutputToContain('configured')
        ->doesntExpectOutputToContain('superadmin (default)')
        ->assertExitCode(0);
});

it('reports a rotated password as not configured', function (): void {
    createProtectedSuperAdmin(password: 'rotated-by-operator-99');

    $this->artisan('superadmin:status')
        ->expectsOutputToContain('not configured')
        ->doesntExpectOutputToContain('rotated-by-operator-99')
        ->assertExitCode(0);
});

it('never prints the configured SUPER_ADMIN_PASSWORD value', function (): void {
    config()->set('superadmin.password', 'demo-host-pw');
    createProtectedSuperAdmin(password: 'demo-host-pw');

    $this->artisan('superadmin:status')
        ->expectsOutputToContain('matches SUPER_ADMIN_PASSWORD')
        ->doesntExpectOutputToContain('demo-host-pw')
        ->assertExitCode(0);
});

it('never prints the configured password under verbose diagnostics either', function (): void {
    config()->set('superadmin.password', 'demo-host-pw');
    createProtectedSuperAdmin(password: 'demo-host-pw');

    $this->artisan('superadmin:status', ['--verbose' => true])
        ->doesntExpectOutputToContain('demo-host-pw')
        ->assertExitCode(0);
});

it('points at CLI recovery when the web recovery route is disabled', function (): void {
    config()->set('superadmin.recovery.enabled', false);
    createProtectedSuperAdmin(password: 'rotated-by-operator-99');

    $this->artisan('superadmin:status')
        ->expectsOutputToContain('web recovery is disabled')
        ->assertExitCode(0);
});

it('flags more than one protected account under verbose diagnostics', function (): void {
    createProtectedSuperAdmin('superadmin@aqarkom.test');
    createProtectedSuperAdmin('second-root@aqarkom.test');

    $this->artisan('superadmin:status', ['--verbose' => true])
        ->expectsOutputToContain('accounts with is_protected = true')
        ->assertExitCode(1);
});

it('displays the is_protected flag value in the summary table', function (): void {
    createProtectedSuperAdmin();

    $this->artisan('superadmin:status')
        ->expectsOutputToContain('is_protected')
        ->assertExitCode(0);
});

it('skips the summary email row when no email is configured (falls back to default)', function (): void {
    config()->set('superadmin.email', null);
    config()->set('app.url', 'https://aqarkom.test');
    createProtectedSuperAdmin('superadmin@aqarkom.test');

    $this->artisan('superadmin:status')
        ->expectsOutputToContain('superadmin@aqarkom.test')
        ->assertExitCode(0);
});

it('runs full diagnostics under --verbose and passes for a healthy install', function (): void {
    createProtectedSuperAdmin();

    $this->artisan('superadmin:status', ['--verbose' => true])
        ->expectsOutputToContain('Health diagnostics')
        ->expectsOutputToContain('All checks passed')
        ->assertExitCode(0);
});
