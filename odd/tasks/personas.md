# Personas: directorio, cumpleaños y aniversarios

Fase 3 del nuevo inicio («Personas» en el plan aprobado), con el cambio que decidió Jhon el 2026-10-30: los datos salen de Microsoft (Graph), no de un CSV de Talento humano.

## Objetivo

Que cada persona encuentre a sus compañeros de empresa (nombre, cargo, área, oficina, extensión, correo) desde el buscador Ctrl K y una página `/personas`, y que «Mi día» muestre los cumpleaños y los aniversarios de trabajo de su empresa, con «Felicitar» por Teams.

## Problema y por qué

- Hoy el portal solo conoce a quien ya inició sesión, y solo guarda su cargo (`users.job_title`). No hay directorio.
- Microsoft ya tiene los datos laborales: Graph `GET /users` trae `displayName`, `jobTitle`, `department`, `officeLocation`, `businessPhones`, `mail` y `employeeHireDate` (Jhon llenará «Fecha de contratación» en Entra).
- El portal no guarda tokens de usuario, así que la copia es de aplicación: `User.Read.All` (Aplicación), aprobado por Jhon el 2026-10-30 para Segurex y Derivados, con consentimiento por inquilino.
- El cumpleaños no se puede leer en una lista de Graph (`birthday` solo por usuario y casi siempre vacío). Decisión de Jhon: cada persona lo escribe en su perfil, solo día y mes, y decide si se muestra (Ley 1581).

## Alcance

- WU1 (PR1): copia nocturna del directorio de cada empresa activa, tabla `directory_entries`, comando `portal:sync-directory`, búsqueda de personas en Ctrl K y página `/personas`.
- WU2 (PR2): cumpleaños en `/cuenta` (día, mes, «mostrar»), y en «Mi día» los cumpleaños y aniversarios de hoy y los próximos 7 días, con «Felicitar».

## Fuera de alcance

- Fotos de perfil, jefe directo y «se encarga de» (Graph no los trae en la lista o no con permisos de aplicación).
- El celular personal (`mobilePhone`): nunca se guarda.
- Invitados (B2B) y cuentas deshabilitadas: no entran al directorio.
- Correos o avisos de cumpleaños.

## Restricciones

- Una empresa solo ve su propio directorio (la cuenta activa decide). Cuentas de emergencia: sin directorio.
- La empresa de cada entrada sale de la misma regla que el inicio de sesión (`TenantDirectory::resolveCompany`: única empresa del inquilino, si no la dueña del dominio del correo, si no la principal; solo activas). La regla se extrae para no duplicarla.
- Si la copia de un inquilino falla (sin consentimiento, 401/403, red), se conservan los datos anteriores y no se borra nada; el comando termina con error y deja un aviso en el log.
- Solo `https://graph.microsoft.com` (también en `@odata.nextLink`). Pruebas con `Http::fake`, nunca red real.
- Inicio sin Livewire ni Alpine, presupuesto ≤ 12 consultas (`HomeScreenTest`). Hoy son 11: el directorio entra en una sola consulta que sirve al buscador y, en WU2, a cumpleaños y aniversarios.
- El índice de Ctrl K va embebido en el HTML y el service worker lo guarda por persona hasta 24 h: se acepta (es el directorio de la empresa, igual que la libreta de Outlook) y se documenta.
- `person_id` no autoriza nada: el cumpleaños es por cuenta.
- Solo cron, sin colas. `public/build` va en el repositorio. Pruebas con `APP_KEY` en el entorno.
- Commits convencionales sin atribución de IA.

## Tareas

