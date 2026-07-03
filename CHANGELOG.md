# Changelog

All notable changes to `codenzia/laravel-superadmin` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security
- **`SuperAdminServiceProvider`'s role-promotion guard no longer permanently disables itself.** The memoized super-admin role id used a `false` "not yet resolved" sentinel, but a `null` lookup (role row doesn't exist yet) was also cached — silently turning the guard off for the rest of the process once that happened. A `null` result is now retried on the next pivot attach instead of being cached.
- **`SuperAdminObserver` now guards `is_protected = true` on `create()`, not just `update()`.** Previously only flipping the flag on an existing row was blocked; inserting a brand-new row with `is_protected` already `true` (e.g. via `$fillable` mass-assignment, `Model::unguard()`, or a registration flow passing raw input into `create()`) minted an unauthenticated god-account. Now throws `ProtectedAccountException` outside `SuperAdmin::withoutProtection()`, which the package's own `install()` / `ensure()` already use.
- **`password` (and `password_confirmation`) added to the Filament `locked_field_names` default list.** Previously the auto-disable list covered `roles`, `status`, `is_protected`, `email`, etc. but not `password` — any admin with access to a host app's user edit form could set a new password on the protected super admin row and log in as it, inheriting `Gate::before` on every ability.
- **Removed the hardcoded `superadmin@codenzia.com` default email.** `superadmin.email` (env `SUPER_ADMIN_EMAIL`) now has no default; when unset the package derives the address from the host's own domain (`superadmin@<APP_URL host>` → `superadmin@<APP_NAME slug>.local`). This stops routing a third-party install's god-account recovery to a vendor-controlled mailbox. Codenzia's own fleet must now set `SUPER_ADMIN_EMAIL` explicitly per app.
- **Recovery route is now disabled by default** (`superadmin.recovery.enabled` → `false`; opt in with `SUPER_ADMIN_RECOVERY=true`). It is an unauthenticated public endpoint and should be enabled deliberately, ideally with a non-default `SUPER_ADMIN_RECOVERY_PATH`.
- **Memorable `superadmin` password is now restricted to `local`/`testing` environments.** Previously every non-`production` environment (staging, uat, demo, …) auto-created the omnipotent account with the well-known password `superadmin`; those environments now get a random throwaway, claimed via the recovery route or `superadmin:ensure`. `SUPER_ADMIN_PASSWORD` still overrides in any environment.

### Fixed
- **Break-glass reset now evicts the protected user's live database sessions.** The `PasswordReset` event alone does nothing on stock Laravel (no listener ships for it), so an attacker's authenticated session previously survived a break-glass reset unless the host ran `AuthenticateSession` middleware. When `session.driver` is `database`, the reset now also deletes the user's session rows (best-effort, never breaks the reset). Non-database drivers are unaffected — see the README for the `AuthenticateSession` recommendation.
- **`GET /superadmin/reset/{token}` is now rate-limited** like the other recovery endpoints. It runs a bcrypt token check on every unauthenticated request, so without a limit it was both a live/expired-token oracle and an unauthenticated CPU-amplification target. The default per-IP `max_attempts` is raised from 3 to 5 to leave room for a legitimate send -> GET -> POST flow plus one retry.
- **`SuperAdmin::is()` no longer silently fails on host models without an `is_protected` boolean cast.** It now coerces with `(bool)` instead of a strict `=== true || === 1`, so a column returned as the string `"1"` (common on MySQL PDO without a cast) still resolves as protected — restoring all four protection layers (gate, observer, role guard, Filament locks) on such hosts.
- **Break-glass recovery reset now rotates `remember_token` and fires `Illuminate\Auth\Events\PasswordReset`**, evicting any persistent "remember me" cookie / triggering framework session invalidation — the point of a break-glass reset.
- **`GET /superadmin/reset/{token}` now validates the token up front** and redirects with an error on a stale/expired link, instead of rendering the form and only failing on submit.

### Changed
- Removed dead code: the inert email-identity branches in `is()` / `user()` / the observer, and the never-produced `RoleAssignmentResult::Disabled` case (with `describe()` strings updated to current config reality).
- Minor performance: `SuperAdminManager::user()` memoizes its `is_protected` column check, and the role-promotion guard caches the resolved super-admin role id across pivot attaches.

## [0.5.2] - 2026-06-11

