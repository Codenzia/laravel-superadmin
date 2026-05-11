<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SuperAdminManager
{
    private bool $protectionBypassed = false;

    public function __construct(private readonly Container $app) {}

    public function email(): ?string
    {
        $email = $this->config()->get('superadmin.email');

        return is_string($email) && $email !== '' ? mb_strtolower($email) : null;
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

    public function isConfigured(): bool
    {
        return $this->email() !== null;
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
     * Idempotently create or update the protected user with the given
     * password. Sets is_protected = true. Assigns the configured role if
     * supported by the User model.
     */
    public function install(string $password, ?string $email = null, string $name = 'Super Admin'): Model
    {
        $model = $this->userModel();

        if ($model === null) {
            throw new \RuntimeException('Cannot resolve User model. Configure superadmin.user_model or auth.providers.users.model.');
        }

        $email ??= $this->email();

        if ($email === null) {
            throw new \RuntimeException('SUPER_ADMIN_EMAIL is not configured.');
        }

        return $this->withoutProtection(function () use ($model, $email, $password, $name): Model {
            $existing = $this->user();

            $attributes = [
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'is_protected' => true,
            ];

            if ($existing === null) {
                $attributes['email_verified_at'] = now();
                $instance = $model::query()->create($attributes);
            } else {
                $existing->fill($attributes)->save();
                $instance = $existing->fresh();
            }

            return $instance;
        });
    }

    public function resetPassword(string $password): Model
    {
        $user = $this->user();

        if ($user === null) {
            throw new \RuntimeException('Protected super admin does not exist. Run `php artisan superadmin:install` first.');
        }

        return $this->withoutProtection(function () use ($user, $password): Model {
            $user->fill([
                'password' => Hash::make($password),
                'is_protected' => true,
            ])->save();

            return $user->fresh();
        });
    }

    /**
     * Bypass the deletion / email-change / flag-change protection for the
     * duration of a callback. Used internally by install() and resetPassword().
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

    public function configuredRole(): ?string
    {
        $role = $this->config()->get('superadmin.role');

        return is_string($role) && $role !== '' ? $role : null;
    }

    /**
     * Attempt to assign the configured role to the user. Returns a result
     * enum describing what happened. The $respectFlag parameter controls
     * whether to honor superadmin.authorization.assign_role:
     *   - true (default): used by install/reset for automatic assignment
     *   - false: used by the explicit superadmin:assign-role command,
     *     which always attempts assignment regardless of the flag
     */
    public function assignRole(Model $user, bool $respectFlag = true): RoleAssignmentResult
    {
        if ($respectFlag && ! (bool) $this->config()->get('superadmin.authorization.assign_role', true)) {
            return RoleAssignmentResult::Disabled;
        }

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
