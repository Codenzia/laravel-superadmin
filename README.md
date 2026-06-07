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
- An **Eloquent observer** that blocks deletion, email changes, **unprotect attempts (`true → false`)**, and **mass-assignment privilege escalation (`false → true`)** on the `is_protected` flag.
- A **`Gate::before` hook** so the super admin authorizes for every ability — works without Spatie, Shield, or any policies wired up.
- **Late role assignment.** Solves the `MigrationsEnded` vs Spatie-Role-row race: when `spatie/laravel-permission` is in use, the `super_admin` role row often doesn't exist yet at auto-install time, so the role would silently fail to attach. A wildcard `eloquent.created` listener retroactively assigns the configured role the moment the row appears in a later seeder run. Idempotent and best-effort; no-ops cleanly when Spatie isn't installed.
- A **Filament plugin** that hides destructive row actions (`delete`, `suspend`, `ban`, `impersonate`, …) and disables privileged form fields (`roles`, `status`, `email`, …) on the protected user row — automatically, across every consumer app, with no per-resource code.
- A **`superadmin:ensure` command** that interactively rotates name + email + password. DB-only — never reads or writes `.env`.
- A **`superadmin:status` command** (with `--verbose` for full health diagnostics) so you can verify the install in one shot.

## Quick start

```bash
composer require codenzia/laravel-superadmin
php artisan migrate
# ✓ Created protected super admin: superadmin@<your-host> (password: superadmin)
# Override defaults in your seeder via SuperAdmin::ensure([...]). Change later with `php artisan superadmin:ensure`.
```

That's the whole install. The package listens to `MigrationsEnded` and creates the protected user once, if and only if no protected user exists. Re-running `migrate` is a no-op.

### Override the defaults — two paths

v0.4.0+ keeps identity fields (name / email / password) out of `.env` and config entirely. Plaintext credentials never live on the host filesystem. Two override paths:

**(1) Pin the values in your seeder** — runs every `migrate:fresh --seed` / on first install:

```php
use Codenzia\SuperAdmin\Facades\SuperAdmin;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        SuperAdmin::ensure([
            'name'     => 'Super Admin',
            'email'    => 'admin@your-app.test',
            'password' => 'your-strong-password',
        ]);
    }
}
```

Pass any subset of `['name', 'email', 'password']`. Omitted keys fall back to package defaults on create; on update they're left unchanged (password specifically — omit to keep the current hash).

**(2) Rotate post-install** — DB-only artisan command:

```bash
php artisan superadmin:ensure
# Super admin name [Super Admin]:
# Super admin email [admin@your-app.test]:
# Super admin password (leave blank to keep current): <new password>
# ✓ Updated protected super admin: admin@your-app.test
```

Non-interactive variant:

```bash
php artisan superadmin:ensure --email=admin@your-app.test --password='your-strong-password'
```

`superadmin:ensure` never reads or writes `.env`. Plaintext only lives in the seeder source (committed to your repo with code) or in the operator's terminal during rotation.

> **Production password warning.** The default `superadmin` is deliberately memorable for local dev and internal use. **Always** override via the seeder or rotate via `superadmin:ensure` before exposing the app to anyone.

## Default email resolution

When the seeder doesn't pass `email`, the package derives one from your host's own config — never a vendor domain:

1. `superadmin@<host>` where `<host> = parse_url(config('app.url'), PHP_URL_HOST)`
2. else `superadmin@<slug>.local` where `<slug> = Str::slug(config('app.name'))`

So `APP_URL=https://myshop.com` → `superadmin@myshop.com`. `APP_NAME="My Shop"` with no URL → `superadmin@my-shop.local`.

## Default role resolution (Filament Shield bridge)

When `bezhansalleh/filament-shield` is installed, `configuredRole()` auto-discovers Shield's super-admin role name from `filament-shield.super_admin.name`. Apps don't need to set the role name in two places. When Shield is not present, the package falls back to the literal `'super_admin'`.

### "Is this user a super admin?" — `isSuperAdmin()`

