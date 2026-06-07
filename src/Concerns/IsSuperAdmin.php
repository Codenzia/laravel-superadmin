<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Concerns;

use Codenzia\SuperAdmin\Facades\SuperAdmin;
use Illuminate\Database\Eloquent\Builder;

trait IsSuperAdmin
{
    public function isSuperAdmin(): bool
    {
        return SuperAdmin::isSuperAdmin($this);
    }

    public function scopeSuperAdmin(Builder $query): Builder
    {
        return $query->where('is_protected', true);
    }

    public function scopeExceptSuperAdmin(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->where('is_protected', false)->orWhereNull('is_protected');
        });
    }
}