- [x] WU1. **PR1, directorio desde Microsoft.** Ruta: delegada (escritor), 2 o más archivos no triviales (migración, modelo, token de aplicación, sincronización, comando, horario, índice, vistas, pruebas, documentación).
- [x] WU1-R. Arreglos de la revisión independiente del PR1. Ruta: delegada (escritor), 2 o más archivos no triviales (sincronización, comando, modelo, vista, JS, pruebas, documentación).
- [x] WU2. **PR2, cumpleaños y aniversarios.** Ruta: delegada (escritor), 2 o más archivos no triviales (migración, solicitud, controlador, vistas, constructor del inicio, JS, pruebas, documentación).
- [x] WU2-R. Arreglos de la revisión independiente del PR2. Ruta: delegada (escritor), mismo hilo que el WU2 (controlador, vista, pruebas, documentación).

## Preguntas para Jhon

1. ¿Más de 1.000 personas en alguna empresa? El inicio carga hasta 1.000 por nombre; `/personas` siempre tiene todas. **Respuesta (Jhon, 2026-10-30): ninguna empresa llega a 1.000 personas.** El límite se queda como está.

## Criterios de aceptación

- Con el consentimiento dado, `portal:sync-directory` llena `directory_entries` por empresa; una persona que sale de Entra (o se deshabilita) desaparece en la siguiente copia.
- Sin consentimiento en un inquilino, ese inquilino conserva sus datos y los demás se copian.
- Ctrl K encuentra personas por nombre, cargo o área, solo de la empresa activa; `/personas` busca y pagina.
- El cumpleaños solo se ve si la persona lo marcó para mostrar, y se puede quitar.

## Chequeos

- RED antes de GREEN en cada unidad; `php artisan test` completo y `vendor/bin/pint --test`.
- Capturas a 390 y 1.440 px, claro y oscuro, sin desborde horizontal.
- Revisión independiente por PR.

## Entrega

- Rama `feat/personas-1-directorio` apilada sobre #73 (`feat/inicio-5-eventos`), y `feat/personas-2-cumpleanos` sobre ella. Estrategia: PR apilados a `main` (`ask-on-risk`, ya elegida para la cadena del inicio). Sin fusionar ni desplegar.

## Progreso

- 2026-10-30: WU2-R hecho en dos commits más el registro (ver «Registro del WU2-R»).
- 2026-10-30: WU2 hecho en tres commits más el registro (ver «Registro del WU2»).
- 2026-10-30: WU1-R hecho en seis commits (ver «Registro del WU1-R»).
- 2026-10-30: WU1 hecho en tres commits (ver «Registro del WU1»).
- 2026-10-30: mapa del código hecho (token de aplicación en `app/Mail/GraphAccessToken.php`, regla de empresa en `TenantDirectory::resolveCompany`, índice en `app/Search/HomeSearchIndex.php`, `routes/console.php` para el horario). Ya existe una tabla `people` para cuentas vinculadas: el directorio usa `directory_entries`.

## Registro del WU1

Ruta: delegada (escritor), por la regla de 2 o más archivos no triviales. Rama `feat/personas-1-directorio`, sin push.

| Commit | Qué | Líneas (+/−) |
|---|---|---|
| `8cef576` refactor(tenancy): share the company rule of the sign-in | `TenantDirectory::companyAmong()`, la regla de `resolveCompany()` sin consulta; `resolveCompany()` igual. | 55 / 4 |
| `066bcbd` feat(directory): copy each company's people from Microsoft every night | Tabla `directory_entries`, `DirectoryEntry`, `AppOnlyToken` + `GraphAppException`, `DirectorySync`, `portal:sync-directory` a las 03:30, `docs/despliegue.md` §1.10. | 1.382 / 0 |
| `5b52300` feat(people): find coworkers in Ctrl K and on /personas | Grupo «Personas» del Ctrl K, `/personas`, enlace «Ver directorio» en «Mi día», `public/build`, `docs/manual-administracion.md` §9. | 637 / 22 |

Pasa de las ~400 líneas por tarea: el commit del directorio lleva unas 600 líneas de pruebas (24 casos con Graph falso) y los tres commits son unidades que se entienden solas. No se recortó nada.

**RED → GREEN**

