<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Observers;

use Codenzia\SuperAdmin\Exceptions\ProtectedAccountException;
use Codenzia\SuperAdmin\Facades\SuperAdmin;
use Illuminate\Database\Eloquent\Model;

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
     */
    private function guardLockedAttributes(Model $user): void
    {
        if (! (bool) $user->getOriginal('is_protected')) {
            return;
        }

        $locked = (array) config('superadmin.protection.locked_attributes', []);

        foreach ($locked as $attribute) {
            if (is_string($attribute) && $attribute !== '' && $user->isDirty($attribute)) {
                throw ProtectedAccountException::cannotChangeLockedAttribute($attribute);
            }
        }
    }
}
