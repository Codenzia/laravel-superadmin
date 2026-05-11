<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Tests\Fixtures\User;
use Codenzia\SuperAdmin\Tests\TestCase;
use Illuminate\Support\Facades\Hash;

uses(TestCase::class)->in('Feature', 'Unit');

function configureSuperAdmin(string $email = 'superadmin@aqarkom.test'): void
{
    config()->set('superadmin.email', $email);
}

function createProtectedSuperAdmin(string $email = 'superadmin@aqarkom.test', string $password = 'super-secret-pw-12345'): User
{
    return User::query()->create([
        'name' => 'Super Admin',
        'email' => $email,
        'password' => Hash::make($password),
        'email_verified_at' => now(),
        'is_protected' => true,
    ]);
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
