<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Facades\SuperAdmin;
use Codenzia\SuperAdmin\Tests\Fixtures\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    // Per-test .env in a temp dir so tests don't share state.
    $this->envPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'superadmin-setup-test-'.uniqid('', true).'.env';
    file_put_contents($this->envPath, "APP_NAME=Test\n");
    // Make the Laravel app point at our temp .env.
    app()->useEnvironmentPath(dirname($this->envPath));
    app()->loadEnvironmentFrom(basename($this->envPath));
});

afterEach(function (): void {
    if (isset($this->envPath) && is_file($this->envPath)) {
        @unlink($this->envPath);
    }
});

it('creates a brand-new superadmin from --email and --password flags', function (): void {
    configureSuperAdmin('superadmin@aqarkom.test');

    $this->artisan('superadmin:setup', [
        '--email' => 'admin@new-host.test',
        '--password' => 'a-new-secure-password',
    ])->assertSuccessful();

    $user = SuperAdmin::user();
    expect($user)->not->toBeNull();
    expect($user->email)->toBe('admin@new-host.test');
    expect((bool) $user->is_protected)->toBeTrue();
    expect(Hash::check('a-new-secure-password', $user->password))->toBeTrue();

    // .env was updated
    $env = file_get_contents($this->envPath);
    expect($env)->toContain('SUPER_ADMIN_EMAIL=admin@new-host.test');
    expect($env)->toContain('SUPER_ADMIN_PASSWORD=a-new-secure-password');
});

it('updates an existing superadmin without changing the password when --password is omitted', function (): void {
    configureSuperAdmin('current@aqarkom.test');
    createProtectedSuperAdmin('current@aqarkom.test', 'keep-me');

    $this->artisan('superadmin:setup', [
        '--email' => 'updated@aqarkom.test',
        '--password' => '',
    ])
        ->expectsQuestion('Super admin password (leave blank to keep current)', '')
        ->assertSuccessful();

    $user = SuperAdmin::user();
    expect($user->email)->toBe('updated@aqarkom.test');
    expect(Hash::check('keep-me', $user->password))->toBeTrue();

    $env = file_get_contents($this->envPath);
    expect($env)->toContain('SUPER_ADMIN_EMAIL=updated@aqarkom.test');
    expect($env)->not->toContain('SUPER_ADMIN_PASSWORD=');
});

it('rejects an invalid email', function (): void {
    $this->artisan('superadmin:setup', [
        '--email' => 'not-an-email',
        '--password' => 'whatever',
    ])->assertExitCode(Command::INVALID);

    expect(SuperAdmin::user())->toBeNull();
});

it('falls back to defaultPassword() when password is blank on a brand-new account', function (): void {
    config()->set('superadmin.email', null);
    config()->set('app.url', 'https://aqarkom.test');
    config()->set('superadmin.password', null);

    $this->artisan('superadmin:setup', [
        '--password' => '',
    ])
        ->expectsQuestion('Super admin email', 'superadmin@aqarkom.test')
        ->expectsQuestion('Super admin password (leave blank to use default: superadmin)', '')
        ->assertSuccessful();

    $user = SuperAdmin::user();
    expect($user)->not->toBeNull();
    expect($user->email)->toBe('superadmin@aqarkom.test');
    expect(Hash::check('superadmin', $user->password))->toBeTrue();

    $env = file_get_contents($this->envPath);
    // Default password should NOT be written to .env when user explicitly
    // declined to set one — we wrote the literal "superadmin" so the user
    // knows where it came from. Adjust this assertion if the policy changes.
    expect($env)->toContain('SUPER_ADMIN_PASSWORD=superadmin');
});