### Added
- **`SuperAdmin::verifiedKnownPassword(): ?string`** — returns the known password candidate (env override, else the non-production default) only when it `Hash::check`s against the protected account's stored hash; null otherwise. The single safe display primitive: it can never echo a stale or random credential.
- **`superadmin:status` Password row** — shows the verified working password (`superadmin (default)` / `<value> (from SUPER_ADMIN_PASSWORD)`) or `rotated/unknown — reset via the /superadmin recovery route or superadmin:ensure`. With this, apps stop printing superadmin credentials entirely (fleet seeder standard updated); the package owns all credential display.

## [0.5.1] - 2026-06-11

### Fixed
- **`install()` now survives hosts that guard `is_protected` against mass assignment.** It previously used `create()`/`fill()`, so models following the package's own hardening advice (`protected $guarded = [..., 'is_protected']` — e.g. toolenza) silently created the account UNPROTECTED. Provisioning now uses `forceFill()` on both create and update paths.
- **`install()` claims an existing non-protected row holding the target email** (case-insensitive, ignoring host global scopes) instead of crashing on the users.email unique constraint. This also self-repairs accounts broken by the guarded-model bug above: the next `ensure()` promotes the row back to protected.

## [0.5.0] - 2026-06-11

### Changed
- **`superadmin:status` shows the actual account email** when the account exists (row renamed `Configured email` → `Email`); the creation default is only shown while the account is missing.

### Added
- **Stable default email: `superadmin@codenzia.com`.** `defaultEmail()` now reads `superadmin.email` (env `SUPER_ADMIN_EMAIL`, default `superadmin@codenzia.com`) before falling back to the 0.4.0 host-derived forms (`superadmin@<APP_URL host>` → `superadmin@<slug>.local`). One vendor mailbox receives every recovery link across the fleet instead of a per-host address. Creation default only — identification stays strictly on `is_protected`, so registering the well-known address grants nothing. Non-Codenzia consumers set `SUPER_ADMIN_EMAIL` to their own domain (or empty it to restore host-derived emails).
- **Production-safe password defaults.** When nothing supplies a password (no seeder override, no `SUPER_ADMIN_PASSWORD`), the create-time default is now environment-aware: a cryptographically random throwaway in `production` (the account is claimed via the recovery route or `superadmin:ensure`), the literal `superadmin` everywhere else. A production install never sits behind a publicly documented credential. New manager methods: `configuredPassword()`, `knownDefaultPassword()`; auto-install output and the `superadmin:ensure` prompt never echo a random password.
- **`SUPER_ADMIN_PASSWORD` env override returns** (config `superadmin.password`) — with new semantics vs the 0.3.x key: it is an *optional opt-in* honored in every environment, including production, intended for local dev and vendor-controlled live demos. Unset it and you get the environment-aware defaults above. (0.4.0 removed the env var entirely; this reintroduces it deliberately.)
- **Break-glass recovery route.** Self-contained flow to set/reset the super admin password without CLI access: `GET/POST /superadmin` emails a single-use reset link to the protected account's own mailbox; `GET /superadmin/reset/{token}` + `POST /superadmin/reset` apply the new password (min 12 chars). Responds identically whether or not the account exists, only ever mails the protected account's own address, per-IP + global rate limits (3/h, 10/h default), every request logged, reset email names the app/host so unsolicited links double as probe alerts. Independent of the host's `password.reset` scaffolding (works on Filament-only apps). Config: `superadmin.recovery.{enabled,path,throttle}`; views publishable via `--tag=superadmin-views`. New: `RecoveryController`, `RecoveryLinkNotification`, `superadmin::` view namespace.
- **`prevent_role_promotion` protection** (config `superadmin.protection.prevent_role_promotion`, default true) — blocks assigning the configured super-admin role to any user other than the protected account via Eloquent pivot events; throws `ProtectedAccountException::cannotAssignSuperAdminRole()` before the pivot write.
- **`SuperAdmin::isSuperAdmin(?Model $user): bool`** — the single fleet-wide "is this user a super admin?" check: true when the user is the protected super admin (`is()`) **or** holds the configured super-admin role (`hasConfiguredRole()`). Use this in panel gates / policies / navigation so the definition of "super admin" lives in one place.

