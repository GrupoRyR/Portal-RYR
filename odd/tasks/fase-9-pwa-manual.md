# Phase 9 — PWA, device checks and administration manual

## Objective
The portal installs as an app on phone, tablet and desktop, shows the user's cards instantly from cache on slow connections with a clear offline notice, meets the brief's mobile performance/accessibility goals, and comes with an administration manual.

## Problem / why
Brief §2 (mobile-first, PWA), §4b «Celular y tablet» (install, splash with logo, standalone, cached catalog, offline notice, dark mode, user font size, Lighthouse ≥ 90 performance and accessibility on mobile, < 2 s on 4G), §9 item 9.

## Scope
- Web app manifest served per signed-in user's company (name/short name, theme/background colors from the brand palette, icons from the company's brand icon with 192/512 PNG + maskable; fallback to a portal icon), `display: standalone`, `start_url` `/`, scope `/`. Manifest link + `theme-color` (light/dark) + Apple touch icon/meta on the public layouts (home, sign-in, announcements, app pages). Admin pages need not be cached.
- Service worker (vanilla, no framework, no Workbox build step unless already trivial): pre-cache the versioned `public/build` assets for home; network-first for the home document with a cached fallback so the cards show instantly/offline; never cache API, admin, auth, `/apps/{slug}` launches, `/soporte`, POSTs or Livewire; offline page / notice «Sin conexión…» when nothing is cached; versioned cache names, old caches removed on activate.
- Privacy on shared devices: cached pages are per user — sign-out clears them (e.g. `Clear-Site-Data: "cache", "storage"` on logout response plus SW cache purge message), and the SW never serves a cached page to a different signed-in user (key or check by user).
- Home: offline banner when `navigator.onLine` is false or the page came from cache; launching an app while offline shows the notice instead of a broken page.
- Checks: Lighthouse mobile (performance, accessibility, best practices, PWA installability) on home and sign-in with a locally served production build; fix what blocks ≥ 90. Emulated phone (375), tablet (768/1024) and desktop; dark mode; 200 % font size; 44 × 44 touch targets.
- `docs/manual-administracion.md` (Spanish, «usted», like `docs/despliegue.md`): every admin screen (Empresas + asistente, Aplicaciones + reglas, Categorías, Anuncios, Roles, Integraciones, Auditoría, Informe de uso, Configuración), emergency account, common tasks (add an app, give access by group, put an app in maintenance, rotate a token, a person cannot see an app), and a real-device test checklist (Android Chrome, iPhone Safari, tablet) for Jhon.

## Out of scope
Push notifications, background sync, backups, real-device testing itself (Jhon runs the checklist).

## Constraints
Home stays free of Livewire/Alpine. No new heavy JS libraries. Code in English, UI/docs in Spanish. Agent cannot touch `.env*`. HTTPS only in production (SW requires it; localhost works for checks).

## Tasks
- [ ] T1 — Manifest per company + icons (192/512/maskable from brand icon, cached files) + meta tags; tests. Route: delegated writer.
- [ ] T2 — Service worker + offline notice + per-user cache privacy (sign-out purge); tests where deterministic (headers, routes, manifest, SW served with correct scope/headers) + browser check. Route: delegated writer (same).
- [ ] T3 — Lighthouse/device-emulation pass and fixes. Route: delegated writer (same).
- [ ] T4 — `docs/manual-administracion.md` with the device test checklist. Route: delegated writer (same).

## Acceptance criteria
- Chrome reports the portal installable; installed it opens standalone with the company's icon and colors.
- With the network off, a user who opened home before sees their cards and the offline notice; after signing out, nothing of theirs is served.
- Lighthouse mobile ≥ 90 performance and accessibility on home and sign-in (local production build), or the gap is explained.
- `php artisan test` passes.

## Delivery
Branch `feat/fase-9-pwa-manual` stacked on `feat/fase-8-api-integraciones` (`f3f0587`). Forecast ≈ 1200 authored lines incl. docs → one commit per task.

## Progress
- 2026-10-06: Document created. Phase 8 T4 (re-auth for token create/rotate) committed as `f3f0587`.

## Next step
T1.
