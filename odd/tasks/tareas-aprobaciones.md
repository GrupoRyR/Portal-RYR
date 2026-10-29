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

- [ ] T1. **API de entrada.** Ruta: delegada (escritor), porque son 2 o más archivos no triviales: migración, modelo, controlador, reglas, Integraciones, pruebas y documentación. Rama `feat/tareas-1-api` desde `origin/main` (no depende de los PR del inicio ni de cuentas vinculadas).
  - Permiso `work:write` en `Integration::ABILITIES`, que se da en Administración › Integraciones como los demás.
  - Integración con **app del catálogo** (`app_id`, de ella sale el host permitido de los enlaces) y **URL base** (`app_base_url`, `https`, sin usuario, sin `?` ni `#`). Las dos son obligatorias con `work:write`.
  - Tabla `work_items`: `external_id` único por integración, `kind`, `title`, `status`, `due_at`, `url`, `completable_from_portal`, `version`, `assignee_tenant_id`, `assignee_oid`, rastro de borrado (`deleted_at`).
  - `PUT` con las reglas de §3.2, §3.3 y §3.7: `created` 201, `updated`, `unchanged` y `ignored_stale` 200; 413 sobre 8 KB; 404 con un `external_id` inválido; 500 pendientes por persona e integración.
  - `DELETE`: 204 siempre; deja solo el rastro de versión 30 días.
  - Revocar una integración borra sus elementos (§2.2).
  - Visibilidad: solo la cuenta activa (`tenant_id` + `azure_oid`), solo `pending`, solo integraciones no revocadas (§4).
  - Sin entrada de Auditoría por `PUT`/`DELETE` (§6.5): queda en «Último uso». Los cambios de app y URL base en Integraciones sí quedan.
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

(Se llenan durante T1.)

## Progreso

- 2026-10-29: documento creado. T1 en curso.

## Siguiente paso

T1: pruebas en RED de la API de entrada.
