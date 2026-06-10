<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Notifications\RecoveryLinkNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    RateLimiter::clear('superadmin-recovery:127.0.0.1');
    RateLimiter::clear('superadmin-recovery:global');
});

it('shows the recovery page', function (): void {
    $this->get('/superadmin')
        ->assertOk()
        ->assertSee('Super admin recovery');
});

it('registers routes under the configured path', function (): void {
    expect(route('superadmin.recovery.show', absolute: false))->toBe('/superadmin')
        ->and(route('superadmin.recovery.update', absolute: false))->toBe('/superadmin/reset');
});

it('emails a reset link to the protected super admin', function (): void {
    Notification::fake();
    $admin = createProtectedSuperAdmin();

    $this->post('/superadmin')
        ->assertRedirect('/superadmin')
        ->assertSessionHas('superadmin-status');

    Notification::assertSentTo($admin, RecoveryLinkNotification::class);
});

it('responds identically when no super admin exists', function (): void {
    Notification::fake();

    $this->post('/superadmin')
        ->assertRedirect('/superadmin')
        ->assertSessionHas('superadmin-status');

    Notification::assertNothingSent();
});

it('throttles repeated send attempts per ip', function (): void {
    Notification::fake();
    createProtectedSuperAdmin();

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $this->post('/superadmin')->assertSessionHas('superadmin-status');
    }

    $this->post('/superadmin')->assertSessionHasErrors('throttle');
});

it('resets the password with a valid token', function (): void {
    $admin = createProtectedSuperAdmin();
    $token = Password::broker()->getRepository()->create($admin);

    $this->get('/superadmin/reset/'.$token)->assertOk()->assertSee('Set a new super admin password');

    $this->post('/superadmin/reset', [
        'token' => $token,
        'password' => 'brand-new-password-123',
        'password_confirmation' => 'brand-new-password-123',
    ])->assertRedirect('/superadmin')->assertSessionHas('superadmin-status');

    expect(Hash::check('brand-new-password-123', $admin->fresh()->getAttribute('password')))->toBeTrue()
        // Token is single-use.
        ->and(Password::broker()->tokenExists($admin->fresh(), $token))->toBeFalse();
});

it('rejects an invalid token', function (): void {
    $admin = createProtectedSuperAdmin('superadmin@aqarkom.test', 'original-password-123');

    $this->post('/superadmin/reset', [
        'token' => 'not-a-real-token',
        'password' => 'brand-new-password-123',
        'password_confirmation' => 'brand-new-password-123',
    ])->assertSessionHasErrors('token');

    expect(Hash::check('original-password-123', $admin->fresh()->getAttribute('password')))->toBeTrue();
});

it('rejects a short password', function (): void {
    $admin = createProtectedSuperAdmin();
    $token = Password::broker()->getRepository()->create($admin);

    $this->post('/superadmin/reset', [
        'token' => $token,
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');
});
