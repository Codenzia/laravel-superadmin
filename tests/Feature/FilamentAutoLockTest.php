<?php

declare(strict_types=1);

use Codenzia\SuperAdmin\Filament\SuperAdminPlugin;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Support\Components\ComponentManager;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolve the disabled state of a freshly-built field in the context of the
 * given record. Filament's Field reads `isDisabled` via `evaluate()`, which
 * injects parameters by name from the field's resolution chain — `$record`
 * comes from the parent Schema. Building a real Schema + Field is the most
 * faithful reproduction of how an app uses the plugin.
 *
 * @param  class-string<Field>  $fieldClass
 */
function buildFieldDisabledFor(string $fieldClass, string $name, ?Model $record): bool
{
    $field = $fieldClass::make($name);
    $schema = Schema::make()->record($record)->components([$field]);

    // Force the lazy children resolution so the field's `container` typed
    // property is initialised before isDisabled() walks up to read the
    // record. Without this, evaluate() throws on the uninitialised typed
    // property.
    $schema->getComponents(withActions: false, withHidden: true);

    return $field->isDisabled();
}

beforeEach(function (): void {
    configureSuperAdmin('superadmin@aqarkom.test');

    // Reset Filament's component manager between tests so the configureUsing
    // registrations from previous tests don't bleed across. The manager is
    // a singleton — replace it with a fresh instance, then re-register the
    // plugin under test.
    app()->forgetInstance(ComponentManager::class);

    // Force the singleton to rebuild on next resolve.
    SuperAdminPlugin::make()->register(
        Mockery::mock(Panel::class)
    );
});

it('auto-hides DeleteAction on the protected user record', function (): void {
    $protected = createProtectedSuperAdmin();

    $action = DeleteAction::make()->record($protected);

    expect($action->isHidden())->toBeTrue();
});

it('does not hide DeleteAction on a regular user record', function (): void {
    $regular = createUser('regular@aqarkom.test');

    $action = DeleteAction::make()->record($regular);

    expect($action->isHidden())->toBeFalse();
});

it('auto-hides a custom suspend action on the protected user', function (): void {
    $protected = createProtectedSuperAdmin();

    $action = Action::make('suspend')->record($protected);

    expect($action->isHidden())->toBeTrue();
});

it('auto-hides a custom markEmailVerified action on the protected user', function (): void {
    $protected = createProtectedSuperAdmin();

    $action = Action::make('markEmailVerified')->record($protected);

    expect($action->isHidden())->toBeTrue();
});

it('auto-hides a custom impersonate action on the protected user', function (): void {
    $protected = createProtectedSuperAdmin();

    $action = Action::make('impersonate')->record($protected);

    expect($action->isHidden())->toBeTrue();
});

it('does not hide non-destructive actions on the protected user', function (): void {
    $protected = createProtectedSuperAdmin();

    $action = Action::make('view')->record($protected);

    expect($action->isHidden())->toBeFalse();
});

it('does not hide named destructive actions on regular users', function (): void {
    $regular = createUser('regular@aqarkom.test');

    $action = Action::make('suspend')->record($regular);

    expect($action->isHidden())->toBeFalse();
});

it('auto-disables the roles field when editing the protected user', function (): void {
    $protected = createProtectedSuperAdmin();

    expect(buildFieldDisabledFor(Select::class, 'roles', $protected))->toBeTrue();
});

it('auto-disables the status field when editing the protected user', function (): void {
    $protected = createProtectedSuperAdmin();

    expect(buildFieldDisabledFor(Select::class, 'status', $protected))->toBeTrue();
});

it('auto-disables the email field when editing the protected user', function (): void {
    $protected = createProtectedSuperAdmin();

    expect(buildFieldDisabledFor(TextInput::class, 'email', $protected))->toBeTrue();
});

it('auto-disables the is_protected field when editing the protected user', function (): void {
    $protected = createProtectedSuperAdmin();

    expect(buildFieldDisabledFor(Toggle::class, 'is_protected', $protected))->toBeTrue();
});

it('does not disable locked-name fields when editing a regular user', function (): void {
    $regular = createUser('regular@aqarkom.test');

    expect(buildFieldDisabledFor(Select::class, 'roles', $regular))->toBeFalse();
});

it('does not disable non-locked fields on the protected user', function (): void {
    $protected = createProtectedSuperAdmin();

    expect(buildFieldDisabledFor(TextInput::class, 'name', $protected))->toBeFalse();
});

it('respects hide_destructive_actions=false (whole-feature kill switch)', function (): void {
    config()->set('superadmin.filament.hide_destructive_actions', false);

    // Re-register the plugin against a clean component manager — with the
    // master switch off, no configureUsing hooks should be registered.
    app()->forgetInstance(ComponentManager::class);
    SuperAdminPlugin::make()->register(Mockery::mock(Panel::class));

    $protected = createProtectedSuperAdmin();

    $action = Action::make('suspend')->record($protected);

    expect($action->isHidden())->toBeFalse();
});

it('respects app-extended hidden_action_names', function (): void {
    config()->set('superadmin.filament.hidden_action_names', [
        ...config('superadmin.filament.hidden_action_names'),
        'app_specific_destructive_thing',
    ]);

    app()->forgetInstance(ComponentManager::class);
    SuperAdminPlugin::make()->register(Mockery::mock(Panel::class));

    $protected = createProtectedSuperAdmin();

    $action = Action::make('app_specific_destructive_thing')->record($protected);

    expect($action->isHidden())->toBeTrue();
});
