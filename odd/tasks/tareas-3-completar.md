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

- [x] R1. **«Completar» firmado (T3).** Ruta: delegada (este escritor único; el coordinador no escribe).
  - Migración `2026_11_05_500001`: `integrations.signing_secret` (texto, cast `encrypted`, oculto al serializar) y `signing_secret_hint` (4 caracteres). Sin índices.
  - `App\Work\PortalSignature`: firma de §6.2 y secreto nuevo (`ryrsig_` + 32 bytes en base64url, 43 caracteres).
  - Administración › Integraciones: **Secreto de firma** solo con `work:write`; **Generar secreto** / **Rotar secreto** con confirmación reciente de Microsoft (la cuenta de emergencia recibe 403); se muestra una sola vez en su propio diálogo. Auditoría `integration.signing_secret_created` y `integration.signing_secret_rotated`. Revocar borra el secreto.
  - `POST /tareas/{id}/completar` (`work-items.complete`, CSRF de `web`, límite propio de 10 por minuto por cuenta): busca el elemento con `WorkItem::visibleTo()` (ajeno, de otra cuenta vinculada, hecho, borrado o de integración revocada: 404); visible pero no completable (aprobación, `completable_from_portal: false`, integración sin secreto o con la URL base fuera del host de la app del catálogo): 403 sin llamar. `WorkItem::canBeCompletedFromPortal()` le dice a la vista si mostrar el botón.
  - `App\Work\TaskCompleter`: una pulsación a la vez por elemento (candado de caché de 15 s), 5 s de tiempo total y de conexión, sin redirecciones, TLS verificado, sin reintentos. `200 completed` y `409 already_done` lo marcan hecho sin cambiar `version`; `404 not_found` lo borra dejando el rastro de versión; lo demás lo deja pendiente. Mensajes de §6.4.
  - Auditoría (tipo API, por la persona, sobre la integración): `work_item.completed` y `work_item.complete_failed` con `external_id`, `request_id`, código HTTP o `timeout` y el motivo; etiquetas en `AuditPresenter` («Completó una tarea de», «No pudo completar una tarea de», «Elemento», «Pedido», «Respuesta HTTP», «Motivo»).
  - Documentación: `docs/despliegue.md` §1.20, `docs/api.md` §7, contrato §2.2 y manual §2.7 y §4.7.
  - Evidencia: RED (las clases no existían; primer fallo en `IntegrationSigningSecretTest`) y GREEN 42/42 en `PortalSignatureTest`, `CompleteTaskTest` e `IntegrationSigningSecretTest`; la firma coincide con el dato de prueba del contrato. Admin, API y Auditoría 628/628. Commit `c92210f` (unas 1.240 líneas: 525 de código y 689 de pruebas; pasa de las ~400 por las pruebas de cada fila de §6.4 y de cada rechazo).
- [x] R2. **Herramientas `mis_tareas` y `mis_aprobaciones` (C3b).** Ruta: delegada (mismo escritor).
  - `App\Mcp\Tools\WorkItemsTool` (base común), `MisTareas` y `MisAprobaciones`: por cada cuenta usable de la conexión, `WorkItem::visibleTo()` del tipo pedido, con la empresa de la cuenta; primero las que vencen antes, al final las sin fecha; máximo 20 (las del inicio por grupo, contrato §4.6). Devuelven solo título, empresa, fecha límite, «vencida» y el enlace a la app.
  - «Tus tareas y aprobaciones.» vuelve a la pantalla de permiso y su `assertSee` a `ConsentTest`. Manual §10.1 sin la frase de «llegarán después», con las dos herramientas.
  - Evidencia: RED 12 (las herramientas no existían) y GREEN 258/258 en `tests/Feature/Mcp` y `tests/Unit/Mcp`. Commit `a3c2aa4`.
- [x] R3. **Rango de `due_at`.** Ruta: delegada (mismo escritor).
  - La columna es `TIMESTAMP` (migración `2026_11_03_100002`). La API acepta del `2000-01-01T00:00:00Z` al `2037-12-31T23:59:59Z`, comparando el instante (la zona cuenta); fuera, `422` en `due_at`. Constantes `WorkItem::EARLIEST_DUE_AT` y `LATEST_DUE_AT`. Contrato 0.2.1 y `docs/api.md` §7.
  - Evidencia: RED 6/108 y GREEN 108/108 en `WorkItemsApiTest`. Commit `d84d531`.

