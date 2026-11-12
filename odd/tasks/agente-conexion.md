# Conexión del Portal con el agente RYR

Línea `feat/agente-conexion` (worktree `PORTAL_RYR-worktrees/t5-2-agente`, desde `origin/main` 82cfdae). Tarea T5.2 del proyecto Agente RYR (lado de la app; P1 a P6 del plan `docs/conectores/portal-ryr.md` del repositorio del agente, P7 queda fuera). Engram mirror: `odd/agente-conexion/tasks` (proyecto del Portal).

## Objetivo

Que cada persona del Portal pueda preguntarle al agente por lo que ya ve en el Portal (anuncios, eventos, sus tareas y aprobaciones, estado de TI y, para gerencia, las cifras gerenciales) desde un botón flotante que reemplaza al botón «Asistente».

## Alcance

- P1. Vistas de solo lectura `aux_empresa` y `v_anuncios_vigentes`, `v_eventos_proximos`, `v_tareas_pendientes`, `v_estado_ti`, `v_kpis_empresa`, con pruebas de paridad contra los alcances de Eloquent.
- P2. Procedimiento de TI para el usuario de solo lectura `agente_portal_ro` (`docs/agente-conexion.md`).
- P3. `config/agent.php`, `App\Agent\PanelToken` (HS256), reglas de elegibilidad.
- P4. `<x-agent-panel />` en lugar de `assistant-fab`, copia propia del lanzador, cabecera `Permissions-Policy`.
- P5. `GET /.well-known/agente-ryr.json` público, sin datos, con versión fijada por prueba.
- P6. Lista de aceptación (contrato §7) y pasos de despliegue para TI.

Fuera de alcance: P7 (retirar `app/Assistant/**`), `resources/js/assistant-panel.js`, la acción `crear_borrador_anuncio`, el desplazamiento vertical del lanzador (N8), fase 2 de vistas.

## Restricciones

- Sin `.env*`, sin red, sin `fetch`/`pull`/`push`, sin `--no-verify`. Valores inventados en pruebas y docs.
- Producción en MariaDB 10.11; pruebas en SQLite.
- Superficies de edición permitidas: `database/migrations/**`, `app/Agent/**`, `app/View/Components/**`, `resources/views/components/agent-panel*.blade.php`, `resources/views/components/layouts/app.blade.php`, `app/Http/Middleware/SecurityHeaders.php`, `app/Http/Controllers/Agent/**`, `routes/web.php`, `config/agent.php`, `public/js/agente-lanzador.js`, `resources/css/**`, `tests/**`, `docs/agente-conexion.md`, `odd/**`.
- Commits convencionales, sin atribución. Unas 400 líneas por unidad es solo orientación.

## Decisiones

- **La empresa sigue a la cuenta** (decisión del coordinador, 2026-11-12): `empresa` del token es la empresa de la cuenta en sesión (SGX o SDC), nunca `*`. El agente rechaza un token cuya empresa no coincide con el inquilino de la cuenta.
- **`roles_por_persona` regla B:** `["empleado", "gerencia"]`; solo afecta a herramientas con filtro `usuario` (`mis_pendientes`). Las demás son de la empresa y abren desde su `rol_minimo`. Toda consulta declara el filtro `empresa`.
- **El botón del agente reemplaza al del asistente ya.** `/asistente` y su enlace del menú quedan solo para los borradores de anuncio. Sin rol `editor` en el primer manifiesto.
- **Sin token** para cuentas de emergencia, invitadas, sin empresa, con empresa fuera del mapa SGX/SDC o inactiva, ni cuando falta la llave o el origen del agente.
- **Lanzador propio** (`public/js/agente-lanzador.js`, versión 2, SHA-256 `a480f3a7…835eeb`) fijado por prueba; el agente no entra en `script-src`. El Portal solo envía `frame-ancestors 'none'` en su CSP, así que no se agrega `frame-src`.
- **Micrófono:** `Permissions-Policy: camera=(), microphone=("<agente>"), geolocation=()` solo mientras la conexión está configurada; nunca `self`.
- **Hora de las vistas:** la base guarda hora local de Bogotá (UTC-5, sin horario de verano) y el agente abre su propia sesión, así que las vistas no usan `NOW()` sino `UTC_TIMESTAMP() - INTERVAL 5 HOUR`.
- **`sub` y `usuario_correo`:** el Portal guarda `mail` (o el UPN si falta) en `users.email`; no guarda el UPN. Se usa `LOWER(users.email)` en el token y en la vista, con la misma fuente. Abierto: ver «Pendiente».

## Tareas

- [ ] P1. Migración con las vistas y pruebas de paridad. Ruta: delegada (escritor).
- [ ] P3. `config/agent.php`, `PanelToken`, elegibilidad y pruebas. Ruta: directa (este escritor).
- [ ] P4. Componente, layout, cabeceras, lanzador y pruebas. Ruta: directa.
- [ ] P5. Manifiesto público y pruebas. Ruta: directa.
- [ ] P2/P6. `docs/agente-conexion.md` (procedimiento del usuario RO, aceptación, despliegue). Ruta: directa.
- [ ] Revisión independiente de solo lectura y correcciones de hallazgos altos y medios.

## Declaración de ruta

Dos escritores en archivos distintos del mismo worktree: el subagente (vistas y sus pruebas: `database/migrations/2026_11_12_000001_create_agent_views.php`, `app/Agent/Views/**`, `tests/**/Agent/Views/**`) y esta sesión (el resto). Disparadores: más de 2 archivos no triviales y lectura previa a la escritura. El subagente no hace commits.

## Progreso

(se llena con cada unidad)

## Pendiente

- Identidad: el Portal no guarda el UPN. Si la identidad del agente se fija por UPN y el `mail` de alguien difiere, esa persona no coincidiría (falla cerrado). Para cerrarlo hace falta una columna `users.upn` llenada al iniciar sesión (`app/Auth/**`, fuera de las superficies de esta tarea).
