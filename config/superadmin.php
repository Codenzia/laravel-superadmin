<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Identity (name / email / password / role)
    |--------------------------------------------------------------------------
    |
    | Identity is either passed by the host seeder via
    | `SuperAdmin::ensure([...])` or derived by the package:
    |
    |   - name     → "Super Admin" (default) or seeder override
    |   - email    → superadmin@<APP_URL host> → superadmin@<APP_NAME slug>.local
    |                or seeder override
    |   - password → see `password` below
    |   - role     → filament-shield.super_admin.name (auto-discovered) →
    |                "super_admin" (fallback)
    |
    | Post-seed credential changes go through `php artisan superadmin:ensure`
    | (DB-only, never touches `.env`) or the recovery route below.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Password Override
    |--------------------------------------------------------------------------
    |
    | Optional. When set, this password is used whenever the package creates
    | the super admin (auto-install, argless `ensure()`, blank prompt in
    | `superadmin:ensure`) — in every environment, including production.
    | Intended for local dev and vendor-controlled live demos.
    |
    | When NOT set, the create-time default depends on the environment:
    |
    |   - production     → a random throwaway password nobody knows. Claim
    |                      the account via the recovery route below or
    |                      `php artisan superadmin:ensure`.
    |   - anything else  → the literal "superadmin" (zero-touch local dev).
    |
    */

    'password' => env('SUPER_ADMIN_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Recovery Route
    |--------------------------------------------------------------------------
    |
    | A self-contained break-glass flow to set/reset the super admin password
    | at any time without CLI access:
    |
    |   GET  /{path}               one-button page: email me a reset link
    |   POST /{path}               sends the link to the protected account's
    |                              own mailbox (response is identical whether
    |                              or not the account exists — leaks nothing)
    |   GET  /{path}/reset/{token} choose-a-new-password form
    |   POST /{path}/reset         applies it (token is single-use)
    |
    | The route only ever emails the protected account's own address, so a
    | guessable URL is harmless — worst case is mailbox noise, which the
    | throttle below caps. Every request is logged for monitoring. Does not
    | depend on the host app's own password-reset scaffolding (works on
    | Filament-only apps with no `password.reset` route).
    |
    */

    'recovery' => [
        'enabled' => (bool) env('SUPER_ADMIN_RECOVERY', true),

        'path' => env('SUPER_ADMIN_RECOVERY_PATH', 'superadmin'),

        // Max requests per IP within the decay window, plus an app-wide cap
        // shared by all IPs (stops distributed probing).
        'throttle' => [
            'max_attempts' => 3,
            'global_max_attempts' => 10,
            'decay_seconds' => 3600,
        ],
    ],

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

        /*
        | When true (default), prevents any user other than the protected super
        | admin from being assigned the super_admin role via Eloquent's pivot
        | events (syncRoles, assignRole, etc.). Throws ProtectedAccountException
        | before the pivot write, so the DB is never in a bad state.
        */
        'prevent_role_promotion' => (bool) env('SUPER_ADMIN_PREVENT_ROLE_PROMOTION', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Late Role Assignment
    |--------------------------------------------------------------------------
    |
    | Retroactively assign the configured role to the protected super admin
    | the moment that role is created in the database. Solves a real race:
    | `MigrationsEnded` fires during `migrate`, BEFORE any seeder runs. If
    | the host uses spatie/laravel-permission, the Role row for `super_admin`
    | does not exist yet at that point, so install()'s best-effort
    | assignRole() silently fails. With this listener enabled, the role is
    | applied the moment the seeder (or any other source) creates the row.
    | Idempotent. Set to false for strict-control hosts.
    |
    */

    'late_role_assignment' => (bool) env('SUPER_ADMIN_LATE_ROLE_ASSIGNMENT', true),

    /*
    |--------------------------------------------------------------------------
    | Filament Integration
    |--------------------------------------------------------------------------
    |
    | When filament/filament is installed and SuperAdminPlugin is registered
    | on a panel:
    |
    |  - DeleteAction / ForceDeleteAction are auto-hidden on the protected
    |    user row.
    |  - Any row Action whose `getName()` matches `hidden_action_names` is
    |    auto-hidden on the protected user row. Default list covers the
    |    common destructive verbs used across our 14+ Codenzia apps
    |    (suspend/ban/markEmailVerified/impersonate/…). Apps can extend
    |    by setting their own array here.
    |  - Any form Field whose `getName()` matches `locked_field_names` is
    |    auto-disabled when editing the protected user. Default list covers
    |    the privilege-escalation fields admins must never flip on the
    |    super admin (roles, status, is_protected, email, …).
    |
    | Set `hide_destructive_actions` to false to turn the whole thing off.
    |
    */

    'filament' => [
        'hide_destructive_actions' => true,

        'hidden_action_names' => [
            'delete',
            'forceDelete',
            'suspend',
            'unsuspend',
            'ban',
            'unban',
            'markEmailVerified',
            'verify',
            'unverify',
            'impersonate',
            'demote',
        ],

        'locked_field_names' => [
            'roles',
            'role',
            'permissions',
            'status',
            'is_protected',
            'email',
            'user_type',
        ],
    ],

];
