# Conexión del Portal con el agente RYR

Contrato de referencia: `CONTRATO_CONEXION_AGENTE.md` v0.4 del proyecto del agente. Este documento es el procedimiento de TI para dejar el Portal conectado. El código está en `app/Agent/**`, `config/agent.php` y la migración `2026_11_12_000001_create_agent_views.php`; la decisión y el avance, en `odd/tasks/agente-conexion.md`.

## 1. Qué hace y qué decidimos

- Un botón flotante «Preguntar al agente» **reemplaza** al botón «Asistente» en el inicio y en las páginas secundarias con el encabezado del Portal (la misma regla `@if ($assistant)` del layout). La página `/asistente` y su enlace del menú de usuario quedan **solo** para los borradores de anuncio.
- El agente consulta cinco vistas de solo lectura (`v_*`) con el usuario `agente_portal_ro`. Nunca toca tablas.
- **La empresa sigue a la cuenta.** Cada empresa tiene su propio inquilino y su cuenta; el token lleva la empresa (`SGX` o `SDC`) de la cuenta en sesión, nunca `*`. Una persona con una cuenta en cada empresa abre el agente con la empresa de la cuenta con la que entró.
- **Dos roles**, de menor a mayor: `empleado` y `gerencia`. `gerencia` se firma solo mientras pasa la misma comprobación del panel de gerencia del Portal (`ManagementPanel::ABILITY`: rol `management` y pertenencia al grupo de gerencia de su empresa, revisada en cada página); todos los demás son `empleado`. Si se vacía el grupo en Administración, el token deja de decir `gerencia` al instante. El token nunca sube el rol: la asignación por persona la hace TI en el agente.
- **`roles_por_persona` = empleado y gerencia.** Solo cambia lo que ve quien usa una herramienta con filtro `usuario` (`mis_pendientes`): siempre sus propias filas, también `gerencia`. Las otras cuatro herramientas son de la empresa y abren desde su rol mínimo.
- **Identidad = UPN.** El token lleva en `sub` el UPN de la cuenta en minúsculas, que el Portal guarda en `users.upn` en **cada inicio de sesión con Microsoft** (el perfil de Graph `userPrincipalName`, o el reclamo `upn`; nunca `preferred_username` ni `email`). `users.email` no cambia (sigue siendo `mail` primero) y ya no se usa para el agente. Un UPN de invitado (`#EXT#`) no se guarda.
- **Sin token ni botón** para cuentas de emergencia o invitadas, cuentas que aún no tienen UPN, cuentas sin empresa, empresas fuera del mapa SGX/SDC o inactivas, cuentas cuyo UPN no está en un dominio de su empresa, ni cuando faltan la llave o el origen del agente. El botón tampoco entra en la copia del inicio guardada sin conexión.
- Sin acciones en el primer manifiesto. Tampoco rol `editor`.

## 2. Variables de entorno

Las define TI en el `.env` del servidor. Nada de esto se versiona.

| Variable | Qué es |
|---|---|
| `AGENT_URL` | Origen https del agente, sin ruta ni barra final, por ejemplo `https://agente.example.com` |
| `AGENT_PANEL_KEY` | Llave de panel de esta app: texto base64 de 32 bytes o más. La entrega el equipo del agente por un canal seguro |
| `AGENT_APP_URL` | Origen https del Portal tal como lo ve el agente. Si falta, se usa `APP_URL` |

Si `AGENT_URL` o `AGENT_PANEL_KEY` faltan o no son válidos, el botón no se dibuja, no se firma ningún token, y la cabecera `Permissions-Policy` vuelve a `microphone=()`. El manifiesto solo depende de `AGENT_APP_URL` o `APP_URL` (responde 503 si ninguno es un origen https), así que se puede importar antes de encender el botón.

Generar una llave (en un equipo de confianza, nunca en el chat):

