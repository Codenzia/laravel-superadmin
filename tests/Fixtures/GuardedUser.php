<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Tests\Fixtures;

use Codenzia\SuperAdmin\Concerns\IsSuperAdmin;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Mirrors fleet apps that follow the hardening advice and guard the
 * privilege flags against mass assignment (e.g. toolenza). install() must
 * still be able to set is_protected on this model.
 */
class GuardedUser extends Authenticatable
{
    use IsSuperAdmin;
    use Notifiable;

    protected $table = 'users';

    protected $guarded = ['id', 'is_admin', 'is_protected'];

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
