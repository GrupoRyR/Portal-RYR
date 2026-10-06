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
- [ ] T2 — `PUT /api/v1/apps/{slug}/status`, `app_status_events`, maintenance precedence, idempotency, TTL at read time, audit entries; tests. Route: delegated writer (same).
- [ ] T3 — Admin Integraciones (list, detail, create/«Token creado», rotate, revoke, IPs) + `docs/api.md`; tests. Route: delegated writer (same).

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
- `GET /api/v1/apps`: hidden apps excluded; `updated_since` is inclusive (`>=`), any ISO 8601 offset, converted to Bogotá; 422 when unparseable. Apps for every company list the active companies. No pagination (small catalog). Changing an app's companies now touches `updated_at` (`App::syncCompanies`) so `updated_since` sees it.

## Progress
- 2026-10-06: Document created. Jhon confirmed Configuración is super_admin only (Phase 7).
- T1 (route: delegated writer): RED 20 of 21 new tests failing in `tests/Feature/Api` (no `Integration`, no API routes; the unknown-route JSON 404 already passed). GREEN 21/21; full suite 805 passed; pint clean; `route:list --path=api` → `GET api/v1/apps`.

## Next step
T2.