```bash
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

## 3. Vistas

Una migración (`2026_11_12_000001_create_agent_views.php`) crea la vista auxiliar `aux_empresa` y las cinco vistas. Son SQL puro, con columnas explícitas, y no dependen de la zona horaria de la sesión: la base guarda hora de Bogotá (UTC-5, sin horario de verano) y las vistas calculan «ahora» con `UTC_TIMESTAMP() - INTERVAL 5 HOUR`. Una prueba falla si alguien cambia `APP_TIMEZONE` a una zona con otro desfase.

| Vista | Para qué | Dueño por persona |
|---|---|---|
| `v_anuncios_vigentes` | Anuncios publicados ahora para la empresa | no |
| `v_eventos_proximos` | Eventos publicados que aún no terminan; los anuales con su próxima fecha | no |
| `v_tareas_pendientes` | Tareas y aprobaciones pendientes de cada persona | `usuario_correo` = el UPN de la cuenta (`users.upn`), en minúsculas |
| `v_estado_ti` | Servicios de TI y apps con falla o en mantenimiento | no |
| `v_kpis_empresa` | Cifras del panel gerencial (rol `gerencia`) | no |

Todas llevan `empresa` (`SGX` o `SDC`). `aux_empresa` no lleva el prefijo `v_`, así que el usuario de solo lectura no puede leerla; las vistas la usan con los permisos de quien las creó (`DEFINER`).

Lo que no se expone: contraseñas, `remember_token`, 2FA, `azure_oid`, `tenant_id`, grupos, integraciones y tokens, auditoría, lanzamientos y favoritos de personas, autoría de anuncios, imágenes, lecturas individuales. `v_tareas_pendientes` es la única vista con un dato de persona (su correo) y solo trae tareas de cuentas activas con empresa (sin invitadas ni de emergencia).

La visibilidad que decidía PHP (`scopeVisibleTo`, `ItStatusBoard`, `WorkItem::scopeVisibleTo`) pasó a SQL. Para que no se desvíe hay pruebas de paridad entre cada vista y el alcance de Eloquent (`tests/Feature/Agent/Views`). **Si cambia una regla de visibilidad en PHP, cambie también la vista y su prueba.**

Los festivos de Colombia (los calcula PHP) y los cumpleaños no están en ninguna vista; el agente no los ve.

## 4. Usuario de solo lectura (`agente_portal_ro`)

El agente se conecta con `agente_portal_ro` (cPanel le antepone su prefijo, por ejemplo `cuenta_agente_portal_ro`) y solo puede hacer `SELECT` sobre las cinco vistas `v_*`.

**Antes de empezar:** quien ejecuta los `GRANT` necesita `GRANT OPTION` sobre la base (`SHOW GRANTS;`). La pantalla «Bases de datos MySQL» de cPanel da privilegios por base completa, no por vista. **Si el alojamiento no deja otorgar permisos por objeto, deténgase y avise:** dar `SELECT` sobre toda la base rompería el contrato (§3.3) y expondría `users`, `integrations`, `oauth_*` y la auditoría.

Con un usuario que sí pueda otorgar permisos (valores de ejemplo entre `<>`):

```sql
CREATE USER '<cuenta>_agente_portal_ro'@'<host del agente>' IDENTIFIED BY '<clave larga generada>';

GRANT SELECT ON `<base>`.`v_anuncios_vigentes` TO '<cuenta>_agente_portal_ro'@'<host del agente>';
GRANT SELECT ON `<base>`.`v_eventos_proximos`  TO '<cuenta>_agente_portal_ro'@'<host del agente>';
GRANT SELECT ON `<base>`.`v_tareas_pendientes` TO '<cuenta>_agente_portal_ro'@'<host del agente>';
GRANT SELECT ON `<base>`.`v_estado_ti`         TO '<cuenta>_agente_portal_ro'@'<host del agente>';
GRANT SELECT ON `<base>`.`v_kpis_empresa`      TO '<cuenta>_agente_portal_ro'@'<host del agente>';
```

- `<host del agente>` es `localhost` si el agente está en la misma cuenta de cPanel. Si está en otro servidor, es su IP, y MySQL remoto se abre **solo** a esa IP.
- La clave se guarda únicamente en la configuración del agente (`APPDB_PORTAL_RYR_PASSWORD`), nunca en este repositorio ni en el `.env` del Portal.
- Cada vista que se agregue después necesita su propio `GRANT`.

### Comprobar que no lee tablas

Conectado **como `agente_portal_ro`**:

```sql
SHOW GRANTS FOR CURRENT_USER();
-- Solo GRANT SELECT sobre las cinco vistas v_*. Nada sobre la base completa ni GRANT OPTION.

