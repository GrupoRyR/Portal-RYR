# Tareas y aprobaciones en el inicio

Fase 5 del nuevo inicio («Tareas y aprobaciones», decidida por Jhon el 2026-10-29, plan `tingly-toasting-hellman.md`). La fuente de verdad es el contrato v0.1: [`docs/contrato-tareas-aprobaciones.md`](../../docs/contrato-tareas-aprobaciones.md). Este documento no lo cambia: cualquier ambigüedad queda abajo como pregunta para Jhon.

## Objetivo

Que el inicio muestre a cada persona sus tareas y lo que tiene por aprobar, con «Ver tarea», «Ver aprobación» y «Completar» (solo tareas), usando la copia que la app de tareas envía al portal.

## Problema y por qué

- Las tareas y aprobaciones viven en otra app. La gente tiene que entrar a ella para saber qué le toca.
- El inicio nunca debe depender de que esa app esté arriba: el portal guarda una copia.

## Alcance

- T1: el lado de entrada del portal (`PUT` y `DELETE /api/v1/work-items/{external_id}`, permiso `work:write`, app del catálogo y URL base en Integraciones).
- T2 a T5: ver la lista de tareas.

## Fuera de alcance

- Aprobar o rechazar en el portal (nunca, contrato §7).
- Descripción, comentarios, adjuntos o montos (contrato §8.6).
- Cambios al contrato.

## Restricciones

- No hay cola: solo el cron de cada minuto (`schedule:run`).
- Identidad solo por `{tenant_id, oid}`; nunca el correo; `person_id` nunca autoriza.
- Las pruebas nunca usan la red y corren con `APP_KEY` en el entorno (`.env*` bloqueado).
- Commits convencionales sin atribución de IA.

## Tareas

