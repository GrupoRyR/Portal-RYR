# Phase 3 — Companies and multi-tenant Microsoft login

## Objective
Companies are data, not code: the portal accepts Microsoft sign-in only from the tenants of active companies, decides each user's company from the token's tenant (`tid`), keeps users' profile and Microsoft groups fresh at every login, grants admin roles from `APP-PortalAdmin`, and offers a protected emergency admin account.

## Problem / why
Phase 2 delivered a deployable base. Every later phase (catalog, access rules, admin, API) depends on knowing who the user is, which company they belong to and which groups they are in. Brief sections 3, 5.1, 7 and 8.

## Scope (this document)
- `companies` table, model, seeder (Segurex, Derivados) and a tenant directory service with cached allowed tenants.
- Users extended for Microsoft identities; `user_groups`.
- Socialite Microsoft login (`organizations` endpoint), tenant gate, guest linking by email, Graph profile/photo/groups sync, roles from `APP-PortalAdmin` and configured super admins.
- Emergency admin account (design decision pending, see Open questions).
- Companies administration (5.1) UI — after the UI foundation is agreed (see Open questions).

## Out of scope
Catalog, access rules, cards (Phase 4). Real Entra app registration (done by Jhon in Entra; client id/secret go only in his `.env`).

## Constraints
- Shared cPanel hosting: no Redis, no long-running processes; cache is the database store.
- Secrets only in `.env`. The agent cannot read or write `.env*` files: Jhon handles them.
- Microsoft Graph and the Entra token endpoint are never called in tests (faked).
- Code in English, UI text in Spanish.
- Laravel 13.34, PHP 8.3, PHPUnit 12.

## Tasks
- [x] T1 — Companies domain: migration, `Company` model, seeder with the two real tenants, domains and brand colors; tests. Route: delegated writer (multi-file).
- [x] T2 — `TenantDirectory`: allowed tenant IDs from active companies (cached, invalidated on company save/delete), resolve company from `tid` + email domain (shared-tenant rule), reject unknown/inactive tenants; tests. Route: delegated writer (same as T1).
- [x] T3 — Users for Microsoft identities: `azure_oid`, `tenant_id`, `company_id`, job title, photo, last login, nullable password; `user_groups`; tests. Route: delegated writer (same as T1).
- [x] T4 — Microsoft login flow: Socialite + Microsoft provider, redirect/callback routes, tenant gate, user upsert and guest linking by email, Graph sync of profile/photo/groups behind an interface, roles (`super_admin` from config, `portal_admin` from `APP-PortalAdmin`); tests with fakes. Route: delegated writer.
- [x] T5 — Emergency admin account: local database user with password + TOTP 2FA (Open question 1 resolved). Route: delegated writer.
- [ ] T6 — Companies administration UI (list, edit, new-company wizard with admin-consent link, super-admin-only tenant changes, audit + email notice). Unblocked 2026-10-05 (Q2: Livewire 4). Split into:
  - [x] T6a — UI foundation: Livewire 4, self-hosted fonts (Bricolage Grotesque + Figtree), Tailwind 4 design tokens (light/dark) from the approved canvas, `--brand-*` CSS variables filled from the user's company, admin shell layout (sidebar), `/admin` access for `super_admin`/`portal_admin`; tests. Route: delegated writer (frontend-design skill).
  - [ ] T6b — Companies list + edit (no tenant change): fields, domains, quick links, active switch, colors with WCAG contrast check and live preview, logo uploads (PNG/WebP, sanitized SVG); `super_admin` only; tests. Route: delegated writer.
  - [ ] T6c — New-company wizard + tenant changes: recent Microsoft re-authentication, admin-consent link, consent detection, activity log, email notice to admins; tests. Route: delegated writer.

## Acceptance criteria
- A sign-in from a tenant that is not an active company is rejected with a clear Spanish message.
- Segurex and Derivados users land in their own company; a Derivados guest in Segurex is linked to the same user by email, not duplicated.
- Groups, name, job title and photo update at each login.
- Members of `APP-PortalAdmin` get `portal_admin`; configured super admin emails get `super_admin`.
- Saving or deleting a company invalidates the allowed-tenants cache.
- `php artisan test` passes.

## Checks
- `php artisan test`
- `php ~/tools/composer/composer.phar validate --no-check-publish`
- `php artisan migrate:fresh --seed` (SQLite, local)
- `php artisan route:list --path=auth`

