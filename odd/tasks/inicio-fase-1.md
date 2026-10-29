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
- [ ] WU4. **PR4, búsqueda `Ctrl K`.** Ruta: por decidir al empezar.
  - Índice JSON incrustado que reutiliza `App\Catalog\AppSearch::normalize`.
  - Apps, anuncios visibles (`Announcement::scopeVisibleTo`) y fechas.
  - Búsqueda de personas: bloqueada, decisión de Jhon.

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
- **Ramas:** `feat/inicio-1-festivos` (WU1), desde `feat/cuentas-vinculadas-4-interfaz`; `feat/inicio-2-mi-dia` (WU3), desde `feat/inicio-1-festivos`.
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
  - Pendiente: la prueba `test_the_favorite_star_works_without_javascript` (anterior a WU3) falló una vez por texto aleatorio de Faker sin «a»; pasó en 6 de 6 reintentos. Lighthouse: pendiente.

## Siguiente paso

Abrir los PR de `feat/inicio-1-festivos` (encima de #68) y `feat/inicio-2-mi-dia` (encima del anterior): decisión de Jhon. Después, WU4: búsqueda `Ctrl K`. WU2, la barra inferior junto a «Secciones» y la búsqueda de personas esperan las respuestas de Jhon.
