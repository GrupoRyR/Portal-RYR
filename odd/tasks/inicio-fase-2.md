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

- [x] WU1. **PR5, lectura obligatoria.** Ruta: delegada (escritor), porque son 2 o más archivos no triviales: migración, `AnnouncementRead`, `Announcement`, `AnnouncementReadController`, el editor de Admin, las vistas y sus pruebas.
  - Migración: `announcements.requires_read` (falso por defecto) y `announcement_reads` (`announcement_id`, `user_id`, `read_at`, par único, borrado en cascada).
  - Editor de Admin: casilla «Lectura obligatoria»; el registro de actividad guarda el cambio.
  - `/anuncios` y el inicio: chip «Lectura obligatoria» y botón «Confirmar lectura» (`POST /anuncios/{announcement}/leido`, con CSRF y límite); después, «Leído el …». Idempotente; 404 si quien confirma no lo ve.
  - Inicio: el estado de lectura sin consultas de más (o una como máximo).
  - Admin: «X de Y personas lo leyeron» y la lista de quién falta (solo nombres), solo para quien puede editar ese anuncio.
  - Manual de administración.
  - Hecho y verificado (ver el registro del WU1).
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
- **Pronóstico:** WU1 ~450 líneas (código ~200, pruebas ~200, documentos ~50), más `public/build`. Salió con ~900: código 377 (367+, 10−), pruebas 436, `public/build` 6, documentos ~85. Las pruebas son casi la mitad; el código quedó cerca del pronóstico.
- **Revisión nativa (RDD):** apagada (global: off). Se usan los chequeos ordinarios.

## Progreso

- 2026-10-29: plan creado (`97acea6`). Rama `feat/inicio-4-lectura-obligatoria` desde `feat/inicio-3-buscador`.
- 2026-10-29: WU1 hecha (ruta delegada, un escritor). Sin `push` ni PR.
  - RED: `tests/Feature/Announcements/AnnouncementReadTest.php` y `tests/Feature/Admin/AnnouncementReadReportTest.php` nuevas, 21 pruebas: 1 en verde (el editor de otra empresa ya daba 403), 3 fallas y 17 errores (sin columna `requires_read`, sin estado `mandatory()` en la fábrica, sin `$requiresRead` en el editor).
  - GREEN, personas (`8110c4d` `feat(announcements)`): migración `2026_10_29_000004` (`announcements.requires_read` y `announcement_reads` con par único y cascada), modelo `AnnouncementRead`, `Announcement::markReadBy()` (`createOrFirst`: la segunda vez conserva la primera fecha) y `scopeWithReadAtFor()` (subconsulta `viewer_read_at`), `POST /anuncios/{announcement}/leido` (`announcements.read`, `throttle:30,1`), chip «Lectura obligatoria», botón «Confirmar lectura» y «Leído el …» en `x-announcements.card`, y «Lectura obligatoria» en la franja del celular; `public/build` reconstruido (`app-DXCf75lr.css`).
  - GREEN, Admin (`0405c39` `feat(admin)`): casilla «Lectura obligatoria» en el editor (queda en el registro de actividad como los demás campos, por `logFillable`), `AnnouncementPolicy::viewReads` (igual que `update`), `Announcement::audience()`, `readCounts()` y `pendingReaders()` (hasta 200 nombres, `PENDING_READERS_LIMIT`), y la tarjeta «Lectura» con «X de Y personas lo leyeron» y «Quién falta (N)».
  - Manual (`ecd1437` `docs(admin)`): sección 2.4.
  - Suite completa: 1.448 de 1.448 (7.111 aserciones). `vendor/bin/pint --test`: sin cambios.
  - Capturas en el scratchpad (`inicio-4-shots`): `/anuncios` y el inicio, pendiente y después de confirmar, a 390 y 1440 px, claro y oscuro; Admin «Lectura» a 1440 px con «Quién falta» abierto («3 de 5 personas lo leyeron»). La confirmación se hizo con un clic real (POST con CSRF) y volvió a `/anuncios#a-1`. Sin desborde horizontal en ninguna.
  - Decisiones:
    - Cero consultas nuevas en el inicio y en `/anuncios`: el estado de lectura llega en la misma consulta de los anuncios (subconsulta). La prueba compara el inicio con 3 anuncios normales y con los mismos obligatorios (uno leído).
    - Solo se confirma un anuncio obligatorio que quien confirma ve ahora (`visibleTo`); cualquier otro (otra empresa, retirado, vencido, programado, no obligatorio, inexistente) da 404. Emergencia y sin empresa: 404, como hoy.
    - El formulario del inicio lleva `from=home` y vuelve a `/#anuncios`; el de `/anuncios` vuelve a `/anuncios/{id}`, que calcula la página. Mismo patrón que la estrella de favoritos (formulario POST en el inicio). En la copia guardada un botón viejo solo confirma de nuevo (idempotente); sin red, el POST falla como cualquier formulario.
    - La franja del celular es un enlace y no puede tener un formulario: dice «Lectura obligatoria» y lleva a `/anuncios`, donde está el botón. En escritorio el botón está en la tarjeta del lateral.
    - Y = cuentas de las empresas activas del anuncio (todas las activas si es para todas), sin emergencia ni invitados (`is_guest = true`; `null`, aún no se sabe, cuenta). X solo cuenta lecturas de esas cuentas: una lectura de otra empresa (público anterior) no suma. Solo existen las personas que ya iniciaron sesión.
    - La lista y el conteo están solo en el editor (quien puede editar ese anuncio; se revisa `viewReads` en cada render, así quitar el rol lo oculta sin recargar). La lista de Admin (`AnnouncementAdminRow`) no cambió: fuera de las rutas permitidas.
    - Chip con los tokens de estado existentes (`status-maintenance`), botón `bg-brand-button`; sin colores nuevos.
  - No hecho: contador de lecturas pendientes en «Mi día» (opcional; necesitaría otra consulta o contar solo los 50 del índice); Lighthouse.

## Siguiente paso

Abrir el PR de `feat/inicio-4-lectura-obligatoria` encima de #71 (`feat/inicio-3-buscador`): decisión de Jhon. WU2 (eventos con `.ics`) necesita diseño; WU3 y WU4 esperan las respuestas de Jhon.