## Open questions (product decisions, ask one at a time when reached)
1. Emergency account when the database is down: credentials (password hash + TOTP secret) in `.env` with a file-based session for that route only, or a regular database user with 2FA (simpler, but not usable if MySQL is down, which the brief requires). **RESOLVED (Jhon, 2026-10-05):** regular database user with password + TOTP 2FA. Its purpose is signing in when Microsoft is down; it is NOT required to work when MySQL is down. The brief's sentence "Si la base de datos no responde, la cuenta de emergencia sigue funcionando" (section 3) is superseded.
2. ~~UI foundation for T6 and Phase 4~~ — RESOLVED 2026-10-05 by Jhon: **Livewire 4** (v4.4.7 on Packagist, supports Laravel 13; 3.x still maintained but previous major). Tokens: Tailwind 4 (already in the skeleton) + `--brand-*` CSS variables filled from the company record, per the approved Phase 1 canvas.

## Delivery
Strategy: `ask-on-risk`. Forecast T1–T4: ~900–1200 authored lines including tests. Chain strategy (chosen by Jhon 2026-10-05): **stacked-to-main**. No remote yet, so slices are branches until a remote exists.

| Slice | Branch | Base | Holds | Budget |
|---|---|---|---|---|
| 0 | `feat/fase-2-base` | `main` | Phase 2 (generated skeleton → `size:exception`) | — |
| 1 | `feat/fase-3-1-empresas` | `feat/fase-2-base` | T1 (`8f8ee4b`) | 428 (≈half tests) |
| 2 | `feat/fase-3-2-directorio` | slice 1 | T2 (`ff59d89`) | 467 (≈half tests) |
| 3 | `feat/fase-3-3-usuarios` | slice 2 | T3 (`191377f`) | 511 (≈278 tests) |
| 4 | `feat/fase-3-4-auth-base` | slice 3 | T4 packages, config, roles (`385e786`) | 529 (≈356 vendor-published config/migration) |
| 5 | `feat/fase-3-5-login` | slice 4 | T4 login flow + security fix (`ed6453e`, `38da14b`) | 1369 (≈600 tests) |
| 6 | `feat/fase-3-6-sin-vinculo-invitados` | slice 5 | No guest linking (Open question 3 decision) | 333 (+153/−180) |
| 7 | `feat/fase-3-7-emergencia` | slice 6 | T5 schema + management command (`a202419`) | +459/−1 |
| 8 | `feat/fase-3-8-emergencia-login` | slice 7 | T5 two-step sign-in (`cabacdb`) | ≈+611 (≈half tests) |
| 9 | `feat/fase-3-9-ui-tokens` | slice 8 | T6a tokens, fonts, brand palette (`fde6b63`) | +809/−8 (272 tests) |
| 10 | `feat/fase-3-10-admin-shell` | slice 9 | T6a Livewire 4, layouts, admin shell (`1ebd080`) | +626/−2 (207 tests) |
| 11 | `feat/fase-3-11-ui-paginas` | slice 10 | T6a restyled placeholder pages (`b2aa517`) | +204/−73 |
| 12 | `feat/fase-3-12-ajustes-ui` | slice 11 | T6a adjustments: 4.8:1 button, Livewire only where needed (`10eb654` + follow-up) | +68/−18 |

Second slicing pass (2026-10-05): T4 split at its commit boundary into slices 4 and 5. Slice 5 stays well above budget: the flow and its fix are one cohesive unit and about half is tests → `size:exception` recommended.

Slicing pass (2026-10-05): the original slice 1 came to ~895 authored lines, so it was split at the existing T1/T2 commit boundary. Both halves stay slightly above 400 because about half of each is tests; no cohesive smaller split exists → recommend `size:exception` for slices 1 and 2.

Each slice states start, end, dependencies and out-of-scope in its PR body with the dependency diagram; tests and docs travel with their slice.

## Progress
- 2026-10-05: Document created. Branch created from `feat/fase-2-base` at `6cb35bf`.

- 2026-10-05: Chain strategy stacked-to-main chosen; branch renamed to `feat/fase-3-1-empresas`.
- 2026-10-05: T1 done (route: delegated writer). `companies` migration (tenant_id indexed, not unique), `Company` model (array/bool/datetime casts, lowercase tenant_id and domains, `active()` scope, exact-match `ownsEmailDomain`; subdomains such as `mail.segurex.com` are not owned), `CompanyFactory`, idempotent `CompanySeeder` called from `DatabaseSeeder`.
  - RED: `php artisan test tests/Feature/Companies` → 10 errors (`Class "App\Models\Company" not found`, `Target class [Database\Seeders\CompanySeeder] does not exist`).
  - GREEN: `php artisan test` → 22 passed (62 assertions).
  - `php artisan migrate:fresh --seed` → companies migrated, `CompanySeeder` DONE; tinker shows segurex `97cd024c-…5816c8`, derivados `54f53029-…886468`.
  - Commit: `8f8ee4b` feat(companies): add companies domain with seeded Segurex and Derivados tenants.
