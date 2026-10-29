# Nuevo inicio, fase 2: anuncios y eventos

Fase 2 del nuevo inicio, después de la fase 1 (`odd/tasks/inicio-fase-1.md`). Plan: «Fases siguientes», punto 2 («Anuncios y eventos»), del plan del nuevo inicio aprobado por Jhon.

## Objetivo

Que un anuncio importante llegue a todos y se sepa quién lo leyó: lectura obligatoria con confirmación y conteo para los editores; después, eventos con archivo `.ics`, un resumen de los lunes y correo a las listas de cada empresa.

## Problema y por qué

- Hoy un anuncio se publica y no se sabe si alguien lo leyó; para cambios de política o avisos de seguridad hace falta una confirmación por persona.
- Las fechas de eventos viven en correos sueltos; un `.ics` las pone en el calendario de cada uno.
- El correo a las listas de Exchange solo acepta remitentes internos y `Mail.Send` está sin acotar (`accepted-risk-mail-send`): depende de Jhon.

## Alcance

- WU1: lectura obligatoria (tabla `announcement_reads`), botón «Confirmar lectura» y conteo «X de Y personas lo leyeron» en Admin.
- WU2: eventos con `.ics`.
- WU3: resumen de los lunes por cron (bloqueada si necesita correo).
- WU4: correo a las listas de cada empresa (bloqueada: Jhon).

## Fuera de alcance

- Cualquier envío de correo en WU1.
- Recordatorios automáticos a quien no ha leído.

## Restricciones

- Sin Livewire ni Alpine en `/`. Presupuesto de consultas del inicio ≤ 12 (`HomeScreenTest`).
- El service worker guarda el HTML completo de `/` por persona hasta 24 h: la confirmación es siempre un POST al servidor; un «Lectura obligatoria» viejo en la copia guardada es aceptable si al tocarlo no se rompe nada.
- Misma visibilidad que hoy (`Announcement::scopeVisibleTo`): cuentas de emergencia y personas sin empresa no ven anuncios.
- Sin roles nuevos: marca la lectura obligatoria quien ya puede crear o editar ese anuncio (`AnnouncementPolicy`).
- Lighthouse ≥ 90 en el celular. Solo cron; sin colas.
- `public/build` va en el repositorio. Pruebas con `APP_KEY` en el entorno (`.env*` bloqueado).
- Marca: sin cambiar colores, logos ni tokens (decisión pendiente de Edward); se reutilizan los existentes.
- Commits convencionales sin atribución de IA.

## Tareas

- [ ] WU1. **PR5, lectura obligatoria.** Ruta: delegada (escritor), porque son 2 o más archivos no triviales: migración, `AnnouncementRead`, `Announcement`, `AnnouncementReadController`, el editor de Admin, las vistas y sus pruebas.
  - Migración: `announcements.requires_read` (falso por defecto) y `announcement_reads` (`announcement_id`, `user_id`, `read_at`, par único, borrado en cascada).
  - Editor de Admin: casilla «Lectura obligatoria»; el registro de actividad guarda el cambio.
  - `/anuncios` y el inicio: chip «Lectura obligatoria» y botón «Confirmar lectura» (`POST /anuncios/{announcement}/leido`, con CSRF y límite); después, «Leído el …». Idempotente; 404 si quien confirma no lo ve.
  - Inicio: el estado de lectura sin consultas de más (o una como máximo).
  - Admin: «X de Y personas lo leyeron» y la lista de quién falta (solo nombres), solo para quien puede editar ese anuncio.
  - Manual de administración.
- [ ] WU2. **PR6, eventos con `.ics`.** Pendiente; sin diseño todavía.
- [ ] WU3. **PR7, resumen de los lunes.** Bloqueada si necesita correo (ver preguntas).
- [ ] WU4. **PR8, correo a las listas de cada empresa.** Bloqueada: Jhon.

## Preguntas para Jhon

1. WU4: las listas de Exchange solo aceptan remitentes internos. ¿Con qué buzón se envía y cómo se acota `Mail.Send` (RBAC por inquilino) en cada empresa?
2. WU3: ¿el resumen de los lunes va por correo (bloqueado como WU4) o basta con verlo en el portal?
3. WU1: «Y» solo cuenta a quien ya entró al portal (las cuentas se crean al iniciar sesión). ¿Sirve así o hace falta traer el directorio de Microsoft?
4. WU1: ¿quien no ha leído debe recibir un recordatorio (correo o aviso)? Hoy no se construye.

## Criterios de aceptación

- WU1: confirmar dos veces deja una sola fila; un anuncio de otra empresa, retirado, vencido o programado da 404; sin CSRF, 419; el conteo y la lista cuentan solo a las personas de las empresas del anuncio (sin emergencia ni invitados); quien no puede editar el anuncio no ve la lista; el inicio sigue en ≤ 12 consultas y sin Livewire.

## Chequeos

- `APP_KEY=base64:$(php -r 'echo base64_encode(random_bytes(32));') php artisan test --compact`, con la suite completa en verde.
- `vendor/bin/pint --test`.
- `/code-review` en cada PR.

## Entrega

- **Estrategia:** `ask-on-risk`, con cadena `stacked-to-main` (ya elegida por el coordinador): un PR por WU, encadenados encima de #71 (`feat/inicio-3-buscador`).
- **Ramas:** `feat/inicio-4-lectura-obligatoria` (WU1), desde `feat/inicio-3-buscador`.
- **Pronóstico:** WU1 ~450 líneas (código ~200, pruebas ~200, documentos ~50), más `public/build`.
- **Revisión nativa (RDD):** apagada (global: off). Se usan los chequeos ordinarios.

## Progreso

- 2026-10-29: plan creado. Rama `feat/inicio-4-lectura-obligatoria` desde `feat/inicio-3-buscador`.

## Siguiente paso

WU1: pruebas en rojo, luego la migración, la confirmación y el conteo en Admin.
