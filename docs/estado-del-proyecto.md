# Estado del proyecto — Portal RYR

Resumen para retomar el trabajo (personas o agentes) sin depender de conversaciones anteriores. Actualizado: 2026-11-04.

## 1. Qué es y dónde está

Portal de Aplicaciones del Grupo Empresarial RYR (Segurex Latam + Soluciones y Derivados): inicio de sesión con Microsoft, catálogo de apps por empresa y grupo, anuncios, cuentas vinculadas entre empresas, administración, auditoría, informe de uso, API para sistemas externos, PWA y respaldos.

| Qué | Ubicación |
|---|---|
| Proyecto local | `C:\Users\Claude\Documents\PROYECTOS_CLAUDE\PAGINAS_WEB\PORTAL_RYR` |
| Repositorio (privado) | https://github.com/GrupoRyR/Portal-RYR — rama `main` |
| Producción | https://apps.segurexlatam.net |
| Servidor | GoDaddy cPanel, usuario `dzx21gq5iyfc`, proyecto en `~/portal-ryr`, Document Root `portal-ryr/public` |
| Base de datos | MariaDB 10.11 `PortalRYR`, usuario `Portal` (contraseña solo en el `.env` del servidor) |
| PHP | 8.3 fijo para toda la cuenta; el cron usa `/usr/local/bin/php` |
| Brief original | `PROMPT_PORTAL.md` |
| Decisiones y pruebas por fase | `odd/tasks/*.md` |

## 2. Estado

