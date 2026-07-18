# Full Code Review — codenzia/laravel-superadmin

- **Date:** 2026-07-06
- **Reviewer:** Claude Fable 5
- **Version reviewed:** `dev-main` (branch-alias `0.5.x-dev`; last tag `v0.5.2` + 16 commits, working tree at `22f4ee3`)
- **Scope:** every file in `composer.json`, `config/`, `src/`, `database/`, `resources/views/`, `tests/`, `README.md`, `CHANGELOG.md`. Read-only review; no code was modified, no tests were run.

---

## Executive Summary

This is a small (13 src files), well-written, security-conscious package that has clearly been through prior hardening rounds (the Unreleased CHANGELOG section documents six security fixes). Identity is correctly keyed on the `is_protected` column (never email), seeders carry no credentials, the recovery route is off by default, plaintext passwords are never echoed unless hash-verified, and the observer blocks delete / email-change / unprotect / promote in both directions. Code style is exemplary: `declare(strict_types=1)` everywhere, full return types, `final` classes, honest PHPDoc.

However, the review found **one CRITICAL defect**: the flagship `prevent_role_promotion` protection listens for `eloquent.pivotAttaching: *` — **an event that core Laravel never dispatches** (verified: zero occurrences of `pivotAttaching` anywhere in `vendor/laravel/framework/src/Illuminate/Database/`; `BelongsToMany::attach()` is a raw `newPivotStatement()->insert()` with no event). Spatie's `assignRole()` uses that core path. The guard is therefore **inert in every production app**, and its 6 tests pass only because they dispatch the event by hand — a textbook self-confirming test suite. On Shield hosts with `define_via_gate = true`, holding the `super_admin` role is god mode, so the escalation this guard advertises to block is in fact unblocked.

Two HIGH findings follow: the observer deliberately allows **password changes on the protected row** (account-takeover path that the Filament name-allowlist only papers over), and the **documented config-extension pattern silently deletes all default Filament protections** in any app that follows it.

Everything else is MEDIUM/LOW polish. With the CRITICAL fixed, this package would grade A−.

### Health grade: **B−**

Excellent engineering hygiene and threat modeling, dragged down by one advertised security control that does not function at all and a test suite that verifies the mock instead of the mechanism.

### Top 5 issues

| # | ID | Severity | Summary |
|---|----|----------|---------|
| 1 | SUP-001 | CRITICAL | Role-promotion guard listens for `eloquent.pivotAttaching`, which core Laravel/Spatie never fire — the protection is dead code in production; tests self-confirm by dispatching the event manually |
| 2 | SUP-002 | HIGH | Observer allows `password` updates on the protected row — any admin/code path that can update users can take over the god account |
| 3 | SUP-003 | HIGH | README/docblock config-extension pattern (`...config('superadmin.filament.hidden_action_names')` inside the published config file) evaluates to `[]` at config-load time and silently drops every default protection |
| 4 | SUP-004 | MEDIUM | `Gate::before` closure typed `?Model $user` → `TypeError` (HTTP 500 on every authz check) for hosts whose Authenticatable is not an Eloquent model |
| 5 | SUP-005 | MEDIUM | `SuperAdminManager::user()` honors host global scopes (unlike `findByEmail()`), so a scoped/soft-deleted protected row silently disables recovery and auto-install repair |

---

## Findings by Severity

### CRITICAL

#### SUP-001 — `prevent_role_promotion` guard never fires: it listens for an event the framework does not dispatch

- **Files:**
  - `src/SuperAdminServiceProvider.php:285` — `Event::listen('eloquent.pivotAttaching: *', ...)`
  - `tests/Feature/RolePromotionGuardTest.php:24-27` — `firePivotAttaching()` dispatches the event manually
  - Config promise: `config/superadmin.php:171-177`; README lines 217, 383; CHANGELOG 0.5.0 "prevent_role_promotion protection"
- **Description:** Core Laravel has never fired `pivotAttaching`/`pivotAttached` events — that vocabulary comes from the third-party `chelout/laravel-relationship-events` package. Verified against this repo's own vendored framework: `grep -r pivotAttaching vendor/laravel/framework/src/Illuminate/Database/` → zero hits; `InteractsWithPivotTable::attach()` (line 337) inserts via `newPivotStatement()->insert(...)` with no model/event hook. Spatie's `HasRoles::assignRole()` / `syncRoles()` go through exactly that path. Consequently the guard's closure never executes in a real host app.
- **Why it matters:** This is the package's only defense against the #1 privilege-escalation path on the Codenzia fleet: on Filament Shield hosts (`super_admin.define_via_gate = true`), any user holding the `super_admin` role passes every gate. The package config, README, and CHANGELOG all advertise this protection as active by default. It is not.
- **Failure scenario:** A tenant admin with access to any role-assignment UI (Shield role page, a UsersRelationManager, a compromised admin session, or plain `$user->assignRole('super_admin')` in app code) grants themselves `super_admin`. No exception is thrown. `SuperAdmin::isSuperAdmin()` now returns true for them; on Shield hosts they authorize everything. The 6 passing tests in `RolePromotionGuardTest.php` provide false assurance because they simulate the event contract rather than exercising `assignRole()`.
- **Note:** even if the event existed, the guard has a second hole: it only inspects `relation === 'roles'` (user-side attach). Role-side attachment (`$role->users()->attach($userId)`, relation `'users'` on the Role model) would bypass it. Fix both when re-implementing.

