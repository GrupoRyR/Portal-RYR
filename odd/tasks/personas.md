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
- [ ] WU1-R. Arreglos de la revisión independiente del PR1.
- [ ] WU2. **PR2, cumpleaños y aniversarios.** Ruta: delegada (escritor).
- [ ] WU2-R. Arreglos de la revisión independiente del PR2.

## Preguntas para Jhon

1. ¿Más de 1.000 personas en alguna empresa? El inicio carga hasta 1.000 por nombre; `/personas` siempre tiene todas.

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
- Las entradas no vistas se borran por `synced_at` anterior al inicio de la copia (segundos); el horario es `withoutOverlapping()`.
- El índice del Ctrl K lleva por persona nombre, «Cargo · Área», el texto de búsqueda (incluye oficina y correo) y `href` = `/personas?q=<nombre>`; nunca teléfono ni fechas. En la búsqueda del servidor (`?q=`) no se lee el directorio. El inicio queda en ≤ 12 consultas.
- El menú «Secciones» del celular no lleva «Personas»: `HomeMyDayTest` exige solo anclas de la página y una lista exacta (fuera de las superficies permitidas). El enlace está en «Mi día» (sección «Personas», «Ver directorio»), donde están «Ver eventos» y «Ver todos».
- `home.js` toma el texto de espera del buscador de `data-idle`, así el diálogo menciona «personas» solo cuando hay.

**Pendiente**

- Capturas a 390 y 1.440 px, claro y oscuro, de `/personas` y de «Mi día» (no se hicieron).
- Revisión independiente del PR1 (WU1-R).

## Siguiente paso

WU1-R: revisión independiente del PR1; luego WU2.
