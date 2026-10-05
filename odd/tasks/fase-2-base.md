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
- [ ] T1 — Git repo + Laravel 13 skeleton at repo root; tests green. Route: inline (mechanical commands).
- [ ] T2 — Base config + `.env.example` + HTTPS/proxy + database drivers; tests. Route: delegated writer (2+ non-trivial files).
- [ ] T3 — Scheduler heartbeat + test. Route: delegated writer (same writer as T2).
- [ ] T4 — Deployment: committed `public/build`, `deploy/deploy.sh`, `docs/despliegue.md`. Route: delegated writer (same writer).

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

## Next step
T1.