### Changed
- **`IsSuperAdmin` trait's `$user->isSuperAdmin()` is now role-aware.** It delegates to `SuperAdmin::isSuperAdmin($this)` (protected account **or** the configured super-admin role) instead of only `SuperAdmin::is()` (protected account only), so the model method and the facade agree. Apps that key access purely on `is_protected` are unaffected (no super-admin role assigned → same result); apps that assign the `super_admin` role to non-protected users will now see those users counted as super-admins by `$user->isSuperAdmin()`. The `superAdmin()` / `exceptSuperAdmin()` query scopes are unchanged (still filter on the `is_protected` column).

## [0.4.0] - 2026-05-24

### BREAKING — identity moves out of `.env` and config

The protected super-admin's identity (name / email / password / role) no longer lives in environment variables or in `config/superadmin.php`. Every consumer app sets identity in two places only:

1. **Seeder** — pin app-specific values that survive every `migrate:fresh --seed`:
   ```php
   SuperAdmin::ensure([
       'name'     => 'Super Admin',
       'email'    => 'admin@your-app.test',
       'password' => 'your-strong-password',
   ]);
   ```
2. **Artisan command** — rotate post-install: `php artisan superadmin:ensure`. DB-only — never writes to `.env`.

Plaintext credentials no longer touch the host filesystem. The motivating incidents: a deploy where the package's `'superadmin'` default won over an env value that didn't make it to the host, and the recurring drift of `SUPER_ADMIN_PASSWORD` living in 3-4 places per app (host `.env`, package config, app config shim, occasionally hard-coded in a seeder).

### Added
- **`SuperAdmin::ensure(?array $defaults = null)` overload.**
  - No args: existing idempotent get-or-create behavior (returns existing user untouched, creates with defaults when missing). Auto-install hook (`MigrationsEnded`) calls this.
  - With array: extracts `name`, `email`, `password` from the array and force-applies to the user. Creates on missing, updates on existing. Omitted keys fall back to package defaults on create; on update, omitted password keeps the current hash.
- **`SuperAdmin::defaultName()`** returning `'Super Admin'`. Mirror of `defaultPassword()` / `defaultEmail()` so all three identity defaults live in one place.
- **Filament Shield role auto-discovery.** `SuperAdminManager::configuredRole()` now reads `filament-shield.super_admin.name` when Shield is installed and configured. Apps no longer need to set `SUPER_ADMIN_ROLE` in two places (Shield's config + the package's). One-way bridge: Shield's config is the source of truth.
- **`php artisan superadmin:ensure` command.** Replaces `superadmin:setup`. Same interactive prompts (name / email / password) but **DB-only** — never reads or writes `.env`. Pass `--name`, `--email`, `--password` flags non-interactively, or any subset to skip those prompts.

### Removed (breaking)
- **`SUPER_ADMIN_PASSWORD` env var.** Move to seeder via `SuperAdmin::ensure(['password' => '...'])`. Default remains `'superadmin'` when no seeder override.
- **`SUPER_ADMIN_EMAIL` env var.** Move to seeder via `SuperAdmin::ensure(['email' => '...'])`. Default derives from `APP_URL` host or `APP_NAME` slug.
- **`SUPER_ADMIN_ROLE` env var.** Replaced by Shield auto-discovery (or literal `'super_admin'` fallback when Shield not present).
- **Config keys `email`, `password`, `role` in `config/superadmin.php`.** All three keys removed entirely. Behavioral keys (`auto_install`, `authorization.gate_before`, `protection.enabled`, `late_role_assignment`, `filament.*`) stay — those aren't identity.
- **`superadmin:setup` command.** Renamed to `superadmin:ensure` with new DB-only semantics.
- **`EnvWriter` helper class.** No longer needed — the package never writes to `.env`.
- **`is()` email-match identity path.** Identity is now determined exclusively by the `is_protected = true` flag.

### Migration
Per consumer app:
1. `composer update codenzia/laravel-superadmin` (or bump constraint to `^0.4.0`).
2. Move per-app password override into a seeder (`UserSeeder.php` / `StandardSeeder.php`) using the new `ensure([...])` form.
3. Delete `SUPER_ADMIN_PASSWORD`, `SUPER_ADMIN_EMAIL`, `SUPER_ADMIN_ROLE`, `SUPER_ADMIN_NAME` from `.env` + `.env.example`.
4. If `config/superadmin.php` is published, delete the `email`, `password`, `role` keys.
5. Replace any `php artisan superadmin:setup` callers with `superadmin:ensure`.
6. `composer test` should pass without touching test code; existing `is_protected`-based identity assertions are unaffected.

