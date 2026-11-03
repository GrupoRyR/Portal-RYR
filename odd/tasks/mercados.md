# Mercados: cifras de TRM y euro para la banda del inicio

Línea `feat/mercados`, aprobada por la gerencia con el prototipo del inicio (28 de octubre de 2026). Esta línea es solo el respaldo (backend): la banda del inicio la conecta otra línea después.

## Objetivo

Guardar la TRM y el euro en pesos colombianos (COP), actualizados dos veces al día desde fuentes oficiales, y ofrecer un modelo de lectura que entregue, por cada cifra, el último valor, su fecha de vigencia y el cambio frente al valor anterior, en una sola consulta.

## Problema y por qué

- La gerencia pidió ver la TRM y el euro en el inicio. El portal no guarda ninguna cifra de mercado.
- Brent y los precios de suministro por empresa quedan fuera (decisión de Jhon, 2026-11-03).

## Alcance

- Tabla `market_rates` (`code` = `trm` o `eur`, `value` decimal(14,4) en COP, `valid_from`, `valid_until`, `source`, `fetched_at`). Único por (`code`, `valid_from`) con nombre de índice corto.
- Comando `portal:sync-markets`:
  - TRM desde el conjunto de datos oficial `https://www.datos.gov.co/resource/32sa-8pi3.json` (`valor`, `unidad`, `vigenciadesde`, `vigenciahasta`), con `$order=vigenciadesde DESC&$limit=10`. Se guarda o actualiza por (`code`, `valid_from`).
  - Euro en COP = TRM × dólares por euro del XML diario de referencia del BCE (`eurofxref-daily.xml`, `Cube time`, `currency="USD"`). El BCE no publica COP.
  - XML seguro: sin red, sin sustitución de entidades, rechaza `DOCTYPE`.
  - Espera de ~10 s, máximo 1 MB por respuesta, sin redirecciones. Una fuente que falla conserva el último valor y deja un código corto en el log (nunca el cuerpo). Una fuente que falla no detiene a la otra.
  - Horario en `routes/console.php`: 07:10 y 13:10, America/Bogota, `withoutOverlapping()`.
- Modelo de lectura `App\Markets\MarketBoard`: por código, último valor, vigencia y cambio absoluto y porcentual frente al anterior, en UNA consulta (prueba que cuenta consultas).
- Configuración en `config/markets.php` (nunca `config/portal.php`).
- Documentación: `docs/despliegue.md` §1.16 «Mercados».

## Fuera de alcance

- Cualquier cambio en el inicio (`home.blade.php`, `app/View/Home/**`, JS, `public/build`).
- Brent y precios de suministro por empresa.
- `composer.json`, `composer.lock`, `config/portal.php`.

## Restricciones

- Producción es MySQL 8: todo nombre de índice o clave ≤ 64 caracteres, con nombres explícitos. Migraciones con prefijo `2026_11_05_1`.
- Pruebas con `Http::fake()` y `Http::preventStrayRequests()`; nunca red real.
- Solo cron, sin colas. Código y comentarios en inglés; documentación y textos de interfaz en español.
- Commits convencionales, sin atribución de IA. Sin push ni PR.
- Unas 400 líneas por tarea es solo una guía de planificación.

## Tareas

- [x] M1. Migración, modelo `MarketRate`, fábrica y `config/markets.php`. Commit `6cf704e`. Ruta: delegada (escritor único de esta línea), 2 o más archivos no triviales.
- [x] M2. Sincronización: lectores de la TRM y del BCE, `MarketSync`, comando `portal:sync-markets`, horario y pruebas (RED primero). Commit `65aa5c9`.
- [x] M3. Modelo de lectura `MarketBoard` con prueba de conteo de consultas. Commit `d5fbce9`.
- [x] M4. Documentación `docs/despliegue.md` §1.16. Va en el commit de M2 (`65aa5c9`), junto al comportamiento que explica.

## Criterios de aceptación