SELECT empresa, COUNT(*) FROM v_anuncios_vigentes GROUP BY empresa;   -- funciona
SELECT usuario_correo, COUNT(*) FROM v_tareas_pendientes GROUP BY usuario_correo;   -- funciona

SELECT * FROM users LIMIT 1;            -- debe fallar: SELECT command denied (1142)
SELECT * FROM integrations LIMIT 1;     -- debe fallar
SELECT * FROM activity_log LIMIT 1;     -- debe fallar
SELECT * FROM aux_empresa LIMIT 1;      -- debe fallar
INSERT INTO v_estado_ti (empresa) VALUES ('SGX');   -- debe fallar
```

Si alguna lectura de tablas funciona, revoque (`REVOKE ALL PRIVILEGES, GRANT OPTION FROM ...`) y vuelva a empezar.

## 5. Ficha de alta (contrato §9)

```markdown
## Ficha de alta: Portal RYR

- slug propuesto: portal-ryr
- Nombre visible: Portal RYR
- Empresas: ambas (SGX y SDC); app normal, sin «todas las empresas» (empresa = *)
- URL (origen https): <la real, confirmada por TI>
- Contacto técnico: <correo del responsable>
- Roles, de menor a mayor permiso: empleado, gerencia
- Roles que solo ven lo suyo (y columna del dueño): empleado y gerencia; columna usuario_correo (solo v_tareas_pendientes)
- Vistas v_* y para qué sirve cada una: sección 3 de este documento
- Acciones (endpoint, qué hace, rol mínimo): ninguna
- ¿Incrusta el panel flotante? sí; reemplaza al botón «Asistente»
- Personas a asignar y su rol: las que decida TI; gerencia para quienes están en el grupo de gerencia de su empresa
```

## 6. Despliegue, paso a paso (TI)

Orden importante: primero la base, al final el botón.

1. **Usuario de solo lectura.** Siga la sección 4. Si no se pueden otorgar permisos por vista, pare.
2. **Código.** Despliegue la rama como siempre (`docs/despliegue.md`) y ejecute las migraciones (incluida `2026_11_12_000002_add_upn_to_users_table`, que agrega `users.upn`). **Las cuentas existentes no tienen UPN hasta que vuelven a iniciar sesión con Microsoft:** hasta entonces no ven el botón y no aparecen en `mis_pendientes`. Pídales que cierren sesión y entren de nuevo; el botón aparece en ese primer inicio. Compruebe con el usuario de la aplicación que existen las vistas: `SHOW FULL TABLES WHERE Table_type = 'VIEW';` debe listar `aux_empresa` y las cinco `v_*`.
3. **Variables.** Agregue `AGENT_URL`, `AGENT_PANEL_KEY` y, si hace falta, `AGENT_APP_URL` al `.env` (sección 2). Recargue la configuración (`php artisan config:cache` si el despliegue la usa).
4. **Llave de panel.** La entrega el equipo del agente por un canal seguro; el mismo valor se registra allí con el slug `portal-ryr`. No viaja por correo ni chat en claro.
5. **Alta en el agente.** App **normal** (no se habilita «todas las empresas»): slug `portal-ryr`, empresas SGX y SDC, URL del Portal, los dos roles. Un token cuya empresa no coincide con el inquilino de la cuenta se rechaza.
6. **Manifiesto.** Compruebe que `https://<portal>/.well-known/agente-ryr.json` responde 200 sin iniciar sesión y sin cookies, con `version` igual a `Manifest::VERSION`. Impórtelo en el agente.
7. **Conexión del agente** a la base con `agente_portal_ro` (`APPDB_PORTAL_RYR_*` en el agente).
8. **Asignaciones.** Una por persona, con su rol. El agente identifica a la persona por el UPN (en minúsculas) que el Portal guarda al iniciar sesión: el mismo que usa el agente como correo del usuario.
9. **Prueba de aceptación** (sección 7). Si algo falla, quite `AGENT_URL` del `.env`: el botón desaparece y la cabecera del micrófono vuelve a la de antes, sin tocar código.