Use **`SuperAdmin::isSuperAdmin($user)`** as the one fleet-wide check. It returns `true` when the user is the protected account (`is()`) **or** holds the configured super-admin role (`hasConfiguredRole()`) — so role-based super-admins count, not just the protected row. The `IsSuperAdmin` trait's `$user->isSuperAdmin()` delegates to it, so the model method and the facade always agree.

```php
SuperAdmin::isSuperAdmin($user);   // protected account OR super_admin role
SuperAdmin::is($user);             // strictly the protected account
```

Use `isSuperAdmin()` for access gates (who can reach an admin area); use `is()` when you specifically mean "this is *the* protected row" (e.g. guarding it from deletion).

## How protection works

The package identifies the protected row via the `users.is_protected = true` DB column. v0.4.0+ removed the secondary email-match path since identity is no longer env-driven — the flag is the single source of truth, set by `install()` / `ensure()` and defended by the observer.

Four protection layers — each independent, so tampering with one doesn't silently disable the others:

| Layer | Behavior |
|---|---|
| Eloquent observer | Throws `ProtectedAccountException` on **delete**, **email change**, **unprotect (`true → false`)**, and **promote (`false → true` outside `withoutProtection()`)**. The last is what blocks mass-assignment escalation when a consumer app puts `is_protected` in `$fillable`. |
| `Gate::before` | Returns `true` for the protected user on every `can()` / policy / `@can` check — no Spatie or Shield required |
| Filament plugin (UX layer) | Auto-hides destructive row actions (`delete`, `suspend`, `ban`, `impersonate`, …) and auto-disables privileged form fields (`roles`, `status`, `email`, `is_protected`, …) on the protected user row. Zero per-resource code. See [Filament](#filament) below. |
| Late role assignment | Wildcard `eloquent.created` listener that retroactively assigns the configured role to the protected user the moment the role row exists (typically after `migrate --seed`). |

The observer is defense-in-depth. Use the facade in your policies for proper HTTP 403s (see [UserPolicy](#userpolicy) below).

### App-side defense-in-depth (recommended)

Even with the observer guarding `false → true` promotion, you should keep `is_protected` out of the User model's `$fillable`. The observer only fires on `update`, and only inside Eloquent — raw `DB::table('users')->update(...)` calls bypass it. The two-layer pattern:

```php
class User extends Authenticatable
{
    use IsSuperAdmin;

    // is_protected is intentionally NOT fillable. Only the package's
    // SuperAdmin::install() / SuperAdmin::ensure() (which wrap the
    // assignment in SuperAdmin::withoutProtection()) may set it.
    protected $fillable = ['name', 'email', 'password', 'phone', 'slug'];
}
```

## Commands

| Command | Purpose |
|---|---|
| `superadmin:ensure` | Create or update the protected user. **DB-only — never reads or writes `.env`.** Interactive prompts for name / email / password; pass any subset as flags to skip prompts. |
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

The package config is small. After `php artisan vendor:publish --tag=superadmin-config`:

```php
return [
    // v0.4.0+: identity (name / email / password / role) is NOT in this
    // config and NOT in env. See "Override the defaults" above — defaults
    // are seeder-driven via SuperAdmin::ensure([...]) or derived.
    'user_model'            => null,                                              // null = resolved from auth.providers
    'auto_install'          => env('SUPER_ADMIN_AUTO_INSTALL', true),             // create user on MigrationsEnded
    'authorization'         => ['gate_before' => true],                           // super admin passes every can()
    'protection'            => ['enabled' => env('SUPER_ADMIN_PROTECTION', true)],
    'late_role_assignment'  => env('SUPER_ADMIN_LATE_ROLE_ASSIGNMENT', true),     // attach role when row appears later
    'filament' => [
        'hide_destructive_actions' => true,                                       // master switch for the Filament plugin

        // Row actions auto-hidden on the protected user row. Apps extend by
        // merging their own entries — see "Filament" section below.
        'hidden_action_names' => [
            'delete', 'forceDelete',
            'suspend', 'unsuspend', 'ban', 'unban',
            'markEmailVerified', 'verify', 'unverify',
            'impersonate', 'demote',
        ],

        // Form fields auto-disabled when editing the protected user.
        'locked_field_names' => [
            'roles', 'role', 'permissions',
            'status', 'is_protected', 'email', 'user_type',
        ],
    ],
];
```

## Seeder integration

`SuperAdmin::ensure()` is the seeder-safe primitive. Two modes:

```php
use Codenzia\SuperAdmin\Facades\SuperAdmin;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // (a) No args — idempotent get-or-create.
        //     Returns the existing protected user, or creates one with
        //     defaultName() / defaultEmail() / defaultPassword().
        $superAdmin = SuperAdmin::ensure();

        // (b) With array — force-applies the supplied fields. Use this
        //     to pin app-specific values that survive every reseed.
        $superAdmin = SuperAdmin::ensure([
            'name'     => 'Super Admin',
            'email'    => 'admin@your-app.test',
            'password' => 'your-strong-password',
        ]);
    }
}
```

You don't strictly need the no-args call — the `MigrationsEnded` auto-install already handles fresh installs. The array form is the recommended pattern when a project wants stable, repo-tracked superadmin credentials across all of its environments.

For raw create/update with explicit credentials, use `SuperAdmin::install($password, $email, $name)`.

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
$user->isSuperAdmin();                       // bool — protected account OR super_admin role (delegates to the facade)
User::query()->superAdmin()->first();        // WHERE is_protected = true
User::query()->exceptSuperAdmin()->get();    // WHERE NOT is_protected
```

> `$user->isSuperAdmin()` is role-aware (it calls `SuperAdmin::isSuperAdmin($this)`); the `superAdmin()` / `exceptSuperAdmin()` query scopes filter strictly on the `is_protected` column.

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

The plugin registers three defense-in-depth UX layers on the protected user row, all toggleable via `config/superadmin.php` and active by default:

1. **`DeleteAction` / `ForceDeleteAction` auto-hide** — original behavior. Admins never see a button that would only error at the observer layer.
2. **Custom destructive row actions auto-hide.** Any `Filament\Actions\Action` whose `getName()` is in `filament.hidden_action_names` is hidden on the protected user. The default list catches the verbs we ship across our consumer apps: `delete`, `forceDelete`, `suspend`, `unsuspend`, `ban`, `unban`, `markEmailVerified`, `verify`, `unverify`, `impersonate`, `demote`.
3. **Privileged form fields auto-disable.** Any `Filament\Forms\Components\Field` whose `getName()` is in `filament.locked_field_names` is disabled when the form's record is the super admin. Default list: `roles`, `role`, `permissions`, `status`, `is_protected`, `email`, `user_type`. Closes the "admin demotes the super admin via the roles Select" loophole.

Apps extend the defaults via config, no code:

```php
// config/superadmin.php
'filament' => [
    'hidden_action_names' => [
        ...config('superadmin.filament.hidden_action_names'),
        'my_app_specific_destructive_action',
    ],
    'locked_field_names' => [
        ...config('superadmin.filament.locked_field_names'),
        'my_app_specific_privileged_field',
    ],
],
```

> **Caveat.** Filament's `->hidden()` and `->disabled()` setters *replace* prior conditions (they don't AND/OR). If app code chains an explicit `->hidden(false)` *after* construction, the package's auto-hide is overridden. Apps that rely on `->visible(fn () => ...)` for conditional showing (the common pattern) are unaffected because `visible` and `hidden` are separate fields and an action is hidden when *either* hides it.

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

## What's new since 0.3.0

**0.3.2 (2026-05-22).** Adds **late role assignment** for the `MigrationsEnded`-vs-Spatie-Role-row race, and **Filament auto-lock** for the protected user row: every consumer app now auto-hides destructive row actions and auto-disables privileged form fields with no per-resource code. New config keys: `late_role_assignment`, `filament.hidden_action_names`, `filament.locked_field_names`. Tests grew from 84 to 105.

**0.3.1 (2026-05-21).** **Security:** the observer now blocks `is_protected: false → true` promotion via Eloquent update (mass-assignment privilege escalation defense). Previously only the downgrade direction was guarded. Also cleans up three stale `protection.block_*` config reads that were documented as removed in 0.3.0 but never deleted from the observer code.

See [CHANGELOG.md](CHANGELOG.md) for the full release notes.

## Upgrading from 0.3.x to 0.4.0

v0.4.0 moves identity (name / email / password / role) entirely out of `.env` and config. Per-app upgrade:

1. `composer update codenzia/laravel-superadmin`
2. Move any per-app overrides from `.env` into your seeder:
   ```php
   // database/seeders/UserSeeder.php
   SuperAdmin::ensure([
       'email'    => 'admin@your-app.test',     // was: SUPER_ADMIN_EMAIL
       'password' => 'your-strong-password',    // was: SUPER_ADMIN_PASSWORD
   ]);
   ```
3. Delete `SUPER_ADMIN_PASSWORD`, `SUPER_ADMIN_EMAIL`, `SUPER_ADMIN_ROLE`, `SUPER_ADMIN_NAME` from every `.env` and `.env.example`. These env vars are no longer honored — leaving them set is harmless but stale.
4. If you publish the package config: delete the `email`, `password`, `role` keys from `config/superadmin.php`. They're no longer read.
5. Update any callers of `php artisan superadmin:setup` to `php artisan superadmin:ensure`. The old command name was removed.
6. If you use Filament Shield: nothing to do — `configuredRole()` now auto-discovers `filament-shield.super_admin.name`.

### Removed in 0.4.0

| Removed | Replacement |
|---|---|
| `SUPER_ADMIN_PASSWORD` env var | Seeder override: `SuperAdmin::ensure(['password' => '...'])` |
| `SUPER_ADMIN_EMAIL` env var | Seeder override: `SuperAdmin::ensure(['email' => '...'])` |
| `SUPER_ADMIN_ROLE` env var | Auto-discovered from `filament-shield.super_admin.name` |
| `config('superadmin.email' / '.password' / '.role')` | Same — moved into seeder or auto-discovered |
| `superadmin:setup` command | `superadmin:ensure` (interactive prompts, but DB-only — no `.env` writes) |
| `EnvWriter` helper | Removed entirely — the package never writes to `.env` now |

## Upgrading from 0.2.x

v0.3.0 was a **clean break**. The vendor-friction model is gone. Per-app upgrade:

1. `composer update codenzia/laravel-superadmin`
2. `php artisan migrate` — auto-installs the protected user if none exists; no-op if one does.
3. Replace any seeder calls to `SuperAdmin::install(...)` with `SuperAdmin::ensure()` (or keep `install()` if you need explicit credentials).
4. Delete `.env` entries that are no longer recognized (see table below).

### Removed in 0.3.0

| Removed | Replacement |
|---|---|
| `superadmin:install` | `superadmin:ensure` (or just run `migrate` for the default install) |
| `superadmin:reset` | `superadmin:ensure` |
| `superadmin:assign-role` | (automatic on `install()` / `ensure()`) |
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
- `SuperAdmin` facade — `is()`, `isSuperAdmin()`, `user()`, `exists()`, `install()`, `email()`, `userModel()`, `isConfigured()`, `assignRole()`, `hasConfiguredRole()`, `withoutProtection()`
- Facade methods: `ensure(?array)`, `defaultEmail()`, `defaultPassword()`, `defaultName()`

## Testing

**105 Pest tests, 173 assertions.** Covers the manager, the observer (delete + email + unprotect + promote-escalation), Gate::before, the `MigrationsEnded` auto-install hook, the late-role-assignment listener, the setup command, the env writer, and the Filament plugin (DeleteAction / ForceDeleteAction hiding, custom-named-action auto-hide, locked form-field auto-disable, master-switch kill, app-extended allowlists).

```bash
composer test
```

## License

MIT © Codenzia. See [LICENSE.md](LICENSE.md).
