# Nuevo inicio, fase 1: base del inicio

Fase 1 del nuevo inicio, después de las cuentas vinculadas (`odd/tasks/cuentas-vinculadas.md`). Plan: «Fases siguientes», punto 1, del plan del nuevo inicio aprobado por Jhon (prototipo aprobado por el gerente: https://claude.ai/artifact/V6aNj3e3ycJDz4fK4g2oFQ).

## Objetivo

Un inicio que responda «qué hay hoy» en un vistazo: festivos de Colombia, fechas de la empresa, anuncios y accesos a Microsoft 365, con un buscador `Ctrl K` sobre apps, anuncios y fechas.

## Problema y por qué

- Hoy el inicio solo muestra apps y anuncios; las fechas importantes (festivos, cierres, fechas de cada empresa) viven en correos y calendarios sueltos.
- El gerente aprobó un prototipo con una columna «Mi día» y una búsqueda rápida.
- Los festivos cambian cada año (Ley Emiliani y Semana Santa), así que se calculan en código y no se cargan a mano.

## Alcance

- WU1: festivos de Colombia calculados en código (sin interfaz ni migración).
- WU2: fechas por empresa en Admin.
- WU3: nueva distribución del inicio con la columna «Mi día» y el menú «Secciones» en el celular.
- WU4: búsqueda `Ctrl K` con un índice JSON incrustado.

## Fuera de alcance

- Etiquetas de «puente» (no se construyen hasta que Jhon decida).
- Búsqueda de personas (bloqueada, ver preguntas).
- «Ver como» en el inicio.

## Restricciones

- Sin Livewire ni Alpine en `/`.
- Lighthouse ≥ 90 en el celular.
- Solo cron; sin colas ni procesos largos.
- Las pruebas nunca usan la red (`Http::fake`).
- Zona horaria `America/Bogota`, tomada de `config('app.timezone')`.
- Presupuesto de consultas del inicio ≤ 12 (`HomeScreenTest`).
- `public/build` va en el repositorio: clases nuevas de Tailwind piden `npm run build`.
- Las pruebas corren con `APP_KEY` en el entorno, porque `.env*` está bloqueado.
- Commits convencionales sin atribución de IA.

## Tareas

- [x] WU1. **PR1, festivos de Colombia.** Ruta: delegada (escritor), porque son 2 o más archivos no triviales: `Holiday`, `ColombianHolidays` y sus pruebas.
  - `App\Calendar\Holiday`: fecha (`CarbonImmutable`) y nombre.
  - `App\Calendar\ColombianHolidays`: `forYear()`, `isHoliday()`, `nextOnOrAfter()` (cruza el fin de año) y `between()`. Pura: sin base de datos ni red.
  - Reglas: Ley 51 de 1983 (fijos y trasladados al lunes), Pascua gregoriana (Meeus/Jones/Butcher) y las leyes nuevas que cambian la lista.
  - Pruebas con las listas completas de 2025, 2026 y 2027.
  - Hecho y verificado (ver el registro del WU1).
- [ ] WU2. **PR2, fechas por empresa en Admin.** Bloqueada: decisión de Jhon.
- [x] WU3. **PR3, distribución del inicio.** Ruta: delegada (escritor), porque son 2 o más archivos no triviales: `HomePageBuilder`, `HomePage`, `home.blade.php` y sus pruebas.
  - Columna «Mi día»: festivos, tarjeta de anuncios y enlaces de Microsoft 365.
  - Menú «Secciones» (`<details>`) en el celular.
  - Sin «Ver como». Consultas del inicio ≤ 12.
  - Hecho y verificado (ver el registro del WU3).
- [x] WU4. **PR4, búsqueda `Ctrl K`.** Ruta: delegada (escritor), porque son 2 o más archivos no triviales: `HomeSearchIndex`, `HomePageBuilder`, el diálogo, `home.js` y sus pruebas.
  - Índice JSON incrustado que reutiliza `App\Catalog\AppSearch::normalize`.
  - Apps, anuncios visibles (`Announcement::scopeVisibleTo`) y fechas.
  - Búsqueda de personas: bloqueada, decisión de Jhon.
  - Hecho y verificado (ver el registro del WU4).

## Preguntas para Jhon

1. Etiquetas de «puente»: ¿se muestran? (no se construyen todavía).
2. WU2: ¿quién administra las fechas de cada empresa?, ¿cada empresa tiene las suyas?, ¿fechas que se repiten cada año o de una sola vez?
3. Búsqueda de personas: Ley 1581 de 2012 (datos personales) y hoy solo existen en el portal las personas que ya iniciaron sesión.
4. ¿La barra inferior del celular se queda al lado de «Secciones»? (WU3 la dejó; las dos conviven.)
5. Chiquinquirá (Ley 2578 de 2026, art. 6): ya está desde 2026, pero hay una demanda ante la Corte Constitucional (radicada el 9 de junio de 2026, sin fallo conocido). Si la Corte la tumba, se pone en `ColombianHolidays::LAST_CHIQUINQUIRA_YEAR` el último año que se festejó; `FIRST_CHIQUINQUIRA_YEAR` no se toca, porque el fallo no es retroactivo y el 13 de julio de 2026 sí fue festivo.

## Criterios de aceptación

- WU1: las listas de 2025, 2026 y 2027 coinciden con las fuentes; `nextOnOrAfter` cruza del 31 de diciembre al 1 de enero; años lejanos (2000, 2100) no fallan.
- WU3: el inicio no hace más de 12 consultas y no carga Livewire ni Alpine; «Secciones» solo enlaza secciones que están en la página; dos festivos el mismo día muestran los dos nombres; sin desborde horizontal a 390 px.
- WU4: la búsqueda encuentra apps, anuncios visibles para quien busca y fechas, sin tildes ni mayúsculas.

## Chequeos

- `APP_KEY=base64:$(php -r 'echo base64_encode(random_bytes(32));') php artisan test --compact`, con la suite completa en verde.
- `vendor/bin/pint --test`.
- `/code-review` en cada PR.

## Entrega

- **Estrategia:** `ask-on-risk`, con cadena `stacked-to-main` (ya elegida por el coordinador): un PR por WU, encadenados encima de #68 (`feat/cuentas-vinculadas-4-interfaz`).
- **Ramas:** `feat/inicio-1-festivos` (WU1), desde `feat/cuentas-vinculadas-4-interfaz`; `feat/inicio-2-mi-dia` (WU3), desde `feat/inicio-1-festivos`; `feat/inicio-3-buscador` (WU4), desde `feat/inicio-2-mi-dia`.
- **Pronóstico:** WU1 ~400 líneas (más de la mitad, pruebas). Salió con 625 (este documento ~130, código ~210, pruebas ~280).
- **Revisión nativa (RDD):** apagada (global: off). Se usan los chequeos ordinarios.

## Progreso

- 2026-10-29: plan creado (`6386e0a`). Rama `feat/inicio-1-festivos` desde `feat/cuentas-vinculadas-4-interfaz`.
- 2026-10-29: WU1 hecha (ruta delegada, un escritor). Sin `push` ni PR.
  - RED: `tests/Unit/Calendar/ColombianHolidaysTest.php` nueva, 24 pruebas: 0 en verde, 1 falla y 23 errores (la clase no existía).
  - GREEN: 24 de 24 (120 aserciones). Primero falló una (`between` con el rango al revés: `betweenIncluded()` de Carbon invierte los límites); se corrigió con una guarda.
  - GREEN, suite completa: 1.376 de 1.376 (6.722 aserciones).
  - `vendor/bin/pint --test`: sin cambios.
  - Commits: `b6fc40e` `feat(calendar)`: festivos de Colombia; `docs(odd)`: este registro.
  - Fuentes (revisadas el 2026-10-29, citadas en el docblock de la prueba): Ley 51 de 1983 (Alcaldía de Bogotá, sisjur), Ley 2347 de 2024 (Diario Oficial 52.663, Rama Judicial), resumen de la Ley 2578 de 2026 (Universidad del Rosario) y calendarios publicados de 2025, 2026 y 2027.
  - Decisiones:
    - **19 festivos desde 2026, no 18.** La Ley 2578 de 2026 agrega el 9 de julio (Nuestra Señora del Rosario de Chiquinquirá), que se traslada al lunes como los de la Ley 51: 13 de julio de 2026 y 12 de julio de 2027. Hasta 2025 son 18.
    - El 12 de octubre se llama «Día de la Diversidad Étnica y Cultural» (Ley 2347 de 2024), no «Día de la Raza».
    - Dos festivos el mismo día se guardan como dos entradas, ordenadas por nombre. Pasa cuando la Pascua cae entre el 19 y el 25 de abril: Sagrado Corazón y San Pedro el 30 de junio de 2025. `isHoliday()` da verdadero una vez y `nextOnOrAfter()` devuelve la primera por nombre.
    - El día se lee en la zona del calendario: el contenedor la construye con `config('app.timezone')` (`AppServiceProvider`, singleton); a mano, por defecto `America/Bogota`. El 1 de enero a las 03:00 UTC aún es 31 de diciembre en Bogotá.
    - Años fuera de 1583–9999 se rechazan (`InvalidArgumentException`). Los anteriores a 1984 usan las reglas de hoy.
    - `easterSunday()` es pública y estática, para probar la Pascua por separado.
- 2026-10-29: revisión del WU1. Premisas legales confirmadas con el texto oficial:
  - Ley 2578 de 2026: Diario Oficial 53.510 del 2 de junio de 2026, rige desde su promulgación. Demanda de inconstitucionalidad contra el art. 6 radicada el 9 de junio de 2026, sin fallo conocido.
  - Ley 2347 de 2024: Diario Oficial 52.663 del 8 de febrero de 2024.
  - Tres arreglos menores, en `3069abc` `fix(calendar)` (ruta: delegada, un escritor):
    - `LAST_CHIQUINQUIRA_YEAR` (null mientras rige el art. 6) en la regla del 9 de julio; un fallo futuro pone el último año en vez de mover el primero, así no se borra el 13 de julio de 2026. Argumento `lastChiquinquiraYear` del constructor solo para pruebas.
    - `nextOnOrAfter()` después de Navidad de 9999 lanza «No holiday on or after … within the supported range 1583–9999.», no el error engañoso del año 10000.
    - El contenedor construye `ColombianHolidays` con `config('app.timezone')`; la clase sigue usable sin contenedor en las pruebas unitarias.
    - El docblock dice que el inicio debe mostrar los dos nombres cuando dos festivos caen el mismo día.
  - RED: 4 de 28 (2 fallas, 2 errores: constante y argumento inexistentes, mensaje del año 10000, zona `America/Bogota` en vez de la de `config`).
  - GREEN: 28 de 28 (133 aserciones); suite completa 1.380 de 1.380 (6.735 aserciones); `vendor/bin/pint --test` sin cambios.
  - Commits: `3069abc` `fix(calendar)`; `docs(odd)`: este registro.

- 2026-10-29: WU3 hecha (ruta delegada, un escritor). Rama `feat/inicio-2-mi-dia`. Sin `push` ni PR.
  - RED: `HomeMyDayTest` y `HolidayDayTest` nuevas, 13 pruebas: 1 en verde (sin «Ver como»), 5 fallas y 7 errores (`HolidayDay`, `upcomingHolidays` y `UPCOMING_HOLIDAY_LIMIT` no existían; sin «Secciones» ni `#mi-dia`).
  - GREEN, datos (`a25573c` `feat(home)`): `HolidayDay` (un día con uno o dos nombres), `HomePage::$upcomingHolidays`, `$nextHoliday` y `holidayNotice()`, y `HomePageBuilder::UPCOMING_HOLIDAY_LIMIT` (5) y `UPCOMING_HOLIDAY_DAYS` (60). Pruebas del builder en `HomeUpcomingHolidaysTest`.
  - GREEN, interfaz (`98e456e` `feat(home)`): columna «Mi día» (`<aside id="mi-dia">`) al lado de las apps desde 1024 px y debajo en tableta y celular, con «Próximas fechas», la tarjeta de anuncios y Microsoft 365; «Próximo festivo» u «Hoy es festivo» junto a la fecha; menú «Secciones» en el celular; `public/build` reconstruido.
  - Suite completa: 1.397 de 1.397 (6.833 aserciones). `vendor/bin/pint --test`: sin cambios. Consultas del inicio: sin cambio (los festivos se calculan).
  - Capturas a 390, 768 y 1440 px, claro y oscuro, con y sin cuentas vinculadas, más el menú «Secciones» abierto: sin desborde horizontal.
  - Decisiones:
    - El límite cuenta días, no festivos: dos festivos el mismo día ocupan una fila con los dos nombres. En la línea de la fecha se separan con punto y coma («Sagrado Corazón; San Pedro y San Pablo»), porque los nombres ya llevan «y».
    - La lista empieza hoy: un festivo de hoy sale como «Hoy». Si no hay festivos en 60 días, sale solo el siguiente (del 13 de enero al 23 de marzo de 2026).
    - La tarjeta de anuncios se ve solo con la columna al lado (desde 1024 px, antes desde 1280 px); en tableta y celular sigue el banner con el primero. Mismas reglas de visibilidad (`Announcement::scopeVisibleTo`).
    - «Secciones» lista Inicio, Favoritos, Recientes (si hay), Aplicaciones, Próximas fechas y Microsoft 365; no lista Anuncios porque en el celular el banner ya está arriba. La lista sale de `HomePage::sections()`, con las mismas condiciones de la vista.
    - Encabezado sin cambios de marca: buscador, selector de cuenta y menú de cuenta como estaban; «Secciones» comparte fila con el buscador en el celular.
  - Pendiente: Lighthouse.
- 2026-10-29: revisión independiente del WU3, cinco arreglos (ruta: delegada, un escritor). Sin `push` ni PR.
  - RED: 5 de 20 en `HomeMyDayTest`, `HomeUpcomingHolidaysTest` y `HolidayDayTest` (3 fallas: menú sin apps, menú durante la búsqueda, atributos `data-holiday-*`; 2 errores: «Year 10000 is outside…» y `dayName()` inexistente).
  - `af774ee` `fix(home)`: a fines de 9999 el horizonte de 60 días cae en el año 10000 y `between()` lanzaba antes del `try`; las dos llamadas quedan dentro y el inicio sale sin «Próximas fechas».
  - `0a50a8f` `test(home)`: `test_the_favorite_star_works_without_javascript` usa `'name' => 'Cartera'`, así `?q=a` siempre coincide (antes fallaba si Faker no ponía «a»). Sin RED posible: era intermitente.
  - `56409b1` `fix(home)`:
    - Copia guardada (el service worker la sirve hasta 24 h sin red o con red lenta): el aviso del festivo y cada fila de «Próximas fechas» llevan `data-holiday-date`; `home.js`, solo con `data-pwa-source="cache"`, calcula hoy en `America/Bogota` y oculta el aviso y las filas ya pasadas, quita el «Hoy» de un día que ya no es hoy (`data-holiday-today`, `data-holiday-weekday` con `HolidayDay::dayName()`) y oculta «Próximas fechas» si se queda vacía. Sin arnés de pruebas JS en el proyecto: se probó en Playwright con la página marcada como copia y el reloj en el 3 de noviembre (aviso y fila del 2 de noviembre ocultos, el resto visible).
    - «Secciones» ya no sale sin apps: la página es corta y el menú le quitaba espacio al nombre de la empresa en la fila del logo. `HomePage::sections()` devuelve `[]` en ese caso.
    - Durante una búsqueda, «Secciones» no lista Favoritos sin coincidencias ni Aplicaciones sin categorías con tarjetas.
    - `public/build` reconstruido (`home-CO6nO0hU.js`; el CSS no cambió).
  - GREEN: suite completa 1.401 de 1.401 (6.873 aserciones). `vendor/bin/pint --test`: sin cambios.
  - Capturas nuevas a 390 px (`home-sin-vinculos-390-light-fix`, `-dark-fix`, `home-secciones-abierto-390-light-fix`): sin desborde; sin menú, el nombre pasa de «Solucio…» a «Soluciones y Derivados de …». La razón social completa aún se recorta en el celular: es el componente `x-brand-logo`, igual que con apps.

- 2026-10-29: WU4 hecha (ruta delegada, un escritor). Rama `feat/inicio-3-buscador`, desde `feat/inicio-2-mi-dia`. Sin `push` ni PR.
  - RED: `tests/Feature/Search/HomeSearchIndexTest.php` nueva, 10 pruebas: 0 en verde, 8 fallas y 2 errores (sin índice en la página; `HomeSearchIndex` no existía).
  - GREEN, índice (`e93c5ce` `feat(search)`): `App\Search\HomeSearchIndex` con las apps de la página (enlace y pestaña nueva como la tarjeta), hasta 50 anuncios visibles (`ANNOUNCEMENT_LIMIT`; título y extracto de 90 caracteres, `EXCERPT_LENGTH`, nunca el cuerpo) y los festivos de 12 meses (`HOLIDAY_MONTHS`). Diálogo `x-home.search-dialog` y botón «Ctrl K» en el buscador del encabezado.
  - GREEN, interfaz (`c54340e` `feat(home)`): `home.js` abre el diálogo con `Ctrl K`, `Cmd K`, `/` y «Buscar» de la barra inferior; flechas, Enter, Escape (vuelve el foco a quien lo abrió), clic en el fondo; `public/build` reconstruido.
  - Suite completa: 1.412 de 1.412 (6.945 aserciones). `vendor/bin/pint --test`: sin cambios. Consultas del inicio: sin cambio.
  - Capturas `busqueda-390-light`, `-390-dark`, `-1440-light`, `-1440-dark` (consulta «ca»: 2 apps, 1 anuncio, 3 fechas): sin desborde a 390 px (diálogo de 358 px). En Playwright: `Ctrl K` abre, flecha abajo mueve `aria-activedescendant`, Escape cierra y devuelve el foco a la tarjeta, «camaras» encuentra «Cámaras», sin resultados sale «No encontramos resultados para «…».», y con el reloj en el 20 de noviembre «cartagena» (16 de noviembre) ya no sale.
  - Decisiones:
    - Cero consultas nuevas: la consulta de anuncios del inicio trae hasta 50; las 3 primeras hacen las tarjetas y todas el índice.
    - Cada anuncio enlaza a su página de `/anuncios` (`?page=N#a-id`), porque la lista está paginada de a 10. Cambió en la revisión: ahora enlaza a `/anuncios/{id}`, que calcula la página al abrirlo.
    - Una búsqueda en el servidor (`?q=`) no lleva índice ni diálogo: es el camino sin JavaScript, igual que antes (y las pruebas de `?q=` siguen sin ver las apps que no coinciden). Ahí `/` vuelve a enfocar el buscador.
    - El buscador del encabezado sigue filtrando las tarjetas al escribir; el botón «Ctrl K» (`⌘ K` en Mac) abre el diálogo, solo desde tableta y con JavaScript.
    - Los festivos no enlazan: el resultado ya dice la fecha. Los que ya pasaron se ocultan siempre (hoy en `America/Bogota`), lo que cubre la copia guardada.
    - El JSON escapa `<`, `>`, `&` y comillas (`JSON_HEX_*`): un título con `</script>` no cierra el script.
    - Sin personas ni otras cuentas en el índice. Máximo 6 resultados por grupo.
  - Pendiente: Lighthouse; probar con lector de pantalla real (solo se revisó el marcado ARIA).
- 2026-10-29: revisión independiente del WU4, ocho arreglos (ruta: delegada, un escritor). Sin `push` ni PR.
  - RED: 13 de 23 en `HomeSearchIndexTest` y la nueva `tests/Feature/Announcements/AnnouncementLinkTest.php` (4 fallas: `?q=` sin la pista `/`, `?q=` pedía 50 anuncios, y dos de prueba mal escrita que se corrigieron; 10 errores: ruta `announcements.show` y `Announcement::PER_PAGE` inexistentes, «Malformed UTF-8» en `toJson()`).
  - `255a877` `fix(search)`:
    - `GET /anuncios/{announcement}` (`announcements.show`, mismo grupo `auth`, `throttle:60,1`): redirige a `/anuncios?page=N#a-{id}` calculando la página al abrirlo (misma visibilidad `visibleTo`, mismo orden `ordered()` y `Announcement::PER_PAGE`); 404 si quien lo abre no lo ve (otra empresa, retirado, programado, vencido, sin empresa) o si no existe. El índice enlaza ahí, así la copia guardada nunca apunta a una página vieja.
    - `Announcement::PER_PAGE` reemplaza a `AnnouncementController::PER_PAGE` (controlador, índice y redirección).
    - `toJson()` con `JSON_INVALID_UTF8_SUBSTITUTE` (U+FFFD): un texto dañado no tumba el inicio.
    - La búsqueda en el servidor (`?q=`) trae solo los 3 anuncios de las tarjetas y vuelve a mostrar la pista `<kbd>/</kbd>`.
    - Pruebas nuevas del índice: cuenta de emergencia y usuario sin empresa (sin anuncios ni apps, igual que `/anuncios`, que dice «No hay anuncios por ahora.»), empresa inactiva y escape del extracto.
  - `f097f68` `fix(home)`:
    - Una sola fila activa: sin `hover:bg-subtle`; el puntero mueve la opción activa (`pointermove`, no `pointerover`, así las flechas mandan mientras el mouse está quieto) y la activa lleva contorno interior `outline-brand-ink` además del fondo (también se ve en colores forzados).
    - Teclas: se ignora `event.isComposing` (IME) en el diálogo y en el atajo global. `Ctrl K`/`Cmd K` sigue funcionando desde campos de texto, por convención de paleta de comandos (ningún campo de texto lo usa); `/` nunca se toma mientras se escribe.
    - `public/build` reconstruido (`home-W6Ub_hgl.js`, `app-wz3uzfFq.css`).
  - GREEN: suite completa 1.425 de 1.425 (7.012 aserciones). `vendor/bin/pint --test`: sin cambios.
  - Hallazgo (sin cambiar): `Announcement::scopeVisibleTo` no mira `is_active`, así que quien sigue con sesión en una empresa desactivada ve sus anuncios en `/anuncios`, aunque el catálogo no le muestra apps (`AppCatalog::mayUseCatalog`). El índice hace lo mismo que esas dos páginas; si se quiere ocultar, se cambia el scope (decisión aparte).
  - Playwright (servidor local en 8802, ya detenido): a 390 px, claro y oscuro, el mouse sobre la última fila y las flechas en la segunda: solo la segunda tiene `aria-selected`, fondo y contorno; la de debajo del mouse queda sin fondo. Enter con `isComposing` no abre nada; `a/b` se escribe entero en el buscador del encabezado y `Ctrl K` abre el diálogo desde ahí; `?q=ca` muestra la pista `/` y no lleva diálogo; `/anuncios/1` llega a `/anuncios#a-1` con el ancla en la página. Capturas `busqueda-390-light-fix`, `busqueda-390-dark-fix`: sin desborde.

## Estado de la fase 1

- Hechas: WU1, WU3 y WU4 (con sus revisiones).
- Bloqueadas por Jhon: WU2 y la búsqueda de personas.
- Pendiente: Lighthouse en el celular (≥ 90) y lector de pantalla real.

## Siguiente paso

Abrir el PR de `feat/inicio-3-buscador` encima de #70 (`feat/inicio-2-mi-dia`): decisión de Jhon. WU2, la barra inferior junto a «Secciones» y la búsqueda de personas esperan las respuestas de Jhon.
