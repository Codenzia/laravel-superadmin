# Laravel SuperAdmin — Zero-friction protected admin account

[![Latest Version](https://img.shields.io/packagist/v/codenzia/laravel-superadmin.svg?style=flat-square)](https://packagist.org/packages/codenzia/laravel-superadmin)
[![PHP Version](https://img.shields.io/packagist/php-v/codenzia/laravel-superadmin.svg?style=flat-square)](https://packagist.org/packages/codenzia/laravel-superadmin)
[![Laravel](https://img.shields.io/badge/Laravel-11%20%7C%2012%20%7C%2013-ef4444?style=flat-square)](https://laravel.com)
[![Filament](https://img.shields.io/badge/Filament-v4%20%7C%20v5-f59e0b?style=flat-square)](https://filamentphp.com)
[![Tests](https://github.com/Codenzia/laravel-superadmin/actions/workflows/tests.yml/badge.svg?style=flat-square)](https://github.com/Codenzia/laravel-superadmin/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/codenzia/laravel-superadmin.svg?style=flat-square)](LICENSE.md)

> Drop-in **protected super-admin account** for Laravel. Composer require, run migrate, and you have a working super-admin login. One env var (or one interactive command) overrides the defaults. No friction, no ceremony.

---

## What you get

- A **single protected user** that is auto-created on first `migrate`. Default email derived from `APP_URL` / `APP_NAME`. Default password `superadmin`.
- An **Eloquent observer** that blocks deletion, email changes, and unprotect attempts on that user.
- A **`Gate::before` hook** so the super admin authorizes for every ability — works without Spatie, Shield, or any policies wired up.
- A **Filament plugin** that hides `DeleteAction` / `ForceDeleteAction` for the protected row.
- A **`superadmin:setup` command** that interactively rotates the email + password, persisting both to `.env` and the DB.
- A **`superadmin:status` command** (with `--verbose` for full health diagnostics) so you can verify the install in one shot.

## Quick start

```bash
composer require codenzia/laravel-superadmin
php artisan migrate
# ✓ Created protected super admin: superadmin@<your-host> (password: superadmin)
# Override anytime with `php artisan superadmin:setup` or by editing .env.
```

That's the whole install. The package listens to `MigrationsEnded` and creates the protected user once, if and only if no protected user exists. Re-running `migrate` is a no-op.

### Override the defaults — three options

**(1) Before first migrate** — edit `.env`:

```ini
SUPER_ADMIN_EMAIL=admin@your-app.test
SUPER_ADMIN_PASSWORD=your-strong-password
```

**(2) Any time after install** — interactive command:

```bash
php artisan superadmin:setup
# Super admin email [superadmin@your-app.test]: admin@your-app.test
# Super admin password (leave blank to keep current): <new password or blank>
# ✓ Saved SUPER_ADMIN_EMAIL to .env
# ✓ Saved SUPER_ADMIN_PASSWORD to .env
# ✓ Updated DB row for admin@your-app.test
```

**(3) Non-interactive** — flags only:

```bash
php artisan superadmin:setup --email=admin@your-app.test --password='your-strong-password'
```

`superadmin:setup` writes to `.env` and updates the DB row in one step. Pass `--password=''` (or accept the blank prompt) to keep the existing password while rotating the email.

> **Production password warning.** The default `superadmin` is deliberately memorable for local dev and internal use. **Always** set a real password before exposing the app to anyone — either via `SUPER_ADMIN_PASSWORD` in `.env` before first migrate, or via `superadmin:setup` afterwards.

## Default email resolution

When `SUPER_ADMIN_EMAIL` isn't set, the package derives one from your host's own config — never a vendor domain:

1. `env('SUPER_ADMIN_EMAIL')` if set
2. else `superadmin@<host>` where `<host> = parse_url(config('app.url'), PHP_URL_HOST)`
3. else `superadmin@<slug>.local` where `<slug> = Str::slug(config('app.name'))`

So `APP_URL=https://myshop.com` → `superadmin@myshop.com`. `APP_NAME="My Shop"` with no URL → `superadmin@my-shop.local`.

## How protection works

The package identifies the protected row via **either** signal — both must be tampered with to silently disable protection:

- `users.is_protected = true` (DB column)
- `users.email` matching `SUPER_ADMIN_EMAIL` (or the derived default)

Three protection layers:

| Layer | Behavior |
|---|---|
| Eloquent observer | Throws `ProtectedAccountException` on delete, email change, and `is_protected → false` |
| `Gate::before` | Returns `true` for the protected user on every `can()` / policy / `@can` check — no Spatie or Shield required |
| Filament plugin | Hides `DeleteAction` and `ForceDeleteAction` on the protected user |

The observer is defense-in-depth. Use the facade in your policies for proper HTTP 403s (see [UserPolicy](#userpolicy) below).

## Commands

| Command | Purpose |
|---|---|
| `superadmin:setup` | Create or update the protected user. Writes to `.env` AND DB. Replaces the old `install` + `reset` commands. |
| `superadmin:status` | Summary of the protected user. Exits non-zero if missing. |
| `superadmin:status --verbose` | Adds the full health diagnostic matrix (model resolvable, column exists, protection enabled, role assigned, etc.). |

```bash
php artisan superadmin:status
# +----+--------------------+--------------------------+---+
# | #  | Setting            | Value                    |   |
# +----+--------------------+--------------------------+---+
# | 1  | Email              | superadmin@your-app.test | ✓ |
# | 2  | is_protected       | true                     | ✓ |
# | 3  | Role               | super_admin              | ✓ |
# +----+--------------------+--------------------------+---+
# ✓ Healthy.
```

## Configuration

The package config is intentionally tiny. After `php artisan vendor:publish --tag=superadmin-config`:

```php
return [
    'email'         => env('SUPER_ADMIN_EMAIL'),
    'password'      => env('SUPER_ADMIN_PASSWORD', 'superadmin'),
    'user_model'    => null,                                          // null = resolved from auth.providers
    'role'          => env('SUPER_ADMIN_ROLE', 'super_admin'),
    'auto_install'  => env('SUPER_ADMIN_AUTO_INSTALL', true),         // create user on MigrationsEnded
    'authorization' => ['gate_before' => true],                       // super admin passes every can()
    'protection'    => ['enabled' => true],                           // observer + Filament action hiding
    'filament'      => ['hide_destructive_actions' => true],
];
```

## Seeder integration

`SuperAdmin::ensure()` is the seeder-safe primitive — idempotent get-or-create:

```php
use Codenzia\SuperAdmin\Facades\SuperAdmin;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = SuperAdmin::ensure();
        // returns the existing protected user, or creates one with defaultEmail() + defaultPassword()

        // Continue with the rest of your seed data...
    }
}
```

You almost never need to call this — the migration hook already handles fresh installs. `ensure()` is for seeders that need the user object up-front (foreign keys, audit attribution, etc.) without risking a duplicate.

For raw create/update with explicit credentials, use `SuperAdmin::install($password, $email)`.

## Integration patterns

### User model trait (optional)

Adds `isSuperAdmin()` plus two query scopes:

```php
use Codenzia\SuperAdmin\Concerns\IsSuperAdmin;

class User extends Authenticatable
{
    use IsSuperAdmin;
}
```

```php
$user->isSuperAdmin();                       // bool
User::query()->superAdmin()->first();        // WHERE is_protected = true
User::query()->exceptSuperAdmin()->get();    // WHERE NOT is_protected
```

### UserPolicy

The observer throws — your policy should return a proper 403 first:

```php
use Codenzia\SuperAdmin\Facades\SuperAdmin;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    public function delete(User $actor, User $target): Response
    {
        if (SuperAdmin::is($target)) {
            return Response::deny('The super admin account cannot be deleted.');
        }

        return $actor->can('delete_user') ? Response::allow() : Response::deny();
    }

    public function update(User $actor, User $target): Response
    {
        if (SuperAdmin::is($target) && ! SuperAdmin::is($actor)) {
            return Response::deny('Only the super admin can modify the super admin account.');
        }

        return $actor->can('update_user') ? Response::allow() : Response::deny();
    }
}
```

### Filament

```php
use Codenzia\SuperAdmin\Filament\SuperAdminPlugin;

$panel->plugin(SuperAdminPlugin::make());
```

To also hide the protected row from non-super-admin viewers:

```php
public static function getEloquentQuery(): Builder
{
    $query = parent::getEloquentQuery();

    if (! auth()->user()?->isSuperAdmin()) {
        $query->exceptSuperAdmin();
    }

    return $query;
}
```

### Authorization modes

| Mode | `authorization.gate_before` | Behavior |
|---|:---:|---|
| **Default** (zero-config) | `true` | `Gate::before` authorizes the super admin for every ability. Role is also assigned (best-effort, if `assignRole()` exists on the User model). |
| **Role-only** | `false` | Package only assigns the configured role. Authorization is delegated to your project (typically Filament Shield's own `Gate::before`). |

The package never creates the role row, defines permissions, or installs Shield — those remain your project's responsibility. In default mode, you don't need any of them: `Gate::before` covers authorization on its own.

## Upgrading from 0.2.x

v0.3.0 is a **clean break**. The vendor-friction model is gone. Per-app upgrade:

1. `composer update codenzia/laravel-superadmin`
2. `php artisan migrate` — auto-installs the protected user if none exists; no-op if one does.
3. Replace any seeder calls to `SuperAdmin::install(...)` with `SuperAdmin::ensure()` (or keep `install()` if you need explicit credentials).
4. Delete `.env` entries that are no longer recognized (see table below). Keep `SUPER_ADMIN_EMAIL` and `SUPER_ADMIN_PASSWORD` if you set them.

### Removed in 0.3.0

| Removed | Replacement |
|---|---|
| `superadmin:install` | `superadmin:setup` (or just run `migrate` for the default install) |
| `superadmin:reset` | `superadmin:setup` |
| `superadmin:assign-role` | (automatic on `install()` / `ensure()` / `setup`) |
| `superadmin:doctor` | `superadmin:status --verbose` |
| `--confirm` flag, typed phrase, `VendorCommandInvoked` notification | Removed entirely. No friction layer. |
| `SUPER_ADMIN_NOTIFY_MAIL` / `SUPER_ADMIN_NOTIFY_SLACK` / `SUPER_ADMIN_VENDOR_PHRASE` | Removed entirely. |
| `vendor_commands.*` config | Removed entirely. |
| `notifications.*` config | Removed entirely. |
| `protection.block_delete` / `block_email_change` / `block_flag_change` | Collapsed into `protection.enabled` — all three behaviors fire together. |

### Kept

- `is_protected` column + Eloquent observer
- `Gate::before` authorization
- Filament destructive-action hiding
- `IsSuperAdmin` trait + query scopes
- `SuperAdmin` facade — `is()`, `user()`, `exists()`, `install()`, `email()`, `userModel()`, `isConfigured()`, `assignRole()`, `hasConfiguredRole()`, `withoutProtection()`
- New facade methods: `ensure()`, `defaultEmail()`, `defaultPassword()`

## Testing

84 Pest tests, 149 assertions. Covers the manager, observer, Gate::before, the `MigrationsEnded` hook, the setup command, the env writer, and the Filament plugin's authorization mode matrix.

```bash
composer test
```

## License

MIT © Codenzia. See [LICENSE.md](LICENSE.md).
