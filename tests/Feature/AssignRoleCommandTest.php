<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Console\Commands\AssignRoleCommand;
use Codenzia\SuperAdmin\Notifications\VendorCommandInvoked;
use Codenzia\SuperAdmin\Tests\Fixtures\UserWithRoles;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    UserWithRoles::reset();
    config()->set('auth.providers.users.model', UserWithRoles::class);
    config()->set('superadmin.user_model', UserWithRoles::class);
    configureSuperAdmin('superadmin@aqarkom.test');
});

it('refuses without --confirm', function (): void {
    UserWithRoles::query()->create([
        'name' => 'Admin',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => true,
    ]);

    $this->artisan('superadmin:assign-role')
        ->expectsOutputToContain('vendor-only command')
        ->assertExitCode(1);
});

it('refuses if no protected user exists', function (): void {
    $this->artisan('superadmin:assign-role', ['--confirm' => true])
        ->expectsOutputToContain('No protected super admin exists')
        ->assertExitCode(1);
});

it('refuses if no role is configured', function (): void {
    UserWithRoles::query()->create([
        'name' => 'Admin',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => true,
    ]);

    config()->set('superadmin.role', null);

    $this->artisan('superadmin:assign-role', ['--confirm' => true])
        ->expectsOutputToContain('No role is configured')
        ->assertExitCode(1);
});

it('assigns the role successfully when it exists', function (): void {
    $user = UserWithRoles::query()->create([
        'name' => 'Admin',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => true,
    ]);

    UserWithRoles::$rolesInDatabase = ['super_admin'];
    config()->set('superadmin.role', 'super_admin');

    $this->artisan('superadmin:assign-role', ['--confirm' => true])
        ->expectsOutputToContain('assigned successfully')
        ->assertExitCode(0);

    expect($user->fresh()->hasRole('super_admin'))->toBeTrue();
});

it('fails loudly when the role does not exist', function (): void {
    UserWithRoles::query()->create([
        'name' => 'Admin',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => true,
    ]);

    UserWithRoles::$rolesInDatabase = []; // role table empty
    config()->set('superadmin.role', 'super_admin');

    $this->artisan('superadmin:assign-role', ['--confirm' => true])
        ->expectsOutputToContain('FAILED to assign')
        ->expectsOutputToContain('shield:install')
        ->assertExitCode(1);
});

it('reports AlreadyAssigned on subsequent runs', function (): void {
    $user = UserWithRoles::query()->create([
        'name' => 'Admin',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => true,
    ]);

    UserWithRoles::$rolesByUserId[$user->getKey()] = ['super_admin'];
    config()->set('superadmin.role', 'super_admin');

    $this->artisan('superadmin:assign-role', ['--confirm' => true])
        ->expectsOutputToContain('was already assigned')
        ->assertExitCode(0);
});

it('dispatches VendorCommandInvoked notification on success', function (): void {
    Notification::fake();
    config()->set('superadmin.notifications.mail_to', 'security@aqarkom.test');

    UserWithRoles::query()->create([
        'name' => 'Admin',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => true,
    ]);

    UserWithRoles::$rolesInDatabase = ['super_admin'];
    config()->set('superadmin.role', 'super_admin');

    $this->artisan('superadmin:assign-role', ['--confirm' => true])->assertExitCode(0);

    Notification::assertSentOnDemand(VendorCommandInvoked::class);
});

it('hides the command from artisan list by default', function (): void {
    $command = app(AssignRoleCommand::class);

    expect($command->isHidden())->toBeTrue();
});
