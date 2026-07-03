<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Tests\Fixtures\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    // Baseline ability that returns false for everyone — so anything that
    // passes must have been granted by the package's Gate::before.
    Gate::define('do-anything', fn (): bool => false);
});

it('authorizes the protected user for any ability when gate_before is enabled', function (): void {
    config()->set('superadmin.authorization.gate_before', true);

    $user = createProtectedSuperAdmin();

    expect(Gate::forUser($user)->allows('do-anything'))->toBeTrue();
    expect(Gate::forUser($user)->allows('arbitrary-ability'))->toBeTrue();
    expect(Gate::forUser($user)->allows('view'))->toBeTrue();
});

it('does not authorize non-protected users', function (): void {
    config()->set('superadmin.authorization.gate_before', true);

    $user = createUser('regular@aqarkom.test');

    expect(Gate::forUser($user)->allows('do-anything'))->toBeFalse();
});

it('does not authorize when gate_before is disabled', function (): void {
    config()->set('superadmin.authorization.gate_before', false);

    $user = createProtectedSuperAdmin();

    expect(Gate::forUser($user)->allows('do-anything'))->toBeFalse();
});

it('handles null user gracefully (Gate::denies without acting user)', function (): void {
    config()->set('superadmin.authorization.gate_before', true);

    expect(Gate::denies('do-anything'))->toBeTrue();
});

