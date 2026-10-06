# Phase 4 — App catalog, access rules and home screen

## Objective
Each signed-in user sees, on the home screen, exactly the applications their company and Microsoft groups allow — grouped by category, with favorites, recents and search — and opening an app is recorded. Admins manage apps, categories and access rules from Administración.

## Problem / why
Phases 1–3 delivered the design, the base and identity (companies, users, groups, roles). The portal's core job — "mostrar y abrir aplicaciones" (brief §0.6) — starts here. Brief sections 1.1, 4, 4b, 5.2, 8 and 9 (phase 4).

## Scope
- Catalog domain: `app_categories`, `apps` (immutable `slug`), `app_companies`, seeded with the real catalog of brief §1.1 and the nine initial categories.
- Access rules: `app_access_rules` (group / user / company) and one resolver used everywhere (home, launch, search).
- Launching: `app_launches` record (who, which app, when, device), maintenance gate, local-app info page, `user_favorites`, recents.
- Home screen per the approved canvas (desktop 5–6 columns, tablet 3–4, phone 2 with bottom bar): favorites pinned out of their categories, empty categories hidden, recents, search, status chips (active / maintenance / failures / own login / installed locally), empty state, Microsoft 365 quick links (company links + defaults), dark mode.
- App icon system: the five app icons from the canvas as SVG files (light and dark).
- Administración: Aplicaciones list + editor (incl. access rules, maintenance, icon upload with SVG sanitizing) and Categorías (order, rename, empty ones hidden) per the canvas; `super_admin` and `portal_admin`.

## Out of scope
Announcements (Phase 5), audit screens and usage report (Phase 5), Soporte TI button (Phase 7), external status API (Phase 8; the `external_status*` columns are created now but only read), PWA/offline cache (Phase 9).

## Constraints
- Identity is (`tenant_id`, `azure_oid`); never look users up by email alone (Phase 3 decision).
- Group rules match the user's stored Microsoft groups by display name within the rule's company.
- Home, sign-in and app pages ship no Livewire/Alpine (2026-10-05 decision); small vanilla JS modules only where needed (search, favorite toggle). Livewire only in Administración.
- App URLs: `https://` only, host in the allowed list from Configuración (until Phase 5 builds that screen: config `portal.allowed_app_hosts`, defaults `segurex.com.co`, `derivadoscol.com.co`, `sgx-*.vercel.app` — confirmed 2026-10-05).
- Lighthouse ≥ 90 mobile, home < 2 s on 4G; touch targets ≥ 44 px.
- Code in English, UI in Spanish. Agent cannot touch `.env*`.

## Tasks
- [ ] T1 — Catalog domain + seeders (categories, five apps, company links, SVG icons); tests. Route: delegated writer.
- [ ] T2 — Access rules + `AppCatalog` resolver (visible apps per user, per company/groups/user rules; hidden excluded; maintenance kept visible); tests. Route: delegated writer.
- [ ] T3 — Launch flow (`/apps/{slug}`): access check, maintenance page, local-app info page, `app_launches` record, redirect (same or new tab); favorites toggle endpoint; recents query; tests. Route: delegated writer.
- [ ] T4 — Home screen UI (responsive per canvas, search, favorites, recents, status chips, empty state, quick links, dark mode); tests. Route: delegated writer (frontend-design).
- [ ] T5 — Admin Aplicaciones (list + editor + access rules + maintenance + icon upload); tests. Route: delegated writer (frontend-design).
- [ ] T6 — Admin Categorías (order, rename, create, delete only when empty); tests. Route: delegated writer.

## Acceptance criteria
- A Segurex user in `APP-Cartera` sees Cartera; one who is not does not, and opening `/apps/cartera` directly is forbidden.
- Hidden apps never appear; maintenance apps appear and show the maintenance notice instead of opening; failing apps open with the red chip.
- Favorites appear first and are removed from their category; empty categories are not shown.
- Every launch is recorded with user, app, timestamp and device type.
- Local apps open their info page, never a URL.
- `php artisan test` passes; home renders without Livewire/Alpine.

## Checks
- `php artisan test`
- `npm run build`
- `php artisan migrate:fresh --seed`
- `vendor/bin/pint --test`

## Delivery
Strategy `ask-on-risk`, chain strategy **stacked-to-main** (cached from Phase 3). Forecast ≈ 3000–3500 authored lines incl. tests → one slice per task or commit boundary, recommend `size:exception` where a single screen exceeds the budget. First slice branches from `feat/fase-3-20-reauth-inicio` (Phase 3 not yet merged; no remote).

## Progress
- 2026-10-05: Document created.

## Next step
T1 + T2 + T3 (backend, one delegated writer), then T4 (home UI).
