<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Observers;

use Codenzia\SuperAdmin\Exceptions\ProtectedAccountException;
use Codenzia\SuperAdmin\Facades\SuperAdmin;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

final class SuperAdminObserver
{
    public function creating(Model $user): void
    {
        if (SuperAdmin::isProtectionBypassed()) {
            return;
        }

        if ((bool) $user->getAttribute('is_protected')) {
            throw ProtectedAccountException::cannotProtect();
        }
    }

    public function deleting(Model $user): void
    {
        if (SuperAdmin::isProtectionBypassed()) {
            return;
        }

        if (SuperAdmin::is($user)) {
            throw ProtectedAccountException::cannotDelete();
        }
    }

    public function updating(Model $user): void
    {
        if (SuperAdmin::isProtectionBypassed()) {
            return;
        }

        if ($user->isDirty('email')) {
            $wasProtected = (bool) $user->getOriginal('is_protected');

            if ($wasProtected) {
                throw ProtectedAccountException::cannotChangeEmail();
            }
        }

        if ($user->isDirty('is_protected')) {
            $wasProtected = (bool) $user->getOriginal('is_protected');
            $isProtected = (bool) $user->getAttribute('is_protected');

            if ($wasProtected && ! $isProtected) {
                throw ProtectedAccountException::cannotUnprotect();
            }

            if (! $wasProtected && $isProtected) {
                throw ProtectedAccountException::cannotProtect();
            }
        }

        $this->guardLockedAttributes($user);
    }

    /**
     * Block writes to the protected account's credential attributes from
     * anywhere but the package's own trusted provisioning / recovery paths.
     *
     * Hiding the password field in a Filament form is a UI affordance, not
     * authorization: a host's custom "reset password" action, a bulk update or
     * a service call writing the model directly would otherwise let any staff
     * member set a password on the protected row and sign in as an identity
     * that passes every gate.
     *
     * The list is `superadmin.protection.locked_attributes` — hosts that carry
     * their own privileged columns (e.g. `status`, `user_type`) add them there,
     * and setting it to `[]` restores the previous behavior.
     *
     * `remember_token` is intentionally not locked by default: Laravel itself
     * cycles it on "remember me" login, logout and password reset, so guarding
     * it locks the protected account out of those flows. A host that wants it
     * guarded anyway lists it in the config, and this guard then applies.
     *
     * The framework's transparent login-time password rehash is exempt — see
     * `isFrameworkPasswordRehash()`.
     */
    private function guardLockedAttributes(Model $user): void
    {
        if (! (bool) $user->getOriginal('is_protected')) {
            return;
        }

        $locked = (array) config('superadmin.protection.locked_attributes', []);

        foreach ($locked as $attribute) {
            if (! is_string($attribute) || $attribute === '' || ! $user->isDirty($attribute)) {
                continue;
            }

            if ($this->isFrameworkPasswordRehash($user, $attribute)) {
                continue;
            }

            throw ProtectedAccountException::cannotChangeLockedAttribute($attribute);
        }
    }

    /**
     * Allow the framework's transparent login-time password rehash.
     *
     * With `hashing.rehash_on_login` on (the default), a successful login whose
     * stored hash carries a different cost/algorithm than the app now uses makes
     * `EloquentUserProvider::rehashPasswordIfRequired()` re-hash the very
     * password just authenticated with and save the model. That write hit the
     * `password` lock, so the protected account could not sign in *at all*
     * whenever BCRYPT_ROUNDS drifted from the cost its hash was made under —
     * the normal state of an account provisioned under a different `.env`.
     *
     * The same secret is being re-stored, so permitting it re-credentials
     * nothing. It is permitted only when every signal of that path holds: the
     * attribute is the model's auth password, it is the *only* dirty attribute,
     * the stored hash genuinely needed rehashing, the incoming value is a
     * finished hash that no longer does, and the write comes out of a user
     * provider's rehash method. A host "reset password" action, a bulk update or
     * a direct service write fails that last check, so an arbitrary password
     * write stays refused even when the stored hash is stale.
     */
    private function isFrameworkPasswordRehash(Model $user, string $attribute): bool
    {
        $passwordName = $user instanceof Authenticatable ? $user->getAuthPasswordName() : 'password';

        if ($attribute !== $passwordName || array_keys($user->getDirty()) !== [$attribute]) {
            return false;
        }

        $original = $user->getOriginal($attribute);
        $incoming = $user->getAttribute($attribute);

        if (! is_string($original) || $original === '' || ! is_string($incoming) || $incoming === '') {
            return false;
        }

        if (! Hash::needsRehash($original) || Hash::needsRehash($incoming)) {
            return false;
        }

        return $this->calledByUserProviderRehash();
    }

    /**
     * Whether a user provider's `rehashPasswordIfRequired()` is on the stack.
     *
     * This is the signal that separates the framework's re-store of the secret
     * the user just authenticated with from a host write that merely looks like
     * one: the plaintext never reaches the observer, so the incoming hash alone
     * cannot be shown to cover the same password.
     */
    private function calledByUserProviderRehash(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $class = $frame['class'] ?? null;

            if (($frame['function'] ?? null) === 'rehashPasswordIfRequired'
                && is_string($class)
                && is_a($class, UserProvider::class, true)) {
                return true;
            }
        }

        return false;
    }
}
