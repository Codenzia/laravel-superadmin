<?php

declare(strict_types=1);

it('reports not-configured state', function (): void {
    config()->set('superadmin.email', null);

    $this->artisan('superadmin:status')
        ->expectsOutputToContain('not yet installed')
        ->assertExitCode(1);
});

it('reports configured-but-missing-user state', function (): void {
    configureSuperAdmin();

    $this->artisan('superadmin:status')
        ->expectsOutputToContain('user record is missing')
        ->assertExitCode(1);
});

it('reports healthy state when user exists', function (): void {
    configureSuperAdmin();
    createProtectedSuperAdmin();

    $this->artisan('superadmin:status')
        ->expectsOutputToContain('superadmin@aqarkom.test')
        ->assertExitCode(0);
});

it('displays is_protected flag value', function (): void {
    configureSuperAdmin();
    createProtectedSuperAdmin();

    $this->artisan('superadmin:status')
        ->expectsOutputToContain('is_protected')
        ->assertExitCode(0);
});
