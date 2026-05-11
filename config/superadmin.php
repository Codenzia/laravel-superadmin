<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Super Admin Email
    |--------------------------------------------------------------------------
    |
    | The email address of the protected super admin account. Set during
    | initial deployment (typically by the vendor). The package identifies
    | the protected account by *both* this email AND a database boolean
    | column `is_protected`, so neither alone can be tampered with to
    | silently disable protection.
    |
    */

    'email' => env('SUPER_ADMIN_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model that represents your application's users. By default
    | this is resolved from the auth config, but you may override it here.
    |
    */

    'user_model' => null,

    /*
    |--------------------------------------------------------------------------
    | Role
    |--------------------------------------------------------------------------
    |
    | If your project uses spatie/laravel-permission, the package will assign
    | this role to the super admin on install and reset. Set to null to skip
    | role assignment.
    |
    */

    'role' => env('SUPER_ADMIN_ROLE', 'super_admin'),

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | Two orthogonal toggles that control how the package authorizes the
    | protected super admin. Both default to true — the simplest path that
    | "just works" out of the box.
    |
    |   gate_before — When true, the package registers a Gate::before that
    |                 returns true for the protected user on every ability.
    |                 This makes the super admin pass every authorization
    |                 check in the app, with zero dependency on Spatie or
    |                 Shield. Disable if your project wants strict per-
    |                 permission control over the super admin.
    |
    |   assign_role — When true, install/reset commands auto-assign the
    |                 configured role to the protected user (only fires
    |                 if the User model has Spatie's HasRoles trait).
    |                 Useful for integration with Spatie/Shield admin UIs
    |                 and project code that calls hasRole(). Disable if
    |                 you don't use Spatie at all.
    |
    | The explicit `php artisan superadmin:assign-role` command always
    | honors a user invocation even when this flag is false — the flag
    | controls only the automatic behavior of install/reset.
    |
    */

    'authorization' => [
        'gate_before' => (bool) env('SUPER_ADMIN_GATE_BEFORE', true),
        'assign_role' => (bool) env('SUPER_ADMIN_ASSIGN_ROLE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Protection
    |--------------------------------------------------------------------------
    |
    | Defense-in-depth: an Eloquent model observer blocks deletion of the
    | protected user, blocks changes to its email, and blocks attempts to
    | flip its is_protected flag off. Disable only if you are certain your
    | application-layer authorization is sufficient.
    |
    */

    'protection' => [
        'enabled' => env('SUPER_ADMIN_PROTECTION', true),
        'block_delete' => true,
        'block_email_change' => true,
        'block_flag_change' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Vendor Commands
    |--------------------------------------------------------------------------
    |
    | Commands that mutate the protected account (install, reset) are intended
    | for the package vendor's use during deployment and support work. They
    | are not for the end-customer to invoke.
    |
    | Friction controls (this is not cryptographic security — a determined
    | actor with shell access can run anything; these settings exist to make
    | accidental or casual misuse harder, and to ensure every invocation is
    | loudly audited):
    |
    |   - hide_from_list: hide the commands from `php artisan list`
    |   - require_confirm_flag: refuse to run without explicit --confirm
    |   - require_typed_phrase: prompt for a typed confirmation phrase
    |   - notify_on_invocation: send a notification on every run
    |
    */

    'vendor_commands' => [
        'hide_from_list' => true,
        'require_confirm_flag' => true,
        'require_typed_phrase' => true,
        'typed_phrase' => 'I AM THE VENDOR',
        'notify_on_invocation' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Every vendor-command invocation fires a notification. Configure where
    | these go on the customer's deployment so you (the vendor) get a real-
    | time alert if anyone runs an internal command.
    |
    */

    'notifications' => [
        'enabled' => env('SUPER_ADMIN_NOTIFY', true),
        'mail_to' => env('SUPER_ADMIN_NOTIFY_MAIL'),
        'slack_webhook' => env('SUPER_ADMIN_NOTIFY_SLACK'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Log Channel
    |--------------------------------------------------------------------------
    |
    | Every install, reset, and protection-event is logged to this channel.
    | Defaults to the application's default log channel. Configure a
    | dedicated channel pointing at a vendor-controlled log aggregator for
    | tamper-evident audit trails.
    |
    */

    'log_channel' => env('SUPER_ADMIN_LOG_CHANNEL'),

    /*
    |--------------------------------------------------------------------------
    | Filament Integration
    |--------------------------------------------------------------------------
    |
    | When filament/filament is installed, the package can hide destructive
    | row actions on the protected account in any Filament resource. The
    | plugin must be explicitly registered on each panel.
    |
    */

    'filament' => [
        'enabled' => true,
        'hide_destructive_actions' => true,
        'badge_label' => 'Super Admin',
    ],

];
