<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Tests\Fixtures;

use RuntimeException;

/**
 * Test fixture simulating a User model with Spatie's HasRoles behavior.
 *
 * - Roles that "exist" are listed in static::$rolesInDatabase.
 * - assignRole() throws when the role is not in $rolesInDatabase
 *   (simulating Spatie's RoleDoesNotExist exception).
 * - Role membership is tracked in static::$rolesByUserId keyed by user ID,
 *   so the same role assignment is visible across multiple instances of
 *   the same user (mirroring real Spatie pivot-table behavior).
 *
 * Reset both arrays in beforeEach() to keep tests isolated.
 */
final class UserWithRoles extends User
{
    /** @var array<int|string, array<int, string>> */
    public static array $rolesByUserId = [];

    /** @var array<int, string> */
    public static array $rolesInDatabase = ['super_admin'];

    public static function reset(): void
    {
        self::$rolesByUserId = [];
        self::$rolesInDatabase = ['super_admin'];
    }

    public function assignRole(string $role): self
    {
        if (! in_array($role, self::$rolesInDatabase, true)) {
            throw new RuntimeException(sprintf('Role "%s" does not exist.', $role));
        }

        $key = $this->getKey();
        self::$rolesByUserId[$key] ??= [];

        if (! in_array($role, self::$rolesByUserId[$key], true)) {
            self::$rolesByUserId[$key][] = $role;
        }

        return $this;
    }

    public function hasRole(string $role): bool
    {
        $key = $this->getKey();

        return in_array($role, self::$rolesByUserId[$key] ?? [], true);
    }
}
