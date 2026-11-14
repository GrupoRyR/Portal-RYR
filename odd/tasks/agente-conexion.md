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

Dos escritores en archivos distintos del mismo worktree: el subagente (vistas y sus pruebas: `database/migrations/2026_11_12_000003_create_agent_views.php`, `app/Agent/Views/**`, `tests/**/Agent/Views/**`) y esta sesión (el resto). Disparadores: más de 2 archivos no triviales y lectura previa a la escritura. El subagente no hace commits.

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
- Las vistas se ejercitaron una vez en MariaDB 10.11.19 (prueba opcional, 4 casos). Repetir en el ambiente de prueba del hosting antes de desplegar.
- Barra inferior del inicio en teléfono (N8): el lanzador queda encima; documentado, sin resolver.

## Seguimiento UPN (2026-11-12)

- [x] U1. Pruebas primero (RED): UPN guardado, `sub` del token, sin UPN. Ruta: directa.
- [x] U2. Migración `users.upn`, lectura en `MicrosoftIdentity`, escritura en `MicrosoftLogin::applyIdentity`, `User` (sin asignación masiva). Ruta: directa.
- [x] U3. `PanelToken::subject` = UPN, sin UPN no hay token; vista `usuario_correo` = `LOWER(users.upn)`, filas sin UPN excluidas; pruebas de paridad actualizadas. Ruta: directa.
- [x] U4. Docs. Revisión corta de solo lectura del cambio de inicio de sesión.

## Seguimiento: orden de migraciones (2026-11-12)

- La migración de las vistas se llamaba `…000001` y corría antes de `users.upn` (`…000002`): en MariaDB `CREATE VIEW v_tareas_pendientes` falló con «Unknown column 'u.upn'» y SQLite no lo vio, porque no comprueba las columnas al crear la vista.
- [x] Renombrada a `2026_11_12_000003_create_agent_views.php` (`git mv`). Prueba nueva `MigrationOrderTest`: crea el esquema en orden hasta la migración de las vistas y lee cada vista por sus columnas (falló antes del cambio con el mismo error en SQLite) y comprueba el orden de los dos archivos.
- [x] `AgentViewsMariaDbTest`: el `tearDown` usa `db:wipe --drop-views` (sin eso las vistas quedaban y los casos siguientes se omitían). Los 4 casos pasan en MariaDB 10.11.19.

## Seguimiento: correcciones de la revisión del agente del Portal (2026-11-12)

Cinco puntos, uno por commit, pruebas primero (RED y luego GREEN). Ruta: directa en todos (cambios pequeños y entendidos).