- Regla de empresa: `TenantDirectoryTest` 24 pruebas, 2 errores («Call to undefined method companyAmong()») → 24/24; `tests/Feature/Tenancy tests/Unit` 385/385.
- Copia: `tests/Feature/Directory` 24 pruebas, 0 pasan (clases y comando inexistentes, horario sin registrar) → 24/24.
- Personas: `PeoplePageTest` + `HomeSearchIndexTest` + `tests/Feature/Home` 48 pruebas, 13 fallan (ruta `people.index` inexistente, índice sin `people`) → 150/150 tras ajustar el menú «Secciones» (abajo).
- Enfocadas finales: `tests/Feature/Directory tests/Feature/Search tests/Feature/Home tests/Feature/Tenancy tests/Feature/Pwa` 203/203.
- Completa: `php artisan test --compact` 1.566/1.566 (base 1.519). `vendor/bin/pint --test` pasa. `npm run build` hecho y `public/build` en el commit.

**Decisiones**

- La empresa de cada persona sale de `TenantDirectory::companyAmong()`, que también usa `resolveCompany()`.
- Columna extra `search_text` (texto por `AppSearch::normalize`): la búsqueda de `/personas` ignora tildes en SQLite y MySQL con `LIKE … escape '!'` (`App\Support\Like`), igual que el Ctrl K.
- Quién ve el directorio (`DirectoryEntry::companyIdFor()`): cuenta normal de empresa activa. Sin directorio: emergencia, sin empresa, empresa inactiva e **invitados** (`is_guest = true`); `is_guest = null` cuenta como miembro, como en los anuncios. Lo de los invitados es una decisión del escritor: revisar con Jhon.
- Las personas de inquilinos sin empresa activa se borran en la copia (una empresa desactivada sale del directorio).
- Las entradas no vistas se borran por `synced_at` anterior al inicio de la copia (segundos); el horario es `withoutOverlapping()`. *(Cambiado en WU1-R: se borran por `azure_oid` no visto y el comando toma un candado.)*
- El índice del Ctrl K lleva por persona nombre, «Cargo · Área», el texto de búsqueda (incluye oficina y correo) y `href` = `/personas?q=<nombre>`; nunca teléfono ni fechas. En la búsqueda del servidor (`?q=`) no se lee el directorio. El inicio queda en ≤ 12 consultas.
- El menú «Secciones» del celular no lleva «Personas»: `HomeMyDayTest` exige solo anclas de la página y una lista exacta (fuera de las superficies permitidas). El enlace está en «Mi día» (sección «Personas», «Ver directorio»), donde están «Ver eventos» y «Ver todos».
- `home.js` toma el texto de espera del buscador de `data-idle`, así el diálogo menciona «personas» solo cuando hay.

**Pendiente**

- Capturas a 390 y 1.440 px, claro y oscuro, de `/personas` y de «Mi día» (no se hicieron).
- Revisión independiente del PR1 (WU1-R).

## Registro del WU1-R

Ruta: delegada (escritor), por la regla de 2 o más archivos no triviales. Rama `feat/personas-1-directorio`, sin push. Arreglos de la revisión independiente del PR1, uno por commit, cada uno con su prueba.