- [x] T1. **API de entrada.** Ruta: delegada (escritor), porque son 2 o más archivos no triviales: migración, modelo, controlador, reglas, Integraciones, pruebas y documentación. Rama `feat/tareas-1-api` desde `origin/main` (no depende de los PR del inicio ni de cuentas vinculadas).
  - Permiso `work:write` en `Integration::ABILITIES`, que se da en Administración › Integraciones como los demás.
  - Integración con **app del catálogo** (`app_id`, de ella sale el host permitido de los enlaces) y **URL base** (`app_base_url`, `https`, sin usuario, sin `?` ni `#`). Las dos son obligatorias con `work:write`.
  - Tabla `work_items`: `external_id` único por integración, `kind`, `title`, `status`, `due_at`, `url`, `completable_from_portal`, `version`, `assignee_tenant_id`, `assignee_oid`, rastro de borrado (`deleted_at`).
  - `PUT` con las reglas de §3.2, §3.3 y §3.7: `created` 201, `updated`, `unchanged` y `ignored_stale` 200; 413 sobre 8 KB; 404 con un `external_id` inválido; 500 pendientes por persona e integración.
  - `DELETE`: 204 siempre; deja solo el rastro de versión 30 días.
  - Revocar una integración borra sus elementos (§2.2).
  - Visibilidad: solo la cuenta activa (`tenant_id` + `azure_oid`), solo `pending`, solo integraciones no revocadas (§4).
  - Sin entrada de Auditoría por `PUT`/`DELETE` (§6.5): queda en «Último uso». Los cambios de app y URL base en Integraciones sí quedan (`integration.work_app_changed`).
  - Evidencia: RED 75/75 (`Integration::WORK_WRITE` no existía) y GREEN 75/75 en `WorkItemsApiTest`; RED 4 y GREEN en `IntegrationsPageTest`; suite completa 1333/1333; `pint --test` limpio. Commits `0a45140` (plan), `bcc396c` (API), `39df1cd` (Integraciones), `aff6d74` (docs). Unas 1.712 líneas: 886 de código, 696 de pruebas y 130 de documentación. Pasa de las ~400 por las pruebas de cada regla del contrato y por el formulario de Integraciones; no se partió para no separar las reglas de sus pruebas.
  - **Correcciones de la revisión independiente** (ruta: delegada, escritor; RED antes de cada una):
    - [x] `due_at` se guardaba en la zona equivocada (`22:00Z` quedaba como 22:00 de Bogotá, 5 h tarde). Ahora se convierte a `config('app.timezone')` antes de guardar. RED 2/3 (`Z` y `+02:00`) y GREEN 3/3.
    - [x] Carrera de los primeros `PUT` y del límite de 500: cada escritura (`PUT` y `DELETE`) bloquea primero la fila de su integración (`lockForUpdate`) dentro de la transacción, así las escrituras de una integración van en fila. SQLite ignora `FOR UPDATE`; la prueba comprueba que la primera consulta de cada transacción es ese bloqueo y que el resultado no cambia.
    - [x] `external_id` con intercalación binaria (`utf8mb4_bin`) solo en MySQL/MariaDB, en la misma migración (aún no desplegada). La prueba genera el SQL de MySQL con `pretend`, sin conectarse.
    - [x] Elementos varados: `WorkItem::visibleTo()` exige además que la integración tenga hoy `work:write` y `app_id`. Si pierde cualquiera, sus elementos se ocultan (no se borran) y vuelven al recuperarlos.
    - [x] Dar `work:write` y cambiar la app o la URL base piden confirmar con Microsoft (`RecentMicrosoftLogin`, igual que crear o rotar). Quitar permisos sigue siendo un clic. La cuenta de emergencia recibe 403 con el mensaje nuevo. Guardar los mismos valores no pide nada.
    - [x] Limpieza diaria programada: `model:prune` de `WorkItem` a las 03:00 de Bogotá, sin solaparse.
    - [x] Ambigüedad 2: un `:443` escrito equivale a no tener puerto, en el enlace y en la app del catálogo; cualquier otro puerto (también `:0443` o `:` vacío) debe coincidir exacto.
    - [x] Ambigüedad 3: el título rechaza también U+200E, U+200F, U+061C, U+200B y U+FEFF. `TrimStrings` ya los quita de los bordes, así que solo cuentan dentro del título.
    - [x] Pruebas de enlaces nuevas: `https:\evil`, host con `%`, `https:///…` (rechazados) y `@` en la ruta (permitido).
    - Evidencia: RED 11 en `WorkItemsApiTest` + `WorkItemsSchemaTest` y 3 en `IntegrationsPageTest` + `WorkItemPruneScheduleTest`; GREEN en todas; suite completa 1355/1355; `pint --test` limpio. Commits `bd54690` (limpieza), `3e2830b` (Integraciones), `9bfc004` (API).
  - [x] **Contrato v0.2: cambiar la persona** (decisión de Jhon, 2026-10-30; ruta: delegada, escritor). La app puede equivocarse: con una `version` mayor, un `PUT` cambia el `assignee` y el elemento pasa del portal de una persona al de la otra (`WorkItem::visibleTo()` lee el `tenant_id` y el `oid` guardados). Al reasignar un elemento pendiente se comprueba el límite de 500 de la persona nueva (antes no se comprobaba porque el elemento ya estaba pendiente). Con la misma `version`, otro `assignee` responde `422` pidiendo subir la versión. `kind` sigue sin cambiar con ninguna versión (Jhon corrigió el primer planteamiento, que también lo dejaba cambiar). Quien envía el elemento no entra en el contrato.
    - Evidencia: RED 7/101 con el primer planteamiento y RED 3/102 tras la corrección de `kind`; GREEN 102/102 en `WorkItemsApiTest`; suite completa 1364/1364; `pint --test` limpio. Commit `593da49`.
