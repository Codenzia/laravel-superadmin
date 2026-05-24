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
     * Returns the configured super-admin email. v0.4.0 removed the config /
     * env key entirely, so this now always returns null. The method is kept
     * because `is()` and `user()` call it — null lets them fall through to
     * the `is_protected = true` lookup cleanly without an extra branch.
     */
    public function email(): ?string
    {
        return null;
    }

    /**
     * Email used when none is passed via `ensure([...])`. Always derived
     * from the host's own app config so the package never bakes in a vendor
     * domain:
     *
     *   1. superadmin@<host>          — where <host> = parse_url(APP_URL).host
     *   2. superadmin@<slug>.local    — where <slug> = Str::slug(APP_NAME)
     */
    public function defaultEmail(): string
    {
        $url = (string) $this->config()->get('app.url', '');
        $host = $url !== '' ? parse_url($url, PHP_URL_HOST) : null;

        if (is_string($host) && $host !== '') {
            return 'superadmin@'.mb_strtolower($host);
        }

        $slug = Str::slug((string) $this->config()->get('app.name', 'app')) ?: 'app';

        return 'superadmin@'.$slug.'.local';
    }

    /**
     * Password used when none is passed via `ensure([...])`. Always returns
     * the literal "superadmin" — memorable for local dev. Apps deploying to
     * production must override via the seeder or rotate post-install via
     * `php artisan superadmin:ensure`.
     */
    public function defaultPassword(): string
    {
        return 'superadmin';
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
        $email    = $defaults['email']    ?? null;
        $name     = $defaults['name']     ?? $this->defaultName();

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
            $existing = $this->user();

            $attributes = [
                'name' => $name,
                'email' => $email,
                'is_protected' => true,
            ];

            if ($existing === null) {
                $attributes['password'] = Hash::make($password ?? $this->defaultPassword());
                $attributes['email_verified_at'] = now();
                $instance = $model::query()->create($attributes);
            } else {
                if ($password !== null) {
                    $attributes['password'] = Hash::make($password);
                }
                $existing->fill($attributes)->save();
                $instance = $existing->fresh();
            }

            // Best-effort role assignment — fires whenever Spatie HasRoles is
            // present on the User model and a role is configured.
            $this->assignRole($instance);

            return $instance;
        });
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
