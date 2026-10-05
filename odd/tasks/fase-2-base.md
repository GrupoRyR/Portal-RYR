# Phase 2 — Base project (Laravel 13, cPanel deploy, cron)

## Objective
Stand up the Laravel 13 base of the RYR application portal so it runs locally and can be deployed to GoDaddy cPanel (`apps.segurexlatam.net`) over Git + SSH, with the cPanel cron driving the Laravel scheduler.

## Problem / why
Phase 1 (design) is approved. Every later phase (multi-tenant login, catalog, admin, API) needs a deployable, tested base first. The spec says Laravel 11, but 11 lost security support on 2026-03-12; Laravel 13 (PHP 8.3–8.5, security until Q1 2028) was approved on 2026-10-05.

## Scope
- Git repository, `.gitignore`, feature branch.
- Laravel 13 skeleton at the repository root, PHPUnit.
- Base configuration: Spanish locale, `America/Bogota`, app name, HTTPS in production, database-backed session/cache/queue (no Redis, no long-running processes).
- `.env.example` for local (SQLite) and production (MySQL 8, utf8mb4).
- Scheduler heartbeat task proving the cPanel cron runs `schedule:run`.
- Deployment: compiled Vite assets shipped in the repo, server deploy script, deployment guide (subdomain document root, `.env`, cron line, SSL).

## Out of scope
Login, companies, catalog, UI screens (Phase 3+). Actual server changes on GoDaddy (needs explicit authorization for remote work).

## Constraints
- Shared hosting: no Redis, no supervisor, no websockets, no Node on the server.
- Secrets only in `.env`, never committed.
- Code/identifiers in English; user-facing text in Spanish.

## Tasks
- [x] T1 — Git repo + Laravel 13 skeleton at repo root; tests green. Route: inline (mechanical commands). Evidence: Laravel 13.34.0, `php artisan test` 2/2 passed, commit `38ce619` on `feat/fase-2-base`. Review: assessed high (config/auth.php), consent declined by user.
- [x] T2 — Base config + `.env.example` + HTTPS/proxy + database drivers; tests. Route: delegated writer (2+ non-trivial files). `.env.example` applied and committed by Jhon (`5850c04`); review of that change declined by user. Code done (config defaults, `URL::forceScheme('https')` in production, loopback-only trusted proxies, tests). PENDING: `.env.example` and local `.env` could not be written (agent permission rules deny `.env*`); proposed template handed to parent.
- [x] T3 — Scheduler heartbeat + test. Route: delegated writer (same writer as T2). Evidence: see Progress.
- [x] T4 — Deployment: committed `public/build`, `deploy/deploy.sh`, `docs/despliegue.md`. Route: delegated writer (same writer). Evidence: see Progress.

## Acceptance criteria
- `php artisan test` passes.
- `php artisan schedule:list` shows the heartbeat task every minute.
- `GET /up` returns 200.
- `npm run build` output is committed and served without Node on the server.
- Deployment guide lets Jhon deploy by SSH with no extra decisions.

## Checks
- `php ~/tools/composer/composer.phar validate`
- `php artisan test`
- `php artisan schedule:list`
- `npm run build`

## Delivery
Strategy: `ask-on-risk`. Forecast: skeleton is generated code (excluded); authored lines ≈ 250–350.

## Progress
- 2026-10-05: Composer 2 downloaded to `~/tools/composer/composer.phar`, SHA-256 verified against getcomposer.org.

- 2026-10-05: T1 done. Skeleton ships `CLAUDE.md`/`AGENTS.md` (Laravel Boost) asking agents to run a remote PHP 8.5 installer; not followed, kept untracked and excluded locally via `.git/info/exclude` pending user decision.

- 2026-10-05: T2 code. RED: `php artisan test` 7 failed/1 error of 12 (name `Laravel`, locale `en`, timezone `UTC`, `http://localhost:8000` not https, forwarded proto ignored, heartbeat missing). GREEN: config/app.php defaults (`Portal RYR`, `es`, `es_CO`, `APP_TIMEZONE` default `America/Bogota`), AppServiceProvider forces https in production, bootstrap/app.php trusts forwarded headers only from 127.0.0.1/::1. `APP_NAME="Portal RYR" APP_LOCALE=es APP_FALLBACK_LOCALE=es php artisan test --filter=BaseConfigurationTest`: 8 passed. Without those env overrides, 2 tests fail because the local `.env` still has `APP_NAME=Laravel`/`APP_LOCALE=en` (cannot be edited by the agent). Commit `afbe555`.

- 2026-10-05: T3 done. RED: `SchedulerHeartbeatTest` 1 failed + 1 error (task not registered). GREEN: `routes/console.php` registers `scheduler-heartbeat` (`everyMinute`, `withoutOverlapping`) writing `Cache::forever('scheduler:last_run', ISO-8601)`. `php artisan test` (with env overrides above): 12 passed. `php artisan schedule:list`: `* * * * * scheduler-heartbeat`. `php artisan schedule:run` locally: `Running [scheduler-heartbeat] DONE`, cache value `2026-10-05T13:48:30-05:00`. Commit `d74e712`.

- 2026-10-05: T4 done. `/public/build` removed from `.gitignore` (`/node_modules` still ignored); `npm install` 0 vulnerabilities; `npm run build` built in ~1.6s, 11 files (196K) committed with `package-lock.json` in `1b95ee6`. `deploy/deploy.sh` (mode 100755; `bash -n` OK): maintenance mode + EXIT trap `artisan up`, `git pull --ff-only`, composer `--no-dev`, `migrate --force`, `optimize`, `storage:link` if missing. Composer override is `COMPOSER_BIN` (not `COMPOSER`, which Composer reads as the composer.json filename). `docs/despliegue.md` (Spanish) covers first install, cron + heartbeat check, AutoSSL, routine deploy, rollback, do-nots. `composer validate --no-check-publish`: valid.

- 2026-10-05: Parent spot check: `APP_NAME="Portal RYR" APP_LOCALE=es APP_FALLBACK_LOCALE=es APP_FAKER_LOCALE=es_CO php artisan test`: 12 passed (18 assertions); `schedule:list` shows `scheduler-heartbeat` every minute. Review of `38ce619..312b644`: assessed high (deploy/deploy.sh executable, shell process), consent declined by user. `.atl/` (gentle-ai skill registry cache) excluded locally.

- 2026-10-05: Jhon applied `.env.example` and local `.env` by hand. Plain `php artisan test` (after `config:clear`): 12 passed, 18 assertions. `.env.example` commit left to Jhon (agent cannot read or stage `.env*` files).

- 2026-10-05: Jhon committed `.env.example` (`5850c04`, 48+/24-). All tasks T1–T4 done; working tree clean.

## Next step
Decide on template `CLAUDE.md`/`AGENTS.md` (untracked, locally excluded), then Phase 2 is closed. Push/merge are Jhon's decisions. Next review base: `5850c04`. Jhon applies the proposed template (scratchpad `env.example.proposed`) to `.env.example` and sets local `.env` values (APP_NAME, APP_LOCALE, APP_FALLBACK_LOCALE, APP_FAKER_LOCALE, APP_TIMEZONE), or grants access; then re-run `php artisan test`, commit `.env.example`, close T2. Next review base: `312b644`.
