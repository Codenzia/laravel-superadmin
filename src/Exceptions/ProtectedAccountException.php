<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Exceptions;

use RuntimeException;

final class ProtectedAccountException extends RuntimeException
{
    public static function cannotDelete(): self
    {
        return new self('The protected super admin account cannot be deleted.');
    }

    public static function cannotChangeEmail(): self
    {
        return new self('The protected super admin email address cannot be changed.');
    }

    public static function cannotUnprotect(): self
    {
        return new self('The is_protected flag cannot be set to false on the super admin account.');
    }

    public static function cannotProtect(): self
    {
        return new self(
            'The is_protected flag cannot be promoted to true outside of SuperAdmin::withoutProtection(). '
            .'This guards against mass-assignment privilege escalation on regular accounts.'
        );
    }

    public static function cannotAssignSuperAdminRole(): self
    {
        return new self('The super_admin role can only be held by the protected super admin account.');
    }

    public static function cannotAdoptExistingAccount(string $email): self
    {
        return new self(
            'An existing account already uses '.$email.', so the protected super admin cannot be installed there. '
            .'Provision a different address, or adopt that account deliberately with '
            .'`php artisan superadmin:ensure --adopt` (or SuperAdmin::ensure([..., \'adopt\' => true])), '
            .'which rotates its password and remember token.'
        );
    }

    public static function cannotChangeLockedAttribute(string $attribute): self
    {
        return new self(
            'The protected super admin\'s "'.$attribute.'" cannot be changed outside of SuperAdmin::withoutProtection(). '
            .'Use `php artisan superadmin:ensure` or the break-glass recovery route.'
        );
    }
}