- Las 9 fases del brief están terminadas y fusionadas en `main` (PR #1 a #45). Suite: **984 tests** pasan (`php artisan test`, medido el 2026-10-27).
- Producción configurada y probada el 2026-10-09: inicio de sesión con Microsoft, correo por Graph (`portal:mail-test` OK), MySQL, respaldo manual OK, cron cada minuto (heartbeat OK).
- Lighthouse móvil, medido el 2026-10-09 antes de los cambios de marca (no es la medición actual): inicio de sesión 96/100/96, inicio 99/100/100 (rendimiento/accesibilidad/buenas prácticas).
- #47 (fusionada el 2026-10-22): lista de aplicaciones que solo tienen reglas de empresa completa.
- #56 y #57 (fusionadas el 2026-10-27), resultado de la auditoría de seguridad de `main`. Aún no están en producción:
  - #57: el rol de administrador del portal sale solo del grupo `APP-PortalAdmin` de la organización de Segurex, por ID de objeto (nunca por nombre). Las reglas de «Quién la ve» solo cuentan los grupos de seguridad.
  - #56: cabeceras de seguridad, HSTS solo en HTTPS, `X-Powered-By` fuera, la confirmación de acciones sensibles exige `auth_time` reciente, y las fotos de perfil ya no se guardan (`portal:purge-public-avatars`).
- **Marca SGX/SDC y pantallas de entrada** (manual de marca de 2026-10-23), fusionadas en `main` el 2026-10-26 y 2026-10-27. Aún no están en producción:
  - #48: tokens del manual (Helvetica/Arial, logo de la empresa en los encabezados, columnas de colores del manual por empresa y migraciones de datos con entrada en Auditoría).
  - #49 y #53: mensaje más claro cuando «+ Usuario» no encuentra a nadie.
  - #50: franja de marca en el inicio, logos en el inicio de sesión y en las páginas secundarias.
  - #51: Administración con la marca.
  - #52: correos con marca y color de la barra del navegador en la PWA.
  - #54: logos animados de las apps en el inicio.
  - #55: nuevo inicio de sesión de escritorio (cometas detrás de una tarjeta).
- **Cuentas vinculadas** (fase 1 del nuevo inicio, `odd/tasks/cuentas-vinculadas.md`). Una persona con una cuenta de Microsoft en cada empresa cambia de empresa desde el encabezado. Aún no está en producción:
  - T1 y T2 (#61 a #65), fusionadas en `main` el 2026-10-29: confirmación reciente ligada a la cuenta, personas, vincular y desvincular automático.
  - T3 (#67, fusionada en `main`): cambiar de empresa con un inicio de sesión de Microsoft con la cuenta de destino.
  - T4 (rama `feat/cuentas-vinculadas-4-interfaz`, fusionada en `main`): selector en el encabezado, página `/cuenta`, desvincular, comando `portal:unlink-account` y manual (§8 de `docs/manual-administracion.md`).
- **Asistente del portal** (`odd/tasks/asistente.md`, ola 3), aprobado por Jhon como líder de IA. Página `/asistente` que responde con lo que la cuenta activa ya ve, con Claude Haiku 5.5 (personas) y Sonnet 5.5 (editores) por el SDK oficial de PHP, las mismas herramientas de solo lectura del conector, presupuesto mensual de US$ 20, proveedores enchufables (DeepSeek por verificar) y un proveedor secundario opcional. Los editores reciben borradores de anuncios que solo se publican con su confirmación. Fusionado en `main` (rama `feat/asistente`). Apagado por defecto (`PORTAL_ASSISTANT_ENABLED`, interruptor de Configuración y `ANTHROPIC_API_KEY`, `docs/despliegue.md` §1.23; manual §15).
- **Conector de Claude (MCP)** (`odd/tasks/conector-mcp.md`), aprobado por Jhon como líder de IA el 2026-10-30. Cada persona conecta su Claude, incluso una cuenta personal, y Claude lee lo que ella ya ve en el portal. Solo lectura. Cadena de PR #80, #82, #84, #85, #86 y la del manual (§10 de `docs/manual-administracion.md`), fusionada en `main`. Apagado por defecto (`PORTAL_MCP_ENABLED`, `docs/despliegue.md` §1.15). C3b (`mis_tareas` y `mis_aprobaciones`) se fusionó en `main` desde la rama `feat/tareas-3-completar`, junto con «Completar» firmado (T3 de `odd/tasks/tareas-aprobaciones.md`).
- **Bloques del nuevo inicio** (`odd/tasks/inicio-bloques.md`, rama `feat/inicio-bloques`, fusionada en `main`): el inicio muestra Mercados (TRM y euro), Panel gerencial (solo Gerencia), Noticias, Catálogos y categorías, Tareas pendientes con «Completar» y Estado de TI. Cada bloque se oculta si no tiene qué mostrar o la persona no puede verlo. Con todos los bloques, el inicio corre un número fijo de consultas (22). Incluye `public/build` con el cambio de cierre de sesión (avisos del celular). Está en `main`; aún no está en producción.

### 2026-11-04

Se fusionaron en `main` los PRs #101 a #113 (`main` = `c9698ce`). Tres bloques:

| Bloque | Qué quedó | Dónde se documenta |
|---|---|---|
| **Observaciones** (#101) | Anuncios y eventos se envían por correo a la lista de todo el personal de cada empresa, desde su buzón (casilla marcada por defecto). Se pueden eliminar anuncios y eventos. Noticias: una página web busca su canal RSS (código `html_page`) y hay 20 fuentes verificadas. Los borradores del asistente **no** envían correo (decisión de Jhon, 2026-11-04). | `docs/manual-administracion.md` §2.4, §2.5, §2.11; `docs/despliegue.md` §1.13, §1.17 |
| **Liquid Glass** (#102 a #111) | Kit `resources/css/glass.css` y `glass-pages.css`; tema claro, oscuro o del sistema; encabezado con cambio de empresa, búsqueda y avisos; banda de mercados con pausa; inicio con «Mi día»; botón «Asistente»; inicio de sesión «Vitrina»; páginas secundarias y Administración en vidrio. Lighthouse móvil ≥ 90 en inicio e inicio de sesión. | Skill `liquid-glass-ryr` (para otras apps) |
| **Mercados O5** (#112, #113) | Brent, tasa de política del Banco de la República y COLCAP para todas las empresas. Insumos por empresa desde FRED (Segurex: zinc, cobre, aluminio, latón, acero; SDC: cloro y soda, resinas, envases). Precios manuales de proveedores en Administración › Mercados (máximo 12 en la banda). | `docs/despliegue.md` §1.16; `docs/manual-administracion.md` §16 |

**Para desplegar:**

1. Migraciones nuevas: `2026_11_06_100001`, `2026_11_07_100001` y `2026_11_07_100002` (`php artisan migrate --force`, paso 2.2 de `docs/despliegue.md`).
2. Después del despliegue: `php artisan portal:sync-markets`.
3. En Administración › Empresas, completar **Lista de todo el personal** y **Buzón remitente** de cada empresa.

**Problema conocido:** Banco de la República responde HTTP 500 cuando la petición no trae cabecera `Accept`. **Corregido en el PR #114** (`fix/banrep-accept`, envía `Accept: application/xml`), pendiente de fusionar y desplegar.

**Pendientes para Jhon:**

- [ ] Agregar `FRED_API_KEY` al `.env` del servidor y a `.env.example`.
- [ ] Comprobar en MariaDB la banda de Mercados con un precio manual.
- [ ] Completar la lista de correo y el buzón remitente de cada empresa.
- [ ] Revisar las fuentes RSS de `docs/despliegue.md` §1.17 en Administración › Noticias.
- [ ] Configurar `PUSH_VAPID_*` y `ANTHROPIC_API_KEY` en el servidor.
- [ ] Crear o confirmar el grupo `APP-PortalGerencia`.
- [ ] Revisar el CI del PR #89.
- [ ] Dar el OK para fusionar y desplegar el PR #114 (BanRep).
- [ ] Contrato de tareas v0.2.1: falta la lista del inicio con sus botones (`docs/contrato-tareas-aprobaciones.md`).
- [ ] Robot del panel: en pausa hasta que Jhon lo indique.

## 3. Pendientes

1. **Seguridad del correo (riesgo aceptado):** `Mail.Send` (aplicación) está concedido en Entra sin restricción, así que la app podría enviar como cualquier buzón de Segurex. Hay que restringirlo en Exchange con RBAC for Applications al buzón `segurex.info@segurex.com` y **después** quitar `Mail.Send` de Entra (los permisos de Entra se suman). Pasos: `docs/despliegue.md` §1.10. Registrado en `odd/tasks/correo-graph.md`.
2. **Cuenta de emergencia:** crearla en el servidor con `php artisan portal:emergency-account` (`docs/manual-administracion.md` §3).
3. **Pruebas en equipos reales:** Android, iPhone, tablet y computador (`docs/manual-administracion.md` §6).
4. **Recordatorio:** fecha de vencimiento del secreto de la app «Portal RYR» en Entra (renovación en `docs/despliegue.md` §1.10).
5. **Limpieza opcional en el servidor:** `~/portal-ryr/database/database.sqlite` (datos anteriores a MySQL) y `~/portal-ryr/.env.antes-20261009`.
6. **Apps aún con inicio de sesión propio:** Cuentas por pagar y Cartera (Vercel) se migrarán cuando estén completas; Portafolio al final. Guía: `docs/migracion-apps.md`. Mientras tanto el portal las abre y piden su propio usuario y contraseña.
7. **Desplegar la marca:** `cd ~/portal-ryr && bash deploy/deploy.sh`. El script corre `artisan migrate --force`, que aplica las dos migraciones de colores del manual (`2026_10_26_113259` y `2026_10_26_113300`).
8. **Subir los logos (Jhon):** en Administración › Empresas, el logo blanco de Segurex como «Logo oscuro» y el de Derivados como «Logo claro». Sin ellos aparece solo el nombre.
9. **Lighthouse en producción:** medir `/login` y el inicio. En local, `/login` dio móvil 99/100/96 y escritorio 100/100/96; el 96 de buenas prácticas viene de errores 404 de `sw.js` y del manifiesto en el servidor local (`odd/tasks/marca-sgx-sydc-y-entrada.md`, F5).
10. **Prueba en celular real:** tocar un logo animado del inicio (se encoge y la app abre) y comprobar que, con «Reducir movimiento» activo, nada se mueve (`docs/lista-de-chequeo.md`, sección D).
11. **Desplegar la seguridad (#56 y #57):** pasos en `docs/despliegue.md`, sección 2.3. Antes del despliegue: revisar que los grupos de las reglas sean de seguridad, tomar los IDs del grupo `APP-PortalAdmin` y del inquilino de Segurex, y agregar el dato `auth_time` al registro de la app. Después: `portal:sync-portal-admins`, cerrar sesiones, `portal:purge-public-avatars --force`, Force HTTPS Redirect en cPanel, comprobar cabeceras con `curl` y desactivar «Users can create security groups» en cada inquilino.
12. **Prueba de las cuentas vinculadas (Jhon):** con dos cuentas reales, antes de fusionar #67 y el PR de T4: cambio silencioso, cambio con MFA y con una cuenta desactivada en Microsoft (`odd/tasks/cuentas-vinculadas.md`, Chequeos).
13. **Decisiones abiertas de la auditoría:**
    - Invitados B2B del inquilino de Segurex: si entran con la pertenencia a `APP-PortalAdmin` del inquilino, quedan como administradores. Hoy es una decisión de Segurex sobre quién puede estar en ese grupo; no hay cambio en el código.
    - Respaldos sin cifrar: el `.zip` de cada respaldo (base de datos y archivos) no tiene contraseña, a propósito (`config/backup.php`, `password` en `null`). Decidir si se cifra, con quién se guarda la clave y cómo se recupera (`docs/despliegue.md`, sección 6).
    - `Mail.Send` podría estar concedido en otros inquilinos por consentimiento de administrador. Revisar en cada inquilino (ver el punto 1).

## 4. Cómo publicar cambios

1. Cambios en local, con pruebas (`php artisan test`, `vendor/bin/pint --test`) y `npm run build` si cambia CSS/JS (`public/build` va en el commit: el servidor no tiene Node).
2. Rama, PR y fusión en `main` en GitHub.
3. En el servidor: `cd ~/portal-ryr && bash deploy/deploy.sh` (o el script `deploy-remoto.ps1` de la skill `cpanel-deploy`).

El servidor descarga de GitHub con una llave de despliegue de solo lectura (alias SSH `github-portal-ryr` en `~/.ssh/config`).

## 5. Decisiones que no hay que reabrir

- **Alcance del portal.** Ya no es solo «muestra y abre apps»: el 2026-10-28 el gerente aprobó el nuevo inicio (prototipo en `odd/tasks/cuentas-vinculadas.md`), que agrega cuentas vinculadas y, en fases siguientes, anuncios y eventos, personas, contenido externo, integraciones, asistente y avisos. Las tareas y aprobaciones vendrán de una app aparte, según `docs/contrato-tareas-aprobaciones.md` (planeado, versión 0.1); el portal solo las muestra y abre su enlace.
- **Cuentas vinculadas.** Vincular nunca da una sesión: cambiar de empresa es un inicio de sesión real de Microsoft con la cuenta de destino. `person_id` nunca autoriza nada: roles, grupos, favoritos y anuncios siguen siendo de cada cuenta. Solo se vinculan cuentas de Microsoft de la propia organización (no invitados ni la cuenta de emergencia), de empresas activas, una por empresa. Una prueba (`tests/Unit/Auth/PersonNeverAuthorizesTest.php`) falla si otro código lee la persona.
- Laravel 13 (no 11, que ya no tiene soporte), Livewire 4 solo en Administración; el inicio no usa Livewire ni Alpine.
- Identidad = inquilino + `oid` de Microsoft; los invitados no se vinculan. Inicio de sesión multiinquilino («Allow all tenants»); el portal filtra por las empresas activas.
- Roles: `super_admin` (correos de `PORTAL_SUPER_ADMIN_EMAILS`), `portal_admin` (grupo de seguridad `APP-PortalAdmin` de la organización de Segurex, por ID de objeto: `PORTAL_ADMIN_GROUP_ID` + `PORTAL_ADMIN_TENANT_ID`; sin ellos nadie es administrador; `app/Auth/PortalAdminGroup.php`), `announcements_editor` (manual). Configuración e Integraciones: solo súper administrador.
- Las reglas de «Quién la ve» por grupo solo cuentan con grupos de seguridad (no Microsoft 365 ni Teams). Se guarda `securityEnabled`/`groupTypes` por grupo; un dato desconocido no da acceso (`app/Microsoft/HttpGraphClient.php`, `app/Catalog/AppCatalog.php`).
- Las fotos de perfil de Microsoft no se guardan: el portal muestra iniciales.
- Cabeceras de seguridad en todas las respuestas (`App\Http\Middleware\SecurityHeaders`, `public/.htaccess`): `nosniff`, `X-Frame-Options: DENY`, `frame-ancestors 'none'`, Referrer-Policy, Permissions-Policy, HSTS solo en HTTPS. Sin CSP completa por ahora: Livewire y Alpine necesitan `unsafe-eval` y estilos en línea; se revisará cuando se adopte la build de CSP de Alpine.
- Cada app decide su propio acceso con su tabla `usuarios` (opción A); no existe `/api/access/{app}`. Registro en Entra de un solo inquilino por app.
- Crear o rotar tokens de la API exige confirmar con Microsoft (10 min). Las apps ocultas responden 404 a la API.
- Respaldos solo en el mismo servidor: 02:00 limpieza, 02:30 respaldo, 08:00 monitor; 7 diarios + 4 semanales; tope 2000 MB; correo solo si falla.
- Correo por Microsoft Graph (no SMTP): `MAIL_MAILER=graph`, remitente `segurex.info@segurex.com`.
- Colores oficiales: el manual de marca SGX/SDC (2026-10-23) reemplaza a los colores del brief. Segurex: acento `#F26522`, botón `#C6531C`, texto de marca `#943E15`, texto largo y oscuro `#58595B`; Derivados: acento y botón `#0B5FA5`, texto de marca, texto largo y oscuro `#073B66`. Tipografía Helvetica/Arial, sin fuentes web. Los colores del manual se guardan por empresa (`color_button`, `color_ink`, `color_text`, `color_ink_dark_mode`, `color_text_dark_mode`); los que faltan se calculan del principal (`app/Branding/BrandPalette.php`). Fuente: `database/migrations/2026_10_26_113300_apply_brand_manual_colors.php`.
- «+ Usuario» en «Quién la ve» solo encuentra a personas que ya entraron al portal, y es intencional: el portal no consulta el directorio de Microsoft. Buscar en el directorio por Graph se dejó para más adelante (`app/Livewire/Admin/AppAccessRules.php`). Para dar acceso a quien aún no entra, usar un grupo `APP-…` o pedir que entre una vez.

## 6. Lecciones de la puesta en producción

- `.env.example` deja comentados los valores de producción; si una clave se repite, gana la **última**. Revisar siempre con `grep -n -E "^(APP_ENV|APP_DEBUG|APP_URL|DB_CONNECTION)" .env`.
- El cron se crea en cPanel › Cron Jobs; ejecutarlo en la terminal no lo programa.
- El Document Root de cPanel es relativo a la carpeta personal (`portal-ryr/public`).
- `DatabaseSeeder` no usa factories (Faker no está en producción): `php artisan db:seed --force` es seguro y repetible.

## 7. Herramientas

- Skills de usuario: `ryr-design` (sistema de diseño para apps nuevas) y `cpanel-deploy` (instalación y despliegue GitHub → cPanel), en `C:\Users\Claude\.claude\skills\`.
- GitHub CLI: `C:\Program Files\GitHub CLI\gh.exe` (no está en el PATH de Git Bash), sesión iniciada como `GrupoRyR`.
- Convenciones del repositorio: commits con Conventional Commits y sin atribución a IA; los agentes no leen ni editan archivos `.env*`.

## 8. Documentación

| Documento | Para qué |
|---|---|
| `docs/despliegue.md` | Instalación, publicar cambios (§2), reversa (§3), qué no hacer (§4), problemas (§5), respaldos (§6), Microsoft y correo (§1.10) |
| `docs/manual-administracion.md` | Uso diario de Administración, cuenta de emergencia (§3), tareas frecuentes (§4), pruebas en equipos (§6), cuentas vinculadas y `portal:unlink-account` (§8), conectar Claude (§10) |
| `docs/api.md` | API para sistemas externos |
| `docs/contrato-tareas-aprobaciones.md` | Contrato planeado con la app de tareas y aprobaciones (aún no implementado) |
| `docs/migracion-apps.md` | Pasar las demás apps a «Entrar con Microsoft» |
