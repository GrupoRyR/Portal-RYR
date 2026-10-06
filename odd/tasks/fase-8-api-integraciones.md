# Phase 8 — API for external systems and Integraciones

## Objective
A small versioned JSON API (`/api/v1`) so IT systems (availability monitors, management tools) can read the app catalog and report each app's status, with one Bearer token per system managed in Administración → Integraciones (docs/diseno/AdminIntegrations.dc.html, AdminTokenNew.dc.html).

## Problem / why
Brief §6b, §7, §8 and phase 9 item 8. `apps.external_status*` columns and the «con fallas» card state exist, but nothing can set them; «Integraciones» is «Próximamente»; the status expiry setting (Phase 7) is stored but unused.

## Decisions
- Option A (Phase 6): no `/api/access/{app}` and no «Apps (validar acceso)» tab — Integraciones lists external systems only.
- If nobody uses the API the portal works exactly the same.

## Scope
- Sanctum (Laravel 13-compatible version) with tokens owned by an integration/system record (not a user); abilities `catalog:read`, `status:write`; optional allowed IPs per token; last used at; revoke; rotate (new secret, old one stops working at once). Token shown once, stored hashed, recognisable prefix (e.g. `ryr_`). Rate limiting per token.
- `GET /api/v1/apps` (`catalog:read`): slug, name, URL, companies, hosting, login type, category, current status (manual + external), updated_at; `?updated_since=` (ISO 8601); no users, no access rules; hidden apps excluded (decide and document).
- `PUT /api/v1/apps/{slug}/status` (`status:write`): body `status` operational|degraded|down, `message` ≤ 140, optional `source`, `reference`. degraded/down → card «con fallas»; operational → normal. Manual maintenance wins (event stored, card unchanged). Idempotent (same status+message → no new change/event noise; define and test). Every report stored in `app_status_events` (app, status, message, source, reference, token/system, date). Expiry: a failing status older than the Configuración TTL (`api_status_ttl_hours`, default 2 h) no longer shows — computed at read time so it does not depend on cron (optionally also a scheduled cleanup).
- Every changing call appears in Auditoría with the system/token name (activity log; replace the disabled «API» type «Próximamente» in Auditoría with real entries).
- Admin Integraciones per canvas: list (name, abilities, masked token `ryr_…xxxx`, last used, status Activo/Revocado), detail (abilities with descriptions, allowed IPs add/remove, latest status changes it made, Rotar, Revocar), create flow → «Token creado» screen with copy button and example request, shown once. Access per `manageIntegrations` gate (check RoleMatrix; keep super_admin + portal_admin unless the matrix says otherwise). Sidebar link enabled.
- Short API doc for IT in `docs/api.md` (Spanish, «usted»): auth header, endpoints, examples with curl, errors, limits.

## Out of scope
App access API, PWA (Phase 9), backups.

## Constraints
JSON errors for API routes (401/403/404/422/429), never HTML or redirects. No personal data in the API. Tokens never logged in plaintext. Agent cannot touch `.env*`. Code in English, UI and docs in Spanish. Home free of Livewire/Alpine.

## Tasks
- [x] T1 — Sanctum + integration tokens (model, abilities, IP allowlist, last used, revoke/rotate) + `GET /api/v1/apps`; tests. Route: delegated writer.
- [x] T2 — `PUT /api/v1/apps/{slug}/status`, `app_status_events`, maintenance precedence, idempotency, TTL at read time, audit entries; tests. Route: delegated writer (same).
- [x] T3 — Admin Integraciones (list, detail, create/«Token creado», rotate, revoke, IPs) + `docs/api.md`; tests. Route: delegated writer (same).
- [x] T4 — Re-authentication with Microsoft before creating or rotating a token (decided by Jhon 2026-10-06): same `App\Auth\RecentMicrosoftLogin` check (10-minute TTL) as creating a company, on «Nuevo token», `create` and `rotate`, re-checked server-side on every call; emergency accounts refused; `docs/api.md` note; tests. Route: delegated writer (one component + its test file).

## Acceptance criteria
- A token without `catalog:read` gets 403 on the catalog; a revoked or rotated-away token gets 401; a token used from a non-allowed IP gets 403.
- A `down` report makes the card «con fallas» with the message; maintenance stays maintenance; repeating the same report creates no new change; after the TTL with no report the card is normal again.
- `?updated_since=` returns only apps changed after that time.
- Every changing API call appears in Auditoría with the system's name.
- `php artisan test` passes.

## Delivery
Branch `feat/fase-8-api-integraciones` stacked on `feat/fase-7-soporte-configuracion` (`72956dc`). Forecast ≈ 1800 authored lines incl. tests → one commit per task; `size:exception` or split per task.