### Tests
- **99 Pest tests / 169 assertions green** after the rewrite. New coverage for:
  - `ensure(['password' => X])` / `ensure(['email' => X])` / `ensure(['name' => X])` on both create and update paths.
  - `defaultPassword()` / `defaultEmail()` / `defaultName()` ignoring any stale `superadmin.*` config writes.
  - `configuredRole()` Shield auto-discovery and literal-`'super_admin'` fallback.
  - `superadmin:ensure` artisan command never touching `.env`.

## [0.3.2] - 2026-05-22

### Added — Late role assignment (race fix)
- **Retroactive role assignment** for the protected super admin when the Spatie Role row is created later. Solves a real race: `MigrationsEnded` fires during `migrate`, which is *before* any seeder runs. If the host uses `spatie/laravel-permission`, the Role row for `super_admin` does not exist yet at that point, so `install()`'s best-effort `assignRole()` silently fails — the user ends up created and protected but without the role. The new wildcard `eloquent.created: *` listener picks up the role row the moment it appears (typically the seeder run that follows migrate) and assigns it to the existing protected user. Idempotent and best-effort.
- New config key `late_role_assignment` (default `true`) to disable for hosts that want strict control.
- Listener short-circuits cleanly when `spatie/laravel-permission` is not installed at all.

### Added — Filament auto-lock for protected user
- **Auto-hide named destructive row actions on the protected user.** The Filament plugin now walks every `Filament\Actions\Action` subclass at construction (via `configureUsing` on the base class — `ComponentManager` propagates configurations down `class_parents`) and hides any action whose `getName()` is in the configurable `filament.hidden_action_names` allowlist when the record is the super admin. Default list covers the destructive verbs we see across the 14+ Codenzia consumer apps: `delete`, `forceDelete`, `suspend`, `unsuspend`, `ban`, `unban`, `markEmailVerified`, `verify`, `unverify`, `impersonate`, `demote`.
- **Auto-disable privileged form fields when editing the protected user.** Same mechanism on `Filament\Forms\Components\Field`. Default `filament.locked_field_names`: `roles`, `role`, `permissions`, `status`, `is_protected`, `email`, `user_type`. Closes the "admin opens the super admin user form and removes the super_admin role assignment" loophole.
- **Apps can extend defaults via config** without touching any code:
  ```php
  // config/superadmin.php
  'filament' => [
      'hidden_action_names' => [...config('superadmin.filament.hidden_action_names'), 'my_custom_destructive_action'],
      'locked_field_names'  => [...config('superadmin.filament.locked_field_names'), 'my_privileged_field'],
  ],
  ```
- **Whole feature still gated by `filament.hide_destructive_actions`** (existing master switch).

### Migration notes
Consumer apps inherit the new protection on next `composer update` — no code changes required. If an app has a legitimately destructive action with a name that collides with the default list (e.g. an unrelated `delete` action against a non-User resource), the auto-hide closure short-circuits when `SuperAdmin::is($record)` is false, so non-User resources are unaffected.

### Tests
- 15 new Pest tests in `tests/Feature/FilamentAutoLockTest.php` covering: auto-hide of `DeleteAction`/`ForceDeleteAction`/named custom actions on the protected user, no-op on regular users and non-destructive actions, auto-disable of locked-name fields on the protected user, no-op on regular users and non-locked fields, the `hide_destructive_actions=false` master kill switch, and app-extended `hidden_action_names`. **105 tests / 173 assertions green.**

## [0.3.1] - 2026-05-21

### Security
- **Block `is_protected: false → true` promotion via Eloquent update.** `SuperAdminObserver::updating()` now throws `ProtectedAccountException::cannotProtect()` when a regular user's `is_protected` flag is flipped to `true` outside of `SuperAdmin::withoutProtection()`. Previously the observer only blocked the downgrade direction (`true → false`), so a consumer app that placed `is_protected` inside the User model's `$fillable` was vulnerable to mass-assignment privilege escalation — an attacker submitting `is_protected=true` on a profile-update payload could mark themselves as the protected super admin and become un-deletable.
  - **Impact**: every consumer app gains defense-in-depth without code changes. Existing callers that legitimately promote users to protected (custom admin tools, seeders) must wrap the update in `SuperAdmin::withoutProtection(fn () => $user->update(['is_protected' => true]))`. The package's own `install()` / `ensure()` flow already does this.
  - **Note**: `insert` (creating a new user with `is_protected=true` directly) is still allowed — only `update` is gated. Seeders and `User::factory()->create(['is_protected' => true])` continue to work.
  - Consumer apps are still encouraged to keep `is_protected` out of `$fillable` as belt-and-suspenders.