| Commit | Qué | Líneas (+/−, sin `public/build`) |
|---|---|---|
| `1666625` fix(directory): keep the hire date on the day Entra wrote | La fecha de contratación es el día UTC 12 horas después del momento que guarda Entra: medianoche UTC (centro de administración), medianoche de Bogotá u otra zona dan el día escrito. Antes, medianoche UTC quedaba un día antes. | 18 / 7 |
| `953e99f` fix(directory): keep people without a user type in the directory | Solo `userType = Guest` queda fuera; `null` o sin el campo cuenta como miembro, como `is_guest = null` al ver. | 25 / 2 |
| `d664f13` fix(directory): fail a tenant on any error, a redirect or an empty answer | Cualquier error de un inquilino (no solo `GraphAppException`) lo marca fallido, conserva sus datos y la copia sigue, incluida la limpieza de empresas desactivadas; el log lleva solo la clase del error si no es nuestro. Graph sin seguir redirecciones (`withoutRedirecting()`, un 3xx falla). Una respuesta sin nadie que copiar cuando el inquilino ya tiene personas falla y conserva. Documentado en `docs/despliegue.md` §1.10 y `docs/manual-administracion.md` §9.2. | 150 / 22 |
| `6573b67` fix(directory): remove only people a run did not see, one run at a time | Se borran los `azure_oid` del inquilino que esta copia no vio (ids calculados en PHP y borrados en bloques de 500), no por `synced_at`. `portal:sync-directory` toma `Cache::lock('portal:sync-directory', 2 h)`: si está tomado, dice «Ya hay una copia del directorio en curso…», no copia y termina con error. Prueba nueva de un dominio que pasa a otra empresa del mismo inquilino. Documentado en las mismas secciones. | 132 / 17 |
| `b573edb` fix(people): store only valid emails and encode the mail link | La copia usa `mail` o `userPrincipalName` solo si es una dirección válida (`FILTER_VALIDATE_EMAIL`, con Unicode); si ninguno lo es, la persona no entra. `/personas` arma el `mailto:` con `DirectoryEntry::mailtoHref()` (partes codificadas). | 54 / 3 |
| `df7b1ab` fix(home): fall back to the default idle text of the search | `home.js` vuelve al texto de espera de siempre si falta `data-idle`. `public/build` recompilado. | 4 / 1 |

**RED → GREEN** (`tests/Feature/Directory`)

- Fecha de contratación: 32 pruebas, 1 falla (`2013-12-31` en vez de `2014-01-01`, y tres casos más un día antes) → 32/32. La prueba `test_the_hire_date_is_the_day_in_bogota` pasa a `test_the_hire_date_is_the_day_entra_wrote_whatever_the_midnight` (medianoche UTC, `T05:00:00Z`, `T22:00:00-02:00`, medianoche UTC+2, 03:00 UTC, texto inválido).
- Tipo de usuario: 33 pruebas, 1 falla (sin `userType` y `null` quedaban fuera) → 33/33.
- Fallos de inquilino: 39 pruebas, 5 fallan (conexión perdida sin `exception` en el log, `RuntimeException` sin capturar, redirección seguida a otro host, respuesta vacía y solo invitados borraban el inquilino) → 39/39.
- Borrado y candado: 43 pruebas, 2 fallan (entrada no vista con sello posterior conservada; comando con el candado tomado copiaba) → 43/43. La de 1.100 entradas no vistas y la del dominio movido pasaban ya: cubren huecos, no fallos.
- Correos: 45 pruebas, 2 fallan (correo con `?cc=` guardado; `mailto:` sin codificar) → 45/45.
- `home.js`: sin ejecutor de pruebas JS en el proyecto; chequeo estructural: el paquete `public/build/assets/home-DcM_FsJZ.js` lleva `dataset.idle||` y el texto por defecto.
- Completa: `php artisan test --compact` 1.579/1.579 (base 1.566, 13 pruebas nuevas). `vendor/bin/pint --test` pasa. `npm run build` hecho.

**Decisiones**

- Invitado: la comparación con «Guest» ignora mayúsculas (más estricta que `=== 'Guest'`, sin efecto con lo que manda Graph).
- `GraphAppException` sigue llevando al log su mensaje, estado y códigos AADSTS (los arma el portal, sin secretos); cualquier otro error, solo su clase.
- La respuesta vacía se juzga después de filtrar: si nadie queda (solo invitados o deshabilitados) y el inquilino tiene personas, falla. Un inquilino sin personas aún puede copiar cero sin error. Consecuencia: si una empresa de verdad se queda sin nadie, sus personas no se borran solas; se desactiva la empresa.
- Candado de 2 horas (`LOCK_SECONDS`): de sobra para la copia; si el proceso muere, se libera solo. Es aparte del `withoutOverlapping()` del horario.
- Correo válido según `FILTER_VALIDATE_EMAIL`: un `?` dentro de la parte local es válido, por eso el `mailto:` además se codifica.

