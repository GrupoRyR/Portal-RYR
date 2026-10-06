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
- [x] T1 — Catalog domain + seeders (categories, five apps, company links, SVG icons); tests. Route: delegated writer.
- [x] T2 — Access rules + `AppCatalog` resolver (visible apps per user, per company/groups/user rules; hidden excluded; maintenance kept visible); tests. Route: delegated writer.
- [x] T3 — Launch flow (`/apps/{slug}`): access check, maintenance page, local-app info page, `app_launches` record, redirect (same or new tab); favorites toggle endpoint; recents query; tests. Route: delegated writer.
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

### Slices
| Slice | Branch | Base | Commits | Authored lines (excl. `odd`) | Status |
|---|---|---|---|---|---|
| T1 catalog domain | `feat/fase-4-1-catalogo` | `06566f9` | `7059efe` | +1152 / −1 (tests 419, SVG 40) | committed, not pushed |
| T2 access rules + resolver | `feat/fase-4-2-acceso` | T1 (`4bf4a95`) | `2e8b4e2` | +654 / −0 (tests 363) | committed, not pushed |
| T3 launch, favorites, recents | `feat/fase-4-3-lanzamiento` | T2 (`fba3ae0`) | `84be060` | +919 / −0 (tests 511) | committed, not pushed |

## Progress
- 2026-10-05: Document created.
- 2026-10-06 T1 (delegated writer; trigger: 2+ non-trivial files): RED `php artisan test tests/Feature/Catalog tests/Unit/Catalog` → 53 tests, 0 passed (missing classes/files). GREEN → 53 passed. Full `php artisan test` → 372 passed. `php artisan migrate:fresh --seed` OK; `vendor/bin/pint --test` passed. Commit `7059efe`.
  - Host patterns: a plain domain matches itself and its subdomains on a dot boundary; `*` matches one or more `[a-z0-9-]` characters of a single label. User info, explicit ports, trailing dots and non-`https` schemes are rejected (`App\Catalog\AppUrlPolicy`, rule `App\Rules\AllowedAppUrl`, model guard on save).
  - Seeded name for `cuentas-por-pagar` is «Cuentas por pagar» (canvas card) — brief §1.1 says «Cuentas por pagar (saldo y antigüedad)», which the description already covers. Camaras' third step reads «Si no aparece, pide ayuda a TI.» because there is no installer yet.
  - Icons are `public/icons/apps/{slug}.svg` / `{slug}-dark.svg`: 48×48 tile with `rx=14`, the 24-px pictogram nested at 26 px (same proportion as the canvas).
- 2026-10-06 T2 (delegated writer; trigger: 2+ non-trivial files): RED `php artisan test tests/Feature/Catalog/AppCatalogTest.php tests/Feature/Catalog/AccessRuleSeederTest.php` → 21 tests, 0 passed (missing classes). GREEN `php artisan test tests/Feature/Catalog` → 39 passed (two test fixes: `fresh()` was inside the counted closure; a rule for a user without company now names a company explicitly). Full `php artisan test` → 393 passed; `migrate:fresh --seed` OK; `pint --test` passed. Commit `2e8b4e2`.
  - `App\Catalog\AppCatalog::visibleApps()` runs 5 queries (company, groups, apps, rules, categories) regardless of catalog size; `canOpen()` uses the same rules.
  - Every rule carries `company_id` (user rules are filed under the user's company); group rules only match users of that company. A user rule matches by user id alone, but the app must still be linked to the user's current company.
  - MySQL/SQLite treat NULLs as distinct in unique indexes, so duplicate `company` rules (null `value`) are prevented by `AppAccessRule::forCompany()` (firstOrCreate), not by the index. The admin editor (T5) must use these helpers.
- 2026-10-06 T3 (delegated writer; trigger: 2+ non-trivial files): RED `php artisan test tests/Unit/Support tests/Feature/Catalog/AppLaunchTest.php tests/Feature/Catalog/FavoriteTest.php tests/Feature/Catalog/RecentAppsTest.php` → 40 tests, 2 passed (only the two 404 cases, trivially, because the routes did not exist). GREEN `php artisan test tests/Unit/Support tests/Feature/Catalog` → 79 passed. Full `php artisan test` → 434 passed; `migrate:fresh --seed` OK; `route:list --path=apps` → `apps.open`; `route:list --path=favoritos` → `favorites.store`, `favorites.destroy`; `pint --test` passed. Commit `84be060`.
  - `GET /apps/{app:slug}` (auth, `throttle:60,1`): 404 without access or when hidden; maintenance → notice, nothing recorded; local → info page + launch; web → launch + 302 to the URL (URL re-checked against the allowed hosts first). Failing apps (`external_status`) still open.
  - **`open_in = new` is honoured by the home card markup (`target="_blank" rel="noopener"`), not by this route** — T4 must do it.
  - `app_launches` stores user, app, company, device (`desktop` | `tablet` | `phone`, `App\Support\DeviceClassifier`) and `launched_at`; no IP and no raw User-Agent (pending user confirmation against brief §5.2 «desde qué dispositivo»). iPadOS 13+ Safari reports a desktop User-Agent and counts as `desktop`.
  - `POST`/`DELETE /favoritos/{app:slug}` → `{favorite: bool}`, 404 for apps the user cannot open. `AppCatalog::recentApps()` (30 days, distinct, newest first, still visible) and `lastOpenedWithin()` (default 30 min, for Soporte TI in Phase 7).
  - Maintenance and local pages are minimal Blade pages on `x-layouts.app` (no Livewire); the Soporte TI button of the canvases arrives in Phase 7.

## Next step
T4 (home UI) on a new branch from `feat/fase-4-3-lanzamiento`: uses `AppCatalog::visibleApps()/recentApps()`, `User::favoriteApps()`, the favorite routes and `apps.open` (cards with `open_in = new` get `target="_blank" rel="noopener"`). Open questions for the user: "all companies" apps, and whether device type without IP/User-Agent is enough for the launch log.
