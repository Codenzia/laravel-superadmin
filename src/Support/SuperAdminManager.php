<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Support;

use Codenzia\SuperAdmin\Exceptions\ProtectedAccountException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

final class SuperAdminManager
{
    private bool $protectionBypassed = false;

    /** @var array<string, bool> */
    private array $hasProtectedColumn = [];

    public function __construct(private readonly Container $app) {}

    /**
     * Inert facade stub kept for backward compatibility. Always returns null.
     *
     * Identity is `is_protected = true`-only (see is()); there is no
     * email-based identity path. Deliberately does NOT read `superadmin.email`
     * — that key is a CREATION default only (see defaultEmail()). Keying
     * identity on an email would let any user who registered the configured
     * address pass every gate.
     */
    public function email(): ?string
    {
        return null;
    }

    /**
     * Email used when none is passed via `ensure([...])`.
     *
     *   1. `superadmin.email` (env SUPER_ADMIN_EMAIL) when explicitly set —
     *      a recovery mailbox the operator controls. No vendor default.
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

        // Memorable password only for genuinely local/dev envs; every other
        // environment (production, staging, uat, demo, ...) gets a random
        // throwaway claimed via the recovery route / superadmin:ensure.
        return $this->app->environment('local', 'testing') ? 'superadmin' : null;
    }

    /**
     * Password used when none is passed via `ensure([...])`.
     *
     *   1. `superadmin.password` (env SUPER_ADMIN_PASSWORD) when set.
     *   2. In local/testing: the literal "superadmin" — memorable for dev.
     *   3. Everywhere else (production, staging, uat, demo, ...): a
     *      cryptographically random throwaway — nobody knows it; the account
     *      is claimed via the recovery route or `php artisan superadmin:ensure`.
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
     * A user is the protected super admin when it has is_protected = true in
     * the database. Identity is is_protected-only (the email-identity path is
     * intentionally inert — see email()).
     *
     * The attribute is coerced with (bool) rather than a strict === check so
     * that a host User model WITHOUT an `is_protected` boolean cast (where
     * MySQL's PDO driver commonly returns the string "1") still resolves as
     * protected. (bool) "0" and (bool) null are false, so non-protected rows
     * are still correctly excluded — fail-safe in both directions.
     */
    public function is(?Model $user): bool
    {
        if ($user === null) {
            return false;
        }

        return (bool) $user->getAttribute('is_protected') === true;
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

    /**
     * The protected super admin row, or null when there is none.
     *
     * Global scopes are deliberately removed: the protected account is a
     * single root identity, and a host scope (tenant, "approved only",
     * soft-delete-adjacent filters) hiding it would make discovery report
     * "missing" and let provisioning install a SECOND protected account. This
     * is the same explicit provisioning scope policy findByEmail() applies.
     *
     * Deterministic on the lowest primary key when a host somehow holds more
     * than one protected row; `superadmin:status` surfaces that as a problem
     * (see protectedAccountCount()).
     */
    public function user(): ?Model
    {
        $model = $this->userModel();

        if ($model === null) {
            return null;
        }

        /** @var Model $instance */
        $instance = new $model;

        if (! $this->hasProtectedColumn($instance)) {
            return null;
        }

        return $this->protectedQuery($model)->orderBy($instance->getKeyName())->first();
    }

    /**
     * How many protected accounts exist. More than one is a misconfiguration:
     * identity resolution becomes ambiguous, so diagnostics report it.
     */
    public function protectedAccountCount(): int
    {
        $model = $this->userModel();

        if ($model === null) {
            return 0;
        }

        /** @var Model $instance */
        $instance = new $model;

        if (! $this->hasProtectedColumn($instance)) {
            return 0;
        }

        return $this->protectedQuery($model)->count();
    }

    /**
     * @param  class-string<Model>  $model
     * @return Builder<Model>
     */
    private function protectedQuery(string $model): Builder
    {
        return $model::query()->withoutGlobalScopes()->where('is_protected', true);
    }

    /**
     * Memoized `is_protected` column check, keyed by connection + table. The
     * manager is a container singleton — in a queue worker or a tenant switch
     * it outlives a single request — so one cached boolean would answer for
     * the wrong schema. The key carries the identity instead, and the check
     * runs on the model's OWN connection rather than the default one.
     */
    private function hasProtectedColumn(Model $instance): bool
    {
        $connection = $instance->getConnectionName();
        $table = $instance->getTable();

        return $this->hasProtectedColumn[($connection ?? '').'.'.$table]
            ??= Schema::connection($connection)->hasColumn($table, 'is_protected');
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
     *    when present. Keys that are OMITTED (not merely null) fall back to
     *    package defaults on create and are left untouched on update — so a
     *    password-only `ensure()` never rewrites the account's name or email.
     *
     *    The `adopt` key opts into claiming a pre-existing ordinary account
     *    that already holds the target email. Without it that case is refused
     *    (see install()).
     *
     * @param  array{name?: string|null, email?: string|null, password?: string|null, adopt?: bool}|null  $defaults
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

        return $this->install(
            $defaults['password'] ?? null,
            $defaults['email'] ?? null,
            array_key_exists('name', $defaults) ? $defaults['name'] : null,
            (bool) ($defaults['adopt'] ?? false),
        );
    }

    /**
     * Idempotently create or update the protected user.
     *
     * Null-handling rules:
     *  - $email = null:
     *      • if creating a new user → use defaultEmail().
     *      • if updating an existing user → KEEP the current email.
     *  - $name = null:
     *      • if creating a new user → use defaultName().
     *      • if updating an existing user → KEEP the current name.
     *  - $password = null:
     *      • if creating a new user → use defaultPassword().
     *      • if updating an existing user → KEEP the current password
     *        (lets callers change just the email).
     *
     * Adoption: when no protected account exists but an ORDINARY account
     * already holds the target email, promoting it silently would hand the
     * gate-bypassing root identity to whoever knows that account's existing
     * password (and to any live "remember me" cookie it issued). That case is
     * refused unless `$adopt` is passed — and an adoption always rotates the
     * password and the remember token, so the previous owner's credentials
     * cannot reach the promoted account.
     *
     * Sets is_protected = true and assigns the configured role if supported.
     */
    public function install(?string $password = null, ?string $email = null, ?string $name = null, bool $adopt = false): Model
    {
        $model = $this->userModel();

        if ($model === null) {
            throw new \RuntimeException('Cannot resolve User model. Configure superadmin.user_model or auth.providers.users.model.');
        }

        $email = $email !== null ? mb_strtolower($email) : null;

        return $this->withoutProtection(function () use ($model, $email, $password, $name, $adopt): Model {
            $existing = $this->user();
            $adopted = false;

            if ($existing === null) {
                // An ordinary account may already hold the address this install
                // would create. Claiming it is a real repair path (a guarded
                // model once dropped the flag) but it is never implicit.
                $claimable = $this->findByEmail($model, $email ?? $this->defaultEmail());

                if ($claimable !== null) {
                    if (! $adopt) {
                        throw ProtectedAccountException::cannotAdoptExistingAccount($email ?? $this->defaultEmail());
                    }

                    $existing = $claimable;
                    $adopted = true;
                }
            }

            $attributes = ['is_protected' => true];

            // forceFill throughout: this is the trusted provisioning path, and
            // hosts are *encouraged* to guard is_protected against mass
            // assignment — create()/fill() would silently drop the flag there.
            if ($existing === null) {
                $attributes['name'] = $name ?? $this->defaultName();
                $attributes['email'] = $email ?? $this->defaultEmail();
                $attributes['password'] = Hash::make($password ?? $this->defaultPassword());
                $attributes['email_verified_at'] = now();

                /** @var Model $instance */
                $instance = new $model;
                $instance->forceFill($attributes)->save();
                $instance = $instance->fresh() ?? $instance;
            } else {
                if ($name !== null) {
                    $attributes['name'] = $name;
                }
                if ($email !== null) {
                    $attributes['email'] = $email;
                }
                if ($password !== null) {
                    $attributes['password'] = Hash::make($password);
                }

                if ($adopted) {
                    // Turning an ordinary account into the root identity: the
                    // old password and any persistent login must stop working.
                    $attributes['password'] = Hash::make($password ?? $this->defaultPassword());
                    $attributes['remember_token'] = Str::random(60);
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
     * The role name explicitly opted into via `superadmin.role`
     * (env `SUPER_ADMIN_ROLE`), or null when unset. This is the only source
     * that also *creates* the role row (see ensureRoleExists()); the Shield /
     * literal fallbacks below are best-effort assign-only.
     */
    public function explicitRole(): ?string
    {
        $configured = $this->config()->get('superadmin.role');

        return is_string($configured) && $configured !== '' ? $configured : null;
    }

    /**
     * Guard name used when creating the configured role. Falls back to the
     * app's default guard (`auth.defaults.guard`) when `superadmin.role_guard`
     * is unset.
     */
    public function roleGuard(): string
    {
        $configured = $this->config()->get('superadmin.role_guard');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $default = $this->config()->get('auth.defaults.guard');

        return is_string($default) && $default !== '' ? $default : 'web';
    }

    /**
     * Role assigned to the protected super admin.
     *
     * Resolution order:
     *
     *   1. `superadmin.role` (env `SUPER_ADMIN_ROLE`) — the explicit opt-in.
     *   2. `filament-shield.super_admin.name` — auto-discovered when the
     *      host app has `bezhansalleh/filament-shield` installed and
     *      configured. Shield's config is the source of truth.
     *   3. Literal `'super_admin'` — hardcoded fallback when neither of the
     *      above is present or set.
     */
    public function configuredRole(): ?string
    {
        $explicit = $this->explicitRole();

        if ($explicit !== null) {
            return $explicit;
        }

        $shield = $this->config()->get('filament-shield.super_admin.name');

        if (is_string($shield) && $shield !== '') {
            return $shield;
        }

        return 'super_admin';
    }

    /**
     * Resolve the Spatie Role model class, or null when
     * spatie/laravel-permission is not installed. Guards every role-creation
     * path so the package degrades gracefully without Spatie.
     *
     * @return class-string<Model>|null
     */
    public function roleModel(): ?string
    {
        $configured = $this->config()->get('permission.models.role');

        if (is_string($configured) && $configured !== '' && class_exists($configured)) {
            return $configured;
        }

        $canonical = 'Spatie\\Permission\\Models\\Role';

        return class_exists($canonical) ? $canonical : null;
    }

    /**
     * Ensure the given role row exists so a create-from-absent install can
     * assign it. Only fires when the role was EXPLICITLY opted into via
     * `superadmin.role` — the Shield / literal fallbacks stay assign-only, so
     * a null `superadmin.role` leaves role rows untouched. No-op (never fatal)
     * when spatie/laravel-permission is absent. Best-effort: swallows failures.
     */
    private function ensureRoleExists(string $role): void
    {
        if ($this->explicitRole() === null) {
            return;
        }

        $roleModel = $this->roleModel();

        if ($roleModel === null) {
            return;
        }

        try {
            $roleModel::query()->firstOrCreate([
                'name' => $role,
                'guard_name' => $this->roleGuard(),
            ]);
        } catch (Throwable) {
            // Best-effort — assignRole() below reports Failed if it can't
            // resolve the role for any reason.
        }
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
            // When opted in via `superadmin.role`, create the role row first
            // so a fresh install (role does not exist yet) still succeeds.
            $this->ensureRoleExists($role);

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