**Pendiente**

- Espejo en Engram de este documento (lo hace el orquestador).
- Capturas a 390 y 1.440 px de `/personas` y «Mi día» (siguen sin hacerse, del WU1).

## Registro del WU2

Ruta: delegada (escritor), por la regla de 2 o más archivos no triviales. Rama `feat/personas-2-cumpleanos`, apilada sobre #75 (`23c0edd`), sin push.

| Commit | Qué | Líneas (+/−, sin `public/build`) |
|---|---|---|
| `699b7e8` feat(account): let each person save their birthday on /cuenta | Migración `users.birthday_month`, `birthday_day` (tinyint, null) y `show_birthday` (falso por defecto), fuera de `#[Fillable]`. `BirthdayRequest` (día y mes que existen juntos en un año bisiesto, mensajes en español, 404 sin directorio) y `BirthdayController` (`POST` y `DELETE /cuenta/cumpleanos`, `throttle:20,1`). Sección «Mi cumpleaños» en `/cuenta` y enlace «Mi cumpleaños» en el menú de la cuenta del inicio. Manual §9.5. | 633 / 1 |
| `b1fa0ad` test: raise the PHPUnit memory limit to 512M | `<ini name="memory_limit" value="512M"/>` en `phpunit.xml` (aprobado por el coordinador). | 1 / 0 |
| `eb5b5a4` feat(home): show birthdays and work anniversaries in «Mi día» | `App\View\Home\Celebration` (hoy y 7 días, 29 de febrero el 28 en años comunes, aniversario desde 1 año, orden por fecha y nombre, máximo 8 y «Ver directorio»). La consulta del directorio del inicio hace `LEFT JOIN users` por inquilino, `azure_oid` y empresa con `show_birthday = true` (nunca `person_id`). «Felicitar» por Teams con saludo (`DirectoryEntry::teamsChatUrl($message)`), sin «Felicitar» en la fila propia. `home.js` corrige la copia guardada (`data-celebration-date`, `data-celebration-day`). «Secciones» lleva `#celebraciones`. Manual §9.1 y §9.6. `public/build` recompilado. | 737 / 15 |

Los dos commits de funcionalidad pasan de las ~400 líneas por las pruebas (unas 300 y 330 líneas); son unidades que se entienden solas. No se recortó nada.

**RED → GREEN**

- Cumpleaños en `/cuenta`: `BirthdayTest` 27 pruebas, 1 pasa, 3 fallan y 23 errores (sin columnas ni rutas) → 27/27; `tests/Feature/Account` 155/155.
- «Mi día»: `HomeCelebrationsTest` + `HomeScreenTest` + `HomeMyDayTest` 50 pruebas, 16 fallan (bloque, conteo de consultas, menú «Secciones») → 50/50. `HomeSearchIndexTest::test_the_people_come_in_one_capped_query_of_the_needed_columns` se ajustó a propósito: la consulta ahora lleva correo y fecha de contratación (siguen fuera teléfono, oficina y `*`); `HomeCelebrationsTest::test_the_search_index_carries_no_birthday_or_hire_date` vigila que el índice no los lleve.
- Enfocadas: `tests/Feature/Home tests/Feature/Search tests/Feature/Directory tests/Feature/Account tests/Unit/Auth` 508/508.
- Memoria: el pico de la suite completa era 128M en `23c0edd` (justo debajo del límite) y 130M en `699b7e8`; `php artisan test --compact` en `699b7e8` muere por memoria (`UserMicrosoftIdentityTest`). Con `b1fa0ad` pasa.
- Completa: `php artisan test --compact` 1.630/1.630 (base 1.579, 51 pruebas nuevas). `vendor/bin/pint --test` pasa. `npm run build` hecho. `home.js`: sin ejecutor de pruebas JS; chequeo estructural del paquete `home-BIN7fZl0.js` (`celebrationDate`, «Mañana»).
- El inicio sigue en ≤ 12 consultas con 40 cumpleaños y aniversarios en la semana (`HomeScreenTest`).