- `php artisan portal:sync-markets` guarda la TRM (hasta 10 vigencias) y el euro del día; repetirlo no duplica filas.
- Si una fuente falla (HTTP distinto de 200, redirección, más de 1 MB, JSON o XML inválido, `DOCTYPE`, sin conexión), conserva el último valor, deja un código corto en el log, la otra fuente se actualiza igual y el comando termina con error.
- `MarketBoard` entrega por código el último valor con vigencia hasta hoy (hora de Bogotá), su fecha y el cambio frente al anterior, con una sola consulta.
- Horario: `10 7,13 * * *` en America/Bogota, sin solaparse.
- Ningún nombre de índice supera 64 caracteres.

## Chequeos

- RED antes de GREEN en cada unidad; `php artisan test --compact` completo y `vendor/bin/pint --test`.

## Declaración de ruta

Un solo escritor delegado para toda la línea (varias carpetas, más de 2 archivos no triviales). Sin exploración aparte: el patrón está en `SyncDirectoryCommand`, `DirectorySync` y `routes/console.php`.

## Progreso

- 2026-11-03: documento creado antes de la primera escritura de código (`e5e3ff6`).
- 2026-11-03: M1 a M4 hechos en tres commits de trabajo (`6cf704e`, `65aa5c9`, `d5fbce9`). Sin push ni PR.
- Engram mirror: guardado (proyecto `portal_ryr`, tema `odd/mercados/tasks`, observación 267), con un resumen del documento al cierre.

## Decisiones

- **Fecha del euro.** Se guarda con la fecha de referencia del BCE (`Cube time`), sin `valid_until`. Así «frente al valor anterior» compara contra el día de referencia anterior del BCE.
- **TRM para el euro.** La vigente en la fecha del BCE D (la de mayor `valid_from` menor o igual a D), de esta corrida o de una anterior; así la fila D es siempre TRM(D) × BCE(D) (revisión H1). La TRM publicada por adelantado se guarda, pero no entra al euro de una fecha anterior.
- **«Último valor» del tablero.** `MarketBoard` toma el valor más reciente con `valid_from` menor o igual a hoy (Bogotá), con `ROW_NUMBER() OVER (PARTITION BY code …)` en una sola consulta (MySQL 8 y SQLite lo soportan).
- **Tope de 1 MB.** La respuesta se lee por partes (`stream`) y se corta al pasar el tope; también se rechaza por `Content-Length`. Se comprobó con un servidor local (cuerpo de 3 MB con y sin `Content-Length`, redirección, respuesta lenta y puerto cerrado): `too_large`, `too_large`, `http_302`, `connection`, `connection`. El tope de tiempo se reforzó en la revisión (M1).
- **Fechas.** `valid_from` y `valid_until` se escriben siempre como `Y-m-d` con un mutador (revisión L1); el cast `date:Y-m-d` solo da formato al serializar. En SQLite una fecha con hora no compara bien como texto.
- **Códigos de log.** `connection`, `timeout`, `http_<estado>`, `too_large`, `invalid_json`, `invalid_xml`, `doctype`, `invalid_data`, `no_trm`, `unexpected` (con la clase de la excepción).

## Evidencia de verificación


### Entrega inicial (`6cf704e`, `65aa5c9`, `d5fbce9`)

- RED (antes de implementar): `php artisan test --compact tests/Unit/Markets tests/Feature/Markets`: 39 pruebas, 0 pasan (2 fallos, 37 errores por clases y comando inexistentes).
- GREEN: 39/39. Completa: 2311/2311 (base 2272). `vendor/bin/pint --test` pasa.

## Revisión independiente (2026-11-03)

Revisión de `feat/mercados` por el coordinador. Hallazgos, arreglos y evidencia, con el RED antes del GREEN.

