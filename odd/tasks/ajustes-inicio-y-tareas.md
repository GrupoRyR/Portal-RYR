# Ajustes decididos por Jhon el 2026-10-30

Cambios pequeños y medianos que Jhon decidió al revisar las preguntas del inicio (`odd/tasks/inicio-fase-1.md`, `odd/tasks/inicio-fase-2.md`) y de la API de tareas (`odd/tasks/tareas-aprobaciones.md`).

## Objetivo

Aplicar cinco decisiones de Jhon:

1. La API de tareas rechaza la misma versión con otro tipo o persona.
2. «Puente festivo» en los festivos.
3. Fechas propias de cada empresa que se repiten cada año.
4. La lectura obligatoria se cuenta por persona.
5. Recordatorios a quien no ha leído.

## Problema y por qué

- **T1.** Hoy la misma `version` con otro `kind` o `assignee` responde `unchanged` y lo ignora. Jhon prefiere un error, para que la app note su fallo.
  - `kind` y `assignee` ya no pueden cambiar con una versión mayor (`422`), así que se usa el mismo `422`. La app que quiera mover una tarea a otra persona la borra y la crea con otro `external_id`.
- **T2.** Un festivo en lunes o viernes da un fin de semana de 3 días, que en Colombia se llama «puente».
- **T3.** Las fechas de cada empresa (aniversario, cierre contable, entrega de dotación) se repiten cada año.
  - Hoy un evento es de una sola vez.
  - Se reutilizan los eventos (#73): mismo permiso (Jhon: «un permiso» para eventos y anuncios), mismas empresas destinatarias y misma Auditoría. Se agrega «Se repite cada año», solo para eventos de todo el día. Así no hace falta otra pantalla de Admin.
- **T4.** Jhon decidió contar por persona: quien tiene cuenta en dos empresas confirma una sola vez.
- **T5.** Jhon quiere recordatorios. Son correos individuales desde el buzón que el portal ya usa para sus avisos (`MAIL_FROM_ADDRESS` por Graph), no a las listas, así que no dependen de la pregunta del buzón de las listas.

## Alcance

- **T1:** en `feat/tareas-1-api` (#74): misma versión con otro `kind` o `assignee` responde `422`. El contrato §3.3 se actualiza.
- **T2:** rama `feat/inicio-6-puentes` sobre #76: «Puente festivo» en «Próximas fechas» y en el buscador.
- **T3:** rama `feat/inicio-7-fechas-anuales` sobre T2: `events.repeats_yearly`; la próxima ocurrencia en el inicio, en el buscador y en `/eventos`; `RRULE:FREQ=YEARLY` en el `.ics`.
- **T4:** rama `feat/inicio-8-lectura-por-persona` sobre T3: estado «Leído» y reporte «X de Y personas».
- **T5:** rama `feat/inicio-9-recordatorios` sobre T4: botón «Enviar recordatorio» en el reporte. El cron envía en lotes.

## Fuera de alcance

- El correo a las listas de cada empresa y el resumen de los lunes (sigue pendiente qué buzón usar).
- Recordatorios automáticos sin que el editor los pida.
- Recurrencias que no sean anuales.

## Restricciones

- `person_id` no autoriza nada. T4 lo usa solo para agrupar, dentro de un ayudante de `App\Auth\LinkedAccounts`, y con una entrada nueva y razonada en `tests/Unit/Auth/PersonNeverAuthorizesTest.php`. Ver un anuncio sigue siendo por cuenta (`Announcement::scopeVisibleTo`).
- Inicio: sin Livewire ni Alpine y ≤ 12 consultas (`HomeScreenTest`; hoy ya está en 12). Nada de esto puede sumar consultas a `/`.
- Correos: patrón de los avisos actuales (`afterCommit`, `sendNow`, try/catch, `MailBrand`). Un fallo de correo nunca rompe la pantalla. Solo cron, sin colas.
- Pruebas con `APP_KEY` en el entorno y `Http::fake`/`Mail::fake`, nunca red real. `public/build` va en el repositorio.
- Commits convencionales sin atribución de IA.

## Tareas

- [x] T1. **API de tareas: `422` con la misma versión y otro tipo o persona** (#74). Ruta: delegada (escritor), pocas líneas pero código, prueba y contrato. Hecho: `633629f` y `b31b06c` en `feat/tareas-1-api`, empujados a #74; contrato v0.1.1. RED 3 de 97, GREEN 97/97; suite 1.359/1.359; Pint limpio. Revisión: el coordinador leyó el diff (pequeño) y repitió las 97 pruebas.
- [x] T2. **«Puente festivo».** Ruta: delegada (escritor), código, vista y pruebas. Hecho: `ad00aa4` en `feat/inicio-6-puentes`, sin empujar. `HolidayDay::kind()` (con `isBridge()`) da la etiqueta en «Próximas fechas» y en el buscador; `/eventos` no lista festivos y el aviso de la línea de fecha no lleva tipo. RED 6 de 42, GREEN 159/159 en Home, Search y Unit/View; suite 1.639/1.639; Pint limpio; consultas de `/` sin cambio (≤ 12, `HomeScreenTest`); sin clases nuevas de Tailwind, así que sin `npm run build`. Manual §7.2 con una línea.
- [x] T3. **Fechas que se repiten cada año.** Ruta: delegada (escritor), 2 o más archivos no triviales (migración, modelo, `.ics`, Admin, inicio, buscador, `/eventos`, Auditoría, manual y pruebas). Hecho: `f6246b5` y `a0f5117` en `feat/inicio-7-fechas-anuales`, sin empujar (ver el registro de T3).
- [x] T4. **Lectura obligatoria por persona.** Ruta: delegada (escritor). Hecho: `0be536c` en `feat/inicio-8-lectura-por-persona`, sin empujar (ver el registro de T4).
- [x] T5. **Recordatorios.** Ruta: delegada (escritor). Hecho: `df20a1a` en `feat/inicio-9-recordatorios`, sin empujar (ver el registro de T5). Arreglos de la revisión: `ba2a542` (ver el registro de T5-R).
- [ ] Revisión independiente y arreglos de cada PR.

## Preguntas para Jhon

- Ninguna por ahora.
- Las decisiones de diseño del coordinador se anotan en cada registro: `422` y no `409`; fechas anuales dentro de eventos; un correo por persona.

## Criterios de aceptación

- **T1:** la misma versión con otro `kind` o `assignee` responde `422`, con el mismo error que una versión mayor. La misma versión sin cambios sigue en `unchanged`.
- **T2:** un festivo en lunes o viernes muestra «Puente festivo». En martes, miércoles o jueves sigue diciendo «Festivo».
- **T3:** un evento anual aparece cada año en su fecha, en el inicio, en el buscador y en `/eventos`. El `.ics` lo repite.
- **T4:** confirmar en una cuenta marca «Leído» en todas las cuentas vinculadas de esa persona que ven el anuncio. El reporte cuenta personas.
- **T5:** el editor envía un recordatorio como máximo una vez cada 24 horas por anuncio. Le llega un correo a cada persona que falta. El reporte muestra cuándo y a cuántas personas.

## Chequeos

- RED antes de GREEN, la suite completa y Pint en cada rama.
- Capturas cuando cambia la interfaz.
- Una revisión independiente por PR.

## Entrega

- PR apilados, sin fusionar ni desplegar.
- T1 se suma a #74, con commits nuevos y sin reescribir la historia.

## Progreso

- 2026-10-30: documento creado.
- 2026-10-30: T1 hecho y empujado a #74.
- 2026-10-30: T2 hecho en `feat/inicio-6-puentes` (`ad00aa4`), sin empujar.

- 2026-10-30: T3 hecho en `feat/inicio-7-fechas-anuales` (`f6246b5`, `a0f5117`), sin empujar.
  - Commits: `f6246b5` `feat(events)` (migración `2026_10_30_000003`, regla de la ocurrencia, visibilidad, inicio, buscador, `/eventos` y `.ics`); `a0f5117` `feat(admin)` (casilla del editor, «Cada año» en Admin, inicio, buscador y `/eventos`, etiqueta de Auditoría y manual §2.5).
  - RED antes de cada commit: 11 de 64 (5 fallas y 6 errores: sin `repeatsYearly()`, sin ocurrencia, `.ics` 404, no visible); luego 1 de 1 (29 de febrero en el `.ics`); luego 6 de 19 (editor, lista, Auditoría y etiquetas). GREEN: 64/64, 200/200 en eventos, inicio, buscador y Admin de eventos, 19/19.
  - Suite 1.659/1.659 (base 1.639, 20 pruebas nuevas); Pint limpio; consultas de `/` sin cambio (`HomeScreenTest::test_the_query_count_does_not_grow_with_yearly_events`, ≤ 12); `npm run build` sin cambios en `public/build` (sin clases nuevas de Tailwind).
  - Decisiones:
    1. **Una sola regla**: `Event::occurrenceStart()` y `occurrenceLastDay()`. La de este año hasta que termina hoy (hora de Bogotá), luego la del año siguiente; una de varios días que empezó el año pasado (30 de diciembre a 3 de enero) sigue siendo la actual; nunca antes de la primera fecha guardada (si esa fecha aún no llega, es la ocurrencia). 29 de febrero cae el 28 en años comunes.
    2. **Solo todo el día, en un lugar**: `Event::repeatsYearly()` exige `all_day`, así que una marca guardada en un evento con hora no cuenta. En el editor, quitar «Todo el día» desmarca la casilla y un evento con hora que llega marcado da error («Solo un evento de todo el día puede repetirse cada año.»). Se hacen las dos cosas.
    3. **Visibilidad**: `scopeUpcoming` suma «todo el día y anual», así que la misma consulta de eventos (inicio, `/eventos`, `.ics`) los trae; publicado, no retirado y para la empresa siguen igual. Nunca pasa a «Pasados». `endsAtMoment()` es el fin de la ocurrencia, así que `data-event-end` y el buscador la llevan.
    4. **Orden**: la consulta sigue por `starts_at` (un anual antiguo entra primero en el límite de 50/101); `EventDate::soonestFirst()` ordena luego por la ocurrencia. Con más de 50 eventos visibles, los anuales ocupan primero el cupo; no se intentó calcular la ocurrencia en SQL. (Corregido en el T3-R: ver su registro.)
    5. **Etiquetas**: `EventDate::kind()` da «Cada año» o «Evento» en «Próximas fechas» (también en el texto para lectores de pantalla), en el buscador («Cada año · martes 3 de noviembre»; se busca por «cada año») y en `/eventos` («… · Todo el día · Cada año»). Admin muestra la próxima fecha y «Cada año».
    6. **`.ics`**: DTSTART y DTEND de la primera fecha guardada (`VALUE=DATE`) y `RRULE:FREQ=YEARLY`; si la primera fecha es un 29 de febrero, `RRULE:FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=-1`, porque RFC 5545 salta el 29 en años comunes y el portal lo muestra el 28. `SEQUENCE` y `LAST-MODIFIED` siguen saliendo de `updated_at`. Un evento no anual que ya pasó sigue en 404.
  - Ruta del controlador del `.ics`: `app/Http/Controllers/EventCalendarController.php` (fuera de las rutas permitidas) no se tocó; usa `visibleTo`, que ya incluye los anuales.
  - **No hecho / seguimiento**: sin capturas de pantalla (el escritor no levantó el servidor); `.playwright-mcp/` y `RETOMAR.md` quedan sin seguimiento.
- 2026-10-30: T3-R hecho en `feat/inicio-7-fechas-anuales` (`51689c9`, `7b2eeab`), sin empujar.

### Registro del T3-R

Arreglos de la revisión independiente del T3. Ruta: delegada (escritor), 2 o más archivos no triviales (modelo, inicio, `/eventos`, editor, manual y pruebas).

- Commits: `51689c9` `fix(events)` (M1, L2 y L3); `7b2eeab` `fix(admin)` (L1 y la línea del manual).
- RED: 4 de 6 pruebas nuevas en rojo:
  - M1 en el inicio y en `/eventos`: faltaba el evento de mañana.
  - L1: el editor guardaba una fecha anual de un año o más.
  - L2: la ocurrencia seguía el reloj, no el día dado.
- Las 2 de L3 (29 de febrero a 2 de marzo en el portal y en el `.ics`) ya pasaban: son guardas de un comportamiento correcto.
- GREEN: 212/212 en Admin de eventos, Home, Events y Search. Suite 1.665/1.665 (1.659 + 6). Pint limpio. Consultas de `/` sin cambio: la unión es una sola sentencia, comprobado por `HomeEventsTest` y `HomeScreenTest`, ≤ 12. Sin cambios de JS ni de Tailwind, así que sin `npm run build`.
- Decisiones:
  1. **M1, una sola sentencia.** `Event::visibleSoonest($user, $limit, $columns)` hace `UNION ALL` de dos partes:
     - los `$limit` eventos de una vez más próximos (`visibleTo`, no anuales, `chronological`);
     - hasta `Event::YEARLY_LIMIT` = 200 anuales (`visibleTo`, `scopeYearly`, por `id`).

     Luego `EventDate::soonestFirst()` los ordena por ocurrencia, y el inicio (50) y `/eventos` (100 + 1 para el aviso de lista cortada) se quedan con su límite. Laravel envuelve cada parte: `select * from (…)` en SQLite y `(…)` en MySQL, así que cada `ORDER BY` y `LIMIT` vale dentro de su parte. `scopeYearly` (todo el día y marcado) es también el que usa `scopeUpcoming`. Con más de 200 anuales visibles, cuáles entran depende del `id`.
  2. **L1.** Con «Se repite cada año», el último día debe ser anterior a la misma fecha un año después (`before:` con `addYearNoOverflow`, así un 29 de febrero tiene como límite el 28 de febrero). El mensaje es «Una fecha que se repite cada año debe durar menos de un año.». Un evento de una sola vez puede durar más.
  3. **L2.** `occurrenceStart()`, `occurrenceLastDay()` y `endsAtMoment()` reciben un `?CarbonInterface $today` opcional (ahora, si es nulo). `EventDate::from()` les pasa el `$today` de la página. El estado (`status`) y las consultas siguen con la hora actual, como antes.
  4. **Manual §2.5.** Una fecha anual dura menos de un año. Si se desmarca la opción en una fecha cuyo primer día ya pasó, deja de verse en el portal, pero los calendarios que la importaron la siguen repitiendo hasta que cada persona la borre.
- **No hecho / seguimiento:** sin capturas (no hubo cambios visuales, salvo el mensaje de error del editor).


- 2026-10-30: T4 hecho en `feat/inicio-8-lectura-por-persona` (`0be536c`), sin empujar.

### Registro del T4

Lectura obligatoria por persona. Ruta: delegada (escritor), 2 o más archivos no triviales (`LinkedAccounts`, `Announcement`, controlador, editor, vista, manual, prueba de guarda y pruebas).

- Commit: `0be536c` `feat(announcements)` (código, pruebas y manual §2.4 juntos: la vista de quien lee y el reporte deben dar el mismo «leído»).
- RED: 16 de 75 en `tests/Feature/Announcements` y `AnnouncementReadReportTest`:
  - la otra cuenta vinculada seguía con «Confirmar lectura» (en `/anuncios` y en el inicio), sin la primera fecha y sin separarse tras desvincular;
  - la segunda cuenta guardaba otra fila;
  - el reporte contaba cuentas (4 en vez de 3), decía «cuentas» y la lista llevaba `company` y no `companies`;
  - la lectura de la otra cuenta de la persona no contaba.
- Las 2 en verde desde el principio son guardas: una cuenta sin vincular con el mismo nombre conserva su estado, y la cuenta de Derivados no ve ni confirma un anuncio solo para Segurex (404).
- GREEN: 75/75. Suite 1.678/1.678 (1.665 + 13: 7 en `ReadPerPersonTest` y 6 en `AnnouncementReadReportTest`). Guarda `PersonNeverAuthorizesTest` 169/169. Pint limpio. Consultas de `/` sin cambio (`ReadPerPersonTest::test_a_read_by_another_account_of_the_person_costs_the_home_no_extra_query`, igual que sin lecturas y ≤ 12). Sin clases nuevas de Tailwind, así que sin `npm run build`.
- Decisiones:
  1. **Quien lee.** `scopeWithReadAtFor` toma `min(read_at)` de las filas de cualquier cuenta de la persona: `LinkedAccounts::idsOfSamePerson($user)` es una subconsulta con el `person_id` como valor (o solo el `id` si la cuenta no está vinculada), dentro de la misma consulta de los anuncios. Cero consultas nuevas en el inicio y en `/anuncios`.
  2. **Confirmar.** Si la persona ya lo leyó con cualquier cuenta (`Announcement::isReadByPersonOf()`), la segunda cuenta no guarda nada y la respuesta es la misma (vuelve a `/anuncios/{id}` o a `/#anuncios`). Así, al desvincular, solo queda leída la cuenta que confirmó. Ver y poder confirmar siguen por cuenta (`visibleTo`): 404 para lo que la cuenta no ve, igual que antes.
  3. **Reporte.** Una persona es `person_id`, o la cuenta sola. `LinkedAccounts::PERSON_KEY` da un número por persona en SQL (impar con persona, par sin ella; aritmética simple que vale en SQLite y MySQL y nunca es negativa). Y = personas distintas entre las cuentas del público (misma regla: empresas activas del anuncio, sin emergencia ni invitados, `is_guest` nulo cuenta). X = de ellas, las que leyeron con cualquier cuenta, también una fuera del público actual: es la misma regla que el «Leído» de quien lee, si no la persona aparecería como pendiente sin botón para confirmar.
  4. **Lista.** «Personas que faltan (N)»: una fila por persona, con el primer nombre en orden alfabético de sus cuentas del público y solo las empresas de esas cuentas («Ana Pérez · Derivados, Segurex»). Nunca muestra una empresa a la que el anuncio no va. Dos consultas: hasta 200 personas (`GROUP BY` de la clave, `min(name)`), luego sus cuentas del público con la empresa. Sigue el tope de 200 («Y N más») y el recuento cada minuto.
  5. **Guarda.** `tests/Unit/Auth/PersonNeverAuthorizesTest.php` permite `app/Models/Announcement.php` => `['LinkedAccounts']`, con el motivo escrito: la persona solo agrupa lecturas y `Announcement` nunca lee `person_id` (lo hace `LinkedAccounts`). El controlador, el editor y las vistas no mencionan a la persona.
  6. **Textos.** «X de Y personas lo leyeron» («1 persona lo leyó»), «Personas que faltan», ayuda de la tarjeta («Quien tiene cuentas vinculadas en dos empresas cuenta como una persona y confirma una sola vez.») y manual §2.4.
- **No hecho / seguimiento:** sin capturas (el escritor no levantó el servidor; el cambio visual es solo de texto y de la lista de empresas). Una carrera entre dos cuentas de la misma persona que confirman a la vez puede dejar dos filas; no cambia nada (se toma la primera fecha y se cuentan personas).

- 2026-10-30: T5 hecho en `feat/inicio-9-recordatorios` (`df20a1a`), sin empujar.

### Registro del T5

Recordatorios por correo a quien no ha confirmado. Ruta: delegada (escritor), 2 o más archivos no triviales (migración, dos modelos, servicio, aviso, comando, programación, editor, vista, Auditoría, manual y pruebas).

- Commit: `df20a1a` `feat(announcements)` (código, pruebas y manual §2.4 juntos).
- RED: 20 de 20 en `tests/Feature/Admin/AnnouncementReminderTest.php` (10) y `tests/Feature/Console/SendAnnouncementRemindersCommandTest.php` (10), 2 fallas y 18 errores: sin botón «Enviar recordatorio», sin `sendReminder`, sin `AnnouncementReminders`, sin el aviso, sin el comando y sin la programación.
- GREEN: 20/20. Relacionadas: 409/409 (guarda `PersonNeverAuthorizesTest`, `tests/Feature/Announcements`, reporte y editor de anuncios, Auditoría y `tests/Feature/Home`). Suite 1.704/1.704 (1.678 + 20 pruebas nuevas + 6 casos de la guarda por los 6 archivos PHP nuevos de `app/`). Pint limpio. `npm run build` sin cambios en `public/build` (sin clases nuevas de Tailwind). El inicio no cambia: nada nuevo en `/`.
- Decisiones:
  1. **El pedido solo se anota.** `App\Announcements\AnnouncementReminders::request()` bloquea la fila del anuncio (`lockForUpdate`) dentro de una transacción y revisa: publicado (no programado, vencido ni retirado), lectura obligatoria, sin otro pedido en las últimas 24 horas (`AnnouncementReminder::INTERVAL_HOURS`) y con alguien pendiente. Si algo falla lanza `ReminderNotAllowed` con el mensaje para el editor y no guarda nada. Un doble clic o dos editores a la vez esperan el bloqueo y el segundo recibe «Ya se pidió un recordatorio… Podrás pedir otro el …». A las 24 horas exactas ya se puede.
  2. **Tablas.** `announcement_reminders` (anuncio, quién, cuándo, `missing_count` al pedirlo, `finished_at` nulo mientras se envía) y `announcement_reminder_deliveries` con único (recordatorio, `person_key`), la cuenta a la que fue y el estado (`pending`, `sent`, `failed`). Migración `2026_10_30_000004`.
  3. **El cron envía.** `portal:send-announcement-reminders`, cada minuto con `withoutOverlapping()`, hasta `BATCH` = 50 correos por vuelta entre todos los pendientes (el más antiguo primero). Sin colas. La fila de entrega se escribe antes del correo: si una vuelta muere a la mitad, esa persona queda en `pending` y no se vuelve a intentar. Así nadie recibe dos correos del mismo recordatorio, aunque se pierda uno. Un fallo del correo queda en el log (`Announcement reminder could not be sent.`) y en `failed`, no se reintenta y no corta la vuelta. Un recordatorio termina cuando ya no queda nadie o cuando el anuncio deja de estar publicado y obligatorio.
  4. **Una persona, un correo.** `Announcement::reminderRecipients()` usa la regla del T4 al momento de enviar: el público sin las personas que leyeron (con cualquier cuenta) ni las ya anotadas en el recordatorio, agrupado por `LinkedAccounts::PERSON_KEY`. Elige la cuenta de menor `id` de la persona dentro del público, así que un anuncio solo para Derivados nunca escribe a la cuenta de Segurex. El correo lleva la marca de la empresa de esa cuenta (`MailBrand`). Quien confirma antes de su turno queda fuera.
  5. **Correo.** `AnnouncementReminderNotice`: asunto «Recordatorio: confirma la lectura de «título»», el título, que pide confirmar la lectura, el botón «Ver el anuncio» a `/anuncios/{id}` y nada del texto del anuncio. Sale por el mismo transporte de Graph (`MAIL_FROM_ADDRESS`), con `Notification::sendNow` y try/catch como los demás avisos. No lleva `afterCommit` porque el comando no corre dentro de una transacción.
  6. **Reporte.** La tarjeta **Lectura** muestra «Último recordatorio: pedido el … por … · enviado a N personas.» o «Enviando…». El botón (con `wire:confirm`) solo aparece si se puede pedir; si no, «Podrás pedir otro recordatorio el …». El dato entra en el mismo `readReport` guardado 60 segundos (sigue el tope de 200 y el recuento cada minuto) y se borra al pedir uno. `sendReminder` revisa `update` en cada llamada (403 si se perdió el rol).
  7. **Auditoría.** `announcement.reminder_requested` en el registro de anuncios, con quien lo pidió y `missing_people`: «Pidió un recordatorio de lectura para el anuncio «…»» y la fila «Personas que faltan».
  8. **Guarda.** Sin cambios en `PersonNeverAuthorizesTest`: la consulta por persona vive en `Announcement` (ya permitido para `LinkedAccounts`). El servicio, el comando, el aviso, el editor y los modelos nuevos no mencionan a la persona. `person_key` solo agrupa.
- **No hecho / seguimiento:** sin capturas (el escritor no levantó el servidor). Con `lockForUpdate` en SQLite no se prueba la carrera real; en MySQL (producción) el bloqueo de la fila del anuncio la ordena. Una persona sin ninguna cuenta con correo no existe hoy (`users.email` es obligatorio). El correo de un recordatorio en `pending` perdido no se reintenta a propósito.

- 2026-10-30: T5-R hecho en `feat/inicio-9-recordatorios` (`ba2a542`), sin empujar.

### Registro del T5-R

Arreglos de la revisión independiente del T5: ningún hallazgo alto ni medio, cinco bajos. Ruta: delegada (escritor), 2 o más archivos no triviales (servicio, modelo, editor, vista, migración, manual y pruebas).

- Commit: `ba2a542` `fix(announcements)` (los cinco hallazgos, con sus pruebas y el manual §2.4).
- RED: 6 de 27 en `AnnouncementReminderTest` y `SendAnnouncementRemindersCommandTest` (5 fallas y 1 error):
  - B1: Ana recibió un correo como cuenta sola, luego vinculó sus cuentas y recibió otro del mismo recordatorio.
  - B2: una cuenta borrada entre la consulta de personas y la de cuentas cerró el recordatorio antes de tiempo.
  - B3: un recordatorio detenido no tenía `stopped_at` (2 pruebas) y, con el anuncio publicado otra vez, seguía bloqueando 24 horas.
  - B4: un `QueryException` (espera del bloqueo) al pedirlo subía como error 500.
- Las 2 de B5 ya pasaban: son guardas. Un editor de Segurex recibe 403 con un anuncio para Segurex y Derivados o para todas las empresas, también si lo amplían con la página abierta. Las cuentas invitadas, de emergencia y de empresas inactivas nunca reciben el correo.
- GREEN: 27/27. Suite 1.711/1.711 (1.704 + 7). Pint limpio. Sin clases nuevas de Tailwind. El inicio no cambia.
- Decisiones:
  1. **B1.** `Announcement::reminderCandidates()` deja fuera a la persona si cualquiera de sus cuentas actuales ya tiene una entrega en el recordatorio (por `user_id`, llevado a la clave de persona actual). También la deja fuera por la clave guardada, que cubre una cuenta borrada.
  2. **B2.** `reminderCandidates()` es solo la consulta agrupada (id de cuenta y clave de persona). El servicio carga después las cuentas con su empresa. «No queda nadie» se decide con el número de personas elegidas, así que una cuenta que desaparece en medio se salta y la siguiente vuelta vuelve a elegir.
  3. **B3.** `announcement_reminders.stopped_at`, en la misma migración `2026_10_30_000004` (aún no desplegada). Se llena junto con `finished_at` cuando el anuncio ya no está publicado y obligatorio. La tarjeta dice «… · se detuvo porque el anuncio ya no pide confirmación: enviado a N personas.». El límite de 24 horas solo cuenta los recordatorios no detenidos, así que con el anuncio de vuelta se puede pedir otro enseguida. Quien recibió el detenido puede recibir el nuevo, que es otro recordatorio.
  4. **B4.** `sendReminder` atrapa `QueryException`, la anota en el log («Announcement reminder could not be requested.») y muestra «No se pudo pedir el recordatorio. Inténtalo de nuevo.» en la tarjeta.
  5. **Manual §2.4.** Agrega el aviso de fallo al pedirlo, el «un correo aunque vincule después» y el recordatorio detenido que no cuenta para las 24 horas.
- **No hecho / seguimiento:** sin capturas (no hubo cambios visuales, salvo los textos de la tarjeta).

## Siguiente paso

Abrir el PR apilado de T5 (`feat/inicio-9-recordatorios`, sobre #79) con los arreglos del T5-R, sin fusionar ni desplegar.