### HIGH

#### SUP-002 — Observer does not guard `password` on the protected row: account takeover via any user-update path

- **Files:**
  - `src/Observers/SuperAdminObserver.php:35-61` — `updating()` guards `email` and `is_protected` only
  - `tests/Feature/ObserverTest.php:51-59` — explicitly asserts password changes are *allowed*
  - `src/Filament/SuperAdminPlugin.php:139-154` + `config/superadmin.php:239-249` — the only password guard is a name-based UX allowlist
- **Description:** The only thing stopping an admin from setting a new password on the protected super admin is the Filament plugin's `locked_field_names` list — which (a) only applies in Filament, (b) only matches fields literally named `password`/`password_confirmation`, (c) is bypassed by any custom action, Livewire component, API endpoint, or a field named `new_password`, and (d) per the package's own README caveat (line 355) is overridden by any later `->disabled(false)` chain. At the model layer, `$protectedUser->update(['password' => Hash::make('mine')])` succeeds from anywhere.
- **Why it matters:** Changing the protected account's password *is* deleting the vendor's control of it — strictly worse than the email change the observer does block, because the attacker then logs in as the account that passes `Gate::before` on every ability.
- **Failure scenario:** Customer-app admin opens any non-Filament user-edit surface (or an app whose form field is named `new_password`), sets a password on the superadmin row, logs in as it. All four "protection layers" remain green in `superadmin:status -v`.
- **Fix direction:** block `isDirty('password')` (and `remember_token`) on the protected row in `updating()` unless `isProtectionBypassed()`; wrap the two legitimate writers (`RecoveryController::update()` at `RecoveryController.php:116-119`, and the protected user's own profile-change flow — see execution plan for the self-service carve-out) in `withoutProtection()`.

#### SUP-003 — Documented config-extension pattern silently drops all default Filament protections

- **Files:**
  - `README.md:341-353` and `src/Filament/SuperAdminPlugin.php:47-54` — both tell apps to write, inside their *published* `config/superadmin.php`:
    ```php
    'hidden_action_names' => [
        ...config('superadmin.filament.hidden_action_names'),
        'my_app_specific_destructive_action',
    ],
    ```
- **Description:** Two compounding problems. (1) When Laravel's `LoadConfiguration` bootstrapper evaluates the published `config/superadmin.php`, the `superadmin` key is not yet in the repository (it is being produced by this very file) and `mergeConfigFrom()` has not run (it runs later, in the provider's `register()`), so `config('superadmin.filament.hidden_action_names')` returns `null` → the spread yields nothing. (2) Even ignoring load order, `mergeConfigFrom()` merges **top-level keys only** — a published `filament` key wholesale replaces the package's nested defaults. Net result: `hidden_action_names === ['my_app_specific_destructive_action']`; the eleven default destructive verbs (`delete`, `impersonate`, `ban`, …) are no longer hidden, and if the same pattern is used for `locked_field_names`, the `password`/`roles`/`is_protected` field locks vanish too.
- **Why it matters:** The pattern is the package's own recommended way to extend protection; following the docs *reduces* protection, silently, fleet-wide.
- **Failure scenario:** An app copies the README snippet to hide one custom action; from that deploy on, `DeleteAction` and the password field are live again on the protected row in that app, and nothing warns anyone.
- **Fix direction:** replace the documented pattern with fluent plugin API (`SuperAdminPlugin::make()->hideActions('x')->lockFields('y')` merging at runtime) and/or "list the full array" guidance; optionally warn from `superadmin:status -v` when the configured lists are missing package defaults.

### MEDIUM

#### SUP-004 — `Gate::before` closure fatals on non-Eloquent authenticatables

- **File:** `src/SuperAdminServiceProvider.php:108` — `Gate::before(function (?Model $user, string $ability): ?bool { ... })`
- **Description:** `Gate::callBeforeCallbacks()` invokes every before-callback with the resolved user. If a host uses a non-Eloquent `Authenticatable` (custom user provider, JWT-backed GenericUser, etc.), PHP throws `TypeError: Argument #1 ($user) must be of type ?Model` on **every** authorization check, i.e. a 500 on effectively every request.
- **Why it matters:** A fleet-wide zero-config package must degrade, not fatal, on non-standard hosts. All Codenzia apps use Eloquent users today, but this is a published Packagist package.
- **Failure scenario:** Any host with `Illuminate\Auth\GenericUser` (array/database provider) installs the package → entire app 500s post-login.
- **Fix:** accept `mixed`/untyped and `instanceof Model` check inside (return `null` otherwise).

#### SUP-005 — `user()` honors host global scopes; `findByEmail()` doesn't — inconsistent identity resolution can silently kill recovery

