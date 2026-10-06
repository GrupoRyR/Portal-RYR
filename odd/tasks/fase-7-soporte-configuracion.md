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
- [x] T1 — Settings store + Configuración screen + apply idle timeout, Microsoft sign-out and allowed domains from it; sidebar link; tests. Route: delegated writer.
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
- 2026-10-06: **T1 done** (route: delegated writer).
  - Store: `App\Settings\PortalSettings` (singleton) over a key/value table `portal_settings` (`key` primary, JSON `value`). Chosen over a single-row table because a key that was never saved simply falls back to `config/portal.php` / `session.lifetime` (behaviour unchanged until a super admin saves) and later phases add settings without a migration. Saved values are cached forever under `portal.settings` and forgotten on save; only changed keys are written; one `settings` activity entry per save with `old`/`attributes` (Auditoría: «Cambio · Cambió la configuración del portal», Spanish field labels in `AuditPresenter`). New config defaults: `portal.support_url` (`PORTAL_SUPPORT_URL`, empty), `portal.microsoft_logout` (false = current behaviour), `portal.api_status_ttl_hours` (2, brief §6b).
  - Screen: `/admin/configuracion` (`admin.settings.index`), Livewire `App\Livewire\Admin\SettingsEditor`; `manageSettings` is now super_admin only (was super_admin + portal_admin); RoleMatrix row updated and no longer «Próximamente»; sidebar shows the link only to super admins (the group is dropped otherwise). Fields: support URL (https only, no user info, ≤ 500 chars) with the live «Así llega el ticket» example built by `App\Support\SupportLink`; idle timeout 4/8/24 h plus the server lifetime when it is not one of them («2 horas (valor actual del servidor)»); Microsoft sign-out switch; domain list (add/remove in the form, applied on save; syntax `AppUrlPolicy::isValidPattern`; refuses removing a domain still used by a non-hidden web app, naming the apps, re-checked on save; never empty); API status expiry 1/2/4 h (stored, note: applies with Integraciones); «Respaldo diario» card with «Próximamente», no data. Mount and every action re-authorized.
  - Applied at runtime: idle timeout through `App\Settings\ApplySessionLifetime` (listener of `RouteMatched`, so it runs before the `web` group starts the session; sets `session.lifetime`, which the session driver uses to expire idle sessions and for the cookie). Registered in `AppServiceProvider` because `bootstrap/app.php` was outside the allowed edit surface. Microsoft sign-out in `LogoutController` (Microsoft users only; `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/logout?post_logout_redirect_uri={login}`). Allowed domains: `AppUrlPolicy::current()` reads the settings (replaces `fromConfig()`), used by `App::urlIsAllowed`, `AllowedAppUrl` and `AppLaunchController`.
  - RED: `php artisan test` on the new/changed tests → 79 tests, 30 passed, 3 failed, 46 errors (missing classes/route, matrix grants). GREEN: same set 88/88; full suite **769 passed** (3651 assertions); `vendor/bin/pint --test` passed; `npm run build` (new utility classes).
  - Idle-timeout proof: `AppliedSettingsTest` uses the database session driver with a stored signed-in session idle for 5 h: redirected to sign-in by default (120 min) and with 4 h, kept with 8 h; the session cookie lasts the saved 24 h.
  - Browser check (static render): 1440 px two columns as in the canvas (546 px cards, 248 px sidebar, «Configuración» current); 375 px one column, no horizontal scroll.
  - Design deviations: the add-domain control is an always-visible field plus «+ Agregar» (the canvas only shows the dashed button); each domain chip has a «×» remove button; the backup card shows «Próximamente» and «Aún no está disponible desde el portal.» instead of a last-backup line and «Respaldar ahora».

## Next step
T2.
