# Tareas: «Completar» firmado, herramientas de Claude y rango de `due_at`

Línea «Tareas» del portal, rama `feat/tareas-3-completar` desde `origin/main` (`59f2209`). Fuente de verdad: [`docs/contrato-tareas-aprobaciones.md`](../../docs/contrato-tareas-aprobaciones.md) (§6 «Completar»). Documentos padre: [`tareas-aprobaciones.md`](tareas-aprobaciones.md) (T3) y [`conector-mcp.md`](conector-mcp.md) (C3b).

## Objetivo

- Que una persona pueda completar desde el portal una tarea suya, con la llamada firmada a la app de tareas (contrato §6).
- Que Claude pueda leer las tareas y aprobaciones de las cuentas de la conexión (`mis_tareas`, `mis_aprobaciones`).
- Que un `due_at` fuera del rango de la columna responda `422` y no un error de base de datos.

## Problema y por qué

- T3 estaba pendiente: el contrato ya define la firma, pero el portal no tenía secreto ni llamada.
- C3b esperaba a que `work_items` (#74) estuviera en `main`; ya está.
- `work_items.due_at` es `TIMESTAMP`: en MariaDB 10.11 solo guarda de 1970 a 2038. Un `due_at` del año 2100 daba 500 en modo estricto. El panel gerencial tuvo el mismo hueco con `as_of` (`odd/tasks/panel-gerencial.md`, punto 1).

## Alcance

- R1 (T3): secreto `ryrsig_` por integración, cifrado, que se muestra una sola vez al generarlo o rotarlo en Administración › Integraciones; endpoint `POST /tareas/{id}/completar` (CSRF, límite propio) para las tareas propias de la cuenta activa; llamada firmada `POST {app_base_url}/api/portal/v1/tasks/{external_id}/complete` (5 s, sin redirecciones, `https` solo en el host de la app del catálogo); Auditoría `work_item.completed` y `work_item.complete_failed`.
- R2 (C3b): herramientas `mis_tareas` y `mis_aprobaciones`; «Tus tareas y aprobaciones.» vuelve a la pantalla de permiso; manual §10.1.
- R3: rango de `due_at` validado en la API de entrada.

## Fuera de alcance

- El botón del inicio y la lista «Mi día» (T2, línea del inicio). El endpoint y `WorkItem::canBeCompletedFromPortal()` quedan listos para esa vista.
- Aprobar o rechazar (contrato §7).
- T4 y T5 (preguntas abiertas del contrato).

## Restricciones

- MariaDB 10.11 en producción, SQLite en pruebas. Migración nueva con prefijo `2026_11_05_5xxxxx`; nombres de índices de 64 caracteres o menos.
- Pruebas sin red (`Http::fake`, `Http::preventStrayRequests`), con `APP_KEY` en el entorno.
- Commits convencionales sin atribución de IA. Sin push forzado, sin rebase, sin PR.

## Tareas

- [ ] R1. **«Completar» firmado (T3).** Ruta: inline (único escritor delegado; las pruebas guían cada archivo).
- [ ] R2. **Herramientas `mis_tareas` y `mis_aprobaciones` (C3b).** Ruta: inline.
- [ ] R3. **Rango de `due_at`.** Ruta: inline.

## Criterios de aceptación

- R1: la firma coincide con el dato de prueba del contrato §6.2; solo la cuenta activa completa sus propias tareas pendientes y completables; una aprobación responde `403` sin llamar a la app; un elemento ajeno responde `404`; cada respuesta de la app sigue la tabla §6.4; cada intento deja una entrada de Auditoría sin título, secreto ni firma; el secreto se guarda cifrado y se muestra una sola vez.
- R2: cada herramienta lee solo con las cuentas usables de la conexión, por `{tenant_id, oid}`, con la empresa de cada una; nunca mezcla elementos de otra empresa; máximo 20; queda en Auditoría como las demás.
- R3: `due_at` antes del 2000-01-01 o después del 2037-12-31 (UTC) responde `422`.

## Comprobaciones

- `vendor/bin/pint --test`.
- `APP_KEY=base64:… php artisan test --compact` (suite completa; base en `main`: 2.723).
- RED antes de cada cambio de comportamiento; GREEN después.

## Progreso

- 2026-11-03: documento creado. R1 en curso.

## Siguiente paso

- R1.
