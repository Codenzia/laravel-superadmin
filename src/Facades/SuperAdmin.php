<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Facades;

use Codenzia\SuperAdmin\Support\SuperAdminManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;

/**
 * @method static string|null email()
 * @method static string defaultEmail()
 * @method static string defaultPassword()
 * @method static string defaultName()
 * @method static string|null userModel()
 * @method static bool isConfigured()
 * @method static bool is(?Model $user)
 * @method static bool isSuperAdmin(?Model $user)
 * @method static Model|null user()
 * @method static bool exists()
 * @method static Model ensure(?array $defaults = null)
 * @method static Model install(?string $password = null, ?string $email = null, string $name = 'Super Admin')
 * @method static mixed withoutProtection(callable $callback)
 * @method static bool isProtectionBypassed()
 * @method static string|null configuredRole()
 * @method static \Codenzia\SuperAdmin\Support\RoleAssignmentResult assignRole(Model $user)
 * @method static bool|null hasConfiguredRole(?Model $user = null)
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
