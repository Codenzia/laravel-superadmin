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
}