- 2026-10-05: T2 done (route: delegated writer). `App\Tenancy\TenantDirectory` singleton: `allowedTenantIds()` (distinct lowercase tenants of active companies, `Cache::rememberForever` under `tenancy:allowed_tenant_ids`), `isAllowed()`, `resolveCompany()` (single company; shared tenant by exact email domain, else the single active primary, else null), `forget()`. `CompanyObserver` (`#[ObservedBy]`) forgets the cache on saved/deleted and rejects a second primary company per tenant with `DuplicatePrimaryCompanyException` (application-level check, no DB constraint). `CompanySeeder` forgets the cache explicitly because `DatabaseSeeder` mutes model events.
  - RED: `php artisan test tests/Feature/Tenancy tests/Feature/Companies` → 34 tests, 12 passed, 18 errors (`Target class [App\Tenancy\TenantDirectory] does not exist`), 4 failures (expected `DuplicatePrimaryCompanyException` not thrown).
  - GREEN: `php artisan test` → 46 passed (105 assertions). `vendor/bin/pint --test app database tests` → passed.
  - `php artisan migrate:fresh --seed` → DONE; tinker `allowedTenantIds()` → `["54f53029-e6e2-4b5f-a862-3454ae886468", "97cd024c-cd7a-419b-b385-f47b5f5816c8"]`.
  - `composer validate --no-check-publish` → valid.

  - Commit: `ff59d89` feat(tenancy): add cached tenant directory with shared-tenant company resolution.
- 2026-10-05: Parent spot check `php artisan test` → 46 passed. Review of `feat/fase-2-base..ff59d89`: assessed medium (`slice_budget_reached`, executable change in `app/Models/Company.php`), consent declined by Jhon. Next review base: `ff59d89`.
- Writer decisions to confirm: exact-domain match only (subdomains not owned); second primary per tenant rejected (application check only); shared tenant without an active primary resolves to null; seeded colors follow the design canvas (Segurex dark `#8F360B` is not in the brief; Derivados extra blues `#6D94E8`, `#031D54` not stored).

- 2026-10-05: T3 done (route: delegated writer) on `feat/fase-3-3-usuarios`. Migration `add_microsoft_identity_to_users_table` (`azure_oid`/`tenant_id` string 36, `company_id` FK nullOnDelete, `job_title`, `photo_path`, `last_login_at`, nullable `password`, unique (`tenant_id`, `azure_oid`); NULL pairs allowed on SQLite and MySQL), `user_groups` table (FK cascade, unique (`user_id`, `group_id`)). `User`: `company()`, `groups()`, case-insensitive `isInGroup()`, `syncGroups()` (one transaction; deletes missing, renames changed, adds new; skips items without id, dedupes ids), lowercase GUID mutators. Group model placed at `App\Microsoft\UserGroup` because `app/Models/UserGroup.php` was outside the authorized edit surface (parent to confirm or move). `UserFactory::microsoft(?Company)` state.
  - RED: `php artisan test tests/Feature/Users` → 20 tests, 0 passed, 2 failures (missing columns), 18 errors (`Call to undefined method User::syncGroups()`, `NOT NULL constraint failed: users.password`, `no column named azure_oid`, `UserFactory::microsoft()` undefined).
  - GREEN: `php artisan test` → 66 passed (143 assertions). `php artisan migrate:fresh --seed` → DONE; `migrate:rollback --step=2` then `migrate` → DONE (down migration works on SQLite). `vendor/bin/pint` → passed.
  - Authored lines: 511 insertions, 1 deletion (278 of them tests).
  - Commit: `191377f` feat(users): store Microsoft identities, company and groups on users.
