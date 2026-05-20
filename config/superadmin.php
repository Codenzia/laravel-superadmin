<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Email + Password (override the package defaults)
    |--------------------------------------------------------------------------
    |
    | When unset, the package auto-derives the email from your app config:
    |   1. SUPER_ADMIN_EMAIL — if set
    |   2. superadmin@<host>          — from parse_url(APP_URL).host
    |   3. superadmin@<slug>.local    — from Str::slug(APP_NAME)
    |
    | Password defaults to the literal string "superadmin" — memorable for
    | local dev / internal use. Override before deploying to production via
    | SUPER_ADMIN_PASSWORD or `php artisan superadmin:setup`.
    |
    */

    'email' => env('SUPER_ADMIN_EMAIL'),

    'password' => env('SUPER_ADMIN_PASSWORD', 'superadmin'),

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | Resolved from auth.providers.users.model by default. Override only when
    | your app has multiple user models and the auth config does not point at
    | the right one.
    |
    */

    'user_model' => null,

    /*
    |--------------------------------------------------------------------------
    | Role
    |--------------------------------------------------------------------------
    |
    | If your project uses spatie/laravel-permission, the package assigns
    | this role to the super admin on install/setup. Set to null to skip
    | role assignment entirely.
    |
    */

    'role' => env('SUPER_ADMIN_ROLE', 'super_admin'),

    /*
    |--------------------------------------------------------------------------
    | Auto-install on Migrate
    |--------------------------------------------------------------------------
    |
    | When true (default), the package listens to Laravel's MigrationsEnded
    | event and auto-creates the protected super admin if it does not yet
    | exist. Makes `composer require + migrate` a zero-touch setup. Set to
    | false if you prefer to install only via `php artisan superadmin:setup`.
    |
    */

    'auto_install' => (bool) env('SUPER_ADMIN_AUTO_INSTALL', true),

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | When `gate_before` is true (default), the package registers a
    | Gate::before that returns true for the protected user on every
    | ability. The super admin passes every authorization check with zero
    | dependency on Spatie or policies. Disable if your project wants
    | strict per-permission control over the super admin.
    |
    */

    'authorization' => [
        'gate_before' => (bool) env('SUPER_ADMIN_GATE_BEFORE', true),
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
        'enabled' => (bool) env('SUPER_ADMIN_PROTECTION', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Filament Integration
    |--------------------------------------------------------------------------
    |
    | When filament/filament is installed and SuperAdminPlugin is registered
    | on a panel, this hides destructive row actions on the protected
    | account in any Filament resource.
    |
    */

    'filament' => [
        'hide_destructive_actions' => true,
    ],

];
