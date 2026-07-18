<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Tests\Fixtures;

use Codenzia\SuperAdmin\Concerns\IsSuperAdmin;
use Codenzia\SuperAdmin\Tests\SpatieTestCase;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * User fixture backed by the REAL spatie/laravel-permission HasRoles trait, so
 * `assignRole()` / `syncRoles()` perform genuine pivot writes against the
 * tables created by {@see SpatieTestCase}.
 */
class SpatieUser extends Authenticatable
{
    use HasRoles;
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
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_protected' => 'boolean',
        ];
    }
}