- **Files:** `src/Support/SuperAdminManager.php:235` (`$model::query()->where('is_protected', true)...` — scoped) vs `:365-368` (`->withoutGlobalScopes()` — deliberately unscoped, with a comment explaining why provisioning must ignore scopes)
- **Description:** The same reasoning in `findByEmail()`'s comment ("a scope like 'approved only' must not hide the row install() needs") applies with more force to `user()`, which is the identity primitive for the recovery flow, auto-install existence check, late role assignment, and `superadmin:status`. A host with SoftDeletes (a trashed protected row — reachable via `withoutProtection(fn () => $user->delete())`) or an `active`/`approved` global scope makes `user()` return `null`.
- **Why it matters / failure scenario:** With a scoped-out protected row: the recovery route silently emails nothing forever ("If the super admin account exists…" — it does exist); `exists()` returns false so auto-install tries to insert a **second** protected row (unique-email collision → `findByEmail` claims the old row — partially self-healing, but only on the next migrate); `Gate::before` still works (it reads the model attribute, not `user()`), so the system looks healthy while break-glass is dead.
- **Fix:** add `->withoutGlobalScopes()` to `user()` (matching `findByEmail()`), and consider `superadmin:status -v` surfacing "row exists but hidden by global scopes".

#### SUP-006 — Package migration can be permanently skipped: no-op is recorded as "run"

- **File:** `database/migrations/2025_01_01_000000_add_is_protected_to_users_table.php:13-15` — `if (! Schema::hasTable('users')) { return; }`
- **Description:** The migration is dated `2025_01_01_000000`. Laravel's default users migration (`0001_01_01_000000_create_users_table`) sorts before it, so the common case works. But any host whose users table is created by a migration dated **after** 2025-01-01 (rebuilt apps, module-provided user tables, squashed schemas re-expanded) runs this migration first; it silently no-ops **and is recorded in the `migrations` table as executed** — it will never run again. The auto-install listener then no-ops forever too (`SuperAdminServiceProvider.php:155` returns when the column is missing, with a comment incorrectly promising "the next migrate … will have the column").
- **Why it matters:** Zero-touch install is the package's core promise; on affected hosts nothing is created, nothing errors, and only `superadmin:status -v` (which the operator has no reason to run — everything "worked") reveals the gap.
- **Failure scenario:** New app generates `2026_07_01_000000_create_users_table`, requires the package, `migrate` → green output, no super admin, no `is_protected` column, ever.
- **Fix:** self-heal in the `MigrationsEnded` listener (if users table exists and column missing → `Schema::table()` add it, mirroring the migration), or at minimum fix the misleading comment and make status non-verbose warn.

#### SUP-007 — Long-running-process staleness in two memoizations (Octane / queue workers)

- **Files:** `src/Support/SuperAdminManager.php:245` and `src/SuperAdminServiceProvider.php:283, 313-315`
- **Description:** (a) `hasProtectedColumn ??= Schema::hasColumn(...)` memoizes **false** as readily as true. The manager is a singleton; under Octane/queue workers a process booted before the column existed answers "no column" for its entire lifetime → `user()` returns null → recovery/status/exists() dead until worker restart (fail-safe direction, but confusing). The comment says "request-scoped singleton", which is only true for PHP-FPM. (b) The role-promotion guard memoizes the resolved role **id** in the closure for the process lifetime; if the role row is deleted and re-created with a new id (a `migrate:fresh --seed` while workers run, or admin role management), the guard checks a stale id and would wave the new-id role through. (Currently moot given SUP-001, but must be fixed together with it.)
- **Fix:** memoize only positive results for (a); for (b) drop the memo entirely — pivot attaches are rare, one indexed query per attach is fine (Simplicity First).

### LOW

#### SUP-008 — Recovery `send()` has a timing/latency side channel and blocks on synchronous SMTP

- **File:** `src/Http/Controllers/RecoveryController.php:54-64`; `src/Notifications/RecoveryLinkNotification.php:15` (does not implement `ShouldQueue`)
- **Description:** The "responds identically whether or not the account exists" guarantee holds for the response body, not the clock: a real send does token-create (bcrypt) + full SMTP round-trip inline; the no-account path returns in milliseconds. Also, a slow/down SMTP host makes the public endpoint hang for its timeout. Existence of the superadmin account is a weak secret (it nearly always exists), hence LOW — but queueing the notification (when a queue is configured) fixes both cheaply.

#### SUP-009 — Throttle fallback constants disagree with config defaults

- **File:** `src/Http/Controllers/RecoveryController.php:169` — `$config['max_attempts'] ?? 3` vs `config/superadmin.php:106` (`5`) and README line 104 ("5/hour").
- **Description:** Dead-in-practice (mergeConfigFrom guarantees the key), but if a host publishes a stripped config the behavior silently drops to 3 while all docs say 5. Align the fallback to 5.

#### SUP-010 — `whereRaw` in `findByEmail()` deviates from the "NO Raw SQL" standard

- **File:** `src/Support/SuperAdminManager.php:365-368`
- **Description:** `->whereRaw('LOWER('.$grammar->wrap('email').') = ?', [...])` is parameterized and grammar-wrapped — safe — but it is still raw SQL in a fleet governed by a "NO Raw SQL" rule. Since emails are lowercased on every write path (`install()` at `:311`, `EnsureCommand` at `:69`), a plain `where('email', mb_strtolower($email))` covers all package-written rows; the LOWER() only helps claim pre-package mixed-case rows. Keep if that repair path is valued, but document the exception; otherwise simplify.

#### SUP-011 — `--password=` CLI option and prompt echo expose plaintext in shell history/terminal

