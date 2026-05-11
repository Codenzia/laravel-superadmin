<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Console\Commands\ResetCommand;
use Codenzia\SuperAdmin\Notifications\VendorCommandInvoked;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    configureSuperAdmin('superadmin@aqarkom.test');
});

it('refuses to run without --confirm', function (): void {
    createProtectedSuperAdmin();

    $this->artisan('superadmin:reset', ['--password' => 'new-strong-pw-12345'])
        ->expectsOutputToContain('vendor-only command')
        ->assertExitCode(1);
});

it('refuses to run if no protected super admin exists', function (): void {
    $this->artisan('superadmin:reset', [
        '--password' => 'new-strong-pw-12345',
        '--confirm' => true,
    ])
        ->expectsOutputToContain('No protected super admin exists')
        ->assertExitCode(1);
});

it('resets the password with a provided value', function (): void {
    $user = createProtectedSuperAdmin('superadmin@aqarkom.test', 'old-password-1234');

    $this->artisan('superadmin:reset', [
        '--password' => 'fresh-password-98765!',
        '--confirm' => true,
    ])
        ->expectsOutputToContain('Super admin password reset')
        ->assertExitCode(0);

    expect(Hash::check('fresh-password-98765!', $user->fresh()->password))->toBeTrue();
});

it('generates and displays a random password when --password is omitted', function (): void {
    createProtectedSuperAdmin();

    $this->artisan('superadmin:reset', ['--confirm' => true])
        ->expectsOutputToContain('Generated password')
        ->assertExitCode(0);
});

it('rejects passwords shorter than 12 characters', function (): void {
    createProtectedSuperAdmin();

    $this->artisan('superadmin:reset', [
        '--password' => 'short',
        '--confirm' => true,
    ])
        ->expectsOutputToContain('at least 12 characters')
        ->assertExitCode(1);
});

it('dispatches VendorCommandInvoked notification on success', function (): void {
    Notification::fake();
    config()->set('superadmin.notifications.mail_to', 'security@aqarkom.test');

    createProtectedSuperAdmin();

    $this->artisan('superadmin:reset', [
        '--password' => 'notify-test-pw-123!',
        '--confirm' => true,
    ])->assertExitCode(0);

    Notification::assertSentOnDemand(VendorCommandInvoked::class);
});

it('skips notification when no recipient is configured', function (): void {
    Notification::fake();
    config()->set('superadmin.notifications.mail_to', null);
    config()->set('superadmin.notifications.slack_webhook', null);

    createProtectedSuperAdmin();

    $this->artisan('superadmin:reset', [
        '--password' => 'silent-test-pw-123!',
        '--confirm' => true,
    ])->assertExitCode(0);

    Notification::assertNothingSent();
});

it('hides the command from artisan list by default', function (): void {
    $command = app(ResetCommand::class);

    expect($command->isHidden())->toBeTrue();
});
