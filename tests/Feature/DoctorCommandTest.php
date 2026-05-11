<?php

declare(strict_types=1);
use Codenzia\SuperAdmin\Tests\Fixtures\User;
use Codenzia\SuperAdmin\Tests\Fixtures\UserWithRoles;
use Illuminate\Support\Facades\Hash;

it('reports failure when SUPER_ADMIN_EMAIL is missing', function (): void {
    config()->set('superadmin.email', null);

    $this->artisan('superadmin:doctor')
        ->expectsOutputToContain('SUPER_ADMIN_EMAIL is not set')
        ->assertExitCode(1);
});

it('reports failure when protected user does not exist', function (): void {
    configureSuperAdmin();

    $this->artisan('superadmin:doctor')
        ->expectsOutputToContain('No protected user found')
        ->assertExitCode(1);
});

it('reports success when everything is configured correctly', function (): void {
    configureSuperAdmin();
    createProtectedSuperAdmin();
    config()->set('superadmin.notifications.mail_to', 'security@aqarkom.test');

    $this->artisan('superadmin:doctor')
        ->expectsOutputToContain('All checks passed')
        ->assertExitCode(0);
});

it('warns when notifications are not configured', function (): void {
    configureSuperAdmin();
    createProtectedSuperAdmin();
    config()->set('superadmin.notifications.mail_to', null);
    config()->set('superadmin.notifications.slack_webhook', null);

    $this->artisan('superadmin:doctor')
        ->expectsOutputToContain('No notification recipient configured')
        ->assertExitCode(1);
});

it('fails when protected user has is_protected = false', function (): void {
    configureSuperAdmin('superadmin@aqarkom.test');

    User::query()->create([
        'name' => 'Mismatch',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => false,
    ]);
    config()->set('superadmin.notifications.mail_to', 'x@y.test');

    $this->artisan('superadmin:doctor')
        ->expectsOutputToContain('is_protected = false')
        ->assertExitCode(1);
});

it('fails when protection is disabled', function (): void {
    configureSuperAdmin();
    createProtectedSuperAdmin();
    config()->set('superadmin.protection.enabled', false);
    config()->set('superadmin.notifications.mail_to', 'x@y.test');

    $this->artisan('superadmin:doctor')
        ->expectsOutputToContain('Protection is disabled')
        ->assertExitCode(1);
});

it('fails when the configured role is not assigned to the protected user', function (): void {
    UserWithRoles::reset();
    config()->set('auth.providers.users.model', UserWithRoles::class);
    config()->set('superadmin.user_model', UserWithRoles::class);
    configureSuperAdmin('superadmin@aqarkom.test');
    config()->set('superadmin.role', 'super_admin');
    config()->set('superadmin.notifications.mail_to', 'x@y.test');

    UserWithRoles::query()->create([
        'name' => 'Admin',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => true,
    ]);
    // Role is not assigned to the user

    $this->artisan('superadmin:doctor')
        ->expectsOutputToContain('does not have role')
        ->assertExitCode(1);
});

it('passes when the configured role is assigned to the protected user', function (): void {
    UserWithRoles::reset();
    config()->set('auth.providers.users.model', UserWithRoles::class);
    config()->set('superadmin.user_model', UserWithRoles::class);
    configureSuperAdmin('superadmin@aqarkom.test');
    config()->set('superadmin.role', 'super_admin');
    config()->set('superadmin.notifications.mail_to', 'x@y.test');

    $user = UserWithRoles::query()->create([
        'name' => 'Admin',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => true,
    ]);
    UserWithRoles::$rolesByUserId[$user->getKey()] = ['super_admin'];

    $this->artisan('superadmin:doctor')
        ->expectsOutputToContain('All checks passed')
        ->assertExitCode(0);
});