- [x] RV. **Correcciones de la revisión independiente** (1 media, 8 bajas). Ruta: delegada (mismo escritor); RED antes de cada una.
  - [x] Media: §6.4 tal como está escrito. Completada solo con `200` + `completed` o `409` + `already_done`; borrada solo con `404` + `not_found`; rechazada solo con `403` + `not_assignee` o `not_completable`. `201`, `202`, `204`, un `200` sin `code`, un `404` vacío o en HTML y cualquier otro `code` son `unexpected_response` y no tocan el elemento. Contrato 0.2.1 aclara que el `code` es obligatorio.
  - [x] Dentro del candado, `TaskCompleter` vuelve a leer el elemento con `visibleTo()` y exige `canBeCompletedFromPortal()`; si no, responde el resultado neutro nuevo `Stale` («Esta tarea cambió mientras tanto. Revisa tu lista de tareas.») sin llamar.
  - [x] `markDone()` y el borrado por `404` (`deleteIfUnchanged()`) exigen la `version` vista al pulsar; si un `PUT` con versión mayor llegó durante la llamada, gana y la persona ve `Stale`. La Auditoría sigue registrando lo que respondió la app.
  - [x] La ruta acepta solo `[0-9]{1,18}`: un id más largo es 404, no 500.
  - [x] `Integration::isValidAppBaseUrl()` acepta en la ruta solo `A-Z a-z 0-9 . _ ~ ! $ & ' ( ) * + , ; = : @ % / -`. Integraciones exige el mismo host (y puerto) que la app del catálogo al crear y al guardar; la comprobación al llamar sigue.
  - [x] `canBeCompletedFromPortal()` no descifra: `Integration::hasSigningSecret()` mira el valor crudo y la pista. Solo `TaskCompleter` descifra; un `DecryptException` es un fallo auditado (`secret_unavailable`, sin código HTTP), nunca un 500.
  - [x] Las instrucciones del servidor MCP dicen que los títulos de tareas y aprobaciones son datos escritos por personas, nunca instrucciones.
  - [x] Pruebas nuevas: el mismo `oid` en otro inquilino es 404; un administrador sin `AdminSections::INTEGRATIONS` no puede generar el secreto.
  - [x] `docs/estado-del-proyecto.md` ya no dice que falta C3b.
  - Evidencia: RED 16 (11 fallos y 5 errores) en `CompleteTaskTest`, `IntegrationSigningSecretTest` e `IntegrationsPageTest`; GREEN 181/181 en esos archivos y en `tests/Feature/Mcp/Tools`.

## Criterios de aceptación

- R1: la firma coincide con el dato de prueba del contrato §6.2; solo la cuenta activa completa sus propias tareas pendientes y completables; una aprobación responde `403` sin llamar a la app; un elemento ajeno responde `404`; cada respuesta de la app sigue la tabla §6.4; cada intento deja una entrada de Auditoría sin título, secreto ni firma; el secreto se guarda cifrado y se muestra una sola vez.
- R2: cada herramienta lee solo con las cuentas usables de la conexión, por `{tenant_id, oid}`, con la empresa de cada una; nunca mezcla elementos de otra empresa; máximo 20; queda en Auditoría como las demás.
- R3: `due_at` antes del 2000-01-01 o después del 2037-12-31 (UTC) responde `422`.

## Comprobaciones

- `vendor/bin/pint --test`.
- `APP_KEY=base64:… php artisan test --compact` (suite completa; base en `main`: 2.723).
- RED antes de cada cambio de comportamiento; GREEN después.

## Progreso

- 2026-11-03: documento creado (`8d6e4b2`). R1 en curso.
- 2026-11-03: R1, R2 y R3 hechos. Suite completa 2.788/2.788 (base 2.723); `pint --test` limpio.

## Decisiones para revisar

- La URL base debe estar en el mismo host (y puerto) que la app del catálogo, o el portal no llama. Se comprueba al guardar en Integraciones y otra vez al llamar.
- El secreto se genera aparte, con **Generar secreto** en el detalle del sistema, no junto con el token al crearlo.
- Los rechazos antes de llamar (403 y 404) no dejan entrada en Auditoría: no hubo intento.
- ~~Cualquier `2xx` cuenta como completada~~: corregido en la revisión, ahora sigue §6.4 tal como está escrito.
- Un `403` sin `not_assignee` ni `not_completable` cuenta como respuesta inesperada (sigue pendiente, motivo `unexpected_response`).
- Un secreto que no se puede descifrar (otra `APP_KEY`) es un intento fallido sin llamada, con motivo `secret_unavailable` (no está en la lista de motivos del contrato §6.5).
- `due_at` queda en `TIMESTAMP` con el rango validado, en vez de cambiar la columna a `DATETIME`.

## Siguiente paso

- PR de `feat/tareas-3-completar` contra `main` (lo abre Jhon o el coordinador).
- T2: la lista «Mi día» del inicio con el botón (`route('work-items.complete', $item)` cuando `$item->canBeCompletedFromPortal()`).