| ID | Hallazgo | Arreglo | Prueba |
|---|---|---|---|
| H1 (alto) | La corrida de las 7:10 ve el archivo del día anterior del BCE (publica hacia las 16:00 de Frankfurt, 9:00 a 10:00 en Bogotá) y guardaba (euro, D-1) con TRM(D) × BCE(D-1), pisando la fila correcta; en fines de semana y festivos reescribía la del viernes sin parar. | Cada fila del euro usa la TRM vigente en la fecha del BCE (`valid_from <= fecha`, la más nueva): la fila D es siempre TRM(D) × BCE(D) y las corridas son idempotentes. Se corrigieron el comentario de `routes/console.php`, la documentación del servicio y `docs/despliegue.md` §1.16. | `test_the_morning_run_before_the_ecb_publishes_does_not_rewrite_the_previous_euro` (13:10 del día anterior, 7:10 con el archivo viejo, 13:10 del día: la fila anterior no cambia y el cambio del tablero es 82,3708 / 2,40 %), `test_a_weekend_run_keeps_rewriting_the_same_friday_row_with_the_same_value`, `test_an_older_ecb_date_takes_the_trm_of_that_older_date`. `test_running_it_again_updates_rows_instead_of_duplicating_them` se reescribió: ya no consagra el pisado (misma data dos veces no cambia nada; una corrección de la TRM mueve su fila y el euro de la misma fecha). |
| M1 (medio) | Con `stream => true` el manejador de flujo de Guzzle 8.2 descarta el tiempo total y conserva solo el de inactividad entre lecturas: una fuente que gotea bytes no termina nunca. | Plazo de reloj (`microtime`) dentro del ciclo de lectura, con `markets.timeout`; código `timeout`. Horario con `withoutOverlapping(10)`. Documentación del código `timeout` y del candado de 10 minutos. | `test_a_source_that_trickles_bytes_is_cut_at_the_deadline` (flujo que entrega 1 byte cada 250 ms: falla en 1 a 4 s con `timeout`; el código anterior no terminaba nunca), `test_it_is_scheduled_twice_a_day_in_bogota_time` (ahora exige `expiresAt` 10). |
| L1 | El cast `date:Y-m-d` solo da formato al serializar: asignar un Carbon escribía fecha y hora. | Mutadores de `valid_from` y `valid_until` que escriben `Y-m-d`; comentario corregido. | `test_dates_are_stored_without_a_time_whatever_is_assigned`. |
| L2 | Sin desempate en el orden de la TRM. | `$order=vigenciadesde DESC, :updated_at DESC` (comprobado a mano contra el servicio real: responde igual y acepta el campo del sistema). | `test_it_asks_the_official_hosts_for_the_latest_ten_without_redirects` (orden exacto) y `test_it_keeps_the_first_row_of_a_repeated_start_date_whole` (la fila repetida conserva su propia vigencia, sin mezcla). |
| L3 | El euro se calculaba con flotantes. | `App\Markets\Decimal::multiply`: producto exacto de cadenas decimales y redondeo half-up a 4 decimales. **Elegí aritmética de cadenas en PHP y no `bcmul`**: `bcmath` no está garantizado en el hosting compartido y `composer.json` no se toca. Equivale a `bcmul` con 6 decimales y redondeo half-up a 4 (el truncado a 6 no cambia el quinto decimal). | `DecimalTest` (5 pruebas): exactitud, medios, 12×11 dígitos, 300 productos aleatorios contra `bcmul` cuando la extensión existe, entradas inválidas. |
| L4 | Las pruebas de zona horaria no probaban la fecha de Bogotá. | `config(['app.timezone' => 'UTC'])` y `date_default_timezone_set('UTC')` (se restaura en `tearDown`) en `MarketBoardTest` y en `SyncMarketsCommandTest`. | `test_today_is_the_date_in_bogota_not_in_utc`. |
| L5 | Rutas de salida temprana sin cerrar el flujo. | `fetch()` cierra el flujo en un `finally` que cubre estado distinto de 200, `too_large` por cabecera y todo lo demás. | `test_the_body_is_closed_when_the_answer_is_not_a_200`, `test_the_body_is_closed_when_the_declared_size_is_over_the_limit`. |
| L6 | `ROW_NUMBER()` en el tablero. | Sin cambios, a la espera de que el coordinador confirme la versión de MySQL en producción. | n/a |

**Evidencia**

- RED (antes de los arreglos): `php artisan test --compact tests/Unit/Markets tests/Feature/Markets`: 51 pruebas, 37 pasan, 14 fallan (9 fallos y 5 errores de clase inexistente). La prueba del goteo, con el código anterior, no terminaba: se cortó a mano.
- GREEN: 51/51 en esas carpetas. Completa: `php artisan test --compact` 2324/2324. `vendor/bin/pint --test` pasa.
- Commits de la revisión: `084f5a0` (fechas, L1) y `267d4d2` (H1, M1, L2 a L5).
