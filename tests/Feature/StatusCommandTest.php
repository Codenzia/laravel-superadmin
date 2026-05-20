<?php

declare(strict_types=1);

it('reports missing-account state and exits non-zero', function (): void {
    configureSuperAdmin();

    $this->artisan('superadmin:status')
        ->expectsOutputToContain('account is missing')
        ->assertExitCode(1);
});

it('reports healthy state when the protected user exists', function (): void {
    configureSuperAdmin();
    createProtectedSuperAdmin();

    $this->artisan('superadmin:status')
        ->expectsOutputToContain('superadmin@aqarkom.test')
        ->assertExitCode(0);
});

it('displays the is_protected flag value in the summary table', function (): void {
    configureSuperAdmin();
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
    configureSuperAdmin();
    createProtectedSuperAdmin();

    $this->artisan('superadmin:status', ['--verbose' => true])
        ->expectsOutputToContain('Health diagnostics')
        ->expectsOutputToContain('All checks passed')
        ->assertExitCode(0);
});
