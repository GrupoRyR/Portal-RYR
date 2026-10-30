# Nuevo inicio, fase 2: anuncios y eventos

Fase 2 del nuevo inicio, después de la fase 1 (`odd/tasks/inicio-fase-1.md`). Plan: «Fases siguientes», punto 2 («Anuncios y eventos»), del plan del nuevo inicio aprobado por Jhon.

## Objetivo

Que un anuncio importante llegue a todos y se sepa quién lo leyó: lectura obligatoria con confirmación y conteo para los editores; después, eventos con archivo `.ics`, un resumen de los lunes y correo a las listas de cada empresa.

## Problema y por qué

- Hoy un anuncio se publica y no se sabe si alguien lo leyó; para cambios de política o avisos de seguridad hace falta una confirmación por persona.
- Las fechas de eventos viven en correos sueltos; un `.ics` las pone en el calendario de cada uno.
- El correo a las listas de Exchange solo acepta remitentes internos y `Mail.Send` está sin acotar (`accepted-risk-mail-send`): depende de Jhon.

## Alcance

- WU1: lectura obligatoria (tabla `announcement_reads`), botón «Confirmar lectura» y conteo «X de Y cuentas lo leyeron» en Admin (por cuenta, no por persona).
- WU2: eventos con `.ics`.
- WU3: resumen de los lunes por cron (bloqueada si necesita correo).
- WU4: correo a las listas de cada empresa (desbloqueada por Jhon el 2026-10-30).

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
  - Admin: «X de Y cuentas lo leyeron» y «Cuentas que faltan» (nombre y empresa), solo para quien puede editar ese anuncio.
  - Manual de administración.
  - Hecho y verificado (ver el registro del WU1).
