# Phase 6 — App migration to «Entrar con Microsoft» (guide)

## Objective
A migration guide (`docs/migracion-apps.md`, Spanish, «usted» register like `docs/despliegue.md`) that lets Jhon move each company app to Microsoft sign-in so users opening it from the portal are not asked for a password again.

## Problem / why
Brief §6 and phase 9 item 6. Only Toma Pedidos signs in with Microsoft today; Cuentas por pagar and Cartera (Next.js + Supabase) and Portafolio (plain PHP) use email + password, so the portal card shows «Inicio de sesión propio».

## Decisions (Jhon, 2026-10-06)
- Each app belongs to one company → **single-tenant** registration per app (Toma Pedidos, Cuentas por pagar, Cartera: Segurex; Portafolio: Derivados). No multi-tenant in the apps.
- **Option A:** each app keeps deciding who gets in with its own `usuarios` table and roles. No `GET /api/access/{app}` in the portal; apps do not depend on the portal being up.
- Toma Pedidos is already on Microsoft (single tenant Segurex); only hardening suggested (identify by `entra_object_id` once linked).
- Other app repositories are not modified in this phase; the guide covers them.

## Scope
- T1 guide: Entra registration per app (single tenant, redirect URIs, secrets and expiry, which decision per app), Laravel + Socialite (Toma Pedidos checklist), Next.js + Supabase Auth Azure provider (Cuentas por pagar, Cartera; keeps email-based RLS), plain PHP (Portafolio), linking existing users by email then by object id, cut-over and rollback, marking the app as «Microsoft» in Administración → Aplicaciones.

## Out of scope
Changes inside the app repositories, portal access API (dropped by option A), Phases 7–9.

## Tasks
- [ ] T1 — Write `docs/migracion-apps.md` from the real state of the three app repos and verified provider docs. Route: delegated writer (research + one long doc).

## Acceptance criteria
- Every app in the catalog has a section with concrete steps, the files it touches in its repo, Entra values, how existing users are linked, and how to roll back.
- Claims about Supabase/Entra/Socialite are checked against official docs (URLs cited in the guide).
- No secrets or tenant IDs in the document.

## Checks
Passive documentation: structural readback; links and file paths verified against the repos. `php artisan test` unchanged (no code).

## Delivery
Branch `feat/fase-6-guia-migracion` stacked on `feat/fase-5-6-informe-uso` (`fa802af`). Forecast ≈ 400 lines of documentation.

## Progress
- 2026-10-06: Document created after mapping the app repos (Toma Pedidos `PEDIDOS_COTIZACIONES_SAP/web`, `CUENTAS_POR_PAGAR_WEB`, `PROYECTO_CARTERA_WEB`; Portafolio not available locally).

## Next step
T1.
