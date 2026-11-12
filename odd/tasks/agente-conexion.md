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
- **`sub` y `usuario_correo` = UPN** (decisión del coordinador y del usuario, 2026-11-12, reemplaza la primera versión que usaba `users.email`): columna `users.upn` (migración `2026_11_12_000002`), llenada en cada inicio de sesión con Microsoft desde el perfil de Graph (`userPrincipalName`) o el reclamo `upn`, en minúsculas; nunca un UPN de invitado (`#EXT#`) ni un valor que no sea una dirección simple. `users.email` no cambia. Sin UPN no hay token ni botón (la cuenta lo obtiene en su siguiente inicio de sesión) y la vista excluye sus filas; se mantiene la comprobación de dominio de MEDIO-1, aplicada al UPN.

## Tareas

- [x] P1. Migración con las vistas y pruebas de paridad. Ruta: delegada (escritor). Commit `545cc74`.
- [x] P3. `config/agent.php`, `PanelToken`, elegibilidad y pruebas. Ruta: directa. Commit `65908c2`.
- [x] P4. Componente, layout, cabeceras, lanzador y pruebas. Ruta: directa. Commit `c52c1ea`.
- [x] P5. Manifiesto público y pruebas. Ruta: directa. Commit `105bd35`.
- [x] P2/P6. `docs/agente-conexion.md` (usuario RO, aceptación, despliegue). Ruta: directa. Commit `ad97c74`.
- [x] Revisión independiente de solo lectura y correcciones de hallazgos altos y medios (ver Progreso).

## Declaración de ruta

Dos escritores en archivos distintos del mismo worktree: el subagente (vistas y sus pruebas: `database/migrations/2026_11_12_000001_create_agent_views.php`, `app/Agent/Views/**`, `tests/**/Agent/Views/**`) y esta sesión (el resto). Disparadores: más de 2 archivos no triviales y lectura previa a la escritura. El subagente no hace commits.

## Progreso

- P3: 12 pruebas de token (claims, firma HS256, caducidad 300 s, `jti`, elegibilidad, llave y origen).
- P4: 13 pruebas de componente y cabeceras; `AssistantFabTest` se reescribió (el botón viejo ya no se dibuja). `LauncherCopyTest` fija versión 2 y SHA-256 `a480f3a7…835eeb`.
- P1: 82 pruebas (78 pasan, 4 omitidas: MariaDB opcional). El dialecto MariaDB se renderizó y se leyó, nunca se ejecutó.
- P5: 15 pruebas del manifiesto; hash fijado `54deba2d…0bed` para la versión `2026-11-12.1`. Comprobación aparte: las 5 herramientas seleccionan, ordenan y filtran por las columnas declaradas contra las vistas reales (SQLite).
- Suite completa antes de la revisión: 3635 pruebas, 3627 pasan, 8 omitidas, 0 fallas.
- Revisión independiente: sin hallazgos altos. MEDIO-1 (`sub` es `mail`, sin comprobar propiedad del dominio): corregido, el token exige que la dirección esté en un dominio de la empresa de la cuenta. MEDIO-2 (`gerencia` ignoraba la comprobación viva de gerencia): corregido, usa `ManagementPanel::ABILITY`. BAJO-3 (token en la copia sin conexión): corregido con `data-offline-omit`. BAJO-1 (`v_estado_ti` lista apps sin regla de acceso por empresa) y BAJO-2 (zona horaria de las columnas `TIMESTAMP`) quedan documentados.

## Pendiente

- Cuentas existentes: reciben el botón en su siguiente inicio de sesión con Microsoft.
- `v_estado_ti` no aplica `app_access_rules`: puede listar el nombre y el estado de una app que ningún grupo abre para esa persona (baja).
- Zona horaria de la conexión del agente: debe coincidir con la del Portal para `due_at` y las columnas `updated_at` (`TIMESTAMP`).
- La consulta de las vistas nunca se ejecutó en MariaDB aquí.
- Barra inferior del inicio en teléfono (N8): el lanzador queda encima; documentado, sin resolver.

## Seguimiento UPN (2026-11-12)

- [x] U1. Pruebas primero (RED): UPN guardado, `sub` del token, sin UPN. Ruta: directa.
- [x] U2. Migración `users.upn`, lectura en `MicrosoftIdentity`, escritura en `MicrosoftLogin::applyIdentity`, `User` (sin asignación masiva). Ruta: directa.
- [x] U3. `PanelToken::subject` = UPN, sin UPN no hay token; vista `usuario_correo` = `LOWER(users.upn)`, filas sin UPN excluidas; pruebas de paridad actualizadas. Ruta: directa.
- [x] U4. Docs. Revisión corta de solo lectura del cambio de inicio de sesión.
