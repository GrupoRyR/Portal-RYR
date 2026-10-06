# Phase 5 — Announcements, roles, audit and usage report

## Objective
Admins can publish announcements that appear on the home screen of the right companies, see and assign portal roles, read a complete audit trail (configuration changes, app launches, security events) and a usage report built from real data.

## Problem / why
Phases 1–4 delivered identity, companies and the catalog with the home screen. The brief's remaining administration areas (§4 «Anuncios», §5.2 roles, «Registro de auditoría», «Informe de uso») are still «Próximamente» in the sidebar. Brief phase 9 list item 5.

## Scope
- Announcements domain, home display (desktop aside, tablet/phone banner) and administration (list + editor).
- Roles screen: permission matrix, members per role, announcements editors management, emergency account status.
- Audit screen over `activity_log` + `app_launches` (+ security events), filters, before/after diff, CSV export.
- Usage report from `app_launches` and users (7/30/90 days), with a table view.

## Out of scope
Configuración screen and Soporte TI (Phase 7), Integraciones and API events (Phase 8), PWA (Phase 9).

## Constraints
- Roles: `super_admin` (config emails), `portal_admin` (from `APP-PortalAdmin`), `announcements_editor` (assigned by hand; portal admins may assign it — decision 2026-10-05).
- Identity is (`tenant_id`, `azure_oid`); never look users up by email alone.
- Home stays free of Livewire/Alpine; admin uses Livewire 4.
- Charts follow the dataviz skill; single-series charts; numbers always available as a table.
- Personal data minimisation: launches keep only the device type.
- Code in English, UI in Spanish. Agent cannot touch `.env*`.

## Tasks
- [x] T1 — Announcements domain + home display: table, audience (all companies / chosen companies), window (`starts_at`/`ends_at`), pinned, image (stored as WebP), withdraw; visible-announcements query; home aside/banner per canvas; activity log; tests. Route: delegated writer.
- [x] T2 — Admin Anuncios: list (status tabs Publicados / Programados / Vencidos / Retirados, company filter) + Livewire editor with live preview (desktop card / phone banner); `announcements_editor` limited to their own company's audience, admins any; tests. Route: delegated writer (same as T1).
- [x] T3 — Admin Roles per canvas: permission matrix, members per role, add/remove announcements editors, emergency account status card; tests. Route: delegated writer.
- [ ] T4 — Admin Auditoría per canvas: unified event list, filters (person/app/token search, type, company, period), expanded before/after diff, CSV export (streamed), Microsoft and emergency sign-ins recorded as security events; tests. Route: delegated writer.
- [ ] T5 — Admin Informe de uso per canvas from real data: active users per company, device split, most-opened apps, apps without use in 90 days, 7/30/90-day toggle, table view; tests. Route: delegated writer (dataviz).

## Acceptance criteria
- An announcement for Derivados never shows to a Segurex user; one for all companies shows to both; outside its window it shows to nobody; pinned ones come first.
- An announcements editor cannot publish to another company and cannot reach other admin sections.
- Every admin change and every app launch appears in Auditoría with who, what, when and from where (device type).
- The usage report numbers match the underlying rows for the chosen period.
- `php artisan test` passes.

## Delivery
Strategy `ask-on-risk`, chain strategy **stacked-to-main** (cached). First slice branches from `feat/fase-4-10-cierre`. Forecast ≈ 3000 authored lines incl. tests → slice per task/commit.

| Slice | Branch | Base | Holds | Budget |
|---|---|---|---|---|
| 1 | `feat/fase-5-1-anuncios` | `4b782c4` (plan) | T1 domain + images (`897ecea`) | +1062 (413 tests) → `size:exception` |
| 2 | `feat/fase-5-2-anuncios-inicio` | slice 1 | T1 home display + /anuncios (`2a6a118`) | +465 −3 (167 tests) |
| 3 | `feat/fase-5-3-admin-anuncios` | slice 2 | T2 list + access (`1b47fb7`) and editor (`48f6590`) — kept together: `1b47fb7` alone has a dead «Nuevo anuncio» link | +1701 −29 (≈840 tests) → `size:exception` |
| 4 | `feat/fase-5-4-roles` | slice 3 | T3 roles page (one commit) | +982 −1 (404 tests) → `size:exception` |