- 2026-10-05: T4 done (route: delegated writer) on `feat/fase-3-4-login`, two work-unit commits.
  - Commit A `385e786` feat(auth): `laravel/socialite` ^5.31, `socialiteproviders/microsoft` ^4.10, `spatie/laravel-permission` ^8.3 (published migration renamed to `2026_10_05_000004_create_permission_tables.php`, `teams` = false); `services.microsoft` (tenant `organizations` by default, absolute redirect default `APP_URL/auth/microsoft/callback` because the SocialiteProviders manager does not expand relative URIs); provider registered with `Event::listen(SocialiteWasCalled)` in `AppServiceProvider::boot`; `config/portal.php` (`super_admin_emails`, `admin_group`); idempotent `RoleSeeder` called from `DatabaseSeeder`; `User` uses `HasRoles`. RED: `php artisan test tests/Feature/Auth` → 8 tests, 1 passed, 3 failed, 4 errors (`Driver [microsoft] not supported`, `RoleSeeder` missing, config null). GREEN: `php artisan test` → 74 passed.
  - Commit B (login flow): provider source read in `vendor/socialiteproviders/microsoft/Provider.php` — `user()` exchanges the code, keeps the token response, and validates the `id_token` (signature against the tenant JWKS, `iss` with the token's `tid`, `aud`, `exp`) while fetching `/me`; `getClaims()` then returns the validated claims. `tid`/`oid` come from those claims; the Socialite user's `getRaw()` gives `mail`/`userPrincipalName`; `token` is the Graph access token. `App\Auth\MicrosoftIdentity` (email = `mail`, else id_token `email`, else UPN/`preferred_username`; any `#EXT#` value skipped; lowercase), `App\Auth\MicrosoftLogin` (tenant gate → guest rule → upsert in a transaction → Graph sync → roles → `last_login_at`), `App\Auth\LoginFailed` (Spanish messages), `App\Microsoft\GraphClient` + `HttpGraphClient` (profile, photo with 404 → null, transitive groups following `@odata.nextLink`, max 50 pages), controllers, routes (`/login` guest, `/auth/microsoft/{redirect,callback}` guest + `throttle:20,1`, `/` auth, `POST /logout` auth + throttle), placeholder Spanish views.
  - RED: `php artisan test` → 109 tests, 73 passed, 23 failed, 13 errors (routes missing, `App\Microsoft\GraphClient` missing). During GREEN the pagination test caught a real bug: `Http::get($url, [])` strips the next link's `$skiptoken`; fixed by passing `null`.
  - GREEN: `php artisan test` → 109 passed (279 assertions). `php artisan migrate:fresh --seed` → CompanySeeder and RoleSeeder DONE. `route:list --path=auth` → 2 routes; `--path=login` → 1 route. `composer validate --no-check-publish` → valid. `vendor/bin/pint --test` → passed.
  - Authored lines slice 4 (excluding `odd/`, `composer.lock`): commit A ≈529 (356 of them the published spatie config/migration), commit B 1156 insertions / 3 deletions (608 tests).
- Writer decisions to confirm (T3/T4): `UserGroup` model lives at `App\Microsoft\UserGroup` (edit surface); a domain owned by an INACTIVE company of another tenant rejects the sign-in (tenant message) instead of falling back to the token tenant's company; (superseded by the security fix below for linking and `super_admin`) a guest sign-in links by email and never replaces a stored home `tenant_id`/`azure_oid`, but it does replace the stored groups with the groups Graph returns for the guest tenant; a home sign-in matched by email records its own `tenant_id`/`azure_oid`; no usable email (only an `#EXT#` UPN) → retry message; `super_admin` is also removed when the email leaves `PORTAL_SUPER_ADMIN_EMAILS`; the email is not changed if another user already holds it; Graph `/me` is read twice per sign-in (once by the Socialite provider, once by `GraphClient`); `welcome.blade.php` is now unused (outside the edit surface, left in place).
- 2026-10-05: Security fix on slice 4 (coordinator finding): guest linking and `super_admin` trusted the `mail` attribute, which the signing tenant's admins control. That allowed a cross-tenant account takeover: an admin of any allowed tenant sets `mail` to the super admin's address and signs in.
  - Claim used: `idp` from the validated id_token. Source: https://learn.microsoft.com/en-us/entra/identity-platform/id-token-claims-reference. `idp` "is identical to the value of the issuer claim unless the user account isn't in the same tenant as the issuer - guests"; when absent, `iss` applies. Guest value: home STS URI, e.g. `https://sts.windows.net/{homeTid}/`. `MicrosoftIdentity::homeTenant()` accepts `sts.windows.net` or `login.microsoftonline.com` URIs carrying a GUID; absent → the signing tenant; anything else (e.g. `live.com`) → no proven tenant.
  - Rules now:
    1. Guest of company C (domain owner, tenant ≠ `tid`) only when the `idp` tenant equals C's tenant and C is active. Otherwise reject with the tenant message.
    2. Link by email only on a verified guest, or on a home sign-in (`idp` tenant = `tid`) in the tenant owning the email domain. Otherwise match only by (`tenant_id`, `azure_oid`); an email already held by another user → retry message.
    3. `super_admin` is granted only when the email is listed AND it is a trusted home sign-in in the owning tenant. It is removed when the email leaves the list and otherwise left unchanged.
  - Caveat for Jhon (same doc page): Microsoft advises against correlating users across tenants and suggests treating guests as new users. Email linking of verified guests follows the brief (section 3) but remains a product decision. Residual risk: the inviting tenant's admins can still edit a real guest's `mail`, so a verified Derivados guest could be linked to another Derivados address.
  - RED: `php artisan test` → 120 tests, 111 passed, 9 failed (4 takeover data sets, mismatched/missing home claim, email collision, 2 super_admin cases).
  - GREEN: `php artisan test` → 120 passed (330 assertions). `vendor/bin/pint --test` → passed.
  - Commit: `fix(auth): ...` (see `git log --oneline -3`).
- `.env` keys for Jhon: `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`, `MICROSOFT_REDIRECT_URI` (e.g. `https://portal.example.com/auth/microsoft/callback`), optional `MICROSOFT_TENANT=organizations`, `PORTAL_SUPER_ADMIN_EMAILS` (comma-separated). Run `php artisan storage:link` on the server for avatars.

- 2026-10-05: Parent found a cross-tenant account-takeover path in the first T4 version (guest status and `super_admin` derived from the mutable `mail` attribute). Fixed in `38da14b` by the writer: guest only when the validated `idp` claim proves the home tenant owns the email domain; email linking only for trusted home or verified-guest sign-ins; `super_admin` only on a trusted home sign-in. Source verified by the parent: https://learn.microsoft.com/en-us/entra/identity-platform/id-token-claims-reference (`idp`; `email`/`preferred_username` mutable, never for authorization; guests should be treated as new users).
- 2026-10-05: Parent spot check `php artisan test` → 120 passed (330 assertions). Review of `ac301d6..38da14b`: assessed high (auth hot path), consent declined by Jhon. Next review base: `38da14b`.
- Env keys Jhon must add (names only): `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`, `MICROSOFT_REDIRECT_URI` (absolute), `MICROSOFT_TENANT` (optional, default `organizations`), `PORTAL_SUPER_ADMIN_EMAILS`. Server needs `php artisan storage:link` for avatars.

## Open question 3 (product, raised 2026-10-05) — RESOLVED 2026-10-05
Brief section 3 asks to link a Derivados guest in Segurex to their Derivados account by email. Microsoft's guidance is to treat guests as new users; even with the `idp` check, Segurex admins can edit a real guest's `mail` and redirect the link to another Derivados user. Options: keep verified-guest linking or stop linking guests (separate user per tenant).

**Decision (Jhon, 2026-10-05): do NOT link guests.** Following https://learn.microsoft.com/en-us/entra/identity-platform/id-token-claims-reference, a guest sign-in is a brand-new, separate portal user. Its privileges come only from the tenant it signed in to. This overrides brief section 3 ("se vincula por correo").

- 2026-10-05: Slice 6 (`feat/fase-3-6-sin-vinculo-invitados`, from `feat/fase-3-5-login`; route: delegated writer) implements the decision.
  - Identity is (`tenant_id` = `tid`, `azure_oid` = `oid`) only. No email matching anywhere.
  - Company = `TenantDirectory::resolveCompany(tid, email)`, so a Derivados person signing in through Segurex becomes a Segurex user. The tenant gate is unchanged.
  - Migration `2026_10_05_000005_relax_users_email_uniqueness` drops the unique index on `users.email` and keeps a plain index. Its `down()` fails if emails are duplicated. The "email already taken → reject" rule was removed, and the email from the token is stored at every sign-in (never an `#EXT#` UPN).
  - `super_admin` is unchanged: the email must be listed, `idp` home tenant = `tid`, and the company owning the email domain must be in `tid`. Removed from the code: guest-company resolution, the `isGuest`/email-trust branches and the email-collision check. `MicrosoftIdentity::homeTenant()` stays because `super_admin` needs it.
  - Tests:
    - Replaced the linking tests with: a guest creates a separate Segurex user and leaves the home user's attributes, groups and roles intact; six spoofed-mail scenarios (four from another tenant, two guests inside the owning tenant) never touch Jhon's user or roles; repeated sign-ins of the same (`tid`, `oid`) update one row; a same-email user is never linked; an unowned email may be shared.
    - Added: `super_admin` on a home sign-in with an issuer-style `idp`; the `users.email` index is not unique.
  - RED: `php artisan test` → 120 tests, 109 passed, 11 not passing (10 failures, 1 error).
  - GREEN: `php artisan test` → 120 passed (357 assertions). `php artisan migrate:fresh --seed` → DONE (`migrate:rollback --step=1` + `migrate` → DONE). `vendor/bin/pint --test` → passed.
  - Authored lines (excluding `odd/`): 4 files, +153/−180.

- 2026-10-05: Parent spot check on `feat/fase-3-6-sin-vinculo-invitados` (`f819492`): `php artisan test` → 120 passed (357 assertions). Review of `38da14b..f819492`: assessed high (auth hot path), consent declined by Jhon. Next review base: `f819492`. Note: brief section 3 (guest linking by email) is superseded by this decision.

- 2026-10-05: T5 done on `feat/fase-3-7-emergencia` (route: delegated writer), two work-unit commits.
  - Commit A `a202419`:
    - `pragmarx/google2fa` ^9.1, read in `vendor/pragmarx/google2fa/src/Google2FA.php`: `verifyKeyNewer($secret, $code, $old)` returns the matched time step only when `$old` is not null; with null it returns `true`. So 0 stands for "never used". Steps ≤ `$old` are not searched, which rejects a replayed code. The default window is ±1 step.
    - Migration `add_two_factor_to_users_table` adds `is_emergency`, `two_factor_secret` (encrypted cast, hidden), `two_factor_confirmed_at` and `two_factor_last_timestep`. `UserFactory::emergency()` state.
    - `App\Auth\EmergencyTwoFactor` wraps google2fa.
    - `portal:emergency-account {email} [--reset-2fa] [--disable]`: hidden password asked twice (≥16 characters, must match); prints the otpauth URI (issuer "Portal RYR") and the secret once; saves nothing until a code confirms the new secret. It assigns `super_admin`. `--disable` nulls the password and 2FA and removes `super_admin`, keeping the row. It refuses Microsoft emails and other local users' emails.
    - Isolated check with commit A alone: `php artisan test` → 130 passed.
  - Commit B (sign-in flow):
    - `config('portal.emergency_path')` (env `PORTAL_EMERGENCY_PATH`, default `acceso-emergencia`). Guest-only routes `emergency.login`/`emergency.credentials` (`/acceso-emergencia`) and `emergency.code`/`emergency.verify` (`/acceso-emergencia/codigo`). Unlinked; `noindex` pages.
    - `App\Auth\EmergencyLogin`: eligible only when `is_emergency`, no `azure_oid`/`tenant_id`, a password and confirmed 2FA. The pending user id sits in the session for at most 5 minutes; the session is regenerated on success. Every failure shows «Credenciales inválidas.».
    - Throttling: 5 attempts per minute, counted inside the service so lockouts stay generic. Step 1 is keyed by IP + email. Step 2 is keyed by the pending account rather than the session, so restarting step 1 does not reset it (deviation, stricter than asked).
    - `MicrosoftLogin` rejects an emergency user even if an identity was attached.
    - Logs: `notice` on success, `warning` on each failure, with email, IP, user agent, step and reason; never the password, code or secret. A full audit trail comes in Phase 5.
    - Found during verification: the unnamed POST routes inherited the duplicate name `emergency.`, which would break `route:cache`. They are now named, and a test asserts route names are unique.
  - RED: `php artisan test` → 150 tests, 121 passed, 4 failed, 25 errors (`UserFactory::emergency()` undefined, command missing, routes 404, config null).
  - GREEN: `php artisan test` → 151 passed (515 assertions). `php artisan migrate:fresh --seed` → DONE (rollback + migrate of the new migration → DONE). `route:list --path=acceso-emergencia` → 4 routes. `route:cache` → cached successfully (then cleared). `php artisan list portal` → `portal:emergency-account`. `composer validate` → valid. `vendor/bin/pint --test` → passed.
  - Authored lines: commit A +459/−1 (excluding `composer.lock`), commit B ≈+611 (about half tests).
- Env keys for T5 (names only): `PORTAL_EMERGENCY_PATH` (optional). Server: run `php artisan portal:emergency-account <email>` over SSH once and store the password and secret offline.

- 2026-10-05: Parent spot check on `feat/fase-3-8-emergencia-login` (`cabacdb`): `php artisan test` → 151 passed (515 assertions). T5 split at its commit boundary into slices 7 and 8. Review of `6ee7a39..cabacdb`: assessed high (auth hot path), consent declined by Jhon. Next review base: `cabacdb`. To confirm with Jhon: code-step throttle is per account (stricter; a password holder can lock the owner out for ≤1 min).

- 2026-10-05: Q2 resolved (Livewire 4). T6 split into T6a–T6c. Approved design references copied to `docs/diseno/`.

- 2026-10-05: T6a done on `feat/fase-3-9-ui-base` (route: delegated writer, frontend-design skill), three work-unit commits.
  - Commit A `fde6b63` feat(ui): design tokens, fonts and brand palette.
    - Tailwind 4 tokens in `resources/css/app.css`: neutral light/dark (redefined under `prefers-color-scheme`), status chips, radii 10/12/14/18, `spacing-touch` 44px, `font-display` (Bricolage Grotesque) and `font-sans` (Figtree).
    - Fonts self-hosted from `@fontsource-variable/bricolage-grotesque` and `@fontsource-variable/figtree` 5.3.0; the Bunny font was removed from `vite.config.js`.
    - `App\Branding\HexColor` (strict `#RRGGBB`, `\z` anchor), `Contrast` (WCAG 2.x ratio and AA/AAA constants, reusable for the T6b warning), `Oklch` (OKLab matrices, gamut clipping by chroma), `BrandPalette`.
    - Derivation: button = primary darkened in OKLCH steps of 0.005 (chroma scaled) until white ≥ 4.5:1; tint = L 0.96, C ≤ 0.025; ink = primary darkened until ≥ 7:1 on the tint; dark scheme: tint L 0.30, ink lightened until ≥ 7:1. Invalid primary → neutral palette; invalid accent/dark → primary/ink.
    - RED: `php artisan test tests/Unit/Branding` → 50 tests, 0 passed, 2 failed, 48 errors (`Class "App\Branding\…" not found`). GREEN: 50 passed (114 assertions); `php artisan test` → 201 passed.
    - Segurex `#FF671F` → button `#C94F16` (4.55:1), tint `#FEEEE8`, ink `#8D350C` (7.03:1), dark tint `#432519`, dark ink `#FFA17D`.
    - Derivados `#183F94` → button `#183F94` (9.64:1, unchanged), tint `#ECF2FE`, ink `#183F94` (8.59:1), dark tint `#202D47`, dark ink `#97BAFE`.
  - Commit B `1ebd080` feat(admin): Livewire 4 layouts and admin shell.
    - `livewire/livewire` v4.4.7 bundled from `vendor/livewire/livewire/dist/livewire.esm` in `resources/js/app.js` with `@livewireScriptConfig` (no CDN). `config/livewire.php`: `inject_assets` false, `component_layout` `components.layouts.app`. Alpine (with the focus plugin) comes from that bundle.
    - `x-layouts.app`: lang es, viewport, light/dark `theme-color`, `<x-brand-style />` (inline `--brand-*` of the user's company), Vite, Livewire.
    - `x-layouts.admin` (class `App\View\Components\Layouts\Admin`): grouped nav; only Empresas is a link, other items show «Próximamente» without links; active item `bg-brand-tint text-brand-ink` + `aria-current`; user card with role label; skip link; off-canvas below 1024px with a real button (`aria-expanded`/`aria-controls`), `x-trap`, Escape/backdrop close and focus return.
    - Spatie `role`/`permission`/`role_or_permission` aliases in `bootstrap/app.php`. `/admin` (`admin.index`, auth + `role:super_admin|portal_admin`) redirects to `/admin/empresas` (`admin.companies.index`, extra `role:super_admin`). Spanish `errors/403` view.
    - RED: `php artisan test tests/Feature/Admin tests/Feature/Layouts` → 16 tests, 0 passed, 3 failed, 13 errors (404s, `Route [admin.companies.index] not defined`, `Unable to locate … [layouts.app]`). GREEN: 16 passed; `php artisan test` → 217 passed.
    - Found in the browser check: with `prefers-reduced-motion`, the global `transition-duration: 0.01ms` kept inherited `visibility` hidden for a frame, so focus could not move into the opened menu. Fixed with `0s`; the sidebar no longer transitions `visibility`.
  - Commit C (see `git log`) feat(ui): restyled placeholders.
    - Login per `Login.dc.html`: neutral, decorative app tiles, «Todas tus aplicaciones en un solo lugar», single «Entrar con Microsoft» in `#17191C`, active companies' short names (view composer in `AppServiceProvider`).
    - Emergency steps and home on `x-layouts.app`; home shows the company brand, «Administración» for admins and «Cerrar sesión».
    - `ExampleTest` now uses `RefreshDatabase` because `/login` reads the active companies.
    - `resources/css/app.css` no longer scans `storage/framework/views`: the compiled-view cache (including error pages compiled during tests) changed the CSS hash between builds. The build is now the same before and after `php artisan view:cache`.
    - RED: `php artisan test tests/Feature/Layouts/PlaceholderPagesTest.php` → 5 tests, 1 passed, 4 failed (the emergency noindex guard already held). GREEN: `php artisan test` → 222 passed (701 assertions).
  - Checks: `npm run build` → built (CSS 50.88 kB / 12.26 kB gzip; JS 296.38 kB / 96.14 kB gzip, almost all Livewire + Alpine). `php artisan route:list --path=admin` → 2 routes. `php artisan view:cache` → cached, then `view:clear`. `vendor/bin/pint --test` → passed.
  - Browser check (local static render): desktop sidebar 248px, every item 44px high; no horizontal scroll at 375px; menu opens with focus on «Cerrar menú», Escape closes and returns focus; dark scheme colors applied; login fits 375×812 without scrolling.
  - Authored lines (excluding `public/build`, lock files, `odd/`): A +809/−8 (272 tests), B +626/−2 (207 tests), C +204/−73 (85 tests + 1-line ExampleTest change). Slice 9 is over the 400-line budget; the three commits are the natural split points.
- To confirm with Jhon (T6a):
  1. Ink is derived at 7:1 (AAA) to stay close to the canvas (`#8D350C` vs `#8F360B`); the brief only requires 4.5:1.
  2. Segurex button is `#C94F16` (4.55:1), slightly lighter than the canvas `#C4470F` (4.94:1), because the derivation stops at the first AA step.
  3. Livewire + Alpine add about 96 kB gzip of JS to every page, including the sign-in page.
  4. portal_admin may enter `/admin`, but its only section today (Empresas) is super_admin only, so it lands on a 403 until later sections exist.
  5. The sign-in page lists active companies by short name; logos come with T6b.

- 2026-10-05: Parent spot check on `feat/fase-3-11-ui-paginas` (`b2aa517`): `php artisan test` → 222 passed (701 assertions). T6a split at its commit boundaries into slices 9–11. Review of `0f290a2..b2aa517`: assessed high (auth view), consent declined by Jhon. Next review base: `b2aa517`.
- To confirm with Jhon: ink at 7:1 (AAA) vs 4.5:1; Segurex button `#C94F16` (4.55:1) vs adding a margin to land near the canvas `#C4470F`; Livewire+Alpine ~96 kB gzip JS on every page (incl. sign-in) vs loading it only where needed; portal_admin lands on 403 until later admin sections exist.

- 2026-10-05: Jhon's T6a decisions: (1) ink stays at 7:1 (AAA); (2) button target 4.8:1 with white text, AA 4.5:1 kept as invariant; (3) Livewire/Alpine only where needed. Implemented on `feat/fase-3-12-ajustes-ui` (from `feat/fase-3-11-ui-paginas`; route: delegated writer), two work-unit commits.
  - Commit A `10eb654` feat(branding): `BrandPalette::BUTTON_CONTRAST = 4.8`. Segurex button → `#C24C15` (4.84:1; canvas `#C4470F` is 4.94:1, within 6 per channel). Derivados unchanged (`#183F94`, 9.64:1). Invariant test (every sample primary ≥ 4.5:1) unchanged.
    - RED: `php artisan test tests/Unit/Branding` → `Undefined constant App\Branding\BrandPalette::BUTTON_CONTRAST`. GREEN: all branding tests pass.
  - Commit B (see `git log`) feat(ui): `resources/js/app.js` renamed to `resources/js/livewire.js` (the only JS entry). `x-layouts.app` gets a `livewire` flag (default false) that adds that entry, `@livewireStyles` and `@livewireScriptConfig`; `x-layouts.admin` always passes it. Sign-in, emergency, home and 403 pages now ship CSS only.
    - RED: 4 failed (layout default/opt-in, placeholder pages, admin page). GREEN: `php artisan test` → 226 passed (717 assertions).
    - Build: `app-*.css` 50.88 kB (12.26 kB gzip); `livewire-*.js` 296.38 kB (96.14 kB gzip), admin only; no JS on other pages.
    - Pages that later embed a Livewire component outside the admin shell must pass `livewire` to `x-layouts.app` (Livewire asset injection is off).
  - Authored lines: A +11/−5, B +57/−13.

## Next step
T6b (companies list + edit) on `feat/fase-3-13-empresas-admin`, branched from `feat/fase-3-12-ajustes-ui`.