- [x] R1. Botón de respaldo: el layout dibuja `<x-agent-panel />` solo si `AgentSettings::active()` y `<x-assistant-fab />` si no (como en `origin/main`). `AssistantFabTest` volvió a su versión de `origin/main`; `AgentPanelTest` prueba los cuatro casos de configuración rota y la cuenta rechazada. Commit `c71f5d2`.
- [x] R2. `v_estado_ti` aplica `app_access_rules`: lista una app solo si existe una regla «toda la empresa» (`type = company`) de esa empresa. Las reglas por grupo de Entra o por persona dependen de quién pregunta y SQL no ve los grupos: esas apps se dejan fuera (falla cerrada), documentado. Pruebas de paridad: sin regla, solo grupo, solo persona, regla de otra empresa, regla de una empresa no vinculada, y un miembro del grupo ve más en el tablero que en la vista. La prueba de MariaDB opcional se actualizó, pero **no se ejecutó** (no hay MariaDB en esta sesión). Commit `e302e4f`.
- [x] R3. `PanelToken::for` revisa llave y origen antes de armar las claims; prueba: sin llave u origen no corre ninguna consulta. Commit `ca3f2c8`.
- [x] R4. `AgentPanelQueryCountTest`: con la conexión encendida el botón suma como máximo `HomeQueryBudget::AGENT_PANEL` (1) consulta, igual con 1 o con 6 filas, para cuenta ordinaria y de gerencia. La consulta extra medida es la carga del registro de permisos (en producción la caché de permisos la absorbe). Commit `19ef70d`.
- [x] R5. `/code-review` (nivel alto) sobre `origin/main..HEAD`: 7 hallazgos, ninguno alto; ninguno aplicado. Quedan para decisión: (1) con la conexión configurada, una cuenta sin UPN (todas hasta su próximo inicio de sesión), invitada o sin mapa no ve ningún botón; (2) las apps con regla solo por grupo no salen en `v_estado_ti`; (3) `LOWER()` sobre columnas indexadas en `v_tareas_pendientes`; (4) un inicio de sesión sin UPN borra el guardado; (5) la prueba de consultas tiene cotas inferiores triviales; (6) la clave y el origen se interpretan varias veces por petición; (7) una base que ya corrió la migración 000003 conserva la vista vieja. **Resolución:** se aplicó el hallazgo (1) en `8ca3603`: el layout dibuja `<x-agent-panel />` solo si `AgentPanel::offeredTo($user)` (conexión configurada y cuenta elegible); en cualquier otro caso dibuja `<x-assistant-fab />`, así que ninguna cuenta se queda sin botón (pruebas: sin UPN, invitada y empresa sin mapa caen al botón viejo; la cuenta elegible ve solo el del agente; sin consultas nuevas). Descartados, con su razón: (2) es una decisión de producto abierta, hoy una app solo por grupo no sale en `v_estado_ti` (falla cerrado); (3), (4), (6) y (7) quedan fuera del alcance de este seguimiento (el brief pidió solo el hallazgo 1) y siguen anotados para quien decida; (5) la cota `HomeQueryBudget::AGENT_PANEL` ya es 0 y la prueba de consultas la ejerce.

## Seguimiento: N5, el token se pide al hacer clic (T5.7, 2026-11-12)

Lanzador versión 3 del agente (`main` 5c5a4b6, SHA-256 `0a7d1f30…ca38`). Pruebas primero (RED: 12 fallas y 1 error) y luego GREEN. Ruta: directa.

- [x] N5.1. `POST /agente/token` (`App\Http\Controllers\Agent\TokenController`, ruta `agent.token`): detrás de `auth` y CSRF, 10 por minuto por cuenta en un contador propio (`throttle:10,1,agent-token:`: el tercer argumento es un prefijo de clave, así no comparte contador con las rutas `throttle:N,1`), `200 {"token"}` con `no-store`, `404` con cuerpo fijo si no hay token, nunca redirige. Misma firma y elegibilidad: `PanelToken::for`. Pruebas en `AgentTokenRouteTest` (12). Commit `9c0b794`.
- [x] N5.2. Copia del lanzador versión 3, byte a byte; `LauncherCopyTest` fija versión 3 y el SHA-256. Commit `4fe7f89`.
- [x] N5.3. Componente: sin token en la página; enlace `<agente>/panel/abrir`; `data-agente-token-url` con `route('agent.token', absolute: false)`; la elegibilidad se decide al dibujar con `PanelToken::eligible`, sin la comprobación de gerencia (el rol se resuelve al firmar). La meta `csrf-token` ya está en el layout `components/layouts/app` (probada). El botón ya no suma consultas a la página (`HomeQueryBudget::AGENT_PANEL = 0`). Commit `4fe7f89`.
- [x] N5.4. CSP: el Portal solo envía `frame-ancestors 'none'`, sin `connect-src`: el `POST` del mismo origen pasa; el service worker ignora todo lo que no sea `GET`.
- [x] MariaDB: el `v_estado_ti` con reglas de acceso se ejecutó el 2026-11-13 en MariaDB 10.11.19 local (zip oficial, base vacía `agent_test`, puerto 3307): `AgentViewsMariaDbTest` → 4 passed, 22 assertions. Las migraciones crearon todas las vistas sin errores.
