<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Exceptions\ProtectedAccountException;
use Codenzia\SuperAdmin\Facades\SuperAdmin;
use Codenzia\SuperAdmin\SuperAdminServiceProvider;
use Codenzia\SuperAdmin\Tests\Fixtures\FakeRole;
use Codenzia\SuperAdmin\Tests\Fixtures\UserWithRoles;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Exercises `SuperAdminServiceProvider::registerRolePromotionGuard()`,
 * previously untested (SUPERADMIN-TEST-01).
 *
 * The guard listens on the wildcard `eloquent.pivotAttaching: *` event that
 * Eloquent's BelongsToMany relation fires before writing a pivot row (e.g.
 * from Spatie's `assignRole()` / `syncRoles()`). Since spatie/laravel-permission
 * is not a dev dependency of this package, these tests drive the guard
 * directly via that event contract rather than through a real pivot write.
 */
function firePivotAttaching(object $user, string $relation, array $ids): void
{
    Event::dispatch('eloquent.pivotAttaching: '.get_class($user), [$user, $relation, $ids]);
}

beforeEach(function (): void {
    UserWithRoles::reset();
    config()->set('permission.models.role', FakeRole::class);
    config()->set('auth.providers.users.model', UserWithRoles::class);
    config()->set('superadmin.user_model', UserWithRoles::class);
    config()->set('filament-shield.super_admin.name', 'super_admin');
    config()->set('superadmin.protection.prevent_role_promotion', true);

    Schema::dropIfExists('fake_roles');
    Schema::create('fake_roles', function ($table): void {
        $table->id();
        $table->string('name');
    });

    FakeRole::query()->create(['name' => 'super_admin']);
});

function makeNonProtectedUser(string $email = 'attacker@aqarkom.test'): UserWithRoles
{
    return UserWithRoles::query()->create([
        'name' => 'Regular User',
        'email' => $email,
        'password' => Hash::make('password-1234'),
        'is_protected' => false,
    ]);
}

it('blocks attaching the super_admin role to a non-protected user', function (): void {
    $user = makeNonProtectedUser();
    $roleId = FakeRole::query()->where('name', 'super_admin')->value('id');

    expect(fn () => firePivotAttaching($user, 'roles', [$roleId]))
        ->toThrow(ProtectedAccountException::class);
});

it('allows attaching the super_admin role to the protected user', function (): void {
    $user = SuperAdmin::withoutProtection(fn (): UserWithRoles => UserWithRoles::query()->create([
        'name' => 'Super Admin',
        'email' => 'superadmin@aqarkom.test',
        'password' => Hash::make('pw-1234567890'),
        'is_protected' => true,
    ]));
    $roleId = FakeRole::query()->where('name', 'super_admin')->value('id');

    firePivotAttaching($user, 'roles', [$roleId]);

    expect(true)->toBeTrue();
});

it('allows attaching any other role to anyone', function (): void {
    $user = makeNonProtectedUser();
    FakeRole::query()->create(['name' => 'editor']);
    $roleId = FakeRole::query()->where('name', 'editor')->value('id');

    firePivotAttaching($user, 'roles', [$roleId]);

    expect(true)->toBeTrue();
});

it('allows the super_admin role attach inside withoutProtection', function (): void {
    $user = makeNonProtectedUser();
    $roleId = FakeRole::query()->where('name', 'super_admin')->value('id');

    SuperAdmin::withoutProtection(function () use ($user, $roleId): void {
        firePivotAttaching($user, 'roles', [$roleId]);
    });

    expect(true)->toBeTrue();
});

it('is disabled when prevent_role_promotion is false', function (): void {
    // The guard checks the config flag once, at listener-registration time
    // (boot), not per-event. Remove the listener registered during the
    // suite's normal boot and re-register the provider under the flipped
    // config so the guard is skipped this time.
    Event::forget('eloquent.pivotAttaching: *');
    config()->set('superadmin.protection.prevent_role_promotion', false);
    app()->register(SuperAdminServiceProvider::class, force: true);

    $user = makeNonProtectedUser();
    $roleId = FakeRole::query()->where('name', 'super_admin')->value('id');

    firePivotAttaching($user, 'roles', [$roleId]);

    expect(true)->toBeTrue();
});

it('regression: does not permanently disable itself after a null role-id lookup (SEC-01)', function (): void {
    // Delete the role so the guard's memoized lookup resolves null first.
    FakeRole::query()->where('name', 'super_admin')->delete();

    $bystander = makeNonProtectedUser('bystander@aqarkom.test');
    // Attaching an unrelated role while the super_admin role row does not
    // exist yet forces the guard to resolve (and, pre-fix, memoize) null.
    FakeRole::query()->create(['name' => 'editor']);
    $editorId = FakeRole::query()->where('name', 'editor')->value('id');
    firePivotAttaching($bystander, 'roles', [$editorId]);

    // Now the super_admin role row appears (e.g. seeder runs later in the
    // same process/request).
    FakeRole::query()->create(['name' => 'super_admin']);
    $roleId = FakeRole::query()->where('name', 'super_admin')->value('id');

    $attacker = makeNonProtectedUser('later-attacker@aqarkom.test');

    expect(fn () => firePivotAttaching($attacker, 'roles', [$roleId]))
        ->toThrow(ProtectedAccountException::class);
});