- [ ] T2. **«Mi día» en el inicio.** Lista «Por aprobar» y «Tareas» con «Ver tarea» y «Ver aprobación» (§4, §5). Depende de la cadena del inicio (#69 a #73). Ruta: por decidir.
- [ ] T3. **«Completar» firmado.** Secreto `ryrsig_` cifrado, llamada `POST {app_base_url}/api/portal/v1/tasks/{external_id}/complete` con HMAC-SHA256, 5 s, sin redirecciones, Auditoría `work_item.completed` y `work_item.complete_failed` (§6). Ruta: por decidir.
- [ ] T4. **Carga inicial y conciliación.** Bloqueada: pregunta abierta 1 del contrato (§11).
- [ ] T5. **Invitados (B2B) con otro `oid`.** Bloqueada: pregunta abierta 2 del contrato (§11).

## Criterios de aceptación (T1)

- Solo un token con `work:write`, no revocado y desde una IP permitida publica elementos.
- Cada regla de §3.7 responde `422` con el campo que falló.
- Las tres ramas de `version` y el rastro de borrado de 30 días funcionan como §3.3.
- Un enlace con otro host, `http`, usuario o contraseña, punto final o barra invertida responde `422`.
- Una integración nunca ve ni cambia los elementos de otra.
- El límite de 120 llamadas por minuto no cambia.

## Comprobaciones

- `APP_KEY=base64:… php artisan test --compact` (suite completa).
- `vendor/bin/pint --test`.
- RED antes de implementar; GREEN después.

## Entrega

- Estrategia `ask-on-risk`, cadena `stacked-to-main`. T1 es un PR propio contra `main`.
- Commits por unidad de trabajo. Jhon fusiona y despliega.

## Preguntas para Jhon

Ambigüedades del contrato v0.1 encontradas en T1. Todas decididas; solo la 1 cambió el contrato (v0.1.1).

1. **`version` igual con otro `kind` o `assignee`** (§3.3 punto 3 contra §3.7). Con la misma versión, el portal responde `unchanged` sin comparar, como dice §3.3. El `422` por cambio de `kind` o de `assignee` solo sale cuando la versión es mayor y el cambio se aplicaría. *Decidido (Jhon, 2026-10-30):* con la misma versión, otro `kind` u otro `assignee` responde `422`, como con una versión mayor; lo demás sigue sin compararse. Para cambiar de persona o de tipo se borra y se crea con otro `external_id`. Contrato v0.1.1, commit `633629f`. *Revisado (Jhon, 2026-10-30, contrato v0.2):* con una versión mayor la persona sí puede cambiar; con la misma versión, otro `assignee` responde `422` pidiendo subir la versión. El tipo sigue sin cambiar nunca. Commit `593da49`.
2. **Puerto en los enlaces** (§5, «y el puerto, si lo trae»). *Decidido en la revisión:* `:443` escrito es igual a sin puerto; todo lo demás es `host[:puerto]` exacto. El catálogo hoy no acepta direcciones con puerto.
3. **Caracteres del título** (§3.2, «caracteres de control»). *Decidido en la revisión:* además de los de control, los separadores de línea y párrafo, y los de dirección del texto (U+202A a U+202E, U+2066 a U+2069), se rechazan U+200E, U+200F, U+061C, U+200B y U+FEFF.
4. **Inquilino de una empresa registrada** (§3.5). Se acepta cualquier empresa registrada, también una inactiva (sus cuentas no entran, así que no ven nada). *Decidido (Jhon, 2026-10-30):* se queda así, para poder precargar elementos de empresas que aún no están activas.
5. **Qué app del catálogo se puede elegir** (§2.1). Se listan las apps web con dirección, también las ocultas. *Decidido (Jhon, 2026-10-30):* se queda así, se listan también las ocultas.
6. **`user_id` del elemento.** El contrato pide guardar solo sus campos (§8.6) y mostrar por `{tenant_id, oid}` (§4.1), así que no se guarda `user_id`: `WorkItem::visibleTo($user)` compara `tenant_id` y `azure_oid` de la cuenta activa. Así funciona también para cuentas que entran por primera vez.
7. **Fecha sin segundos.** `due_at` acepta `2026-11-05T17:00-05:00` y fracciones de segundo, siempre con zona.
8. **Confirmar con Microsoft al guardar la app de tareas.** Si la confirmación venció, **Guardar app** lleva a Microsoft y al volver hay que escribir otra vez la app y la URL base (el formulario de crear token sí se abre solo después de confirmar). *Decidido (Jhon, 2026-10-30):* se queda así, sin paso «Editar».

## Progreso

- 2026-10-29: documento creado. T1 en curso.
- 2026-10-29: T1 hecho en `feat/tareas-1-api` (sin push ni PR). Ruta: delegada (escritor).
- 2026-10-29: correcciones de la revisión independiente de T1 (9 puntos) hechas y comprobadas. Sin push ni PR.
- 2026-10-30: Jhon decidió las preguntas 1, 4, 5 y 8. La 1 cambia el código: misma `version` con otro `kind` o `assignee` responde `422` (`633629f`, contrato v0.1.1; 1.359 pruebas en verde). Sin push.
- 2026-10-30: contrato v0.2. Con una `version` mayor la persona puede cambiar y el elemento pasa a la nueva; el tipo no cambia nunca (`593da49`; 1.364 pruebas en verde). Sin push.

## Siguiente paso

- Push de `633629f` y `593da49` al PR #74 de T1 y revisión de Jhon.
- T2 cuando la cadena del inicio (#69 a #73) esté en `main`.