### Fixed
- **Observer no longer reads removed config keys.** `SuperAdminObserver` was still reading `superadmin.protection.block_delete`, `protection.block_email_change`, and `protection.block_flag_change` — keys that were removed from the published config file in 0.3.0 but never deleted from the observer code. The granular gates worked only by accident (each `config()` call defaulted to `true`). They are now gone; the single `protection.enabled` switch (gated in the service provider's `registerObserver()`) is the only lever, as documented.

### Tests
- New: "blocks flipping is_protected from false to true on a regular user", "withoutProtection allows promoting a regular user to protected", "creating a user with is_protected = true is allowed (insert, not update)".
- Removed: 3 tests that exercised the stale `protection.block_*` granular config keys.

## [0.3.0] - 2026-05-20

**Zero-friction redesign.** Composer require + `php artisan migrate` now produces a working protected super admin — no ceremony, no `--confirm`, no typed phrase, no vendor notifications. This is a breaking-change release; see "Upgrading from 0.2.x" in the README for the per-app migration steps.

### Added
- **Auto-install on `MigrationsEnded`.** The package listens to Laravel's `MigrationsEnded` event and creates the protected user once, if and only if no protected user exists. Gated by `superadmin.auto_install` (default `true`). Idempotent — re-running `migrate` is a no-op.
- **Default email derived from host config.** Three-tier resolution: `env('SUPER_ADMIN_EMAIL')` → `superadmin@<parse_url(app.url).host>` → `superadmin@<Str::slug(app.name)>.local`. No vendor domain is ever hard-coded.
- **Default password.** Literal `'superadmin'` (overridable via `SUPER_ADMIN_PASSWORD`). Deliberately memorable for local dev / internal use — README warns that production deployments must override.
- **`superadmin:setup` command.** Single interactive create/update command. Validates the email, writes `SUPER_ADMIN_EMAIL` + `SUPER_ADMIN_PASSWORD` to `.env`, and updates the DB row inside `withoutProtection()`. Supports `--email=` / `--password=` flags for non-interactive use, and a blank password to keep the existing one when only rotating the email.
- **`SuperAdmin::ensure(): Model`.** Idempotent get-or-create primitive for seeders. Returns the existing protected user untouched, or creates one with `defaultEmail()` + `defaultPassword()`. Replaces fragile seeder patterns that called `install()` and re-rotated the password on every reseed.
- **`SuperAdmin::defaultEmail()` and `SuperAdmin::defaultPassword()`** on the facade — exposed so callers can preview what auto-install will produce without firing the side effect.
- **`EnvWriter` helper.** Tiny idempotent `.env` writer that preserves line endings (CRLF/LF), surrounding comments, and blank lines; auto-quotes values containing whitespace, `#`, or quote characters.
- **`StatusCommand --verbose` flag.** Folds the old `doctor`'s full diagnostic matrix (email configured, model resolvable, column exists, protection enabled, user exists, flag=true, email match, authorization mode, role assigned) into a single command.

### Changed
- **`install($password = null, $email = null)` argument semantics.** Both arguments are now nullable with smart fallbacks: null email always uses `defaultEmail()`; null password uses `defaultPassword()` when creating a new user, but **keeps the existing password** when updating an existing one. Enables `setup` to rotate just the email without touching the password.
- **Config collapsed from 18 keys to 8.** New shape: `email`, `password`, `user_model`, `role`, `auto_install`, `authorization.gate_before`, `protection.enabled`, `filament.hide_destructive_actions`. Old `vendor_commands.*`, `notifications.*`, and individual `protection.block_*` keys are gone.
- **Authorization modes simplified from 4 to 2.** `gate_before` is the only switch — when `true` (default), the super admin authorizes for every ability; when `false`, you wire authorization yourself (typically via Shield). Role assignment is now unconditional best-effort (fires whenever the User model has `assignRole()` and a role is configured), so the old `assign_role` flag is redundant.
- **Auto-install output.** Prints `✓ Created protected super admin: {email} (password: {password})` to STDOUT on first migrate so the operator sees what happened.

### Removed (BREAKING)
- `superadmin:install` command — replaced by auto-install + `superadmin:setup`.
- `superadmin:reset` command — folded into `superadmin:setup`.
- `superadmin:assign-role` command — role assignment is automatic on every `install()` / `ensure()` / `setup` call.
- `superadmin:doctor` command — folded into `superadmin:status --verbose`.
- `VendorCommandFriction` trait, `VendorCommandInvoked` notification, the `--confirm` flag, typed-phrase prompt, and all vendor notification machinery.
- Config keys: `vendor_commands.*`, `notifications.*`, `log_channel`, `protection.block_delete`, `protection.block_email_change`, `protection.block_flag_change`, `authorization.assign_role`, `filament.enabled`, `filament.badge_label`.
- Env vars: `SUPER_ADMIN_NOTIFY`, `SUPER_ADMIN_NOTIFY_MAIL`, `SUPER_ADMIN_NOTIFY_SLACK`, `SUPER_ADMIN_VENDOR_PHRASE`, `SUPER_ADMIN_LOG_CHANNEL`, `SUPER_ADMIN_ASSIGN_ROLE`, `SUPER_ADMIN_PROTECTION`.
- `SuperAdmin::resetPassword()` method — use `install($password, $email)` directly.

### Tests
- 84 Pest tests, 149 assertions, all passing.
- New: `AutoInstallTest` (5 tests covering the `MigrationsEnded` hook), `SetupCommandTest` (4 tests), `EnvWriterTest` (10 unit tests).
- Updated: `SuperAdminManagerTest` adds coverage for `ensure()`, `defaultEmail()` three-tier resolution, `defaultPassword()`, and the null-password update path on `install()`.
- Removed: `InstallCommandTest`, `ResetCommandTest`, `AssignRoleCommandTest`, `DoctorCommandTest`, `AutoMigrationTest`.

### Migration notes
The 5 known consumers (`BuyMyProducts`, `wikiBankNotes`, `plugins-demo`, `asset-flow`, `task-off`) upgrade with:

```bash
composer update codenzia/laravel-superadmin
php artisan migrate    # auto-installs on first run; no-op afterwards
```

Then replace any seeder calls to `SuperAdmin::install(...)` with `SuperAdmin::ensure()`, and delete `.env` entries that are no longer recognized. See the README's "Upgrading from 0.2.x" section for the full table of removed keys.

## [0.2.0] - 2026-05-14

### Added
- Laravel 13 + Filament v5 support. Widened `composer.json` constraints to `illuminate/* ^11.0|^12.0|^13.0` and `filament/filament ^4.0|^5.0`. Pest constraint widened to `^3.0|^4.0`.

## [0.1.3] - 2026-05-12

### Fixed
- `install()` and `resetPassword()` now auto-assign the configured role at the end of the manager flow, so existing protected users get the role re-applied on every reset (matters when the role is rotated, or when the project's role seeder ran after the initial install).

## [0.1.2] - 2026-05-12

### Fixed
- `superadmin:doctor` was under-reporting in cases where the protected user existed but `is_protected = false` on the row. Now flags this state explicitly.

### Documentation
- README: clarified that `SUPER_ADMIN_EMAIL` should be set in `.env` even when the seeder creates the protected user — multiple commands depend on it.

## [0.1.1] - 2026-05-12

### Documentation
- README + composer metadata refresh for first public release.

## [0.1.0] - 2026-05-12

Initial release.

[Unreleased]: https://github.com/Codenzia/laravel-superadmin/compare/v0.3.2...HEAD
[0.3.2]: https://github.com/Codenzia/laravel-superadmin/compare/v0.3.1...v0.3.2
[0.3.1]: https://github.com/Codenzia/laravel-superadmin/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/Codenzia/laravel-superadmin/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/Codenzia/laravel-superadmin/compare/v0.1.3...v0.2.0
[0.1.3]: https://github.com/Codenzia/laravel-superadmin/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/Codenzia/laravel-superadmin/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/Codenzia/laravel-superadmin/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/Codenzia/laravel-superadmin/releases/tag/v0.1.0
