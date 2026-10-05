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
- [ ] T1 — Companies domain: migration, `Company` model, seeder with the two real tenants, domains and brand colors; tests. Route: delegated writer (multi-file).
- [ ] T2 — `TenantDirectory`: allowed tenant IDs from active companies (cached, invalidated on company save/delete), resolve company from `tid` + email domain (shared-tenant rule), reject unknown/inactive tenants; tests. Route: delegated writer (same as T1).
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
Strategy: `ask-on-risk`. Forecast T1–T4: ~900–1200 authored lines including tests; above the ~400-line slice budget, so ask for the chain strategy before the second work-unit commit.
Branch `feat/fase-3-empresas-login` is stacked on `feat/fase-2-base` (not yet merged to `main`).

## Progress
- 2026-10-05: Document created. Branch created from `feat/fase-2-base` at `6cb35bf`.

## Next step
T1–T3 (one delegated writer).
