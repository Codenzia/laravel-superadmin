<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Filament;

use Closure;
use Codenzia\SuperAdmin\Facades\SuperAdmin;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Field;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;

/**
 * Filament v4 plugin. Provides defense-in-depth UX guards on top of the
 * package's Eloquent observer + Gate::before so that **every** consumer app
 * inherits sensible protection of the super admin row without writing
 * per-resource code.
 *
 * Three layers, each toggleable via config:
 *
 *  1. **DeleteAction / ForceDeleteAction auto-hide** — original 0.1 behavior.
 *     Hidden on the protected record so the admin never sees a button that
 *     can only throw at the observer layer.
 *
 *  2. **Custom destructive row actions auto-hide** (new in 0.3.2) — any
 *     `Filament\Actions\Action` whose `getName()` matches the configured
 *     allowlist (`suspend`, `unsuspend`, `ban`, `markEmailVerified`, …) is
 *     auto-hidden on the protected record. Uses Filament's `configureUsing`
 *     mechanism, which the `ComponentManager` walks up the inheritance chain
 *     — so this fires on every Action subclass including app-defined ones.
 *
 *  3. **Privileged form fields auto-disable** (new in 0.3.2) — any
 *     `Filament\Forms\Components\Field` whose `getName()` matches the
 *     configured allowlist (`roles`, `status`, `is_protected`, `email`, …)
 *     is auto-disabled when editing the protected record. Closes the
 *     "admin demotes the super admin via the roles Select" loophole.
 *
 * Registration:
 *
 *     $panel->plugin(\Codenzia\SuperAdmin\Filament\SuperAdminPlugin::make());
 *
 * Apps can extend the defaults via config without writing code:
 *
 *     // config/superadmin.php
 *     'filament' => [
 *         'hidden_action_names' => array_merge(
 *             config('superadmin.filament.hidden_action_names', []),
 *             ['my_app_specific_destructive_action'],
 *         ),
 *     ],
 */
final class SuperAdminPlugin implements Plugin
{
    public static function make(): self
    {
        return new self;
    }

    public function getId(): string
    {
        return 'codenzia-superadmin';
    }

    public function register(Panel $panel): void
    {
        if (! class_exists(Action::class)) {
            return;
        }

        if (! config('superadmin.filament.hide_destructive_actions', true)) {
            return;
        }

        $this->configureDeleteActions();
        $this->configureNamedDestructiveActions();
        $this->configureLockedFormFields();
    }

    public function boot(Panel $panel): void
    {
        //
    }

    private function configureDeleteActions(): void
    {
        DeleteAction::configureUsing(function (DeleteAction $action): void {
            $action->hidden($this->isProtectedRecord());
        });

        if (class_exists(ForceDeleteAction::class)) {
            ForceDeleteAction::configureUsing(function (ForceDeleteAction $action): void {
                $action->hidden($this->isProtectedRecord());
            });
        }
    }

    /**
     * Walk every Action subclass at construction time. When the action's
     * name matches the configured destructive-action allowlist AND the row
     * record is the super admin, hide it. Filament's ComponentManager applies
     * configureUsing hooks registered on parent classes to all subclasses
     * (it walks `class_parents` on construct), so registering this once on
     * `Action::class` covers every built-in and custom Action.
     */
    private function configureNamedDestructiveActions(): void
    {
        Action::configureUsing(function (Action $action): void {
            $action->hidden(function (?Model $record) use ($action): bool {
                if ($record === null) {
                    return false;
                }

                $hiddenNames = (array) config('superadmin.filament.hidden_action_names', []);

                if (! in_array($action->getName(), $hiddenNames, true)) {
                    return false;
                }

                return SuperAdmin::is($record);
            });
        });
    }

    /**
     * Walk every form field at construction time. When the field's name
     * matches the configured locked-field allowlist AND the form record is
     * the super admin, disable it. Same `class_parents` walk as for actions.
     */
    private function configureLockedFormFields(): void
    {
        if (! class_exists(Field::class)) {
            return;
        }

        Field::configureUsing(function (Field $field): void {
            $field->disabled(function (?Model $record) use ($field): bool {
                if ($record === null) {
                    return false;
                }

                $lockedNames = (array) config('superadmin.filament.locked_field_names', []);

                if (! in_array($field->getName(), $lockedNames, true)) {
                    return false;
                }

                return SuperAdmin::is($record);
            });
        });
    }

    private function isProtectedRecord(): Closure
    {
        return fn (?Model $record): bool => $record !== null && SuperAdmin::is($record);
    }
}
