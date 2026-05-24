<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Facades\SuperAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

it('creates a brand-new superadmin from --name, --email, and --password flags', function (): void {
    $this->artisan('superadmin:ensure', [
        '--name' => 'Seeded Admin',
        '--email' => 'admin@new-host.test',
        '--password' => 'a-new-secure-password',
    ])->assertSuccessful();

    $user = SuperAdmin::user();
    expect($user)->not->toBeNull();
    expect($user->name)->toBe('Seeded Admin');
    expect($user->email)->toBe('admin@new-host.test');
    expect((bool) $user->is_protected)->toBeTrue();
    expect(Hash::check('a-new-secure-password', $user->password))->toBeTrue();
});

it('updates an existing superadmin without changing the password when --password is omitted', function (): void {
    createProtectedSuperAdmin('current@aqarkom.test', 'keep-me');

    $this->artisan('superadmin:ensure', [
        '--name' => 'Super Admin',
        '--email' => 'updated@aqarkom.test',
    ])
        ->expectsQuestion('Super admin password (leave blank to keep current)', '')
        ->assertSuccessful();

    $user = SuperAdmin::user();
    expect($user->email)->toBe('updated@aqarkom.test');
    expect(Hash::check('keep-me', $user->password))->toBeTrue();
});

it('rejects an invalid email', function (): void {
    $this->artisan('superadmin:ensure', [
        '--name' => 'Bad Email Admin',
        '--email' => 'not-an-email',
        '--password' => 'whatever',
    ])->assertExitCode(Command::INVALID);

    expect(SuperAdmin::user())->toBeNull();
});

it('falls back to defaultPassword() when password is blank on a brand-new account', function (): void {
    config()->set('app.url', 'https://aqarkom.test');

    $this->artisan('superadmin:ensure', [
        '--password' => '',
    ])
        ->expectsQuestion('Super admin name', 'Super Admin')
        ->expectsQuestion('Super admin email', 'superadmin@aqarkom.test')
        ->expectsQuestion('Super admin password (leave blank to use default: superadmin)', '')
        ->assertSuccessful();

    $user = SuperAdmin::user();
    expect($user)->not->toBeNull();
    expect($user->email)->toBe('superadmin@aqarkom.test');
    expect(Hash::check('superadmin', $user->password))->toBeTrue();
});

it('does NOT write any credentials to .env', function (): void {
    // Point Laravel at a temp .env so we can read it back.
    $envPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'superadmin-ensure-test-'.uniqid('', true).'.env';
    file_put_contents($envPath, "APP_NAME=Test\n");
    app()->useEnvironmentPath(dirname($envPath));
    app()->loadEnvironmentFrom(basename($envPath));

    try {
        $this->artisan('superadmin:ensure', [
            '--name' => 'Super Admin',
            '--email' => 'never-in-env@aqarkom.test',
            '--password' => 'never-in-env-pw',
        ])->assertSuccessful();

        $env = file_get_contents($envPath);
        expect($env)->not->toContain('SUPER_ADMIN_EMAIL');
        expect($env)->not->toContain('SUPER_ADMIN_PASSWORD');
        expect($env)->not->toContain('never-in-env@aqarkom.test');
        expect($env)->not->toContain('never-in-env-pw');
    } finally {
        @unlink($envPath);
    }
});
