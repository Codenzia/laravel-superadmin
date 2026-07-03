<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Facades\SuperAdmin;
use Codenzia\SuperAdmin\Tests\Fixtures\User;
use Codenzia\SuperAdmin\Tests\TestCase;
use Illuminate\Support\Facades\Hash;

uses(TestCase::class)->in('Feature', 'Unit');

/**
 * Legacy helper kept for call-site compatibility. v0.4.0 dropped the
 * `superadmin.email` config key, so setting it is a no-op — identity is now
 * either passed via `SuperAdmin::ensure([...])` or derived from APP_URL.
 * Tests that need a pre-existing protected user should call
 * `createProtectedSuperAdmin()` directly.
 */
function configureSuperAdmin(string $email = 'superadmin@aqarkom.test'): void
{
    // no-op
}

function createProtectedSuperAdmin(string $email = 'superadmin@aqarkom.test', string $password = 'super-secret-pw-12345'): User
{
    return SuperAdmin::withoutProtection(fn (): User => User::query()->create([
        'name' => 'Super Admin',
        'email' => $email,
        'password' => Hash::make($password),
        'email_verified_at' => now(),
        'is_protected' => true,
    ]));
}

function createUser(string $email = 'regular@aqarkom.test'): User
{
    return User::query()->create([
        'name' => 'Regular User',
        'email' => $email,
        'password' => Hash::make('password-1234'),
        'email_verified_at' => now(),
        'is_protected' => false,
    ]);
}
