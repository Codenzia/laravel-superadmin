# Changelog

All notable changes to `codenzia/laravel-superadmin` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/Codenzia/laravel-superadmin/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/Codenzia/laravel-superadmin/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/Codenzia/laravel-superadmin/compare/v0.1.3...v0.2.0
[0.1.3]: https://github.com/Codenzia/laravel-superadmin/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/Codenzia/laravel-superadmin/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/Codenzia/laravel-superadmin/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/Codenzia/laravel-superadmin/releases/tag/v0.1.0
