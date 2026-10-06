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
- [x] T4 — Home screen UI (responsive per canvas, search, favorites, recents, status chips, empty state, quick links, dark mode); tests. Route: delegated writer (frontend-design).
- [x] T5 — Admin Aplicaciones (list + editor + access rules + maintenance + icon upload + «Todas las empresas activas» flag); tests. Route: delegated writer (frontend-design).
- [x] T6 — Admin Categorías (order, rename, create, delete only when empty); tests. Route: delegated writer.

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
| T4 home model | `feat/fase-4-4-inicio-modelo` | T3 (`e9ea5ad`) | `8e9e912` | +1091 / −6 (tests 523) | committed, not pushed |
| T4 home UI | `feat/fase-4-5-inicio-ui` | T4 model | `90a1ebb` | +786 / −25 excl. build (tests 335) | committed, not pushed |
| T5 all-companies flag | `feat/fase-4-6-admin-apps` | T4 UI (`8f35e2d`) | `50ada19` | +138 / −4 (tests 98) | committed, not pushed |
| T5 apps list + policy | `feat/fase-4-6-admin-apps` | `50ada19` | `5e4eb8a` | +712 / −3 excl. build (tests 242) | committed, not pushed |
| T5 app editor | `feat/fase-4-6-admin-apps` | `5e4eb8a` | `3679a2e` | +1429 / −6 excl. build (tests 492) — over budget, one screen: recommend `size:exception` | committed, not pushed |
| T5 access rules | `feat/fase-4-6-admin-apps` | `3679a2e` | `9c2a925` | ≈ +671 / −6 excl. build and `odd` (tests 247) | committed, not pushed |
| T6 categories | `feat/fase-4-7-admin-categorias` | T5 (`9c2a925`) | this commit (see `git log`) | +683 / −1 excl. build and `odd` (tests 266) | committed, not pushed |

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
- 2026-10-05: Parent spot check on `feat/fase-4-3-lanzamiento` (`55d032d`): `php artisan test` → 434 passed (2248 assertions). Review of `06566f9..55d032d`: assessed medium, consent declined by Jhon. Next review base: `55d032d`.
- 2026-10-05 T4 (delegated writer, frontend-design; trigger: 2+ non-trivial files). Two work-unit commits on `feat/fase-4-4-inicio`.
  - Commit 1 `8e9e912` (view model, server search, favorites redirect): RED `php artisan test tests/Unit/Catalog/AppSearchTest.php tests/Unit/View tests/Feature/Home tests/Feature/Catalog/FavoriteTest.php` → 53 tests, 8 passed (the existing JSON favorite tests), 4 failed (no redirect), 41 errors (missing classes). GREEN → same set 114 passed with `tests/Feature/Catalog`; full suite 479 passed.
  - Commit 2 (controller, Blade UI, `home.js`, build): RED `php artisan test tests/Feature/Home/HomeScreenTest.php` → 18 tests, 4 passed (guest redirect and checks that the placeholder already met), 14 failed. GREEN → full `php artisan test` 497 passed (2453 assertions); `npm run build` → `home-*.js` 2.41 kB / **1.21 kB gzip** (target < 5 kB), `app-*.css` 14.94 kB gzip; `view:cache` OK; `pint --test` passed.
  - `App\View\Home\HomePageBuilder` builds one `HomePage` from `AppCatalog` (views run no queries): favorites (oldest first) taken out of their category; categories by `sort_order`, those without visible apps left out, one emptied by favorites rendered `hidden` so the JS toggle can move the card back; up to 5 recents (`recentApps()` now accepts the already-loaded visible apps); chips by priority maintenance → failing (`external_status` degraded/down, message or «Estamos revisando») → local → own login → active; `target="_blank" rel="noopener"` only for web apps with `open_in = new` outside maintenance. Query count of `GET /` is constant (test: 1 app vs 13 apps with favorites and launches, ≤ 12 queries).
  - Search: `App\Catalog\AppSearch` (ASCII-folded, lowercase, every word must match, 100 chars max). `GET /?q=` filters on the server; `resources/js/home.js` filters the rendered cards with the same rules and announces the count in `#resultados` (`aria-live`). `/` focuses the search unless typing in a field.
  - Favorites: the star is a `<form>` (`_method` DELETE when pinned); without JS `FavoriteController` redirects to `/#app-{slug}` keeping `q`; JSON responses unchanged. With JS: `fetch` with the form's CSRF token, flips `aria-pressed`, moves the card, keeps focus; on error it falls back to the plain form.
  - Microsoft 365 defaults (`App\View\Home\QuickLinks`), checked 2026-10-05 on learn.microsoft.com: Outlook `https://outlook.office365.com/owa/` (learn.microsoft.com/exchange/outlook-web-app-exchange-2013-help: Microsoft 365 users use Outlook on the web at outlook.office365.com/owa); Teams `https://teams.microsoft.com/` (learn.microsoft.com/microsoftteams/teams-client-desktop-admin: "Web access: Use Teams at https://teams.microsoft.com"); OneDrive `https://onedrive.live.com/` (learn.microsoft.com/dynamics365/business-central/admin-onedrive-integration-onpremises: sign in to onedrive.live.com for your organization, which lands on the tenant's `-my.sharepoint.com`). SharePoint only from the company's `quick_links`; a company link named like a default replaces its URL; non-`https` links are skipped.
  - Layout: header with brand mark, search and account menu (`<details>`: Administración for `super_admin`/`portal_admin`, «Cerrar sesión» POST); no «Soporte TI» (Phase 7). Desktop ≥ 1280 px: right column with Microsoft 365, room left for announcements (Phase 5). Phone: 2 columns, fixed bottom bar Inicio · Favoritos · Buscar · Perfil padded with `env(safe-area-inset-bottom)` (new layout prop `viewport-fit`, default off). No Livewire/Alpine on the page (test).
  - Browser check (static render of seeded data, served locally): 1440 px, 768 px and 375 px light, 375 px dark (dark icons swap), empty state, server search, client search («CAMARAS» → 1 resultado, «zzz» → empty message), favorite move with a mocked `fetch`, bottom-bar Perfil opens the menu, no horizontal scroll at 375 px.
  - Deviations from the canvases: search sits above the greeting on phones (one search field for all sizes); the Favoritos section shows a hint when empty (the bottom bar links to it); search results keep the card grid instead of the PhoneSearch list and there are no «Búsquedas recientes»; recents show no «hace 12 min» times; no «Solicitar acceso a TI» button in the empty state (needs `support_url`, Phase 7); the star keeps one label «Marcar {app} como favorita» with `aria-pressed` instead of switching between «Agregar/Quitar».
- 2026-10-05: Parent spot check on `feat/fase-4-5-inicio-ui` (`90a1ebb`): `php artisan test` → 497 passed (2453 assertions). T4 split at its commit boundary. Review of `e9ea5ad..90a1ebb`: assessed high (auth test touched), consent declined by Jhon. Next review base: `90a1ebb`.
- 2026-10-05 T5 (delegated writer, frontend-design; trigger: 2+ non-trivial files). Four work-unit commits on `feat/fase-4-6-admin-apps`.
  - Commit 1 `50ada19` (flag): RED `php artisan test tests/Feature/Catalog/AllCompaniesFlagTest.php` → 7 tests, 1 passed (the «without the flag» case), 1 failed, 5 errors (missing column). GREEN `php artisan test tests/Feature/Catalog tests/Feature/Home` → 115 passed. Migration `2026_10_07_000001` adds `apps.available_to_all_companies` (bool, default false); `AppCatalog::candidates()` accepts flagged apps for any user whose company is active; rules still decide.
  - Commit 2 `5e4eb8a` (policy + list): RED `php artisan test tests/Feature/Admin/AppListTest.php` → 16 tests, 0 passed. GREEN full suite 520 passed (one fix found by the tests: `?categoria[]=x` raised a 500; filters now read plain strings only). `AppPolicy`: `super_admin` + `portal_admin` view/create/update, `delete` always false. `/admin` → companies for `super_admin`, apps for everyone else with an admin role. Filters are GET `q`, `empresa` (company slug), `estado` (`activa`/`fallas`/`mantenimiento`/`oculta`), `categoria` (category slug); unknown values are ignored. Tiles count the whole catalog: published = not hidden; «Con fallas» = active with `external_status` degraded/down (maintenance wins); «Entran con Microsoft» = published web apps. Status wording lives in `App\View\Admin\AppAdminStatus`, rows in `AppAdminRow`.
  - Commit 3 `3679a2e` (editor): RED `php artisan test tests/Feature/Admin/AppEditorTest.php tests/Feature/Admin/AppIconTest.php` → 25 tests, 25 errors. GREEN full suite 545 passed (one test-fixture fix: a fake JPEG's temp file was collected before it was read). `App\Livewire\Admin\AppEditor` on `/admin/aplicaciones/nueva` and `/admin/aplicaciones/{slug}`: slug kebab-case, max 60, unique, `nueva` reserved, suggested from the name until edited, read-only afterwards; local apps force hosting/login `local`, clear the URL and keep `open_in = same`; leaving maintenance clears its message and date. Icons via `App\Catalog\AppIconStore` under `icons/apps/{slug}/` on the public disk; `App::iconUrl()` serves uploads from the disk and bundled icons (`public/icons/apps/*.svg`) with `asset()`, and only uploads are ever deleted. Activity: `App` uses `LogsActivity` (log `apps`, fillable, dirty only) plus named entries `app.status_changed`, `app.companies_changed` (short names), `app.external_status_cleared` (old values).
  - Commit 4 (access rules + responsive fixes + this document): RED `php artisan test tests/Feature/Admin/AppAccessRulesTest.php` → 14 tests, 0 passed. GREEN full suite 559 passed. `App\Livewire\Admin\AppAccessRules` (nested in the editor, edit mode only) saves each rule at once through the `AppAccessRule` helpers; duplicates (group names case-insensitive) are refused with «Esa regla ya existe.»; users are searched (≥ 2 letters, name or email, max 8) only inside the chosen company and a user of another company is refused; group names are free text with a `<datalist>` of distinct `APP-…` groups seen among that company's users; a rule for a company the app is not linked to is flagged «esta regla no aplica». Entries `app.access_rule_added` / `app.access_rule_removed` (type, value — user rules as «name <email>» — and company).
  - Browser check (static render of the seeded catalog, served locally): 1440 px list and editor match the canvases; 375 px list and editor have no horizontal page scroll after two fixes in commit 4 (the table scroller needed `relative` so the «Editar» screen-reader text stays inside it; the editor grids needed `grid-cols-1`). Livewire interactions were exercised by the Livewire tests, not in the browser.
  - Deviations from the canvases: no «Estado reportado» history list and no «vuelve a normal sola en 2 h» line (both arrive with the Phase 8 API); «Abrir la app» links straight to the URL (admins may not have a rule for the app); rules are added from an inline panel (company select + field) instead of popovers; on a new app «Quién la ve» appears after the first save; hosting/login selects only show for web apps.
- 2026-10-05 T6 (delegated writer; trigger: 2+ non-trivial files). One work-unit commit on `feat/fase-4-7-admin-categorias`: RED `php artisan test tests/Feature/Admin/CategoryManagerTest.php` → 16 tests, 16 errors (missing component/route). GREEN full `php artisan test` → 575 passed (2839 assertions) after two test fixes (the activity test now ignores entries written by `setUp`; the expanded-list order is checked on `html()` because Livewire's `assertSeeInOrder` after a call compares the JSON-escaped payload). `vendor/bin/pint --test`, `view:cache` OK; `route:list --path=admin` → 9 routes incl. `admin.apps.*` and `admin.categories.index`.
  - `App\Livewire\Admin\CategoryManager` on `/admin/categorias` (`AppCategoryPolicy`: `super_admin` + `portal_admin`). Moving swaps with the neighbour in the list as shown (collapsed empty categories are skipped) and renumbers `sort_order` 1..n; the home screen follows (test). «Empty» means no app at all, hidden apps included, because the foreign key forbids deleting a category that still has apps. New categories go last with a unique slug (`diseno-2` on collision); renaming keeps the slug (the home anchors use it). Names: required, ≤ 60, unique ignoring case.
  - Activity (log `categories`): `created` / `updated` / `deleted` for name and slug only, plus one `category.moved` entry (old/new position) per move instead of one entry per renumbered row.
  - Browser check (static render): 1440 px matches the canvas; 375 px has no horizontal scroll after letting names wrap on phones (they were truncated to «Ve…»).
  - Deviations from the canvas: a delete button on every row (the canvas shows none; the message explains why non-empty ones cannot go); the canvas shows one empty category on its own and the rest collapsed — here all empty ones are collapsed into one row and «Mostrar» reveals them in place with «Ocultar vacías»; no drag handle behaviour (the dots are decorative; ordering is by the up/down buttons).
- Decisions by parent per brief: «Todas las empresas activas» = per-app flag (T5) so new companies are included automatically (brief §5.2); launches store only the device type (desktop/tablet/phone), no IP or user agent.

## Next step
Phase 4 close-out: parent spot check and review of the T5/T6 slices (`8f35e2d..9c2a925`, `9c2a925..` T6), `php artisan migrate:fresh --seed` on a clean database, then acceptance criteria. Open for Jhon: OneDrive default URL; T4, T5 and T6 design deviations; `size:exception` for the T5 editor commit (`3679a2e`).
