<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Tests\Fixtures;

use Codenzia\SuperAdmin\Concerns\IsSuperAdmin;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A host User model that does NOT declare an `is_protected` boolean cast.
 * On MySQL's PDO driver a tinyint/boolean column is commonly returned as the
 * string "1" in this configuration. Used to prove that SuperAdmin::is() (and
 * the protection layers built on it) still fire when the attribute is the
 * string "1" rather than a real boolean / int.
 */
class UncastUser extends Authenticatable
{
    use IsSuperAdmin;
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // Deliberately no 'is_protected' cast.
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