## Progress
- 2026-10-05: Document created; remaining admin design references copied to `docs/diseno/`.
- 2026-10-06: T1 done (route: delegated writer, mapping + 2+ non-trivial files).
  - Domain (`897ecea`): `announcements` + `announcement_company`; `Announcement` scopes `visibleTo`, `forCompany`, `inStatus`, `ordered`; `status` accessor (programado/publicado/vencido/retirado); `withdraw()`/`republish()` with named audit entries; created/updated logged dirty-only with causer.
  - Images: `App\Announcements\AnnouncementImageStore` (PNG/JPEG/WebP ≤ 2 MB, ≤ 40 MP, GD → 16:9 center-crop WebP ≤ 1280×720, EXIF rotation for JPEG, SVG refused). `AnnouncementPublisher` saves companies + image in one transaction; new file deleted on failure, replaced file deleted only after commit.
  - Home: up to 3 in the desktop aside card (≥1280 px) with «Ver todos» → `/anuncios` (auth, 10 per page, no Livewire); phone/tablet banner with the top one → `/anuncios#a-{id}`; nothing rendered without announcements. One extra query on home, constant with the number of announcements. Shared partials `x-announcements.card` / `x-announcements.banner` (reused by the T2 preview).
  - RED: 36/36 new tests errored (missing model/store/route). GREEN: `php artisan test` → 612 passed (576 before).
- 2026-10-06: T2 done (route: delegated writer, same as T1).
  - `AnnouncementPolicy`: super/portal admins manage all; `announcements_editor` creates and edits only announcements whose audience is exactly their own company; `publishTo` re-checked on every save (crafted Livewire requests get 403); nobody deletes. `Announcement::manageableBy()` scopes the list the same way.
  - Access (`1b47fb7`): `/admin` group admits `announcements_editor`; landing companies → apps → announcements; editors see only «Anuncios» in the sidebar (other sections left out) and get «Administración» on the home menu. Existing admin tests kept green (`test_announcement_editors_are_forbidden` became `..._land_on_the_announcements`).
  - List `/admin/anuncios`: tabs Todos/Publicados/Programados/Vencidos/Retirados with counts, company filter (`?empresa=`, admins only), rows with thumbnail, pin, audience · dates · author, status chip; new `status-scheduled` color tokens.
  - Editor `/admin/anuncios/nuevo` and `/admin/anuncios/{id}` (`App\Livewire\Admin\AnnouncementEditor`): title, body with live «n / 280», image upload/remove, «Para quién», Desde/Hasta (`datetime-local`, Hasta after Desde), «Fijar arriba», Retirar (wire:confirm) / Volver a publicar, live preview Escritorio/Celular using `x-announcements.card` and `x-announcements.banner` (banner now sized by container query so the phone preview looks like a phone).
  - RED: 34/39 new tests failed or errored (5 guards on existing 403s already passed). GREEN: `php artisan test` → 651 passed.

- 2026-10-06: Parent spot check on `feat/fase-5-3-admin-anuncios` (`48f6590`): `php artisan test` → 651 passed (3196 assertions). Jhon dismissed the review prompt and then disabled receipt-driven development globally (`gentle-ai review mode disable` → off). From now on: no review assessment or consent prompts; ordinary checks only.

- 2026-10-06: T3 done (route: delegated writer, same as T1/T2).
  - `App\Admin\RoleMatrix` is the single source of «Qué puede hacer cada rol»: each row carries its grants and the real check (`CompanyPolicy`, `AppPolicy`, `AppCategoryPolicy`, `AnnouncementPolicy`, the new `RolePolicy` on spatie `Role`, and new gates `viewActivity` / `manageIntegrations` / `manageSettings` in `App\Policies\AdminSections`). `RoleMatrixTest` asserts every cell against the check for each role, so the table cannot drift. Integraciones and Configuración show «Próximamente».
  - `/admin/roles` (`App\Livewire\Admin\RoleManager`): super_admin and portal_admin only; members per role (super admins read-only with the configured-emails note, portal admins read-only «Se asigna solo… APP-PortalAdmin»), editors with add (search name/email, ≥ 2 chars, ≤ 8 results, never emergency accounts or current editors, `%`/`_` literal) and remove; every action re-authorized (`RolePolicy::assignEditor` / `removeEditor`), crafted calls by an editor get 403. Activity log `roles`: `role.assigned` / `role.removed` with causer, subject user and `role`.
  - Emergency account card: exists or not, 2FA confirmed or not, last use or «nunca usada», hint `php artisan portal:emergency-account`; no secrets, no actions.
  - Design deviations: «Roles» row reads «Solo editores» for both admin roles (both can only manage editors; the other roles are automatic); «Anuncios» reads «Solo de su empresa» for editors.
  - RED: 23/23 new tests errored (missing class/route/component). GREEN: `php artisan test` → 674 passed.

## Next step
T4 (Auditoría), then T5 (Informe de uso) — same delegated writer, stacked slices.