## Implementation decisions (writer)
- `laravel/sanctum` ^4.3 (v4.3.3, Laravel 13). `config/sanctum.php`: no stateful domains, no session guard (an admin cookie never authenticates the API), no `/sanctum/csrf-cookie` route, no expiry, prefix `ryr_`, Sanctum's per-request `last_used_at` write off. No env key needed.
- Token owner `App\Models\Integration` (name, abilities, allowed_ips, token_hint, last_used_at, revoked_at, created_by). One live token per integration. The plaintext shown is only the secret (`ryr_` + 40 random + 8 crc32), without Sanctum's `id|` part (Sanctum finds it by hash), so the prefix is recognisable. Stored: SHA-256 hash + last 4 characters.
- Abilities kept on the integration (source of truth for display, survives revoke) and mirrored on the token; unknown abilities and `*` are dropped.
- Rotate = delete the old token + issue a new one (old one gets 401 at once). Revoke = delete the token + `revoked_at`; a revoked integration stays listed and cannot be rotated.
- IP allowlist: exact IPv4/IPv6 or CIDR; empty list = any address; checked per request after auth (`integration` middleware) → 403 JSON.
- Last use written on the integration at most once a minute (without touching `updated_at`), so it survives rotation and costs at most one write per minute per token.
- Rate limit (`throttle:api`): 120 requests/minute per token; 30/minute per IP for calls reaching the limiter without a valid token.
- JSON errors on `api/*` without `Accept` header (existing `shouldRenderJsonWhen`); 404s on `api/*` answer `{"message":"Not found."}` (no model class names). Messages stay Laravel's English defaults (machine-facing).
- `GET /api/v1/apps`: hidden apps excluded; `updated_since` is inclusive (`>=`), any ISO 8601 offset, converted to Bogotá; 422 when unparseable. Apps for every company list the active companies. No pagination (small catalog). Changing an app's companies now touches `updated_at` (`App::syncCompanies`) so `updated_since` sees it.- `PUT /api/v1/apps/{slug}/status`: form or JSON body; `status` required (operational|degraded|down), `message` ≤ 140, `source`/`reference` ≤ 100, all trimmed (empty → null). Answers `{data: {slug, changed, status}}`. Any existing app may be reported, hidden ones included (hiding only removes it from the home); unknown slug → 404.
- Idempotency: same status + message as the current, unexpired report → `changed: false`, no event, no audit entry, `updated_at` untouched; only `external_status_at` is refreshed so a failure that keeps being reported does not expire. A different message, a different status, or any report after expiry is a change (event row + audit entry + `updated_at`).
- Maintenance precedence is applied when drawing (card, admin list, API `current`); the report is still stored on the app, so a still-fresh failure shows once maintenance ends.
- Expiry at read time everywhere: `App::hasCurrentReport()`/`hasReportedFailure()` and the `reportedFailing`/`notReportedFailing` scopes use `api_status_ttl_hours` (cutoff exclusive). A report without `external_status_at` counts as expired. The API shows `reported: null` once expired. No scheduled cleanup (not needed). Existing tests that set a failing status without a time now set `external_status_at`.
- Audit: activity log `api` (event `api.status_reported`, causer = the integration, subject = the app, old/new status and message, source/reference; never the token). Auditoría «API» type is now a real filter (log `api` only; «Cambios» excludes it); rows show the system's name; the text filter also matches system names. `AuditFilters::TYPE_API`, `AuditEntry::CATEGORY_API`.
- App editor «Estado reportado»: hint when a failure has expired and the last 5 reports (status, message, system, when) — brief §5.2 «se ve en la ficha de la app con su historial». Configuración note now: «Si un sistema deja de reportar una app con fallas, la tarjeta vuelve a verse normal después de este tiempo.»
- Integraciones (`/admin/integraciones`, `can:manageIntegrations` = super_admin only, unchanged; Livewire `IntegrationManager` re-authorizes every action): list (name, masked `ryr_…xxxx`, abilities, last use or «Nunca», Activo/Revocado; revoked last), detail by `?sistema={id}` (creator and date, abilities as checkboxes editable on the live token, allowed IPs add/remove, last 5 status changes, Rotar/Revocar with confirmation). The «Apps (validar acceso)» tab is omitted (option A); the single «Sistemas externos · N» heading keeps the tab look.
- The design has no create form: «Nuevo token» opens a panel in place of the detail (name ≤ 80, abilities, optional IPs separated by commas). Create and rotate show the «Token creado»/«Token nuevo» dialog of AdminTokenNew (copy button, example request, «Ya lo guardé…» enables «Listo»). The plaintext only lives in the Livewire component state until «Listo»; it is never stored or logged.
- Token management is audited in the `api` log with the admin as causer and the integration as subject: `integration.created` (name, abilities, IPs), `rotated`, `revoked`, `abilities_changed`, `ips_changed` (before/after). Auditoría labels: «Creó el token de …», «Permisos», «IP permitidas».
- Sidebar: «Integraciones» is a real link for super admins and hidden for everyone else (like Empresas); the unused `soon()` helper was removed. RoleMatrix row no longer «Próximamente».
- ~~No Microsoft re-authentication is required to create/rotate tokens~~ — superseded by T4: «Nuevo token» (`startCreating`), `create` and `rotate` need a Microsoft sign-in confirmed within `RecentMicrosoftLogin::TTL_SECONDS`, checked on every call (`IntegrationManager::hasRecentLogin`). Without it the component redirects to `auth.microsoft.confirm` with `return=/admin/integraciones` (plus `?sistema={id}` when a system is selected). The form only opens after the confirmation (like the company wizard, whose page is behind `microsoft.recent`), so what the admin types is not lost; if the 10 minutes run out while the form is open, `create` asks again (same as the wizard's `saveCompany`). Emergency accounts (or users without `azure_oid`) get 403 with `IntegrationManager::EMERGENCY_MESSAGE` («Crear o rotar tokens requiere iniciar sesión con Microsoft.»); the shared `RecentMicrosoftLogin::EMERGENCY_MESSAGE` text speaks only of companies. Revoke, abilities and IPs stay one click without re-authentication: they only remove or narrow access, and revoking a leaked token must not wait for Microsoft (nor be impossible for the emergency account).
- `docs/api.md` (Spanish, usted): token, endpoints with curl, fields, idempotency, expiry, errors, limits, FAQ.
- Overflow fix found during the render check: the Auditoría type radios (now five) leaked an `sr-only` input past 375 px; labels are now `relative`.

## Progress
- 2026-10-06: Document created. Jhon confirmed Configuración is super_admin only (Phase 7).
- T1 (route: delegated writer): RED 20 of 21 new tests failing in `tests/Feature/Api` (no `Integration`, no API routes; the unknown-route JSON 404 already passed). GREEN 21/21; full suite 805 passed; pint clean; `route:list --path=api` → `GET api/v1/apps`.
- T2 (route: delegated writer): RED 15 failing (`StatusApiTest` 404s, no `StatusReporter`, Auditoría still «API Próximamente»; then the expired-report hint and the Configuración note). GREEN: full suite 821 passed; pint clean; `route:list --path=api` → `GET api/v1/apps`, `PUT api/v1/apps/{app}/status`.
- T3 (route: delegated writer): RED 15 failing (`IntegrationsPageTest` 13: no route/component; `AdminAccessTest` sidebar link; `RoleMatrixTest` «Próximamente»). GREEN: full suite 834 passed; pint clean; `npm run build` (public/build updated); `route:list --path=admin/integraciones` → `GET admin/integraciones`; `--path=api` → 2 routes. Static render (in-memory SQLite, seeded systems, events, «Token creado» dialog) of Integraciones, Integraciones with detail, Auditoría `tipo=api` and an app editor: no horizontal overflow at 1440 px and 375 px after the two fixes (Auditoría radios, token input height on mobile).
- T4 (route: delegated writer): RED 5 failing in `IntegrationsPageTest` (create/rotate without a recent confirmation not redirected, token created/rotated anyway; emergency account not refused; `EMERGENCY_MESSAGE` undefined). GREEN 18/18 in `IntegrationsPageTest`; full suite 839 passed (4121 assertions); pint clean. No view change, no `npm run build`. Choice: revoke stays without re-authentication (see Implementation decisions).

## Open questions for Jhon
- ~~Should creating or rotating a token require a fresh Microsoft sign-in?~~ Yes (Jhon, 2026-10-06) → T4.
- ~~API status reports are accepted for hidden apps; the catalog leaves hidden apps out. Confirm.~~ Answered 2026-10-06: Jhon decided hidden apps reject reports (404 like unknown apps), branch `feat/api-sin-ocultas`.
- Pending production steps: `composer install` (adds `laravel/sanctum`), `php artisan migrate` (tables `integrations`, `personal_access_tokens`, `app_status_events`), deploy `public/build`. No `.env` key is needed. `public/.htaccess` already forwards the `Authorization` header.

- 2026-10-06: Parent spot check on `cd508f8`: `php artisan test` → 834 passed (4097 assertions); tree clean. Phase 8 closed. To confirm with Jhon: fresh Microsoft sign-in for creating/rotating tokens; status reports accepted for hidden apps. Production: `composer install`, `php artisan migrate`, deploy `public/build`.

## Next step
Phase 9 (PWA, device testing, admin manual) when Jhon asks for it.