Reversa: `AGENT_URL` vacío apaga el botón y el token; `php artisan migrate:rollback --path=database/migrations/2026_11_12_000001_create_agent_views.php` quita las vistas (el rollback solo ejecuta `DROP VIEW`).

## 7. Lista de aceptación (contrato §7)

Con usuarios de prueba, uno por rol y dos personas distintas para el filtro `usuario`. Marque cada línea en el ambiente del agente.

- [ ] El manifiesto se importa sin errores y su `version` coincide con la que publica el Portal.
- [ ] **Empleado** (cuenta sin el rol `management`): ve `anuncios_vigentes`, `proximos_eventos`, `mis_pendientes` y `estado_de_ti`; **no** ve `kpis_gerenciales`.
- [ ] **Gerencia** (cuenta en el grupo de gerencia de su empresa): ve además `kpis_gerenciales`.
- [ ] **Persona A** pregunta «¿qué tengo pendiente?» y recibe solo sus tareas; **persona B** (otra cuenta, misma empresa) recibe solo las suyas. Pedirle al agente «las tareas de A» desde la cuenta de B no devuelve filas de A. Probar con una persona con rol `gerencia` también.
- [ ] Una persona de **SGX** no recibe filas de SDC (anuncios, eventos, estado de TI, cifras) y al revés.
- [ ] Una persona con una cuenta en cada empresa: con la cuenta de SGX solo ve SGX y con la de SDC solo SDC.
- [ ] Una consulta con más filas que `limite_filas` avisa que hubo recorte.
- [ ] El botón abre el panel, una pregunta responde y una gráfica abre el cajón.
- [ ] Un token reusado, vencido (más de 5 minutos) o con un rol inexistente no abre sesión.
- [ ] Una persona sin asignación, o que nunca entró al agente, no abre sesión.
- [ ] Una cuenta de emergencia y una invitada **no** ven el botón; una cuenta que todavía no volvió a iniciar sesión (sin `users.upn`) tampoco, y lo ve tras su siguiente inicio de sesión.
- [ ] Una persona cuyo `mail` difiere de su UPN abre el agente y `mis_pendientes` trae sus tareas.
- [ ] Las vistas no exponen columnas de más: `SELECT *` sobre cada `v_*` como `agente_portal_ro` solo trae las columnas de la sección 3.

## 8. Cómo actualizar la copia del lanzador

`public/js/agente-lanzador.js` es la copia propia del lanzador del agente (versión 2, SHA-256 `a480f3a71d8d2d7b17f75e612237a54876b34022d8f01eeb7bdb1c3634835eeb`). Corre dentro de las páginas del Portal, por eso se sirve desde aquí (así `script-src` no necesita al agente) y `tests/Feature/Agent/LauncherCopyTest.php` falla si el archivo cambia sin querer.

Para actualizarla: 1) pida al equipo del agente el archivo nuevo y su versión; 2) compare su SHA-256 con la tabla del contrato (§3.6); 3) cópielo byte a byte y actualice `EXPECTED_VERSION` y `EXPECTED_SHA256` en la prueba; 4) despliegue. El navegador toma la copia nueva porque la URL lleva `?v=<fecha del archivo>`.

## 9. Pendientes, riesgos y decisiones abiertas

