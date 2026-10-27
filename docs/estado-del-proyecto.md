# Estado del proyecto — Portal RYR

Resumen para retomar el trabajo (personas o agentes) sin depender de conversaciones anteriores. Actualizado: 2026-10-27.

## 1. Qué es y dónde está

Portal de Aplicaciones del Grupo Empresarial RYR (Segurex Latam + Soluciones y Derivados): inicio de sesión con Microsoft, catálogo de apps por empresa y grupo, anuncios, administración, auditoría, informe de uso, API para sistemas externos, PWA y respaldos.

| Qué | Ubicación |
|---|---|
| Proyecto local | `C:\Users\Claude\Documents\PROYECTOS_CLAUDE\PAGINAS_WEB\PORTAL_RYR` |
| Repositorio (privado) | https://github.com/GrupoRyR/Portal-RYR — rama `main` |
| Producción | https://apps.segurexlatam.net |
| Servidor | GoDaddy cPanel, usuario `dzx21gq5iyfc`, proyecto en `~/portal-ryr`, Document Root `portal-ryr/public` |
| Base de datos | MySQL `PortalRYR`, usuario `Portal` (contraseña solo en el `.env` del servidor) |
| PHP | 8.3 fijo para toda la cuenta; el cron usa `/usr/local/bin/php` |
| Brief original | `PROMPT_PORTAL.md` |
| Decisiones y pruebas por fase | `odd/tasks/*.md` |

## 2. Estado

- Las 9 fases del brief están terminadas y fusionadas en `main` (PR #1 a #45). Suite: **984 tests** pasan (`php artisan test`, medido el 2026-10-27).
- Producción configurada y probada el 2026-10-09: inicio de sesión con Microsoft, correo por Graph (`portal:mail-test` OK), MySQL, respaldo manual OK, cron cada minuto (heartbeat OK).
- Lighthouse móvil: inicio de sesión 96/100/96, inicio 99/100/100 (rendimiento/accesibilidad/buenas prácticas).
- **Marca SGX/SDC y pantallas de entrada** (manual de marca de 2026-10-23), fusionadas en `main` el 2026-10-26 y 2026-10-27. Aún no están en producción:
  - #47: lista de aplicaciones que solo tienen reglas de empresa completa.
  - #48: tokens del manual (Helvetica/Arial, logo de la empresa en los encabezados, columnas de colores del manual por empresa y migraciones de datos con entrada en Auditoría).
  - #49 y #53: mensaje más claro cuando «+ Usuario» no encuentra a nadie.
  - #50: franja de marca en el inicio, logos en el inicio de sesión y en las páginas secundarias.
  - #51: Administración con la marca.
  - #52: correos con marca y color de la barra del navegador en la PWA.
  - #54: logos animados de las apps en el inicio.
  - #55: nuevo inicio de sesión de escritorio (cometas detrás de una tarjeta).

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

## 4. Cómo publicar cambios

1. Cambios en local, con pruebas (`php artisan test`, `vendor/bin/pint --test`) y `npm run build` si cambia CSS/JS (`public/build` va en el commit: el servidor no tiene Node).
2. Rama, PR y fusión en `main` en GitHub.
3. En el servidor: `cd ~/portal-ryr && bash deploy/deploy.sh` (o el script `deploy-remoto.ps1` de la skill `cpanel-deploy`).

El servidor descarga de GitHub con una llave de despliegue de solo lectura (alias SSH `github-portal-ryr` en `~/.ssh/config`).

## 5. Decisiones que no hay que reabrir

- Laravel 13 (no 11, que ya no tiene soporte), Livewire 4 solo en Administración; el inicio no usa Livewire ni Alpine.
- Identidad = inquilino + `oid` de Microsoft; los invitados no se vinculan. Inicio de sesión multiinquilino («Allow all tenants»); el portal filtra por las empresas activas.
- Roles: `super_admin` (correos de `PORTAL_SUPER_ADMIN_EMAILS`), `portal_admin` (grupo `APP-PortalAdmin`), `announcements_editor` (manual). Configuración e Integraciones: solo súper administrador.
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
| `docs/manual-administracion.md` | Uso diario de Administración, cuenta de emergencia (§3), tareas frecuentes (§4), pruebas en equipos (§6) |
| `docs/api.md` | API para sistemas externos |
| `docs/migracion-apps.md` | Pasar las demás apps a «Entrar con Microsoft» |