- **File:** `src/Console/Commands/EnsureCommand.php:34, 79`
- **Description:** `superadmin:ensure --password='secret'` lands in shell history and `ps` output (README line 81 even demonstrates it). Additionally the interactive prompt for a brand-new account displays `knownDefaultPassword()` inline — which includes an env-configured `SUPER_ADMIN_PASSWORD` value — echoing a real credential to the terminal/scrollback (`:79`). Both are operator-facing conveniences; at minimum prefer prompting in docs and mask the env-sourced default as `(from SUPER_ADMIN_PASSWORD)` like auto-install output already does (`SuperAdminServiceProvider.php:183`).

#### SUP-012 — `superadmin:status` prints the working password to stdout unconditionally

- **File:** `src/Console/Commands/StatusCommand.php:77-86`
- **Description:** The hash-verified design is genuinely good (never stale, never random). But `status` is exactly the command people wire into CI/deploy checks (the class docblock suggests it), and on a live-demo host with `SUPER_ADMIN_PASSWORD` set, every pipeline run logs the real credential. Consider hiding the value behind `--show-password` (or only showing it in interactive TTYs).

#### SUP-013 — Facade docblock is missing three methods

- **File:** `src/Facades/SuperAdmin.php:12-28`
- **Description:** `configuredPassword()`, `knownDefaultPassword()`, and `verifiedKnownPassword()` (all public on the manager, the latter advertised in CHANGELOG 0.5.2) are absent from the `@method` list → no IDE completion/static-analysis via the facade.

#### SUP-014 — README drift

- **File:** `README.md`
- **Description:** (a) Line 454: "105 Pest tests, 173 assertions" is stale (16 test files now, incl. recovery/role-guard suites) and claims coverage of "the env writer" — removed in 0.4.0. (b) The Configuration sample at lines 233-236 omits `password`/`password_confirmation` from `locked_field_names`, contradicting the actual config (`config/superadmin.php:247-248`) and the README's own section 3 (line 337). (c) Line 383 advertises the role-promotion guard as functioning (see SUP-001).

#### SUP-015 — `composer.json` omits illuminate components the package actually uses

- **File:** `composer.json:23-30`
- **Description:** The code uses `Illuminate\Foundation\Http\FormRequest` (`UpdateRecoveryPasswordRequest.php:7`), routing/HTTP (`RecoveryController`), views, validation rules, the Password broker (`illuminate/auth`), and `RateLimiter` (`illuminate/cache`) — none of which are required. Harmless inside a full Laravel app (the only realistic consumer), but the declared component list is not honest; either add the components or (simpler) require `laravel/framework`-provided components implicitly by documenting "full Laravel apps only".

#### SUP-016 — `SUPER_ADMIN_RECOVERY_PATH` is unvalidated

