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
- [x] T1 — Package, config, schedule, alerts, «Respaldar ahora» via cron flag, Configuración card, tests, docs. Route: delegated writer (writer trigger: 2+ non-trivial files). Commits `fefec3c` (deps), `d049b36` (feature).

## Acceptance criteria
- `php artisan backup:run` creates a zip with the DB dump and public files under `storage/app/private/backups` (verified locally with SQLite).
- The schedule lists clean/run/monitor at the planned times; retention matches.
- The card shows real data (never invented); «Respaldar ahora» results in a backup at the next scheduler minute.
- `php artisan test` passes.

## Delivery
Branch `feat/respaldos` stacked on `feat/fase-9-pwa-manual` (`17c7f25`) → PR #42 to `feat/fase-9-pwa-manual`.

## Decisions (T1)
- `spatie/laravel-backup` 10.3.3 (requires PHP ^8.3, illuminate ^12.40|^13.0).
- Backup name/folder `portal-ryr` (independent of APP_NAME); disk `backups` = `storage/app/private/backups`, `serve: false`.
- Zip: DB dump (plain `.sql`, `db-dumps/mysql-<db>.sql`) + `storage/app/public`, paths relative to the project root; not encrypted (same server, outside web root); `verify_backup` on.
- Retention: keep all 7 days, then weekly 4 weeks, nothing older; cap 2000 MB (oldest deleted first). Monitor: newest < 1 day, total < 2500 MB.
- Alerts: only failures (backup, cleanup, unhealthy) by mail via `App\Backups\BackupNotifiable` → `config('portal.super_admin_emails')`; successes not mailed; Slack/Discord/webhook empty.
- Schedule (America/Bogota, withoutOverlapping, no onOneServer — single host): `backup:clean` 02:00, `backup:run --isolated` 02:30, `backup:monitor` 08:00, `portal:run-requested-backup` every minute.
- Store: table `backup_runs` (status pending/running/succeeded/failed, requested_by/at, started_at, finished_at). Package events `BackupWasSuccessful`/`BackupHasFailed` complete the running request or add a row for nightly/CLI runs. DB instead of cache so `cache:clear` never loses the state.
- «Respaldar ahora»: super_admin (re-authorized `manageSettings`), one unfinished request at a time, max 3 per hour (global limiter), audited in log `backups` («Pidió un respaldo inmediato de la base de datos», category Cambio). Consumer runs `backup:run --isolated=75`; if the nightly run holds the lock the request stays pending; a run stuck «running» > 2 h is marked failed.
- Card: «Último respaldo: D MMM YYYY, HH:mm · correcto» / «nunca», size + copies kept, failure line with date, «Programado…»/«En curso…» with 15 s polling while busy.
- Env key (production, only if needed): `DB_DUMP_BINARY_PATH` = folder of `mysqldump` (default `/usr/bin`).

## Progress
- 2026-10-06: Document created after Jhon chose option 1.
- 2026-10-06: T1 done. RED: 40 tests, 15 passed / 25 failing or erroring (missing disk, config, model, command, card). GREEN: `php artisan test` 888 passed (864 baseline + 24 new), `vendor/bin/pint --test` passed, `npm run build` produced no change in `public/build`.
- `php artisan schedule:list`: `* * * * * scheduler-heartbeat`, `0 2 * * * backup:clean`, `30 2 * * * backup:run --isolated`, `0 8 * * * backup:monitor`, `* * * * * portal:run-requested-backup`.
- Manual local run (SQLite): plain `php artisan backup:run` fails because Windows has no `sqlite3` CLI (recorded as failed in `backup_runs`); with the PHP dumper of the tests (`Tests\Support\PhpSqliteDumper`, via tinker) it completed: zip 3.39 KB with `db-dumps/…sql` + `storage/app/public`, verified, copied to disk `backups`; `backup:list` → `portal-ryr | backups | ✅ | ✅ | 1 | 3.39 KB`. Local files and rows removed afterwards. The automated test `BackupRunTest` does the same real run in a temp dir.
- Commits: `fefec3c` build(deps), `d049b36` feat(backups). ~1000 authored lines over the 400 heuristic: config, card, consumer and restore docs are one behavior; kept together with their tests.

## Next step
Push and open PR #42. In production: `which mysqldump` (set `DB_DUMP_BINARY_PATH` if not `/usr/bin`), first `php artisan backup:run` (docs/despliegue.md 1.12).
