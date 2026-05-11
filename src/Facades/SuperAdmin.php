<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Facades;

use Codenzia\SuperAdmin\Support\SuperAdminManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;

/**
 * @method static string|null email()
 * @method static string|null userModel()
 * @method static bool isConfigured()
 * @method static bool is(?Model $user)
 * @method static Model|null user()
 * @method static bool exists()
 * @method static Model install(string $password, ?string $email = null, string $name = 'Super Admin')
 * @method static Model resetPassword(string $password)
 * @method static mixed withoutProtection(callable $callback)
 * @method static bool isProtectionBypassed()
 *
 * @see SuperAdminManager
 */
final class SuperAdmin extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'superadmin';
    }
}