- **File:** `src/SuperAdminServiceProvider.php:76-87`
- **Description:** Only leading/trailing slashes are trimmed. A value containing `{token}`-style segments, regex metacharacters, or an internal path collision (e.g. a Filament panel path, or `analytics` — cf. the fleet's known `$routePath` collision issue) produces confusing route behavior with no warning. Validate to a safe slug pattern or warn in `status -v`.

---

## Dead Code & Simplification

1. **`SuperAdminManager::email()` and `isConfigured()`** (`SuperAdminManager.php:32-34, 179-182`) — self-described inert BC stubs (always `null` / always `true`). `StatusCommand.php:31` still consumes `email()` in a `?:` whose left side can never win — a dead branch. Plan removal at the next minor after a fleet-wide caller grep.
2. **`configuredRole()` can never return null** (`SuperAdminManager.php:409-418` — hardcoded `'super_admin'` fallback) yet is typed `?string`, producing four dead null-checks: `SuperAdminServiceProvider.php:238-241, 307-311`, `SuperAdminManager.php:428-430, 456-459`, and the unreachable `RoleAssignmentResult::NotConfigured` case. Tighten to `string` and delete the branches.
3. **`RoleAssignmentResult::describe()` / `isProblem()`** (`RoleAssignmentResult.php:15-29`) — zero callers in `src/` or `tests/`. Either use `describe()` in `superadmin:status -v` role diagnostics (nice) or delete.
4. **`StatusCommand::handle()` double-fetch** (`StatusCommand.php:27-28`) — `exists()` internally calls `user()`, then `user()` is called again: two identical queries. Fetch once: `$user = $manager->user(); $exists = $user !== null;`.
5. **Role-id memo in the promotion guard** (`SuperAdminServiceProvider.php:283, 313-315`) — premature optimization with a staleness bug (SUP-007b). Delete when reworking SUP-001.

## Performance

Overall exemplary for the hot path: `Gate::before` resolves the singleton and reads a model attribute — **no query per authorization check** (`SuperAdminServiceProvider.php:108-118`, `SuperAdminManager.php:195-202`). Remaining notes:

- The **`eloquent.created: *` wildcard listener** (`SuperAdminServiceProvider.php:221`) executes on every model creation app-wide, forever, even after the role has long been assigned. Per-event cost is small (config read + `class_exists` + `instanceof`), but on a role-name match it runs two queries. Acceptable; optionally short-circuit permanently once `AlreadyAssigned` is observed in-process.
- `Schema::hasColumn` memoization is right in intent; fix the false-caching direction (SUP-007a).
- Recovery endpoints run bcrypt token checks — correctly rate-limited (including the GET, per CHANGELOG). Good.

## Test Quality & Gaps

**Strengths:** 16 focused Pest files; observer matrix covers all four guard directions including create-time promotion; recovery suite covers happy path, identical no-account response, per-IP throttle on POST *and* GET, invalid token, single-use token, DB-session eviction, short password; `PasswordResolutionTest`/`SuperAdminManagerTest` cover env/environment password semantics; a dedicated regression test for the SEC-01 null-memo bug; clever `captureStdout` stream filter for the auto-install CLI output.

**Gaps (ordered by importance):**

1. **The role-promotion guard tests test the mock, not the mechanism** (`RolePromotionGuardTest.php:24-27`). `spatie/laravel-permission` is absent from `require-dev`, so `firePivotAttaching()` hand-dispatches an event production never fires — this is precisely how SUP-001 stayed invisible. Add Spatie as a dev dependency and drive a real `->assignRole('super_admin')` end-to-end; that test fails today and becomes the acceptance test for the fix.
2. **No test that recovery routes are absent when disabled** — `TestCase.php:66` forces `recovery.enabled = true` suite-wide, so the "disabled by default" security property is untested (assert 404 + `Route::has(...) === false`).
3. **No global-throttle test** (`global_max_attempts` across distinct IPs) and no custom-`path` registration test.
4. **No lockout-recovery interplay test:** attacker exhausts the global limit, then legitimate `superadmin:ensure` still works — the documented tradeoff at `config/superadmin.php:98-104` is unverified.
5. **No Gate::before non-Model-user test** (SUP-004) and no `Gate::before` test for `gate_before` toggling mid-request (the closure claims per-request toggle support at `SuperAdminServiceProvider.php:105-107`).
6. **No global-scope/soft-delete host fixture** exercising `user()` (SUP-005) — there are fixtures for guarded and uncast models, so the pattern exists.
7. **No test of the documented config-extension pattern** (would have caught SUP-003).

## Added-Value Roadmap (pragmatic, in priority order)

1. **Superadmin audit log** — a `superadmin_audit` table (or hook into the fleet's audit layer in `platform-core`) recording: recovery link requested/used (IP, UA), password rotated via `ensure`, protected-row login, `withoutProtection()` invocations. Today the only trail is `Log::warning` lines that rotate away.
2. **Login alert for the protected account** — notification to `SUPER_ADMIN_EMAIL` on every successful login as the protected user (IP, UA, host), via a `Login` event listener. Cheap, high-signal: the account should almost never log in, so any login is worth an email.
3. **Break-glass hardening** — optional "reason" free-text on the recovery send form (stored in the audit log), plus a config knob to auto-expire the break-glass password (force re-rotation after N hours) so an emergency credential can't quietly become the permanent one.
4. **Fleet health surfacing** — expose `superadmin:status -v`'s checks as a small class API so Apptenza's ConventionChecker can consume them (protected row exists, flag true, role attached, recovery reachable, no non-protected `super_admin` holders — the last one doubles as post-hoc detection for SUP-001-class escalations).
5. **Session eviction beyond the database driver** — for redis/file session hosts, document (or ship) an `AuthenticateSession` push, or store a `password_changed_at` check in the package's own middleware so a break-glass reset always evicts live sessions.

---

## Execution Plan for Claude Opus 4.8

Self-contained; each phase lists exact files, the change, and verification. **Environment: Windows + Herd — run all PHP through PowerShell, never Bash** (e.g. `php vendor/bin/pest`, `vendor/bin/pint --dirty` via the PowerShell tool). Repo: `C:\mh2\Projects\Codenzia\GitHub\laravel-superadmin`. Work on `main` per fleet convention; commit per phase.

### Phase 0 — Red test proving SUP-001 (P0, do first; everything in Phase 1 depends on it)

1. `composer require --dev "spatie/laravel-permission:^6.0"` (PowerShell).
2. New test `tests/Feature/RolePromotionRealSpatieTest.php`: register `Spatie\Permission\PermissionServiceProvider` (via `getPackageProviders` in a local TestCase override or `$this->app->register(...)`), run Spatie's migrations (`loadMigrationsFrom(vendor/spatie/laravel-permission/database/migrations)` — or publish stubs), create role `super_admin`, create a non-protected user with `HasRoles`, then `expect(fn () => $user->assignRole('super_admin'))->toThrow(ProtectedAccountException::class)`.
3. Verification: run `php vendor/bin/pest --filter=RolePromotionRealSpatie` via PowerShell — it MUST FAIL (role is assigned, no exception). That failure is the proof; keep the test.

### Phase 1 — Fix SUP-001: real role-promotion enforcement (P0)

Files: `src/SuperAdminServiceProvider.php`, `config/superadmin.php`, `README.md`, `CHANGELOG.md`, `tests/Feature/RolePromotionGuardTest.php`.

1. In `registerRolePromotionGuard()` add a **Spatie-native listener**: when `class_exists(\Spatie\Permission\Events\RoleAttached::class)`, force-enable Spatie events for this feature (`$this->app['config']->set('permission.events_enabled', true);` — document this side effect in the config docblock) and listen:
   ```php
   Event::listen(\Spatie\Permission\Events\RoleAttached::class, function ($event) use ($manager): void {
       if ($manager->isProtectionBypassed()) return;
       $role = /* resolve role name from $event->rolesOrIds via the role model */;
       if ($role !== $manager->configuredRole()) return;
       $model = $event->model;
       if ((bool) $model->getAttribute('is_protected')) return;
       // post-write event: revert, then throw
       $model->roles()->detach(/* super_admin role id */);
       if (method_exists($model, 'forgetCachedPermissions') || class_exists(\Spatie\Permission\PermissionRegistrar::class)) {
           app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
       }
       throw ProtectedAccountException::cannotAssignSuperAdminRole();
   });
   ```
   Check Spatie v6's actual `RoleAttached` payload shape (`$event->model`, `$event->rolesOrIds`) against the vendored source before coding — adjust accordingly.
2. Keep the existing `eloquent.pivotAttaching` listener (it is a valid pre-write layer for hosts running `chelout/laravel-relationship-events`), but: (a) also match role-side attaches — when `payload[0]` is the Role model, relation is `users`, and the role is the configured one, block attaching ids other than the protected user's key; (b) drop the `$superAdminRoleId` closure memo (SUP-007b) and query per event.
3. Rewrite the honesty of docs: `config/superadmin.php:171-177` docblock, `README.md` line 217/383 — state the mechanism (Spatie `RoleAttached` revert-and-throw; pre-write only with relationship-events) and the residual gap (raw `DB::table('model_has_roles')->insert` is not covered).
4. Add a **detection backstop** in `StatusCommand::runDiagnostics()`: query holders of the configured role that are not the protected row (via the role model's `users` relation — Eloquent, no raw SQL); list them as a ✗ problem.
5. Verification (PowerShell): `php vendor/bin/pest` — Phase 0 test now green; full suite green; `vendor/bin/pint --dirty`.

### Phase 2 — Fix SUP-002: guard the protected row's password (P0)

Files: `src/Observers/SuperAdminObserver.php`, `src/Http/Controllers/RecoveryController.php`, `src/Exceptions/ProtectedAccountException.php`, `tests/Feature/ObserverTest.php`, `tests/Feature/RecoveryRouteTest.php`, `README.md`, `CHANGELOG.md`.

1. **Order matters:** wrap the legitimate writer FIRST. In `RecoveryController::update()` (lines 116-121) wrap the `forceFill(...)->save()` + repository delete in `app(SuperAdminManager::class)->withoutProtection(fn () => ...)`.
2. Add to `SuperAdminObserver::updating()`:
   ```php
   if (($user->isDirty('password') || $user->isDirty('remember_token'))
       && (bool) $user->getOriginal('is_protected')) {
       $actor = auth()->user();
       $isSelf = $actor !== null && $actor->getKey() === $user->getKey()
           && $actor::class === $user::class;
       if (! $isSelf) {
           throw ProtectedAccountException::cannotChangePassword();
       }
   }
   ```
   The self-service carve-out keeps the superadmin's own profile page working; CLI (`superadmin:ensure`) is unaffected because `install()` already runs inside `withoutProtection()`. Note: Laravel login writes `remember_token` via the user provider's `updateRememberToken` (an Eloquent save) — the `$isSelf` check covers "remember me" at login since the actor is being authenticated; verify with a feature test that `Auth::login($protected, remember: true)` still works, and if it fires before `auth()->user()` is set, restrict the new guard to `password` only (still the main win) and note `remember_token` as accepted residual risk.
3. Add `ProtectedAccountException::cannotChangePassword()`.
4. Tests: flip `ObserverTest.php:51-59` ("allows password changes") to assert the throw for a non-self actor; add: self-change allowed; recovery flow still green (existing `RecoveryRouteTest` covers this once step 1 is in); `superadmin:ensure --password=x` still green.
5. Docs: README "How protection works" table — add password to the observer row; CHANGELOG Security entry.
6. Verification: `php vendor/bin/pest` via PowerShell — full suite green.

### Phase 3 — Fix SUP-003: safe extension API + doc correction (P1)

Files: `src/Filament/SuperAdminPlugin.php`, `README.md`.

1. Add fluent, runtime-merged extension points on the plugin:
   ```php
   private array $extraHiddenActions = [];
   private array $extraLockedFields = [];
   public function hideActions(string ...$names): self { $this->extraHiddenActions = [...]; return $this; }
   public function lockFields(string ...$names): self { ... }
   ```
   In `configureNamedDestructiveActions()` / `configureLockedFormFields()` use `array_merge((array) config(...), $this->extraHiddenActions)`.
2. Replace the docblock example (`SuperAdminPlugin.php:47-54`) and README lines 341-353 with the fluent API; where config-file editing is still shown, instruct listing the **full** array (copy defaults + additions) and state plainly that spreading `config(...)` inside a config file does not work.
3. Also fix README drift while in the file (SUP-014): locked-field sample at lines 233-236 add `password`, `password_confirmation`; update/remove the stale "105 Pest tests… env writer" paragraph (line 454).
4. Tests: extend `tests/Feature/FilamentAutoLockTest.php` — a plugin configured with `->hideActions('custom')` hides both `custom` AND the default `delete`.
5. Verification: `php vendor/bin/pest` via PowerShell.

### Phase 4 — MEDIUM correctness batch (P1)

1. **SUP-004** — `src/SuperAdminServiceProvider.php:108`: change closure signature to `function ($user, string $ability): ?bool` and early-return `null` unless `$user instanceof Model`. Test: call `Gate::forUser(new \Illuminate\Auth\GenericUser(['id' => 1]))->check('anything')` — must not throw.
2. **SUP-005** — `src/Support/SuperAdminManager.php:235`: add `->withoutGlobalScopes()` to the `user()` query. Test: fixture user model with a global scope hiding the protected row → `user()` still finds it; recovery send still emails.
3. **SUP-006** — in `registerAutoInstall()` (`SuperAdminServiceProvider.php:152-160`): when the users table exists but the column is missing, self-heal — `Schema::table($table, fn ($t) => $t->boolean('is_protected')->default(false)->index());` inside the existing try/catch — then proceed. Fix the stale comment. Test: drop the column in-test, fire `MigrationsEnded` with `auto_install` on, assert column + protected user exist.
4. **SUP-007a** — `SuperAdminManager.php:243-246`: memoize only positive results:
   ```php
   if ($this->hasProtectedColumn !== true) {
       $this->hasProtectedColumn = Schema::hasColumn($table, 'is_protected');
   }
   return $this->hasProtectedColumn;
   ```
   Fix the "request-scoped" comment (singleton lives per process, not per request, under Octane).
5. Verification: `php vendor/bin/pest` via PowerShell; `vendor/bin/pint --dirty`.

### Phase 5 — LOW batch (P2)

1. **SUP-009** — `RecoveryController.php:169`: `?? 3` → `?? 5`.
2. **SUP-008** — `RecoveryLinkNotification`: implement `ShouldQueue` (with `use Queueable;`); sync-queue hosts are unaffected. Keep the try/catch in `send()`.
3. **SUP-011** — `EnsureCommand.php:79`: when `configuredPassword() !== null`, prompt text says `(leave blank to use SUPER_ADMIN_PASSWORD)` instead of echoing the value; README line 81: prefer prompt over `--password=` and add a shell-history warning.
4. **SUP-012** — `StatusCommand`: add `--show-password` flag; without it print `set (verified — pass --show-password to display)` when `verifiedKnownPassword() !== null`. Update README command table. Check fleet callers first (apps may parse status output) — if any do, keep default behavior and only add the flag inverse (`--hide-password`) instead.
5. **SUP-013** — add the three missing `@method` lines to `src/Facades/SuperAdmin.php`.
6. **SUP-016** — `registerRecoveryRoutes()`: validate `$path` with `preg_match('/^[a-zA-Z0-9\-_\/]+$/', $path)` (reject `{`/`}`); log a warning and skip registration on failure.
7. **SUP-015** — add `illuminate/auth`, `illuminate/cache`, `illuminate/http`, `illuminate/routing`, `illuminate/validation`, `illuminate/view` to `require` (match `^12.0 || ^13.0`). Run `composer update --lock` via PowerShell.
8. Dead code: remove `configuredRole()`'s `?string` nullability + the four dead null-checks + `RoleAssignmentResult::NotConfigured`; fix `StatusCommand` double `user()` fetch and the dead `email() ?:` branch. Do **NOT** remove the `email()` / `isConfigured()` facade stubs yet — grep the fleet (`C:\mh2\Projects\Codenzia\GitHub\*`) for callers first; if zero, remove in a separate minor-version commit with a CHANGELOG "Removed" entry.
9. Verification: `php vendor/bin/pest` via PowerShell; `vendor/bin/pint --dirty`.

### Phase 6 — Test-gap closure (P2)

New/extended tests (all Pest, in existing files where natural):

1. Recovery disabled by default: fresh app boot with `superadmin.recovery.enabled = false` (use `defineEnvironment` override or a separate TestCase) → `GET /superadmin` is 404 and `Route::has('superadmin.recovery.show')` is false.
2. Global throttle: hit from two fake IPs (`$this->post('/superadmin', [], ['REMOTE_ADDR' => ...])` or `withServerVariables`) until `global_max_attempts`; assert the 11th request errors regardless of IP.
3. Custom path: set `superadmin.recovery.path = 'break-glass'` before boot; assert routes register there.
4. `gate_before` per-request toggle: flip config mid-test, assert `Gate::allows()` flips without re-boot.
5. Update README test-count paragraph once counts settle.
6. Verification: `php vendor/bin/pest` via PowerShell — full suite green; finish with `vendor/bin/pint --dirty`.

### Ordering / dependencies

- Phase 0 → Phase 1 (red test first). Phase 2 step 1 (wrap recovery writer) MUST precede step 2 (observer guard) or the recovery suite breaks mid-phase. Phases 3-6 are independent of each other and of 1-2; run in numeric order for clean commits.
- P0 = Phases 0-2. P1 = Phases 3-4. P2 = Phases 5-6.

### Do NOT do

- **Never weaken the no-creds-in-seeders invariant** — do not add credential parameters back to seeder guidance, do not make `ensure()` print passwords, do not touch the "apps print NOTHING about the super admin" contract.
- **Do not re-enable the recovery route by default**, and do not remove its rate limiting or the identical-response behavior on `send()`.
- **Do not reintroduce any hardcoded vendor email** (`superadmin@codenzia.com` was deliberately removed) or any email-based identity path — identity stays `is_protected`-only.
- **Do not log or echo plaintext passwords anywhere new**; the only display primitive is `verifiedKnownPassword()`.
- **Do not add the observer password guard without first wrapping `RecoveryController::update()` in `withoutProtection()`** — the intermediate state locks out break-glass.
- **Do not remove the `eloquent.pivotAttaching` listener** when adding the Spatie listener — it is a legitimate extra pre-write layer for relationship-events hosts; fix it, keep it.
- **Do not run `php artisan test`/pest outside the listed verification steps or any background processes without approval** (fleet rule), and run all PHP via PowerShell, never Bash (Herd shims are not on Bash PATH).
- **Do not modify other fleet apps** to accommodate these changes in this pass — package-only; note host-app follow-ups (e.g. `permission.events_enabled` implications) in the CHANGELOG instead.

---

## Verification Pass (Fable, adversarial, 2026-07-06)

Method: opened each cited `file:line`, grepped the repo's own vendored framework for the disputed event, built the reachability/failure scenario. Read-only; no source modified, no tests/composer/artisan run.

### SUP-001 (CRITICAL) — **CONFIRMED** (severity upheld: CRITICAL)

The guard registers `Event::listen('eloquent.pivotAttaching: *', ...)` at `src/SuperAdminServiceProvider.php:285`. Decisive vendor evidence from this repo's own tree:
- `grep pivotAttaching vendor/laravel/framework/.../Database` → **0 hits**; `grep pivotAttaching vendor/` (entire tree) → **0 total occurrences across 0 files**. Core Laravel never dispatches this event.
- `InteractsWithPivotTable::attach()` (`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Relations/Concerns/InteractsWithPivotTable.php:337-353`) inserts via `$this->newPivotStatement()->insert(...)` (line 345) with **no `fireModelEvent` / no event dispatch**. This is the exact path Spatie `assignRole()`→`roles()->sync(...,false)`→`attach()` uses.
- The event vocabulary belongs to `chelout/laravel-relationship-events`, which is **not installed** (`vendor/chelout` absent), and `spatie/laravel-permission` is **also not vendored** (`vendor/spatie` = invade, laravel-package-tools, shiki-php only).
- The tests only pass by hand-dispatching the event: `tests/Feature/RolePromotionGuardTest.php:24-27` (`firePivotAttaching()` → `Event::dispatch('eloquent.pivotAttaching: '.get_class($user), ...)`). No test drives a real `assignRole()`. Textbook self-confirming suite.

Guard is inert in every production host. On Shield `define_via_gate=true` hosts the escalation it advertises to block is unblocked. **Real dead guard — yes.**

### SUP-002 (HIGH) — **CONFIRMED** (severity upheld: HIGH)

`SuperAdminObserver::updating()` (`src/Observers/SuperAdminObserver.php:35-61`) guards only `email` (line 41) and `is_protected` (line 49); `password`/`remember_token` are never inspected. `tests/Feature/ObserverTest.php:51-59` is titled *"allows password changes on the protected super admin"* and asserts the save succeeds and the hash changed. Model-layer `$protected->update(['password' => ...])` succeeds from any non-Filament path → account takeover of the row that passes `Gate::before`. Confirmed.

### SUP-003 (HIGH) — **CONFIRMED** (severity upheld: HIGH)

Both documented mechanisms fail, for the two independent reasons the review gives:
1. **Load-order:** the pattern lives inside the *published* `config/superadmin.php` and references `config('superadmin.filament.hidden_action_names')`. `LoadConfiguration` requires that file while building the `superadmin` key; `mergeConfigFrom` runs later in `register()` (`src/SuperAdminServiceProvider.php:26`). So at evaluation the key is not yet present → resolves `null`/`[]`. The README spread variant `[...config(...)]` (`README.md:345,349`, no default) would even `TypeError` on `[...null]`; the plugin-docblock variant `array_merge(config(..., []), [...])` (`src/Filament/SuperAdminPlugin.php:50-52`) *silently* yields only the app's additions.
2. **Shallow merge:** `mergeConfigFrom` (`vendor/laravel/framework/src/Illuminate/Support/ServiceProvider.php:163-172`) is a **non-recursive** `array_merge(require $path, $config->get($key, []))`. The package uses this, **not** `replaceConfigRecursivelyFrom` (which exists at :181-190). A published nested `filament` array therefore wholesale-replaces the package's nested defaults regardless of load order — the 11 default `hidden_action_names` verbs (and `password`/`roles`/`is_protected` locks) vanish.

Following the package's own recommended extension pattern reduces protection. Confirmed.

### Tally

- Verified: **3 / 3** (SUP-001, SUP-002, SUP-003).
- CONFIRMED: 3 · PLAUSIBLE: 0 · REFUTED: 0 · Downgraded: 0 · Upgraded: 0.
- Severities upheld exactly as written: 1 CRITICAL, 2 HIGH.
- **SUP-001 dead-guard is REAL** — the guard listens for `eloquent.pivotAttaching`, an event with 0 occurrences anywhere in the vendored tree and never dispatched by `attach()`'s raw `insert()`.