- [x] WU1-R. **Arreglos de la revisión independiente del PR5.** Ruta: delegada (escritor), 2 o más archivos no triviales (controlador, límite, modelo, editor, vistas, pruebas, manual). Hecho y verificado (ver el registro).
- [x] WU2. **PR6, eventos con `.ics`.** Ruta: delegada (escritor), 2 o más archivos no triviales (migración, modelo, política, `.ics`, Admin, inicio, buscador, vistas y pruebas). Hecho y verificado (ver el registro del WU2).
- [x] WU2-R. **Arreglos de la revisión independiente del PR6.** Ruta: delegada (escritor), 2 o más archivos no triviales (Auditoría, modelos, políticas, `.ics`, rutas, inicio, `/eventos`, buscador, `home.js` y pruebas). Hecho y verificado (ver el registro).
- [ ] WU3. **PR7, resumen de los lunes.** Bloqueada si necesita correo (ver preguntas).
- [x] WU4. **PR8, correo a las listas de cada empresa.** Desbloqueada con la decisión de Jhon del 2026-10-30 (pregunta 1). Rama `feat/inicio-10-correo-listas`, desde `feat/inicio-9-recordatorios` (PR #81). Ruta: delegada (escritor), porque son 2 o más archivos no triviales (migraciones, `Company`, `Announcement`, `AnnouncementListEmail`, el servicio de envío, el cliente de Graph por inquilino, el comando, los dos editores de Admin, la vista del correo, pruebas y documentos). Hecho y verificado (ver el registro del WU4).
- [x] WU4-R. **Arreglos de la revisión independiente del WU4** (sin hallazgos altos, 2 medios y 4 bajos). Ruta: delegada (escritor), 2 o más archivos no triviales (servicio de envío, editor, vistas, modelo, cron, pruebas y documentos). Hecho y verificado (ver el registro del WU4-R).

## Preguntas para Jhon

1. ~~WU4: las listas de Exchange solo aceptan remitentes internos. ¿Con qué buzón se envía y cómo se acota `Mail.Send` (RBAC por inquilino) en cada empresa?~~ **Decidida (Jhon, 2026-10-30):** cada empresa envía a su lista desde un buzón de su propio inquilino (Segurex desde `segurex.info@segurex.com`, Derivados desde `derivados.info@derivadoscol.com`), siempre con Microsoft Graph, nunca SMTP; el buzón es un dato de la empresa en Admin › Empresas. El acotado (RBAC para aplicaciones de Exchange por inquilino y quitar el `Mail.Send` sin límite de Entra) lo hace Jhon con `docs/despliegue.md` §1.13. Hecho en el WU4.
2. WU3: ¿el resumen de los lunes va por correo (bloqueado como WU4) o basta con verlo en el portal?
3. WU1: «Y» solo cuenta a quien ya entró al portal (las cuentas se crean al iniciar sesión). ¿Sirve así o hace falta traer el directorio de Microsoft?
4. WU1: ¿quien no ha leído debe recibir un recordatorio (correo o aviso)? Hoy no se construye.
5. ~~WU1: ¿contar por persona entre cuentas vinculadas?~~ **Decidida (Jhon, 2026-10-30): por persona.** Hecho en T4 de `odd/tasks/ajustes-inicio-y-tareas.md` (`0be536c`): quien tiene cuentas vinculadas confirma una vez, el reporte dice «X de Y personas» y «Personas que faltan», con un ayudante en `App\Auth\LinkedAccounts` y una entrada razonada en `tests/Unit/Auth/PersonNeverAuthorizesTest.php`.
6. WU2: los eventos los manejan las mismas personas que los anuncios (sin rol nuevo; el editor de anuncios, solo los de su empresa). ¿Sirve así o hace falta un «Editor de eventos»?
7. WU2: el `.ics` ya lleva `SEQUENCE` y `LAST-MODIFIED` (WU2-R): si la persona vuelve a descargarlo, su calendario actualiza la copia. Pero nadie recibe los cambios solo (no hay suscripción). ¿Basta así, o hace falta un calendario suscrito (`webcal://`) por empresa?

## Criterios de aceptación

- WU1: confirmar dos veces deja una sola fila; un anuncio de otra empresa, programado o inexistente da 404; uno retirado, vencido o ya no obligatorio que la empresa de la cuenta llegó a ver vuelve con «Este anuncio ya no pide confirmación.» y no guarda nada; sin CSRF, 419; el conteo y la lista cuentan solo a las personas de las empresas del anuncio (sin emergencia ni invitados); quien no puede editar el anuncio no ve la lista; el inicio sigue en ≤ 12 consultas y sin Livewire.

- WU2: un evento de otra empresa, retirado, programado o que ya terminó no sale en el inicio, en `/eventos`, en el buscador ni como `.ics` (404); el `.ics` es RFC 5545 (UTC, `VALUE=DATE` con fin al día siguiente, texto escapado, líneas de 75 octetos, CRLF); un editor de la empresa A no ve ni edita eventos de B ni publica para todas; el inicio sigue en ≤ 12 consultas y sin Livewire.

## Chequeos

- `APP_KEY=base64:$(php -r 'echo base64_encode(random_bytes(32));') php artisan test --compact`, con la suite completa en verde.
- `vendor/bin/pint --test`.
- `/code-review` en cada PR.

## Entrega

- **Estrategia:** `ask-on-risk`, con cadena `stacked-to-main` (ya elegida por el coordinador): un PR por WU, encadenados encima de #71 (`feat/inicio-3-buscador`).
- **Ramas:** `feat/inicio-4-lectura-obligatoria` (WU1), desde `feat/inicio-3-buscador`; `feat/inicio-5-eventos` (WU2), desde `feat/inicio-4-lectura-obligatoria`.
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

- 2026-10-29: arreglos de la revisión independiente del WU1 (WU1-R, ruta delegada, un escritor). Sin `push` ni PR.
  - Commits: `ebf74b6` `fix(announcements)` (botón viejo, límite propio, `aria-describedby`), `e0fe368` `fix(admin)` (por cuenta con empresa, `#[Computed]`, flecha del desplegable, `public/build` `app-ffOrOrMX.css`), `02f840e` `docs(admin)` (manual §2.4), `abf708a` `fix(admin)` (texto de ayuda).
  - RED (antes de tocar el código): 8 fallas en `AnnouncementReadTest` y `AnnouncementReadReportTest`: el botón viejo daba 404, el límite compartido daba 429 tras 30 visitas a `announcements.show`, sin `id`/`aria-describedby`, la lista devolvía solo nombres, el texto decía «personas», y escribir en el formulario volvía a contar (mostró «1 de 2» tras una lectura nueva). Tres pruebas nuevas pasaron desde el principio porque describen lo que ya hacía el código: `is_guest = null` cuenta, la cuenta que cambia de empresa y la carrera de `createOrFirst`.
  - GREEN: suite completa 1.456 de 1.456 (7.220 aserciones); `vendor/bin/pint --test`: sin cambios.
  - Decisiones:
    1. **Por cuenta, no por persona** (coordinador): «X de Y cuentas lo leyeron» («1 cuenta lo leyó»), «Cuentas que faltan (N)», cada cuenta con nombre y nombre corto de su empresa («Ana Pérez · Segurex»). Las lecturas siguen por cuenta, igual que favoritos, roles y recientes. Pregunta 5 para Jhon.
    2. **`is_guest = null` cuenta**: es una cuenta que no ha vuelto a iniciar sesión desde que se guarda la marca; dejarla fuera podría esconder a alguien que debía leer, y un invitado contado de más solo aparece como una cuenta pendiente hasta su siguiente inicio de sesión. Comentario en `Announcement::audience()` y en el manual.
    3. **Botón viejo**: 404 para lo que la cuenta nunca pudo ver (otra empresa, aunque esté retirado o vencido; programado; inexistente; sin empresa o emergencia). Si el anuncio es de su empresa y ya empezó, pero ya no pide confirmación (retirado, vencido o no obligatorio), vuelve a `/#anuncios` (con `from=home`) o a `/anuncios` con «Este anuncio ya no pide confirmación.» y no escribe nada. Así la respuesta nunca dice que existe un anuncio de otra empresa.
    4. **Límite propio**: `RateLimiter::for('announcement-read')` en `AppServiceProvider` (30 por minuto, clave `announcement-read:{id}`) y la ruta usa `throttle:announcement-read`. La prueba gasta 30 visitas de `announcements.show` y luego confirma 30 veces; la 31.ª da 429 y `show` sigue funcionando.
    5. **Accesibilidad**: el título de la tarjeta lleva `id="announcement-{id}-title"` y el botón `aria-describedby` hacia él. El `summary` lleva `list-none` y su propia flecha (`chevron-right`, gira con `group-open:rotate-90`, sin animación con `motion-reduce`).
    6. **Rendimiento**: `readReport` es `#[Computed(persist: true, seconds: 60)]` (clave por instancia del componente): escribir en el formulario no vuelve a contar (la prueba cuenta 0 consultas a `announcement_reads` en dos `set`). `viewReads` y `requires_read` se revisan en cada render fuera del cálculo; Livewire no deja llamar `readReport` como acción (`CannotCallComputedDirectlyException`, con prueba). Las cifras pueden tener hasta 1 minuto; recargar cuenta de nuevo (dicho en el manual).
    7. **Cuenta que cambia de empresa**: X e Y van por la empresa actual. Sale de X y de Y del anuncio de la empresa anterior; en un anuncio para todas sigue contando como leído; la fila se guarda y vuelve a contar si regresa.
    8. **Carrera**: `createOrFirst` escribe primero y, si choca con el par único, lee la fila existente; no hay hueco entre revisar y escribir. La prueba inserta la fila «por detrás» (como otra petición) y comprueba que se conserva la primera fecha. No hace falta un mock parcial.
    9. **Tope de la lista**: prueba con 203 cuentas pendientes: 200 en la lista, «Cuentas que faltan (203)» y «Y 3 más.».
  - Captura en el scratchpad (`inicio-4-shots`): `admin-cuentas-que-faltan-1440-light-fix.png` y `-dark-fix.png` («3 de 5 cuentas lo leyeron», dos cuentas con «· Segurex», flecha abierta). Sin desborde horizontal. Servidor detenido.
  - **No hecho**: `/anuncios` no muestra el mensaje `status` (solo el inicio lo pinta); quien pulsa un botón viejo en `/anuncios` vuelve allí sin ver «Este anuncio ya no pide confirmación.». Falta una línea en `resources/views/announcements/index.blade.php`, fuera de las rutas permitidas en esta tarea.
  - Seguimiento: las rutas con `throttle:N,1` (cerrar sesión, abrir apps, `announcements.show`, soporte, favoritos) comparten un solo contador por cuenta; no se cambiaron.

- 2026-10-29: WU2 hecha (ruta delegada, un escritor), rama `feat/inicio-5-eventos`. Sin `push` ni PR.
  - RED: `tests/Feature/Events/*` (18 pruebas: 18 errores sin el modelo; con el modelo, 6 en verde y 12 sin rutas), `tests/Feature/Admin/EventEditorTest.php`, `EventListTest.php` y `RoleMatrixTest` (23: 20 fallas o errores, sin componentes ni rutas), `tests/Feature/Home/HomeEventsTest.php` (8: 5 fallas; las 3 en verde son guardas: sin enlace sin eventos, presupuesto y sin Livewire).
  - GREEN:
    - `a89a3ef` `feat(events)`: migración `2026_10_29_000005` (`events` y `event_company`), `Event` (`scopeVisibleTo` = publicado, no retirado, no terminado y para la empresa; `scopeUpcoming`, `scopeInStatus`, `scopeManageableBy`, `saveWithCompanies`, retirar y volver a publicar, registro `events`), `EventCalendarFile` (`.ics`), `GET /eventos` y `GET /eventos/{event}.ics` (`throttle:60,1`, 404 si no lo ve), `EventDate`.
    - `7e34b36` `feat(admin)`: `EventPolicy` (mismas personas y empresa que `AnnouncementPolicy`), `EventList` y `EventEditor` (autorizan en `mount` y en cada acción o render), «Eventos» en el menú de Admin (también para editores) y en `RoleMatrix`, `Route::model('event')` en `AppServiceProvider` (las páginas de Admin son `Route::view`).
    - `501bd47` `feat(home)`: «Próximas fechas» une festivos y eventos (`UPCOMING_DATE_LIMIT` = 5, eventos que empiezan en `UPCOMING_EVENT_DAYS` = 60 días), «Evento» o «Festivo», «Ver eventos» solo con eventos; buscador con hasta `EVENT_LIMIT` = 50 eventos de 12 meses entre las fechas, con enlace `/eventos#e-{id}`; `home.js` oculta en la copia guardada un evento al terminar (`data-event-end`) y corrige su «Hoy».
    - `8be4880` `docs(admin)`: manual §2.5 «Eventos» (las secciones siguientes pasan a 2.6–2.10), roles y §7.2.
    - `7779326` `fix(admin)`: texto de ayuda de «Termina».
  - Suite completa: 1.508 de 1.508 (7.558 aserciones). `vendor/bin/pint --test`: sin cambios. `public/build` reconstruido (`app-Cd2BwBrn.css`, `home-ilkd6fv3.js`).
  - Consultas del inicio: 10 → 11 (una para los eventos, la misma con 1 o 9 eventos); presupuesto ≤ 12.
  - Capturas en el scratchpad (`inicio-5-shots`): inicio («Próximas fechas» con 3 eventos y 2 festivos), `/eventos`, editor y lista de Admin, a 390 y 1440 px, claro y oscuro. Sin desborde horizontal. El `.ics` descargado del servidor: `text/calendar; charset=utf-8`, `attachment; filename=reunion-general-de-resultados.ics`, CRLF, UTC, `LOCATION:Sala de juntas\, piso 3`. Servidor detenido.
  - Decisiones:
    1. **Sin rol nuevo**: manejan los eventos súper administrador y administrador (todos) y el editor de anuncios (solo de su empresa, con la misma regla de «exactamente su empresa»). Valor por defecto que Jhon puede cambiar (pregunta 6).
    2. **Visible = publicado y no terminado**: `published_at` ≤ ahora, sin `withdrawn_at`, para la empresa y sin terminar. El `.ics` de un evento que ya pasó da 404, como el de otra empresa.
    3. **Cuándo termina**: con hora, en `ends_at`; con hora y sin fin, al acabar su día; todo el día, al acabar su último día. Todo el día guarda la medianoche del primer día y del último (`ends_at` null = un día).
    4. **`.ics`**: horas en UTC (`Z`), sin `VTIMEZONE` (Bogotá no tiene horario de verano); todo el día con `VALUE=DATE` y `DTEND` al día siguiente del último; sin fin, sin `DTEND` (un momento, RFC 5545 §3.6.1). `UID` `event-{id}@{host de APP_URL}`. Sin librería.
    5. **`/eventos` sin paginar**, hasta 100: el buscador y «Próximas fechas» enlazan con `#e-{id}` a los 50 más próximos, que siempre están en esa página.
    6. **Orden en «Próximas fechas»**: por inicio; un festivo va antes de los eventos de su día. Un evento de varios días que ya empezó sale con su primer día y «Hasta el …».
  - **No hecho / seguimiento**: Auditoría mostraba los eventos como «Event #N» (resuelto en WU2-R); Lighthouse; suscripción del calendario (pregunta 7).

- 2026-10-29: arreglos de la revisión independiente del WU2 (WU2-R, ruta delegada, un escritor), rama `feat/inicio-5-eventos`. Sin `push` ni PR.
  - Commits: `f75196d` `fix(audit)` (1), `48885f0` `refactor(models)` (2), `1b20555` `fix(events)` (3), `f468597` `fix(admin)` (6), `65f511d` `fix(events)` (4 y 7), `77c7706` `fix(search)` (5, `public/build` `home--MKLBabp.js`).
  - RED antes de cada arreglo: Auditoría decía «Creó Event #1» y «Creó » (2 fallas; la guarda de anuncios en verde); el `.ics` sin `SEQUENCE` (2 fallas); `getBindingCallback('event')` no era nulo (1 falla); inicio y `/eventos` (5 fallas y 1 error: «Ver eventos» faltaba con un evento lejano, sin «En curso», sin aviso de lista cortada, sin `end` en el buscador). El arreglo 2 es un cambio sin comportamiento nuevo: las 236 pruebas de anuncios, eventos e inicio en verde antes, y 492 de Admin, eventos, anuncios, inicio y buscador después.
  - GREEN: suite completa 1.519 de 1.519 (7.624 aserciones). `vendor/bin/pint --test`: sin cambios. Líneas: código 377+ / 306− (el arreglo 2 quita más de lo que pone), pruebas 193+ / 1−, sin contar `public/build`.
  - Decisiones:
    1. **Auditoría**: «el evento «título»» o «(eliminado)»; verbos Retiró, Volvió a publicar y Cambió las empresas de; Lugar, Todo el día, Se muestra desde, Creado por; Empieza y Termina solo para eventos (los anuncios siguen con Desde y Hasta). «Cambió las empresas de el evento» repite la forma de los anuncios («de el anuncio»); no se contrajo a «del» para no cambiar los anuncios.
    2. **Código compartido**: `App\Models\Concerns\AddressedToCompanies` (`audit`, `withdraw`, `republish`, `isForAllCompanies`, `scopeVisibleTo`, `scopeForCompanyId`, `scopeManageableBy`, `companyNames`) y `App\Policies\Rules\CompanyAddressedRules` (quién lista, crea, edita y publica para qué empresas). Quedan en cada modelo: la tabla pivote (`companies()`), qué es «publicado» (`scopeInStatus`; un evento además no ha terminado), `AUDIT_LOG`, `AUDIT_EVENT_PREFIX` y `getActivitylogOptions`. `saveWithCompanies` sigue en `Event`: el de anuncios vive en `App\Announcements\AnnouncementPublisher` (con la imagen), fuera de las rutas permitidas; su `companyNames` privado podría usar el del rasgo. `AnnouncementPolicy::MANAGERS`, `EDITOR` y `manages()` se quedan como alias.
    3. **`.ics`**: `SEQUENCE` = hora Unix de `updated_at` (solo crece; dos cambios en el mismo segundo comparten número; cabe en el entero de 32 bits de RFC 5545 hasta 2038) y `LAST-MODIFIED` en UTC; el `UID` no cambia.
    4. **«Ver eventos»**: sale con cualquier evento visible que no ha terminado. La consulta de eventos del inicio ya no filtra 12 meses: trae los 50 más próximos y el inicio se queda con los de 12 meses para la lista y el buscador (el mismo resultado, por ir en orden). Sigue en 11 consultas. `/eventos` pide 101 y, si llegan 101, muestra 100 y «Se muestran los 100 eventos más próximos.».
    5. **Buscador en la copia guardada**: cada evento lleva `end` (el momento en que termina, ISO 8601) y `home.js` lo oculta en ese momento, como la lista con `data-event-end` (`isOver`). Sin ejecutor de JS en el proyecto: comprobado con `node` sobre la función (evento terminado, en curso, festivo de ayer y de hoy, entrada sin fecha).
    6. **`{event}`**: sin `Route::model` global. La página de Admin es `Route::get` con un cierre de parámetro tipado (`fn (Event $event)`), así `can('update', 'event')` recibe el modelo; el `.ics` ya lo tipaba en su controlador. `route:cache` sigue funcionando.
    7. **En curso**: un evento que empezó un día anterior y no ha terminado dice «En curso · hasta el 31 de octubre» en «Próximas fechas» (con «, 18:00» si tiene hora de fin) y «En curso · hasta el sábado 31 de octubre» en `/eventos`; la fecha de al lado es hoy y se ordena como hoy (después del festivo de hoy). Uno que empezó hoy sigue con «Hoy · 08:00». Admin sigue con las fechas completas (`when()`).
  - Capturas en el scratchpad (`inicio-5-shots`): `home-390-light-fechas-fix.png` y `eventos-390-light-fix.png` («Semana de la seguridad» en curso). Sin desborde horizontal. Servidor detenido.
  - **No hecho / seguimiento**: el buscador sigue diciendo «Evento · martes 27 de octubre» para uno en curso; la copia guardada no pasa a «En curso» un evento que empezó después de guardarse; `requires_read` sigue sin etiqueta en Auditoría; `AnnouncementPublisher` podría usar `companyNames` del rasgo.

- 2026-10-30: WU4 hecha (ruta delegada, un escritor), rama `feat/inicio-10-correo-listas`. Sin `push` ni PR.

  **Registro del WU4**
  - Commits: `a7ee896` `feat(admin)` (lista y buzón remitente por empresa), `320ac25` `feat(announcements)` (casilla, envío por cron, estados y «Reintentar»), `a9de2ce` `docs` (manual §2.1 y §2.4, despliegue §1.13).
  - RED (pruebas antes del código): `tests/Feature/Console/SendAnnouncementListEmailsCommandTest.php` (21), `tests/Feature/Admin/AnnouncementListEmailEditorTest.php` (15) y `tests/Feature/Admin/CompanyListEmailTest.php` (8): 44 pruebas, 1 en verde (solo el súper administrador abre la ficha, ya era así), 3 fallas (sin campos en la ficha ni en el modelo) y 40 errores (sin columnas, sin estados de fábrica, sin modelo, comando ni servicio).
  - GREEN: las 44 en verde. Suite completa: 1.760 de 1.760 (8.897 aserciones; la base de esta rama da 1.716). `vendor/bin/pint --test`: sin cambios. `npm run build`: sin cambios en `public/build` (no hay clases nuevas de Tailwind).
  - Líneas (adiciones y borrados, sin `public/build`): código 1.047+ / 3− (el servicio de envío son 410), pruebas 943+, documentos 107+. Más que las ~400 de referencia: la mayor parte son el servicio con su consulta, el reclamo de filas y los códigos de error, y las pruebas de cada caso pedido.
  - Decisiones:
    1. **Datos, no código**: `companies.all_staff_email` y `companies.mail_sender` (migración `2026_10_30_000005`), en la sección Microsoft 365 de la ficha de la empresa. Los dos o ninguno, correos válidos y distintos, en minúsculas, y de uno de los dominios **del formulario** (coincidencia exacta tras la última «@»: un subdominio no vale). Auditados por `logFillable`, con las etiquetas «Lista de todo el personal» y «Buzón remitente». El asistente de empresa nueva no los pide: se llenan en la ficha cuando la empresa ya existe.
    2. **Casilla**: `announcements.email_lists` (migración `2026_10_30_000006`, con la tabla `announcement_list_emails`). Solo aparece si una empresa del público está activa y tiene los dos datos (de sus dominios); dice «Lo reciben: Derivados (…) y Segurex (…).». Un editor de anuncios solo ve y llega a la lista de su empresa (el `publishTo` de siempre al guardar). Si nadie del público puede recibirlo, se guarda desmarcada. Queda en Auditoría («Enviar por correo a las listas»).
    3. **Una sola vez**: el cron `portal:send-announcement-list-emails` (cada minuto, `withoutOverlapping`, hasta `BATCH` = 10 correos por vuelta) busca en una consulta los pares anuncio publicado con la casilla × empresa activa con los dos datos a la que va dirigido, sin fila o con fila pendiente. Escribe la fila como `sending` **antes** de llamar a Graph (par único, borrado en cascada); una pendiente se reclama con un `update` condicionado. Al terminar queda `sent` (con `sent_at`) o `failed` (con `last_error`). Editarlo después no lo reenvía (dicho en el editor y en el manual). Uno programado sale cuando llega su «Desde»; uno retirado o vencido no sale (una fila pendiente espera).
    4. **Graph por inquilino**: `App\Microsoft\TenantMailSender` hace `POST https://graph.microsoft.com/v1.0/users/{mail_sender}/sendMail` con un token de `AppOnlyToken` del `tenant_id` de la empresa, `saveToSentItems: true`, la lista como destinatario y el título como asunto, sin `from` ni adjuntos, con `timeout` de 15 s, `withoutRedirecting()` y un solo reintento tras un 401 (renueva el token). Nunca SMTP, el mailer `graph` ni `MAIL_FROM_ADDRESS` (la prueba usa `Mail::fake()` con `assertNothingOutgoing` y `Http::preventStrayRequests`).
    5. **El correo**: `resources/views/mail/announcement-list.blade.php` con el renderizador de correos de Laravel y la cabecera de marca (`MailBrand` de la empresa): título, texto escapado con `e()` y saltos como `<br>` en una sola línea dentro de un bloque HTML (CommonMark no lo lee como Markdown: `[x](javascript:…)` y `**…**` salen tal cual), la imagen solo si `imageUrl()` es `https://` (nunca adjunta) y «Ver en el portal» a `APP_URL` + `/anuncios/{id}` (el cron no tiene petición).
    6. **Fallas**: un código corto en la fila y en el log (sin token ni texto): el `error.code` de Graph si es un identificador (`ErrorAccessDenied`, `MailboxNotEnabledForRESTAPI`), `AADSTS…` de un token rechazado, `HTTP 302` o `HTTP 502`, `Unavailable` (sin respuesta o sin configurar), `DomainMismatch` (el cron revisa otra vez que remitente y lista sean de los dominios de la empresa, y no llama a Graph) e `Interrupted` (una fila en `sending` de hace más de `STALE_MINUTES` = 15: no se sabe si salió, así que no se reenvía sola). Nunca rompe la vuelta ni una pantalla.
    7. **Editor**: tarjeta «Correo a las listas» para quien puede editar el anuncio, solo con las empresas a las que va dirigido ahora: «Enviado el …», «Pendiente: sale en los próximos minutos», «Pendiente: sale cuando se publique, el …», «Enviando…», «No se envía mientras el anuncio no esté publicado», «No se envía: la casilla de correo está desmarcada» o «No se pudo enviar (código)» con una pista en español por código (la de `ErrorAccessDenied` remite a `docs/despliegue.md`). «Reintentar» (misma política `update`) deja la fila pendiente con un `update` condicionado, una vez cada `RETRY_MINUTES` = 10 por fila («Podrás reintentar a las …»), y queda en Auditoría («Reintentó el correo a la lista de el anuncio …», con Empresa y Error anterior). Un editor no ve ni reintenta la fila de otra empresa que quedó de un público anterior.
    8. **Auditoría**: de paso, `requires_read` ya tiene etiqueta («Lectura obligatoria»), pendiente desde el WU2-R.
  - Despliegue (`docs/despliegue.md` §1.13), por inquilino: consentimiento de la app, `New-ServicePrincipal` (el Id. de objeto cambia por inquilino), `New-ManagementScope -RecipientRestrictionFilter` con el buzón remitente, `New-ManagementRoleAssignment -Role "Application Mail.Send" -CustomResourceScope`, `Test-ServicePrincipalAuthorization`; en Segurex el ámbito debe incluir `MAIL_FROM_ADDRESS` (hoy es el mismo `segurex.info@segurex.com`) **antes** de quitar el `Mail.Send` sin límite de Entra, porque los dos permisos se suman; cómo quitarlo del registro y de cada inquilino (`Remove-MgServicePrincipalAppRoleAssignment`); la restricción de entrega de la lista (`Set-DistributionGroup -AcceptMessagesOnlyFromSendersOrMembers`); y la prueba con una lista pequeña. Referencias de Microsoft Learn: `application-rbac`, `user-sendmail`, `v2-admin-consent`, `Remove-MgServicePrincipalAppRoleAssignment`, `Set-DistributionGroup` y el NDR 5.7.124.
  - **No hecho**: los pasos de Exchange y Entra en los inquilinos (los hace Jhon con §1.13) y llenar los datos de Segurex y Derivados en Admin › Empresas; el asistente de empresa nueva sin los dos campos; sin capturas ni prueba en el navegador; sin envío real (todo con `Http::fake`). Si una empresa recibe sus datos de lista después, los anuncios publicados con la casilla marcada le llegan en el minuto siguiente (documentado; no se limitó por fecha).

- 2026-10-30: arreglos de la revisión independiente del WU4 (WU4-R, ruta delegada, un escritor), rama `feat/inicio-10-correo-listas`. Sin `push` ni PR.

  **Registro del WU4-R**
  - Commits: `5bdae44` `fix(announcements)` (M1, M2, L3 a L6), `1dedb73` `docs` (manual §2.4 y despliegue §1.13).
  - RED (pruebas antes del código): 72 pruebas en los archivos del WU4 y `AnnouncementModelTest`: 41 en verde, 6 fallas y 25 errores (sin `AnnouncementListEmails::queue()`, título con saltos aceptado, bloqueo de 1440 minutos, columnas sin tabla en `scopeInStatus`, «Pendiente» para una empresa sin dominio válido, sin aviso para `Unavailable` y `HTTP 503`). Dos de las nuevas pasaron desde el principio porque describen lo que ya hacía el código: guardar sin la casilla no anota nada, y B agregada y guardada recibe una vez (el cron anterior ya se lo enviaba; ahora además A no puede recibirlo de nuevo).
  - GREEN: las 85 pruebas de esos archivos y de `SendAnnouncementRemindersCommandTest`. Suite completa: 1.775 de 1.775 (8.946 aserciones). `vendor/bin/pint --test`: sin cambios. `npm run build`: sin cambios en `public/build`.
  - Líneas: código 132+ / 73−, pruebas 255+ / 29−, documentos 7+ / 5−.
  - Decisiones:
    1. **M1, título en una línea**: el editor rechaza saltos de línea en el título (`not_regex:/[\r\n]/`, «El título va en una sola línea.»), y el correo y su asunto los cambian por un espacio (`AnnouncementListEmails::oneLine()`, también el nombre de la empresa del pie). Con el título de la prueba («Aviso», línea en blanco, un enlace y una imagen de Markdown) el correo no lleva ningún `<a>` ni `<img>` hacia evil.example.
    2. **M2, envío explícito**: guardar con la casilla marcada anota una fila `pending` para cada empresa a la que va dirigido que puede recibirlo en ese momento y no tiene fila (`queue()`, `insertOrIgnore` sobre el par único). El cron solo envía filas pendientes y sigue revisando al enviar: publicado, casilla marcada, empresa activa con los dos datos, aún dirigido a ella y dominios. Configurar la lista de B, reactivar una empresa o agregar B a un anuncio ya enviado no envía nada hasta que alguien guarda de nuevo con la casilla marcada, y entonces solo a las empresas sin fila. Desmarcar y guardar detiene los pendientes. La fila nace con `attempts` = 0. No hizo falta tocar las migraciones.
    3. **Estados nuevos**: «Sin enviar: guarda el anuncio con la casilla marcada para enviárselo.» (empresa que puede recibirlo y no tiene fila) y «No se envía: la empresa está inactiva o ya no tiene lista y buzón remitente.» (fila pendiente de una empresa que ya no puede). El texto de ayuda de la casilla y la tarjeta lo explican.
    4. **L3**: `Unavailable` y `HTTP 5xx` muestran «… y no se sabe si salió. Revisa la lista antes de reintentar.», como `Interrupted`.
    5. **L4**: `withoutOverlapping(10)` en `portal:send-announcement-list-emails` y en `portal:send-announcement-reminders`; la prueba revisa `expiresAt` = 10 en los dos.
    6. **L5**: el informe solo muestra una empresa sin fila si `canReceiveListEmail()` (también sus dominios).
    7. **L6**: `scopeInStatus` usa `$query->qualifyColumn()` para `withdrawn_at`, `starts_at` y `ends_at`; la consulta del cron une `announcement_list_emails` y `companies`.
    8. **Documentos**: el manual explica que se anota al guardar y qué pasa con las empresas que llegan después; el despliegue pide la dirección SMTP principal del buzón remitente (el ámbito filtra por `PrimarySmtpAddress`; con un alias, `InScope` da `False` y todo falla con `ErrorAccessDenied`).
  - **No hecho**: lo mismo del WU4 (pasos de Exchange y Entra, datos de las empresas, asistente sin los campos, sin prueba en navegador ni envío real).

## Siguiente paso

WU4 (`feat/inicio-10-correo-listas`): abrir su PR encima de #81 (`feat/inicio-9-recordatorios`) y pasarle `/code-review`, decisión del coordinador o de Jhon. Para que salgan los correos, Jhon sigue `docs/despliegue.md` §1.13 en Segurex y en Derivados y llena la lista y el buzón remitente de cada empresa en Admin › Empresas. WU3 sigue esperando la respuesta a la pregunta 2.
