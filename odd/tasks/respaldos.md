# Daily database backups

## Objective
A nightly backup of the portal's data (MySQL database + uploaded files on the public disk) kept on the same GoDaddy server, with failure alerts and the «Respaldo diario» card in Configuración working.

## Problem / why
Brief §7 («Respaldo diario de la base de datos (`spatie/laravel-backup`)»). Phase 7 left the card as «Próximamente». Without backups, a bad deploy or a mistaken delete loses companies, catalog, access rules, audit and usage history.

## Decision (Jhon, 2026-10-06)
Option 1: copies only on the same server (outside the web root). Off-site copy (SharePoint/OneDrive) may come later.

## Scope
- `spatie/laravel-backup` (version compatible with Laravel 13 / PHP 8.3; verify), configured for the default DB connection + `storage/app/public` (brand files, announcement images); excludes vendor, node_modules, code, `.env`.
- Destination: a local disk under `storage/app/private/backups` (never web-reachable).
- Schedule in `routes/console.php`: `backup:clean` then `backup:run` nightly (Bogotá time, e.g. 02:00 / 02:30), `backup:monitor` daily; works with the existing `schedule:run` cron (no queue worker on GoDaddy).
- Retention: daily for 7 days, weekly for 4 weeks; size cap sensible for shared hosting.
- Alerts: failures (and unhealthy monitor) by mail to the super admin emails already in config (`PORTAL_SUPER_ADMIN_EMAILS`), using the existing mail setup; no Slack.
- Configuración card: last backup date/time, size, status (correcto / con error / nunca), count kept; «Respaldar ahora» for super_admin — must not run a long dump inside the web request on shared hosting: request a backup that the next `schedule:run` minute executes (flag), show «Programado» until done; rate-limited; audited.
- `mysqldump` path configurable via config default (GoDaddy usually `/usr/bin/mysqldump`); SQLite in local/tests.
- Docs: `docs/despliegue.md` (check mysqldump, first manual run, where files are, how to restore step by step, how to download a copy) and `docs/manual-administracion.md` (the card).

## Out of scope
Off-site copies, automatic restore from the UI.

## Constraints
Agent cannot touch `.env*` (defaults in config; report any env key needed). Code in English, UI/docs in Spanish. No queue worker.

## Tasks
- [ ] T1 — Package, config, schedule, alerts, «Respaldar ahora» via cron flag, Configuración card, tests, docs. Route: delegated writer.

## Acceptance criteria
- `php artisan backup:run` creates a zip with the DB dump and public files under `storage/app/private/backups` (verified locally with SQLite).
- The schedule lists clean/run/monitor at the planned times; retention matches.
- The card shows real data (never invented); «Respaldar ahora» results in a backup at the next scheduler minute.
- `php artisan test` passes.

## Delivery
Branch `feat/respaldos` stacked on `feat/fase-9-pwa-manual` (`17c7f25`) → PR #42 to `feat/fase-9-pwa-manual`.

## Progress
- 2026-10-06: Document created after Jhon chose option 1.

## Next step
T1.
