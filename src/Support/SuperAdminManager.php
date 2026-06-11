<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

final class SuperAdminManager
{
    private bool $protectionBypassed = false;

    public function __construct(private readonly Container $app) {}

    /**
     * The email used to IDENTIFY the protected user. Always null:
     * `is_protected = true` is the only identity signal. Deliberately does
     * NOT read `superadmin.email` — that key is a CREATION default only
     * (see defaultEmail()). If identification keyed on the email, any user
     * who registered the well-known vendor address would pass every gate.
     * The method is kept because `is()` and `user()` call it — null lets
     * them fall through to the `is_protected` lookup without an extra branch.
     */
    public function email(): ?string
    {
        return null;
    }

    /**
     * Email used when none is passed via `ensure([...])`.
     *
     *   1. `superadmin.email` (env SUPER_ADMIN_EMAIL) — one stable vendor
     *      address across the fleet; default superadmin@codenzia.com. This
     *      is the recovery-link mailbox, so it must reach the vendor.
     *   2. superadmin@<host>          — where <host> = parse_url(APP_URL).host
     *   3. superadmin@<slug>.local    — where <slug> = Str::slug(APP_NAME)
     */
    public function defaultEmail(): string
    {
        $configured = $this->config()->get('superadmin.email');

        if (is_string($configured) && $configured !== '') {
            return mb_strtolower($configured);
        }

        $url = (string) $this->config()->get('app.url', '');
        $host = $url !== '' ? parse_url($url, PHP_URL_HOST) : null;

        if (is_string($host) && $host !== '') {
            return 'superadmin@'.mb_strtolower($host);
        }

        $slug = Str::slug((string) $this->config()->get('app.name', 'app')) ?: 'app';

        return 'superadmin@'.$slug.'.local';
    }

    /**
     * The operator-set password from `superadmin.password` (env
     * `SUPER_ADMIN_PASSWORD`), or null when not set. Honored in every
     * environment, including production — the deliberate opt-in for
     * vendor-controlled live demos.
     */
    public function configuredPassword(): ?string
    {
        $configured = $this->config()->get('superadmin.password');

        return is_string($configured) && $configured !== '' ? $configured : null;
    }

    /**
     * The default password when it is a KNOWN value, or null when the
     * package would generate a random one (production, no override).
     * Display-side callers (auto-install output, the `superadmin:ensure`
     * prompt) use this so a random password is never echoed — each call to
     * defaultPassword() in that mode returns a fresh random string, so
     * printing it would show a password that was never stored.
     */
    public function knownDefaultPassword(): ?string
    {
        $configured = $this->configuredPassword();

        if ($configured !== null) {
            return $configured;
        }

        return $this->app->environment('production') ? null : 'superadmin';
    }

    /**
     * Password used when none is passed via `ensure([...])`.
     *
     *   1. `superadmin.password` (env SUPER_ADMIN_PASSWORD) when set.
     *   2. In production: a cryptographically random throwaway — nobody
     *      knows it; the account is claimed via the recovery route or
     *      `php artisan superadmin:ensure`.
     *   3. Elsewhere: the literal "superadmin" — memorable for local dev.
     */
    public function defaultPassword(): string
    {
        return $this->knownDefaultPassword() ?? Str::password(40);
    }

