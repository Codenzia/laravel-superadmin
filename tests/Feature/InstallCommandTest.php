<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Console\Commands\InstallCommand;
use Codenzia\SuperAdmin\Facades\SuperAdmin;
use Codenzia\SuperAdmin\Tests\Fixtures\User;
use Illuminate\Support\Facades\Hash;

it('installs a fresh super admin with --confirm', function (): void {
    $this->artisan('superadmin:install', [
        '--email' => 'superadmin@aqarkom.test',
        '--password' => 'super-strong-pw-123!',
        '--confirm' => true,
    ])
        ->expectsOutputToContain('Protected super admin installed')
        ->assertExitCode(0);

    $user = User::query()->where('email', 'superadmin@aqarkom.test')->first();
    expect($user)->not->toBeNull();
    expect((bool) $user->is_protected)->toBeTrue();
    expect(Hash::check('super-strong-pw-123!', $user->password))->toBeTrue();
});

it('refuses to run without --confirm', function (): void {
    $this->artisan('superadmin:install', [
        '--email' => 'superadmin@aqarkom.test',
        '--password' => 'super-strong-pw-123!',
    ])
        ->expectsOutputToContain('vendor-only command')
        ->assertExitCode(1);
});

it('refuses to re-install if a protected account already exists', function (): void {
    createProtectedSuperAdmin('existing@aqarkom.test');
    configureSuperAdmin('existing@aqarkom.test');

    $this->artisan('superadmin:install', [
        '--email' => 'someone@aqarkom.test',
        '--password' => 'another-strong-pw-1!',
        '--confirm' => true,
    ])
        ->expectsOutputToContain('protected super admin already exists')
        ->assertExitCode(1);
});

it('rejects passwords shorter than 12 characters', function (): void {
    $this->artisan('superadmin:install', [
        '--email' => 'superadmin@aqarkom.test',
        '--password' => 'short',
        '--confirm' => true,
    ])
        ->expectsOutputToContain('password of at least 12 characters')
        ->assertExitCode(1);
});

it('rejects invalid email addresses', function (): void {
    $this->artisan('superadmin:install', [
        '--email' => 'not-an-email',
        '--password' => 'super-strong-pw-123!',
        '--confirm' => true,
    ])
        ->expectsOutputToContain('valid email is required')
        ->assertExitCode(1);
});

it('requires the typed phrase when enabled', function (): void {
    config()->set('superadmin.vendor_commands.require_typed_phrase', true);
    config()->set('superadmin.vendor_commands.typed_phrase', 'I AM THE VENDOR');

    $this->artisan('superadmin:install', [
        '--email' => 'superadmin@aqarkom.test',
        '--password' => 'super-strong-pw-123!',
        '--confirm' => true,
    ])
        ->expectsQuestion('Type the following phrase exactly to proceed: "I AM THE VENDOR"', 'wrong')
        ->expectsOutputToContain('Phrase did not match')
        ->assertExitCode(1);
});

it('accepts the typed phrase when correctly typed', function (): void {
    config()->set('superadmin.vendor_commands.require_typed_phrase', true);
    config()->set('superadmin.vendor_commands.typed_phrase', 'I AM THE VENDOR');

    $this->artisan('superadmin:install', [
        '--email' => 'superadmin@aqarkom.test',
        '--password' => 'super-strong-pw-123!',
        '--confirm' => true,
    ])
        ->expectsQuestion('Type the following phrase exactly to proceed: "I AM THE VENDOR"', 'I AM THE VENDOR')
        ->assertExitCode(0);

    expect(SuperAdmin::exists())->toBeTrue();
});

it('hides the command from artisan list by default', function (): void {
    $command = app(InstallCommand::class);

    expect($command->isHidden())->toBeTrue();
});
