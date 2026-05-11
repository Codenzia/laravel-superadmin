<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Filament;

use Codenzia\SuperAdmin\Facades\SuperAdmin;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;

/**
 * Filament v4 plugin that hides destructive row actions on the super admin
 * account and (optionally) excludes it from non-super-admin user listings.
 *
 * Registration:
 *
 *   $panel->plugin(\Codenzia\SuperAdmin\Filament\SuperAdminPlugin::make());
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
        if (! class_exists(DeleteAction::class)) {
            return;
        }

        if (! config('superadmin.filament.hide_destructive_actions', true)) {
            return;
        }

        DeleteAction::configureUsing(function (DeleteAction $action): void {
            $action->hidden(fn (?Model $record): bool => $record !== null && SuperAdmin::is($record));
        });

        if (class_exists(ForceDeleteAction::class)) {
            ForceDeleteAction::configureUsing(function (ForceDeleteAction $action): void {
                $action->hidden(fn (?Model $record): bool => $record !== null && SuperAdmin::is($record));
            });
        }
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
