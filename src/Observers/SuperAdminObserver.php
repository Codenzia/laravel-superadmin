<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Observers;

use Codenzia\SuperAdmin\Exceptions\ProtectedAccountException;
use Codenzia\SuperAdmin\Facades\SuperAdmin;
use Illuminate\Database\Eloquent\Model;

final class SuperAdminObserver
{
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
            $original = $user->getOriginal('email');
            $configured = SuperAdmin::email();
            $wasProtected = (bool) $user->getOriginal('is_protected');

            if ($wasProtected
                || (is_string($original) && $configured !== null && mb_strtolower($original) === $configured)) {
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
    }
}