- **Identidad (UPN).** `users.upn` se llena en cada inicio de sesión desde el perfil de Graph o el reclamo `upn`, y lo controlan los administradores del inquilino (igual que `mail`): por eso el token solo se firma si el UPN está en un dominio de la empresa de la cuenta (`Company::ownsEmailDomain`). Una cuenta nueva o que no ha vuelto a entrar no tiene UPN y no recibe token hasta su siguiente inicio de sesión. Una cuenta cuyo UPN esté en un dominio que no figura en `companies.domains` tampoco: agregue el dominio en Administración › Empresas.
- **Una persona, dos cuentas.** `mis_pendientes` muestra solo las tareas de la cuenta con la que se entró, no las de la otra empresa (el conector MCP sí junta cuentas).
- **Barra inferior del inicio en teléfono (N8).** El lanzador (derecha 16 px, abajo 16 px, `z-index` 2147483000) queda encima de la barra `.g-dock` (`z-30`) del inicio en teléfono. El botón anterior se subía con `.g-fab--raised`; el lanzador no tiene esa opción hasta que el agente la agregue (necesidad N8, desplazamiento vertical). No hay otro elemento fijo en la esquina inferior derecha con `z-index` mayor a 9000.
- **Token y páginas guardadas.** El inicio se guarda para uso sin conexión y el token dibujado caduca a los 5 minutos y es de un solo uso. Mientras el lanzador no pida el token al hacer clic (necesidad N5), una página vieja abre el agente con un token vencido: recargar lo renueva.
- **`gerencia` depende del último inicio de sesión.** Si una persona sale del grupo de gerencia, el rol del token cambia al iniciar sesión de nuevo en el Portal; el rol asignado por TI en el agente es el techo.
- **Cambios de visibilidad en PHP** deben replicarse en las vistas (sección 3).
- **Vistas no ejecutadas en MariaDB aquí.** Las pruebas corren en SQLite; el dialecto MariaDB tiene su prueba opcional (`AGENT_TEST_MARIADB_*`, contra una base vacía). Córrala en un ambiente de prueba antes de desplegar.
- **Dos asistentes.** `/asistente` sigue para borradores de anuncio hasta que exista la acción equivalente del agente (P7, otra tarea).
- **Llaves.** La rotación de la llave de panel se pide al equipo del agente; el Portal solo cambia `AGENT_PANEL_KEY`.
- **Zona horaria de la conexión del agente.** `work_items.due_at`, `it_services.updated_at` y `apps.updated_at` son columnas `TIMESTAMP`: en MariaDB su texto depende de la zona horaria de la sesión que lee. El Portal no fija `timezone` en la conexión (`config/database.php`), así que la conexión del agente debe usar el mismo `time_zone` que la del Portal; si no, `vencida` (`mis_pendientes`) y `actualizado` (`estado_de_ti`) pueden correrse horas. Las demás columnas son `DATETIME` y no se afectan. Compruébelo en la prueba de aceptación con una tarea que venza hoy.
- **`puede_completar` es una cota superior.** Completar desde el Portal depende de validar en PHP la URL de la integración (`Integration::completionUrl`); la vista dice 1 cuando es tarea, es completable, y la integración tiene secreto y URL base. Las aprobaciones siempre dan 0. El agente no completa nada de todos modos.
- **Diferencias deliberadas con el Portal.** (1) `v_tareas_pendientes` excluye cuentas invitadas y de emergencia (el alcance de Eloquent no las excluye). (2) `v_estado_ti` lista todos los servicios, también los operando; el tablero del Portal solo muestra los que no lo están. (3) Las reglas de acceso por grupo de Entra a las apps viven en PHP y no se replican: la vista puede listar una app que la empresa tiene pero que ningún grupo abre.
- **Vistas listas para MariaDB, no ejecutadas aquí.** Construcciones a vigilar en la primera ejecución: subconsultas en `FROM` dentro de una vista, `STR_TO_DATE`, `JSON_CONTAINS(abilities, '"work:write"')`, `INTERVAL (expr) HOUR` y `CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW`.