    /**
     * The known password candidate (env override, else the non-production
     * default) ONLY when it verifiably matches the stored hash of the
     * protected account — otherwise null. This is the single safe primitive
     * for displaying the password: it either proves the value against the
     * database or refuses, so callers can never print a stale credential.
     */
    public function verifiedKnownPassword(): ?string
    {
        $known = $this->knownDefaultPassword();

        if ($known === null) {
            return null;
        }

        $user = $this->user();
        $hash = $user?->getAttribute('password');

        if (! is_string($hash) || $hash === '') {
            return null;
        }

        try {
            return Hash::check($known, $hash) ? $known : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Name used when none is passed via `ensure([...])`. Always returns
     * "Super Admin". Mirror of defaultPassword() / defaultEmail() so all
     * three identity defaults live in one place.
     */
    public function defaultName(): string
    {
        return 'Super Admin';
    }

    /**
     * @return class-string<Model>|null
     */
    public function userModel(): ?string
    {
        $configured = $this->config()->get('superadmin.user_model');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $provider = $this->config()->get('auth.defaults.guard') ?? 'web';
        $providerName = $this->config()->get("auth.guards.{$provider}.provider") ?? 'users';
        $model = $this->config()->get("auth.providers.{$providerName}.model");

        return is_string($model) && $model !== '' ? $model : null;
    }

    /**
     * Whether the package is ready to install the super admin.
     *
     * v0.4.0+ — identity defaults (name / email / password / role) are now
     * always available (derived or hardcoded), so the package is always
     * "configured" and this always returns true. The method is kept for
     * backward compatibility with callers / facade stubs.
     */
    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * A user is the protected super admin if either:
     *  - the user's email matches the configured email, OR
     *  - the user has is_protected = true in the database
     *
     * Both signals are checked so tampering with one alone does not disable
     * the protection.
     */
    public function is(?Model $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->getAttribute('is_protected') === true || $user->getAttribute('is_protected') === 1) {
            return true;
        }

        $email = $this->email();

        if ($email === null) {
            return false;
        }

        $userEmail = $user->getAttribute('email');

        return is_string($userEmail) && mb_strtolower($userEmail) === $email;
    }

    /**
     * The fleet-wide "is this user a super admin?" check — true when the user is
     * the protected super admin (see {@see is()}) OR holds the configured
     * super-admin role (see {@see hasConfiguredRole()}). This is the single
     * primitive callers (panel gates, policies, navigation) should use so the
     * definition of "super admin" lives in one place.
     */
    public function isSuperAdmin(?Model $user): bool
    {
        if ($this->is($user)) {
            return true;
        }

        return $this->hasConfiguredRole($user) === true;
    }

    public function user(): ?Model
    {
        $model = $this->userModel();

        if ($model === null) {
            return null;
        }

        /** @var Model $instance */
        $instance = new $model;

        $query = $model::query();

        if (Schema::hasColumn($instance->getTable(), 'is_protected')) {
            $protected = $query->where('is_protected', true)->first();

            if ($protected !== null) {
                return $protected;
            }
        }

        $email = $this->email();

        if ($email === null) {
            return null;
        }

        return $model::query()
            ->whereRaw('LOWER('.$instance->getConnection()->getQueryGrammar()->wrap('email').') = ?', [$email])
            ->first();
    }

    public function exists(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Idempotent get-or-create with optional seeder overrides.
     *
     * Two modes:
     *
     *  - **No args** (auto-install hook / `ensure()`): returns the existing
     *    protected user untouched when present; otherwise creates one using
     *    `defaultName()` / `defaultEmail()` / `defaultPassword()`.
     *
     *  - **With array** (seeder path / `ensure(['password' => 'X', ...])`):
     *    extracts `name`, `email`, `password` keys and force-applies them.
     *    Creates the user when missing; updates fields on the existing user
     *    when present. Omitted keys fall back to package defaults on create
     *    and are left unchanged on update (password specifically — supply
     *    null/omit to keep the current hash).
     *
     * @param  array{name?: string|null, email?: string|null, password?: string|null}|null  $defaults
     */
    public function ensure(?array $defaults = null): Model
    {
        if ($defaults === null) {
            $existing = $this->user();

            if ($existing !== null) {
                return $existing;
            }

            return $this->install($this->defaultPassword(), $this->defaultEmail(), $this->defaultName());
        }

        $password = $defaults['password'] ?? null;
        $email = $defaults['email'] ?? null;
        $name = $defaults['name'] ?? $this->defaultName();

        return $this->install($password, $email, $name);
    }

    /**
     * Idempotently create or update the protected user.
     *
     * Null-handling rules:
     *  - $email = null  → use defaultEmail() in all cases.
     *  - $password = null:
     *      • if creating a new user → use defaultPassword().
     *      • if updating an existing user → KEEP the current password
     *        (lets callers like SetupCommand change just the email).
     *
     * Sets is_protected = true and assigns the configured role if supported.
     */
    public function install(?string $password = null, ?string $email = null, string $name = 'Super Admin'): Model
    {
        $model = $this->userModel();

        if ($model === null) {
            throw new \RuntimeException('Cannot resolve User model. Configure superadmin.user_model or auth.providers.users.model.');
        }

        $email ??= $this->defaultEmail();

        return $this->withoutProtection(function () use ($model, $email, $password, $name): Model {
            // Prefer the protected row; fall back to claiming an existing
            // (non-protected) account that already holds the target email —
            // repairs hosts where a guarded model once dropped the flag, and
            // avoids a unique-constraint crash on the insert below.
            $existing = $this->user() ?? $this->findByEmail($model, $email);

            $attributes = [
                'name' => $name,
                'email' => $email,
                'is_protected' => true,
            ];

            // forceFill throughout: this is the trusted provisioning path, and
            // hosts are *encouraged* to guard is_protected against mass
            // assignment — create()/fill() would silently drop the flag there.
            if ($existing === null) {
                $attributes['password'] = Hash::make($password ?? $this->defaultPassword());
                $attributes['email_verified_at'] = now();

                /** @var Model $instance */
                $instance = new $model;
                $instance->forceFill($attributes)->save();
                $instance = $instance->fresh() ?? $instance;
            } else {
                if ($password !== null) {
                    $attributes['password'] = Hash::make($password);
                }
                $existing->forceFill($attributes)->save();
                $instance = $existing->fresh();
            }

            // Best-effort role assignment — fires whenever Spatie HasRoles is
            // present on the User model and a role is configured.
            $this->assignRole($instance);

            return $instance;
        });
    }

    /**
     * Case-insensitive email lookup, ignoring protection state AND host
     * global scopes (provisioning context — a scope like "approved only"
     * must not hide the row install() needs to claim).
     *
     * @param  class-string<Model>  $model
     */
    private function findByEmail(string $model, string $email): ?Model
    {
        /** @var Model $instance */
        $instance = new $model;

        return $model::query()
            ->withoutGlobalScopes()
            ->whereRaw('LOWER('.$instance->getConnection()->getQueryGrammar()->wrap('email').') = ?', [mb_strtolower($email)])
            ->first();
    }

    /**
     * Bypass the deletion / email-change / flag-change protection for the
     * duration of a callback. Used internally by install(), and exposed
     * for SetupCommand (which updates the protected row's password / email).
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function withoutProtection(callable $callback): mixed
    {
        $previous = $this->protectionBypassed;
        $this->protectionBypassed = true;

        try {
            return $callback();
        } finally {
            $this->protectionBypassed = $previous;
        }
    }

    public function isProtectionBypassed(): bool
    {
        return $this->protectionBypassed;
    }

    /**
     * Role assigned to the protected super admin.
     *
     * Resolution order (v0.4.0+ — no env/config key):
     *
     *   1. `filament-shield.super_admin.name` — auto-discovered when the
     *      host app has `bezhansalleh/filament-shield` installed and
     *      configured. Shield's config is the source of truth.
     *   2. Literal `'super_admin'` — hardcoded fallback when Shield is not
     *      present or its value is empty.
     */
    public function configuredRole(): ?string
    {
        $shield = $this->config()->get('filament-shield.super_admin.name');

        if (is_string($shield) && $shield !== '') {
            return $shield;
        }

        return 'super_admin';
    }

    /**
     * Attempt to assign the configured role to the user. Returns a result
     * enum describing what happened. Best-effort: never throws.
     */
    public function assignRole(Model $user): RoleAssignmentResult
    {
        $role = $this->configuredRole();

        if ($role === null) {
            return RoleAssignmentResult::NotConfigured;
        }

        if (! method_exists($user, 'assignRole')) {
            return RoleAssignmentResult::NotSupported;
        }

        try {
            if (method_exists($user, 'hasRole') && $user->hasRole($role)) {
                return RoleAssignmentResult::AlreadyAssigned;
            }

            $user->assignRole($role);

            return RoleAssignmentResult::Assigned;
        } catch (Throwable) {
            return RoleAssignmentResult::Failed;
        }
    }

    /**
     * Whether the protected user currently has the configured role.
     * Returns null if role checking is not applicable (no role configured
     * or User model does not support hasRole).
     */
    public function hasConfiguredRole(?Model $user = null): ?bool
    {
        $role = $this->configuredRole();

        if ($role === null) {
            return null;
        }

        $user ??= $this->user();

        if ($user === null) {
            return null;
        }

        if (! method_exists($user, 'hasRole')) {
            return null;
        }

        try {
            return (bool) $user->hasRole($role);
        } catch (Throwable) {
            return false;
        }
    }

    private function config(): ConfigRepository
    {
        return $this->app->make('config');
    }
}
