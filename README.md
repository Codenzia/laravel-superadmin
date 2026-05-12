# codenzia/laravel-superadmin

> A protected super admin account for Laravel — designed for vendor-deployed applications where the customer admins shouldn't accidentally (or casually) wipe the account the vendor needs for support.

[![Tests](https://github.com/Codenzia/laravel-superadmin/actions/workflows/tests.yml/badge.svg)](https://github.com/Codenzia/laravel-superadmin/actions/workflows/tests.yml)
[![PHP Version](https://img.shields.io/badge/php-%5E8.3-blue.svg)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/laravel-%5E11.0%20%7C%20%5E12.0-red.svg)](https://laravel.com/)
[![Filament](https://img.shields.io/badge/filament-%5E4.0-orange.svg)](https://filamentphp.com/)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE.md)

---

## What this package is (and isn't)

This package is **admin-UI tamper protection plus a documented operational runbook** for a special "super admin" account that the package vendor needs for ongoing support of a deployed Laravel application.

It **does** protect against:

- ✅ Customers clicking "Delete" on the protected account in your admin UI (Filament plugin hides the button)
- ✅ Customers running code that deletes or modifies the protected account via Eloquent (observer throws)
- ✅ Casual misuse of vendor-only artisan commands (hidden from `php artisan list`, require `--confirm` flag + typed phrase, every invocation is loudly notified)
- ✅ Silent failure modes (a `doctor` command exits non-zero if anything is misconfigured)

It **does not** protect against:

- ❌ A customer with shell access to their own production server. They can `composer remove` the package, run raw SQL, or modify the package's source code. **Shell access = full control. No app-layer code can change that.**
- ❌ Customers who deliberately violate their support contract by tampering with the package itself.

If you need cryptographic protection against a malicious customer with shell access, this is not that package — you'd need off-server signed tokens (Ed25519) with a private key only the vendor holds, which adds significant operational complexity. This package is deliberately the simpler, honest alternative: protect against accidents and casual misuse, rely on contracts for the rest.

---

## Why a "super admin" account at all?

Vendor-deployed Laravel apps usually have two distinct admin needs:

| Account | Purpose | Who manages it? |
|---|---|---|
| **Customer admin** (e.g., `admin@customer.com`) | Day-to-day customer admin work | The customer |
| **Vendor super admin** (e.g., `support@codenzia.com`) | Emergency vendor support, troubleshooting, fixing things the customer broke | You (the vendor) |

The vendor super admin is the account that:

- Must exist for you to provide ongoing support
- Should never be deletable by the customer's UI users
- Should be detectable as misconfigured if something goes wrong (`doctor` command)
- Has a documented reset procedure if the password is lost

That's what this package provides.

---

## Table of contents

- [Quick start](#quick-start)
- [How protection works](#how-protection-works)
- [Configuration](#configuration)
- [Commands](#commands)
  - [`superadmin:install`](#superadmininstall)
  - [`superadmin:reset`](#superadminreset)
  - [`superadmin:doctor`](#superadmindoctor)
  - [`superadmin:status`](#superadminstatus)
- [Friction controls for vendor commands](#friction-controls-for-vendor-commands)
- [Integration patterns](#integration-patterns)
  - [User model trait](#user-model-trait)
  - [UserPolicy](#userpolicy)
  - [Idempotent seeder](#idempotent-seeder)
  - [Filament v4 panel](#filament-v4-panel)
  - [spatie/laravel-permission roles](#spatielaravel-permission-roles)
- [Vendor runbook](#vendor-runbook)
- [Testing](#testing)
- [Threat model — honest version](#threat-model--honest-version)
- [FAQ](#faq)
- [License](#license)

---

## Quick start

### Minimum (two commands)

```bash
composer require codenzia/laravel-superadmin
php artisan superadmin:install --confirm
```

That's it. The install command:

- Auto-runs the package's own migration if `users.is_protected` is missing (only ours, not other pending migrations)
- Prompts for email + password
- Creates the protected user
- Assigns the `super_admin` role if Spatie is installed (auto-skipped if not)
- Registers `Gate::before` so the super admin can authorize anything in the app

The super admin can log in and do everything immediately. No Spatie, no Shield, no permissions to configure.

### Recommended (full setup)

```bash
# 1. Install
composer require codenzia/laravel-superadmin

# 2. (Optional) Publish the config so you can tweak defaults
php artisan vendor:publish --tag=superadmin-config

# 3. Run all pending migrations (yours + ours; cleaner than relying on install's auto-migration)
php artisan migrate

# 4. Install the protected super admin
php artisan superadmin:install --confirm

You'll be prompted for:

- The super admin email (defaults to `superadmin@<your-app-host>`)
- The super admin password (min 12 characters, confirmed)
- A typed confirmation phrase (default: `I AM THE VENDOR`)

The command:

1. Creates the user row in the database
2. Sets `is_protected = true` on the row
3. Assigns the configured role (`super_admin` by default, if `spatie/laravel-permission` is installed and the role exists)
4. Logs the invocation to your audit channel
5. Dispatches a `VendorCommandInvoked` notification to your configured email/Slack

### 5. Set the env variable

After install, add this to `.env` (on the production server):

```ini
SUPER_ADMIN_EMAIL=superadmin@yourdomain.com
```

This is how the observer identifies the protected account in subsequent requests.

### 6. (Recommended) Configure vendor notifications

So you get an alert if anyone runs an internal command:

```ini
SUPER_ADMIN_NOTIFY_MAIL=you@codenzia.com
SUPER_ADMIN_NOTIFY_SLACK=https://hooks.slack.com/services/T.../B.../...
```

### 7. (Recommended) Register the Filament plugin

In your panel provider:

```php
use Codenzia\SuperAdmin\Filament\SuperAdminPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugin(SuperAdminPlugin::make())
        // ...
        ;
}
```

This hides `DeleteAction` and `ForceDeleteAction` on the protected account in every Filament resource.

### 8. Verify the install

```bash
php artisan superadmin:doctor
```

You should see all green checks. If anything is off, the command exits non-zero and tells you what to fix.

---

## How protection works

```
┌──────────────────────────────────────────────────────────────────────┐
│                       Three protection layers                        │
├──────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  1. Filament UI plugin     ─────►   Customer can't even SEE the     │
│     hides delete actions             delete button on this row       │
│                                                                      │
│  2. UserPolicy (your code) ─────►   HTTP 403 if a customer tries    │
│     SuperAdmin::is($target)          to delete via API/form          │
│                                                                      │
│  3. Eloquent observer      ─────►   Throws even from tinker,        │
│     ProtectedAccountException        seeders, raw Eloquent calls     │
│                                                                      │
│  Identification: DB column (is_protected = true) AND env email.      │
│  Either signal identifies the protected row. Both must be tampered   │
│  with to silently disable protection.                                │
│                                                                      │
└──────────────────────────────────────────────────────────────────────┘
```

**Identification redundancy.** The package identifies the protected account by *both*:

- `users.is_protected = true` (DB-backed, survives env wipes), AND
- `users.email` matching `SUPER_ADMIN_EMAIL` (env-backed, survives DB tampering)

A customer who edits only one source still has the other in place. The `doctor` command flags divergence.

**Observer is defense in depth, not your primary authorization layer.** Use the facade in your `UserPolicy` (see below) so HTTP requests get proper 403s rather than uncaught exceptions.

---

## Configuration

After `vendor:publish`, edit `config/superadmin.php`:

```php
return [
    // Required: identifies the protected account
    'email' => env('SUPER_ADMIN_EMAIL'),

    // Optional: override the User model (null = resolved from auth.providers)
    'user_model' => null,

    // Optional: Spatie role to assign on install/reset
    'role' => env('SUPER_ADMIN_ROLE', 'super_admin'),

    // Eloquent-layer protection
    'protection' => [
        'enabled' => env('SUPER_ADMIN_PROTECTION', true),
        'block_delete' => true,
        'block_email_change' => true,
        'block_flag_change' => true,
    ],

    // Vendor-only command friction
    'vendor_commands' => [
        'hide_from_list' => true,        // hide from `php artisan list`
        'require_confirm_flag' => true,  // require --confirm flag
        'require_typed_phrase' => true,  // require typed phrase
        'typed_phrase' => 'I AM THE VENDOR',
        'notify_on_invocation' => true,
    ],

    // Notification recipients (configure on customer deployments
    // so YOU get alerted when commands run)
    'notifications' => [
        'enabled' => env('SUPER_ADMIN_NOTIFY', true),
        'mail_to' => env('SUPER_ADMIN_NOTIFY_MAIL'),
        'slack_webhook' => env('SUPER_ADMIN_NOTIFY_SLACK'),
    ],

    // Audit log channel
    'log_channel' => env('SUPER_ADMIN_LOG_CHANNEL'),

    // Filament panel integration
    'filament' => [
        'enabled' => true,
        'hide_destructive_actions' => true,
        'badge_label' => 'Super Admin',
    ],
];
```

### Recommended `.env` keys

```ini
SUPER_ADMIN_EMAIL=superadmin@yourdomain.com
SUPER_ADMIN_NOTIFY_MAIL=you@codenzia.com,security@codenzia.com
SUPER_ADMIN_NOTIFY_SLACK=https://hooks.slack.com/services/T.../B.../...
SUPER_ADMIN_LOG_CHANNEL=superadmin
```

---

## Commands

### `superadmin:install`

Initial setup. **Vendor-only**: hidden from `artisan list`, requires `--confirm`, refuses to re-run if a protected account already exists.

```bash
# Interactive
php artisan superadmin:install --confirm

# Fully non-interactive
php artisan superadmin:install \
    --email=superadmin@yourdomain.com \
    --password='your-strong-password' \
    --confirm
```

Behavior:

- Refuses if any protected user exists in the DB
- Validates email format
- Requires password length ≥ 12
- Asks for the typed confirmation phrase (configurable)
- Creates the user with `is_protected = true`
- Dispatches notification + audit log entry

### `superadmin:reset`

Reset the protected account's password. **Vendor-only**: hidden, requires `--confirm`, requires the typed phrase, every invocation is notified.

```bash
# Interactive — generates a random 24-char password
php artisan superadmin:reset --confirm

# Provide your own
php artisan superadmin:reset --password='your-new-password' --confirm
```

Behavior:

- Refuses if no protected user exists (use `install` first)
- Resets the password on the existing protected user
- Maintains `is_protected = true` (so the protection stays in place)
- Re-assigns the role
- Dispatches notification + audit log entry

### `superadmin:assign-role`

Re-attempt role assignment to the protected user. Use after running your role seeder (e.g. `php artisan shield:install`) when initial `install` ran before the role existed. **Vendor-only**: hidden, requires `--confirm`, requires typed phrase.

```bash
php artisan superadmin:assign-role --confirm
```

Behavior:

- Refuses if no protected user exists
- Refuses if `superadmin.role` is null
- Reports the result clearly: `Assigned`, `AlreadyAssigned`, `Failed`, `NotSupported`, or `NotConfigured`
- On `Failed`: tells you to run `shield:install` or your role seeder, then retry
- Dispatches notification + audit log

### `superadmin:doctor`

Health check. **Public** (not hidden). Returns non-zero exit code on any problem. Run it in CI, deploy hooks, or healthcheck scripts.

```bash
php artisan superadmin:doctor
```

Checks:

1. `SUPER_ADMIN_EMAIL` is set
2. User model is resolvable
3. `users.is_protected` column exists
4. Protection is enabled
5. Protected user exists in DB
6. Protected user has `is_protected = true`
7. Protected user's email matches `SUPER_ADMIN_EMAIL`
8. Notification recipients are configured

Output:

```
+----------------------------------------+--------------------------+---+
| Setting                                | Value                    |   |
+----------------------------------------+--------------------------+---+
| SUPER_ADMIN_EMAIL                      | superadmin@you.test      | ✓ |
| User model                             | App\Models\User          | ✓ |
| is_protected column on users           | exists                   | ✓ |
| Protection enabled                     | yes                      | ✓ |
| Protected user exists                  | yes (ID 1)               | ✓ |
| Protected user is_protected = true     | yes                      | ✓ |
| Email matches SUPER_ADMIN_EMAIL        | yes                      | ✓ |
| Vendor notifications                   | configured               | ✓ |
+----------------------------------------+--------------------------+---+

✓ All checks passed.
```

### `superadmin:status`

Read-only summary. **Public.** Always safe to run.

```bash
php artisan superadmin:status
```

---

## Friction controls for vendor commands

These settings (under `superadmin.vendor_commands` in config) make it harder to **accidentally** invoke vendor commands. They are **not cryptographic security**: a determined customer with shell access can disable any of them by editing the config or package source. Their purpose is:

1. Prevent customers from stumbling into the command via `php artisan list`
2. Prevent typo-driven invocations
3. Force a deliberate, multi-step interaction so accidental runs are very unlikely
4. Loudly notify you on every successful invocation

| Setting | Default | Effect |
|---|---|---|
| `hide_from_list` | `true` | Commands don't appear in `php artisan list` |
| `require_confirm_flag` | `true` | Command refuses to run without `--confirm` |
| `require_typed_phrase` | `true` | Prompts for a typed phrase that must match exactly |
| `typed_phrase` | `'I AM THE VENDOR'` | The phrase the operator must type |
| `notify_on_invocation` | `true` | Dispatches `VendorCommandInvoked` notification on every run |

### Customizing the typed phrase

You can configure a per-customer phrase that only you know. Customers won't see it in the README. If they run the command without the phrase, they get an error and learn nothing:

```ini
SUPER_ADMIN_VENDOR_PHRASE="my secret operational phrase 2026-Q1"
```

```php
// config/superadmin.php
'vendor_commands' => [
    'typed_phrase' => env('SUPER_ADMIN_VENDOR_PHRASE', 'I AM THE VENDOR'),
],
```

Again — this is friction, not security. But it raises the bar from "anyone who reads the README can run this" to "you need to be told the current phrase out of band."

---

## Integration patterns

### User model trait

Optional but recommended. Adds `isSuperAdmin()` plus two query scopes.

```php
namespace App\Models;

use Codenzia\SuperAdmin\Concerns\IsSuperAdmin;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use IsSuperAdmin;

    // ...
}
```

```php
$user->isSuperAdmin();                       // bool — checks is_protected first, then email
User::query()->superAdmin()->first();        // WHERE is_protected = true
User::query()->exceptSuperAdmin()->get();    // WHERE is_protected = false OR is_protected IS NULL
```

### UserPolicy

The Eloquent observer is **defense in depth**, not your HTTP authorization layer. Wire the facade into your policy so unauthorized requests get a proper 403:

```php
namespace App\Policies;

use App\Models\User;
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

### Idempotent seeder

If you want the protected account auto-restored on every deploy (so a customer who manually deletes the DB row gets it back on the next migration), or just want a dev-mode super admin in your `db:seed` flow, call `SuperAdmin::install()` from a seeder. The facade is the single primitive — use it from wherever fits your project.

**Pattern A — Inline in your existing `DatabaseSeeder`** (most common; the seeder is already creating roles + dev users)

```php
namespace Database\Seeders;

use Codenzia\SuperAdmin\Facades\SuperAdmin;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create your roles first — install() will assign super_admin to the user.
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        // 2. Install the protected super admin.
        //    Sets is_protected = true, hashes the password, assigns the role.
        $superAdmin = SuperAdmin::install('dev-password-123', 'superadmin@your-app.test', 'Super Admin');

        // 3. Apply project-specific fields the package doesn't know about.
        //    (The observer allows updates as long as you don't change email or
        //    flip is_protected to false.)
        $superAdmin->update(['status' => 'approved']);

        // 4. Continue with the rest of your seeders (regular admin, agents, etc.)
        // ...
    }
}
```

**Pattern B — Dedicated `SuperAdminSeeder`** (when production deploys must auto-restore the account if deleted)

```php
namespace Database\Seeders;

use Codenzia\SuperAdmin\Facades\SuperAdmin;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! SuperAdmin::isConfigured()) {
            $this->command?->warn('superadmin.email is not configured. Skipping.');

            return;
        }

        if (SuperAdmin::exists()) {
            return; // already there, idempotent no-op
        }

        $password = env('SUPER_ADMIN_SEEDER_PASSWORD');

        if (! is_string($password) || strlen($password) < 12) {
            $this->command?->warn(
                'SUPER_ADMIN_SEEDER_PASSWORD is not set or too short. '
                .'Run `php artisan superadmin:install --confirm` manually instead.'
            );

            return;
        }

        SuperAdmin::install($password);
    }
}
```

Call it from `DatabaseSeeder::run()`:

```php
$this->call(SuperAdminSeeder::class);
```

#### Password sources by environment

| Environment | Recommended source |
|---|---|
| Local dev | A hardcoded `'superadmin'` or similar in the seeder is fine. Convenient and matches everyone's expectations. |
| Staging | `env('SUPER_ADMIN_SEEDER_PASSWORD')` populated by your deploy pipeline. |
| Production | **Do not seed.** Run `php artisan superadmin:install --confirm` once, interactively, with a real password. The seeder is for non-prod environments. |

#### What `SuperAdmin::install()` does

- Creates or updates the user identified by the configured email (or the email you pass as the second argument)
- Sets `is_protected = true`
- Hashes the password
- Sets `email_verified_at = now()` on creation only (preserves it on update)
- Returns the `User` model so you can chain project-specific updates (e.g., `->update(['status' => 'approved'])`)
- Automatically calls `assignRole()` if the User model has Spatie's `HasRoles` trait and `superadmin.role` is set
- Reports the role-assignment outcome — see `SuperAdmin::assignRole($user)` if you want the explicit result enum

### Filament v4 panel

```php
use Codenzia\SuperAdmin\Filament\SuperAdminPlugin;

$panel->plugin(SuperAdminPlugin::make());
```

Automatically hides `DeleteAction` and `ForceDeleteAction` on the protected account.

To also exclude the protected account from user listings for non-super-admin viewers:

```php
// In your UserResource
public static function getEloquentQuery(): Builder
{
    $query = parent::getEloquentQuery();

    if (! auth()->user()?->isSuperAdmin()) {
        $query->exceptSuperAdmin();
    }

    return $query;
}
```

### Roles, permissions, and authorization

The package supports **four authorization modes** via two orthogonal config flags. Pick the one that matches your project's RBAC philosophy.

| Mode | `gate_before` | `assign_role` | Behavior |
|---|:---:|:---:|---|
| **Default** (zero-config) | ✅ | ✅ | `Gate::before` authorizes the super admin for everything. Role is also assigned for Spatie/Shield integration (display, `hasRole()` checks). Works without any project-side wiring. |
| **Gate-only** | ✅ | ❌ | `Gate::before` authorizes the super admin. No role is assigned. Use when you don't use Spatie at all. |
| **Role-only** | ❌ | ✅ | Package only assigns the role. Authorization is up to your project (typically Shield's own `Gate::before`). |
| **Manual** | ❌ | ❌ | Package only manages `is_protected` + observer. Your project decides authorization entirely. |

Configure via env or `config/superadmin.php`:

```ini
SUPER_ADMIN_GATE_BEFORE=true     # register Gate::before so super admin passes every check
SUPER_ADMIN_ASSIGN_ROLE=true     # auto-assign the configured role on install/reset
SUPER_ADMIN_ROLE=super_admin     # the role name (used only when assign_role=true)
```

**How `Gate::before` works**

When enabled, the package registers a `Gate::before` callback that returns `true` for the protected user on every ability check. This is the same mechanism Filament Shield uses internally — when registered, every `$user->can(...)`, every policy method, every `@can` directive, every Filament action short-circuits to `true` for the protected user. **No roles, permissions, or policies need to be defined.**

This works in any Laravel project, with any RBAC stack, including none. It survives `composer remove spatie/laravel-permission` because it doesn't depend on roles existing.

**When to disable `gate_before`**

Disable it when your project's policy design requires strict per-permission control for *every* user, including the super admin. Some compliance-driven applications need this. In that case, you'll need Shield (which registers its own `Gate::before` keyed on the `super_admin` role) or per-resource policy logic.

**What the package does NOT do (in any mode)**

- Create the role row in your roles table
- Create permissions
- Define policies
- Install Shield

Those are all your project's responsibility. In mode 1 (default), you don't actually need any of them for authorization to work — Gate::before handles it. In modes 3 and 4, you'll need to wire authorization up yourself, typically via `php artisan shield:install`.

**Deployment order (default mode — Gate::before)**

```bash
php artisan migrate                       # users.is_protected column
php artisan superadmin:install --confirm  # create protected user
```

That's the full flow. The super admin can authorize anything immediately via `Gate::before`. The role is also auto-assigned if Spatie is installed (cosmetic / for integration), but is not required.

**Deployment order (mode 3 — role-only, Shield handles auth)**

```bash
php artisan migrate
php artisan shield:install                # create super_admin role + permissions
php artisan shield:generate --all         # per-resource policies
php artisan superadmin:install --confirm  # create user, assign role
```

If `superadmin:install` runs before the role exists in this mode, the install command warns loudly:

```
  Role: Role "super_admin" FAILED to assign. The role probably does not exist yet.
  Run your role seeder (e.g. `php artisan shield:install`), then re-run:
     php artisan superadmin:assign-role --confirm
```

Recovery:

```bash
php artisan shield:install                    # create the role
php artisan superadmin:assign-role --confirm  # assign it
php artisan superadmin:doctor                 # verify
```

**Custom role name**

```ini
SUPER_ADMIN_ROLE=platform_owner
```

**Projects without Spatie**

The package detects whether the User model has `assignRole()`. If not, role assignment is reported as `NotSupported` and skipped. In the default mode (`gate_before=true`), authorization still works — you just won't have a Spatie role row.

---

## Vendor runbook

### Deploying to a new customer

```bash
# 1. Composer install + migrate
composer require codenzia/laravel-superadmin
php artisan migrate

# 2. Install the protected account
php artisan superadmin:install --confirm

# 3. Add the email + notification creds to .env
echo "SUPER_ADMIN_EMAIL=superadmin+CUSTOMERID@codenzia.com" >> .env
echo "SUPER_ADMIN_NOTIFY_MAIL=you@codenzia.com" >> .env

# 4. Verify
php artisan superadmin:doctor

# 5. Register the Filament plugin in the customer's PanelProvider
```

### Recovering access (forgotten password)

```bash
# SSH to customer server, then:
php artisan superadmin:reset --confirm
# Type "I AM THE VENDOR" when prompted
# A random 24-char password is generated and displayed once
# Copy it, log in, change it via the UI
```

### Customer says "I accidentally deleted the protected user"

```bash
# SSH to customer server, then:
php artisan superadmin:install --confirm
# This succeeds because no protected user exists
```

### Customer says the protected account isn't working

```bash
php artisan superadmin:doctor
# Read the output. Common fixes:
#   - SUPER_ADMIN_EMAIL not set:           fix .env
#   - is_protected column missing:         php artisan migrate
#   - is_protected = false on the row:     php artisan superadmin:reset --confirm (will set it back to true)
#   - Protection disabled in config:       fix config/superadmin.php
```

---

## Testing

The package ships with **60 Pest tests covering 102 assertions**:

- `SuperAdminManagerTest` — facade methods, DB-backed identification, idempotent install, password reset, protection bypass
- `IsSuperAdminTraitTest` — instance method + query scopes
- `InstallCommandTest` — fresh install, refuse re-install, --confirm requirement, typed phrase, hidden state
- `ResetCommandTest` — invalid scenarios, password reset, generation, notification dispatch, hidden state
- `DoctorCommandTest` — every reportable health state
- `StatusCommandTest` — diagnostic output
- `ObserverTest` — block delete + email change + is_protected flip, withoutProtection, config flag respect

Run the suite:

```bash
composer test
```

With coverage:

```bash
composer test-coverage
```

---

## Threat model — honest version

| Threat | Defended? |
|---|---|
| Customer clicks Delete in the admin UI | ✅ Filament plugin hides the action |
| Customer's code calls `$user->delete()` on the protected row | ✅ Observer throws |
| Customer tries to change the protected user's email | ✅ Observer throws |
| Customer tries to flip `is_protected` to false | ✅ Observer throws |
| Customer runs `php artisan superadmin:reset` casually | ⚠️ Friction: `--confirm` + typed phrase + loud notification |
| Customer runs `php artisan superadmin:install --force` after reading the README | ⚠️ Friction + refused (install detects existing protected user) |
| Customer runs raw `DB::table('users')->delete(1)` | ❌ Observer doesn't fire on raw queries. Use DB role separation if this is in your threat model. |
| Customer SSHs into prod and runs `composer remove codenzia/laravel-superadmin` | ❌ Protection vanishes. Contract violation, observable. |
| Customer edits package source to disable protection | ❌ Visible code tampering. Contract violation, observable. |
| Customer with full server compromise (RCE) | ❌ Nothing app-layer can save you. |

The package raises the bar from "any admin user can wipe the protected account from the UI" to "a customer would need shell access AND a clear act of tampering to remove it." For most vendor-deployed Laravel apps with SMB customers, this is sufficient. For higher-stakes deployments (large enterprises, financial services), you need additional defenses outside the scope of this package: signed off-server tokens, DB role separation, external audit log shipping, etc.

---

## FAQ

**Q: Can I have multiple super admin accounts?**
A: Not officially supported. Multiple super admins dilute audit clarity and expand attack surface. Use a regular `admin` role for everyone except the one vendor break-glass account.

**Q: Does this work with Sanctum / Passport / Fortify?**
A: Yes. The package only cares about the User model and the `email` and `is_protected` columns. Your auth stack is unaffected.

**Q: Can I disable the package on a specific environment?**
A: Set `SUPER_ADMIN_PROTECTION=false`. The observer won't run, but the commands still work.

**Q: Does it work without Filament?**
A: Yes. The Filament integration is optional; the plugin auto-no-ops if Filament isn't installed.

**Q: What if `SUPER_ADMIN_EMAIL` and the row's `email` get out of sync?**
A: The package still identifies the row by `is_protected = true`. The `doctor` command warns about the mismatch. To fix, either update `.env` or update the user's email (you'll need `withoutProtection()` since the observer blocks email changes).

**Q: How do I rotate the typed phrase?**
A: It's just a config value. Update `config/superadmin.php` or the `SUPER_ADMIN_VENDOR_PHRASE` env var. No code or DB changes needed.

**Q: My customer found and ran `superadmin:reset`. What now?**
A: You got a `VendorCommandInvoked` notification within seconds, so you know it happened. Steps: (1) call the customer; (2) audit the database for unauthorized changes; (3) reset the password yourself; (4) update the typed phrase; (5) consider whether the relationship requires renegotiation. This is the scenario the friction controls are designed to make noisy, not impossible.

---

## License

MIT © Codenzia. See [LICENSE.md](LICENSE.md).
