# Panel gerencial con datos de Bases SAP

## Objetivo

The «Panel gerencial» block on the home page shows sales, margin and receivables of the account's company, read from the views of «Bases de datos SAP» (https://basessap.segurexlatam.net). Requested by the Bases SAP coordinator and approved by Jhon on 2026-11-17, with his adjustments.

## Problema y por qué

- The current block (PR #93) waits for figures pushed to `PUT /api/v1/kpis` by a SAP robot that was never built.
- Bases SAP is in production since 2026-11-17, its robot loads every night at 02:00, and sales and receivables already match the SAP reports. The Portal and Bases SAP share the same cPanel account, so the Portal reads the views on `localhost`.

## Alcance

- Read-only connections `sap_ventas` (`ventas_sap`), `sap_cartera` (`cartera_sap`) and `sap_maestros` (`maestros_sap`) with one user of the Portal; only empty lines in `.env.example`.
- Read model of the figures for one company code (`SGX` for slug `segurex`, `SDC` for `derivados`, same mapping as `app/Agent/Views/AgentViews.php`), cached about 10 minutes per company.
- Home block: the SAP figures when the connections are configured; otherwise the current behavior (published KPIs).
- «Actualizar ventas y cartera» button for the same Gerencia accounts: requests `VENTAS` and `CARTERA` through the Bases SAP API on the server, with the Portal key (guide §2). «Datos actualizados a las …» from `GET /api/v1/estado`.

## Fuera de alcance

- `PUT /api/v1/kpis` and `company_kpis` stay as they are.
- Month selector and year-over-year comparisons: the sales history starts on 2026-10-25.
- Writing to the Bases SAP databases: never.
- Any connection to SAP or the VPN: never.

## Reglas de cálculo (no cambiarlas)

- Period: the current month, by `fecha_contabilizacion`, Bogotá time.
- **Venta neta** = `SUM(total)` of `v_ventas`, with no filter on `estado_documento`: voided documents and their voids cancel each other, which matches SAP. Credit notes already come negative.
- **Ganancia bruta** = `SUM(ganancia_bruta)`. **Margen** = `SUM(ganancia_bruta) / SUM(total)` only over rows where `ganancia_bruta` is not null. Never use `costo_total`.
- **Coverage notice** of the margin stays: it says over which share of the net sale the margin was computed, even though since 2026-11-18 every line brings `ganancia_bruta`.
- **Cartera** = `SUM(saldo)` of `v_cartera` for the company.
- **Ranges:** «Sin vencer» is `sin_vencer`; the ranges are `d_0_30`, `d_31_60`, `d_61_90`, `d_91_120`, `d_121_150`, `d_151_180` and `d_mas_180`. Check that `sin_vencer` plus the `d_*` add up to `saldo`.
- **Top 5 vendedores:** group by `slp_code` within the company and take the name from `v_ventas.vendedor`; `slp_code = -1` shows as «Sin vendedor». If joined with `v_vendedores`, join by `empresa` and `codigo = slp_code`.
- Every query filters by `empresa`, reads only `v_*` views and lists its columns.

## Restricciones

- Visible only under the existing `viewManagementPanel` policy (Gerencia of the account's own company). The account's company only: to see the other one, switch account.
- The API key stays on the server: never in the browser, JavaScript or the repository. Status polling runs on the server with a cache of about 30 s (limit 60 requests per minute per key).
- A failure reading the views or calling the API never breaks the home page.
- `HomeQueryBudget::MAX` must still hold.
- Tests never hit the real network (`Http::fake`) nor the real databases.

## Tareas

- [x] T1. Connections, configuration and the read model of the figures, with tests of each calculation rule (including ranges = saldo and «Sin vendedor»). Route: delegated writer (2+ non-trivial files).
- [x] T2. Home block with the SAP figures, coverage notice, ranges and top 5; fallback to the published KPIs; query budget. Route: delegated writer.
- [x] T3. «Actualizar ventas y cartera» button, request status and «Datos actualizados a las …». Route: delegated writer.
- [ ] T4. Docs for deploy and administration (through the documentador-portal session after merge).

## Checks

- `php artisan test --compact` (with `APP_KEY` in the environment) and `vendor/bin/pint --test`.
- `/code-review` and `/security-review` before the PR.
- Manual (Jhon, after deploy): the figures match the SAP reports for SGX and SDC.

## Entrega

- Branch `feat/panel-gerencial-sap` from `main` (c1c0b66). Forecast: about 1,200 authored lines with tests, so above the 400-line budget; the chain strategy is asked before opening PRs.

## Progreso

- 2026-11-17: proposal approved with Jhon's four adjustments (ranges, no `estado_documento` filter, top 5 by `slp_code`, coverage notice stays).
- 2026-11-17: T1 done. Connections `sap_ventas`, `sap_cartera`, `sap_maestros` and `services.bases_sap`; `App\Management\SapFigures` (three aggregate queries, cache 10 min per company, failures reported and cached 1 min) returning `SapFigureSet`; one slug-to-`empresa` mapping in `Company::SAP_CODES`, reused by `AgentViews` (same SQL). Seller name from `v_ventas.vendedor` (no join with `v_vendedores`). `.env.example` lines pending: the writer's permissions block `.env*`. Tests: `tests/Feature/Management/SapFiguresTest.php` (20).
- 2026-11-17: T1 commit `a7a3c77`. T2 done: `App\View\Home\SapPanelCard` and `components/home/sap-panel.blade.php` (same id `panel-gerencial`, offline-omitted) when the SAP connections are configured and the company has a code; published KPIs otherwise; «No pudimos leer las cifras de SAP» on a read failure (it replaces the published figures, since SAP is then the source). Whole pesos («$1.250.000.000») so the figures read like SAP's reports; coverage cut, never rounded up. `ManagementPanel::allows()` splits the ability from the KPI query, so cached SAP figures cost one query fewer than the published ones. Tests: `tests/Feature/Home/HomeSapPanelTest.php` (15).
- 2026-11-17: T2 commit `d2d009d`. T3 done: `POST /panel-gerencial/actualizar` (`auth`, CSRF, `can:viewManagementPanel`, 6 a minute in its own bucket) asks Bases SAP for `VENTAS` and `CARTERA` from the server (`App\Management\SapRefresh`, key never in the page); status of each request cached 30 s for every viewer; when both end, the figures and last-update caches are dropped once and the final message stays 10 min; «Datos actualizados a las HH:MM» is the older of the two `GET /api/v1/estado` times, cached 5 min (failures too), left out when unknown. Home reads use a 3 s timeout; no JavaScript poll (the status refreshes on reload). Tests: `tests/Feature/Management/SapRefreshTest.php` (28). Pending for the parent: the `.env.example` lines and the T4 docs.
- 2026-11-17: T3 commit `43013d2`. Review fixes: the figures are cached as plain arrays (`SapFigureSet::toArray()`/`fromArray()`), since production unserializes no classes; only a complete VENTAS+CARTERA pair is followed (a half-accepted press is forgotten, with any earlier message); the status and last-update reads go in pairs through `Http::pool` (one timeout per pair); range shares are over the positive ranges, and a credit-balance range shows «Saldo a favor» without a bar.
- 2026-11-17: review fixes commit `9d7aec1`; `phpunit.xml` pins the Bases SAP settings empty (`7dbda4f`). Checks: full suite 3848 tests, 3840 passed, 8 skipped, 0 failures (the exit code 1 comes from a PHPUnit warning already on `main`: `HomeAppsViewMarkupTest` data providers pass 3 arguments to 1-argument tests); Pint clean; `/code-review` (high) 6 findings, 4 fixed, 2 accepted (database-cache reads per Gerencia home load; a second press tracks only its own ids); `/security-review` no finding at confidence 8 or more. RDD off (global), so ordinary policy. `.env.example` lines left to Jhon (`.env*` is blocked for agents).
