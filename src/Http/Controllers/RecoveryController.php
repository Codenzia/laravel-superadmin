<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Http\Controllers;

use Codenzia\SuperAdmin\Notifications\RecoveryLinkNotification;
use Codenzia\SuperAdmin\Support\SuperAdminManager;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Break-glass recovery flow for the protected super admin.
 *
 * Deliberately self-contained: tokens come from Laravel's password broker
 * repository but the reset URL points back at this controller, so the flow
 * works on hosts (e.g. Filament-only apps) that define no `password.reset`
 * route of their own.
 *
 * Security model: the send endpoint only ever emails the protected
 * account's own address and responds identically whether or not the
 * account, mail config, or token creation succeeded — it leaks nothing.
 * All endpoints share a per-IP and a global rate limit, and every request
 * is logged for monitoring.
 */
final class RecoveryController
{
    public function show(): View
    {
        return view('superadmin::recovery');
    }

    public function send(Request $request, SuperAdminManager $manager): RedirectResponse
    {
        if ($response = $this->throttle($request)) {
            return $response;
        }

        Log::warning('Super admin recovery link requested.', [
            'ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);

        try {
            $user = $manager->user();

            if ($user instanceof CanResetPassword) {
                $token = Password::broker()->getRepository()->create($user);
                $user->notify(new RecoveryLinkNotification($token));
            }
        } catch (\Throwable $e) {
            // Same response either way — failures are for the log only.
            Log::error('Super admin recovery link could not be sent: '.$e->getMessage());
        }

        return redirect()
            ->route('superadmin.recovery.show')
            ->with('superadmin-status', __('If the super admin account exists, a reset link has been emailed to its mailbox.'));
    }

    public function form(Request $request, SuperAdminManager $manager, string $token): View|RedirectResponse
    {
        if ($response = $this->throttle($request)) {
            return $response;
        }

        // Validate the token up front so a stale/expired link fails fast,
        // instead of letting the user fill in a password and only learn on
        // POST that the link was invalid. (The POST path re-checks anyway.)
        $user = $manager->user();

        if (! $user instanceof CanResetPassword
            || ! Password::broker()->tokenExists($user, $token)) {
            return redirect()
                ->route('superadmin.recovery.show')
                ->withErrors(['token' => __('This reset link is invalid or has expired. Request a new one.')]);
        }

        return view('superadmin::reset', ['token' => $token]);
    }

    public function update(Request $request, SuperAdminManager $manager): RedirectResponse
    {
        if ($response = $this->throttle($request)) {
            return $response;
        }

        $validated = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $user = $manager->user();

        if (! $user instanceof CanResetPassword
            || ! Password::broker()->tokenExists($user, $validated['token'])) {
            Log::warning('Super admin recovery: invalid or expired token submitted.', [
                'ip' => $request->ip(),
            ]);

            return redirect()
                ->route('superadmin.recovery.show')
                ->withErrors(['token' => __('This reset link is invalid or has expired. Request a new one.')]);
        }

        // forceFill: password / remember_token may be guarded on host models.
        // Rotating remember_token immediately invalidates any persistent
        // "remember me" cookies — break-glass should evict any live attacker.
        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'remember_token' => Str::random(60),
        ])->save();

        Password::broker()->getRepository()->delete($user);

        // Fires the standard Laravel password-reset event. Stock Laravel
        // ships no listener on it, so this alone does not evict live
        // sessions — hosts running `AuthenticateSession` middleware get
        // that behavior from the session driver check below plus their own
        // middleware; hosts on non-database session drivers should add
        // `AuthenticateSession` if evicting a live attacker's session on
        // reset matters to them.
        event(new PasswordReset($user));

        $this->evictDatabaseSessions($user);

        Log::info('Super admin password set via recovery route.', [
            'ip' => $request->ip(),
        ]);

        return redirect()
            ->route('superadmin.recovery.show')
            ->with('superadmin-status', __('Password updated. You can now sign in with the new password.'));
    }

    /**
     * Best-effort eviction of the protected user's live sessions when the
     * host uses the database session driver. Never breaks the reset itself.
     */
    private function evictDatabaseSessions(Model $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        try {
            $user->getConnection()
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->delete();
        } catch (\Throwable $e) {
            Log::warning('Super admin recovery: failed to evict database sessions: '.$e->getMessage());
        }
    }

    private function throttle(Request $request): ?RedirectResponse
    {
        $config = config('superadmin.recovery.throttle', []);
        $decay = (int) ($config['decay_seconds'] ?? 3600);

        $limits = [
            ['superadmin-recovery:'.$request->ip(), (int) ($config['max_attempts'] ?? 3)],
            ['superadmin-recovery:global', (int) ($config['global_max_attempts'] ?? 10)],
        ];

        foreach ($limits as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                Log::warning('Super admin recovery throttled.', [
                    'ip' => $request->ip(),
                    'key' => $key,
                ]);

                return redirect()
                    ->route('superadmin.recovery.show')
                    ->withErrors(['throttle' => __('Too many attempts. Try again later.')]);
            }
        }

        foreach ($limits as [$key, $max]) {
            RateLimiter::hit($key, $decay);
        }

        return null;
    }
}
