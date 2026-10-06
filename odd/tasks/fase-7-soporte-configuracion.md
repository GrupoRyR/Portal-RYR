# Phase 7 — Soporte TI button and Configuración

## Objective
A «Soporte TI» button on the portal (phone and desktop) that opens the ticket platform with where the user comes from, and an admin «Configuración» screen (docs/diseno/AdminSettings.dc.html) where that URL and the other portal-wide settings are managed.

## Problem / why
Brief §4 (lines 95–96) and phase 9 item 7. The sidebar still shows «Configuración» as «Próximamente»; the support URL has no place to live; allowed app domains, idle timeout and sign-out behaviour are fixed in `config/portal.php` / `.env`.

## Scope
- Settings storage: one portal-wide settings store (DB, cached, audited), with `config/portal.php` / env values as defaults so nothing changes until an admin saves.
- Configuración screen per canvas, super_admin only (`manageSettings` gate, RoleMatrix already says so — verify):
  - Soporte TI: `support_url` (https only, optional), «Así llega el ticket» live example.
  - Sesión: idle timeout 4 / 8 / 24 h; «Al salir, cerrar también Microsoft» on/off.
  - Dominios permitidos para apps: list editable (exact hosts and `sgx-*.vercel.app`-style single-label wildcard as today's `AppUrlPolicy` supports); never remove a domain still used by a published app without warning.
  - Estados reportados por la API: 1 / 2 / 4 h stored now, used by Phase 8 (shown with a note that it applies when Integraciones exists).
  - Respaldo diario: out of scope → card shown as «Próximamente» (no fake data).
- Soporte TI button: home header (desktop) and phone layout per canvas, local-app info page and maintenance page; hidden when `support_url` is empty. Goes through a portal route (`/soporte`) that builds `?origen=portal&empresa={company slug}&app={slug}`; `app` = last app the user opened in the last 30 minutes (from `app_launches`), omitted otherwise; on the local/maintenance page the app shown is used. Existing query string on the configured URL is preserved. No personal data in the URL. Opens in a new tab.

## Out of scope
Database backups (needs a decision on mysqldump/storage on GoDaddy), Integraciones/API (Phase 8), PWA (Phase 9).

## Constraints
Home stays free of Livewire/Alpine; admin uses Livewire 4. Code in English, UI in Spanish. Every settings change in the activity log (Auditoría). Agent cannot touch `.env*`.

## Tasks
- [ ] T1 — Settings store + Configuración screen + apply idle timeout, Microsoft sign-out and allowed domains from it; sidebar link; tests. Route: delegated writer.
- [ ] T2 — Soporte TI button + `/soporte` redirect with origin parameters; tests. Route: delegated writer (same).

## Acceptance criteria
- Empty `support_url` → no button anywhere; set → button on home (phone and desktop), local app page and maintenance page.
- `/soporte` redirects to the configured URL with `origen=portal`, the user's company slug, and the last app opened in the last 30 minutes only; nothing else.
- Only super_admin can open or save Configuración; portal_admin and editors get 403 and see no link.
- Changing idle timeout / sign-out / domains takes effect without editing `.env`, and appears in Auditoría with before/after.
- `php artisan test` passes.

## Delivery
Branch `feat/fase-7-soporte-configuracion` stacked on `feat/fase-6-guia-migracion` (`56b0445`). Forecast ≈ 1200 authored lines incl. tests → one commit per task; `size:exception` or split per task.

## Progress
- 2026-10-06: Document created. Backups left out pending a decision.

## Next step
T1.
