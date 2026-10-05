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
- [ ] T3 — Users for Microsoft identities: `azure_oid`, `tenant_id`, `company_id`, job title, photo, last login, nullable password; `user_groups`; tests. Route: delegated writer (same as T1).
- [ ] T4 — Microsoft login flow: Socialite + Microsoft provider, redirect/callback routes, tenant gate, user upsert and guest linking by email, Graph sync of profile/photo/groups behind an interface, roles (`super_admin` from config, `portal_admin` from `APP-PortalAdmin`); tests with fakes. Route: delegated writer.
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
| 1 | `feat/fase-3-1-empresas` | `feat/fase-2-base` | T1 + T2 | ~350 |
| 2 | `feat/fase-3-2-usuarios` | slice 1 | T3 | ~200 |
| 3 | `feat/fase-3-3-login` | slice 2 | T4 | ~450 |

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

## Next step
Slice 1 complete (T1 + T2). Next: slice 2 on `feat/fase-3-2-usuarios` (T3 — users for Microsoft identities).
