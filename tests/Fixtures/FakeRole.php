<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for spatie/laravel-permission's Role model so the package tests
 * can exercise the "Role::created → retroactive assignment" listener
 * without taking a runtime dependency on Spatie.
 *
 * Only the bits the listener inspects are real: the `name` attribute and
 * the standard Eloquent `created` event firing. Persistence uses an
 * in-memory SQLite table created on-the-fly by the test setUp.
 */
final class FakeRole extends Model
{
    protected $table = 'fake_roles';

    public $timestamps = false;

    protected $guarded = [];
}
