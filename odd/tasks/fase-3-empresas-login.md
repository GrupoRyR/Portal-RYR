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
- [ ] T5 — Emergency admin account. Blocked on Open question 1.
- [ ] T6 — Companies administration UI (list, edit, new-company wizard with admin-consent link, super-admin-only tenant changes, audit + email notice). Blocked on Open question 2.

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
1. Emergency account when the database is down: credentials (password hash + TOTP secret) in `.env` with a file-based session for that route only, or a regular database user with 2FA (simpler, but not usable if MySQL is down, which the brief requires).
2. UI foundation for T6 and Phase 4: Livewire version and how the approved design tokens become CSS (frontend-design skill), before the first screen.

## Delivery
Strategy: `ask-on-risk`. Forecast T1–T4: ~900–1200 authored lines including tests. Chain strategy (chosen by Jhon 2026-10-05): **stacked-to-main**. No remote yet, so slices are branches until a remote exists.

| Slice | Branch | Base | Holds | Budget |
|---|---|---|---|---|
| 0 | `feat/fase-2-base` | `main` | Phase 2 (generated skeleton → `size:exception`) | — |
| 1 | `feat/fase-3-1-empresas` | `feat/fase-2-base` | T1 (`8f8ee4b`) | 428 (≈half tests) |
| 2 | `feat/fase-3-2-directorio` | slice 1 | T2 (`ff59d89`) | 467 (≈half tests) |
| 3 | `feat/fase-3-3-usuarios` | slice 2 | T3 | ~200 |
| 4 | `feat/fase-3-4-login` | slice 3 | T4 | ~450 |

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

## Next step
Review/delivery decision for slices 3 and 4, then T5 (blocked on Open question 1).