**Decisiones**

- Controlador y solicitud propios (`BirthdayController`, `BirthdayRequest`) en vez de crecer `AccountController`; la misma URL para guardar (`POST`) y quitar (`DELETE`).
- En una búsqueda del servidor (`/?q=`) no hay bloque: esa página sigue sin leer el directorio.
- Cumpleaños y aniversarios salen de las mismas 1.000 personas por nombre del buscador (ninguna empresa llega a 1.000).
- Fila con la caja de fecha y «Hoy», «Mañana» o el día de la semana, como «Próximas fechas»; el lector de pantalla oye la fecha completa.
- Saludos: «¡Feliz cumpleaños, Luis!» y «¡Feliz aniversario, Luis! Gracias por estos N años en Segurex.» (o «este primer año»). Aniversario con el nombre corto de la empresa.
- Enlace «Mi cumpleaños» en el menú de la cuenta, para que la sección se encuentre.

**Pendiente**

- Espejo en Engram de este documento (lo hace el orquestador).
- Capturas a 390 y 1.440 px de `/cuenta` y «Mi día» (no se hicieron).
- Revisión independiente del PR2 (WU2-R).

## Registro del WU2-R

Ruta: delegada (escritor), en el mismo hilo que el WU2. Rama `feat/personas-2-cumpleanos`, sin push. Arreglos de la revisión independiente del PR2, con su prueba primero.

| Commit | Qué | Líneas (+/−) |
|---|---|---|
| `ffd22f0` fix(account): let a person remove their birthday without a directory | (Media, Ley 1581) `BirthdayController::destroy` ya no pide directorio: borra solo el cumpleaños de quien llama (cuentas de emergencia siguen con 404, como `/cuenta`). `/cuenta` muestra **Quitar mi cumpleaños** siempre que haya uno guardado; sin directorio (invitado, sin empresa, empresa inactiva) solo eso, con una línea que explica que nadie lo ve. Guardar sigue pidiendo directorio. Manual §9.5: quitar u ocultar vale de inmediato en el servidor, pero la copia guardada del inicio de un compañero puede mostrarlo hasta 24 horas (hallazgo bajo de documentación, en el mismo commit). | 80 / 11 |
| `e9a9400` fix(account): word the shown birthday without promising who sees it | (Baja) «Lo ven las personas de tu empresa.» pasa a «Se muestra en «Mi día» a las personas de tu empresa.»: una cuenta aún fuera del directorio no se muestra a nadie. | 4 / 2 |

**RED → GREEN** (`tests/Feature/Account/BirthdayTest.php`)

- Quitar sin directorio: 32 pruebas, 5 fallan (empresa desactivada → `DELETE` daba 404; invitado, sin empresa y empresa inactiva con cumpleaños guardado no veían **Quitar**; más la del texto, aparcada para su propio commit) → 32/32 con la del texto como antes. `test_accounts_without_a_directory_neither_see_nor_use_it` pasa a `…_neither_see_nor_save_it` (ya no espera 404 al quitar). Prueba nueva: quitar no toca el cumpleaños de otra cuenta aunque se mande su id.
- Texto: 32 pruebas, 1 falla → 32/32.
- Enfocadas: `tests/Feature/Account` 160/160.
- Completa: `php artisan test --compact` 1.635/1.635 (antes 1.630, 5 pruebas nuevas). `vendor/bin/pint --test` pasa. Sin cambios de JS ni de clases nuevas: no hizo falta `npm run build`.

**Pendiente**

- Espejo en Engram de este documento (lo hace el orquestador).
- Capturas a 390 y 1.440 px de `/cuenta` y «Mi día» (siguen sin hacerse).

## Siguiente paso

PR2 listo para que Jhon lo revise; sin fusionar ni desplegar.
