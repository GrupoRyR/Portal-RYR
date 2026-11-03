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

- [ ] M1. Migración, modelo `MarketRate`, fábrica y `config/markets.php`. Ruta: delegada (escritor único de esta línea), 2 o más archivos no triviales.
- [ ] M2. Sincronización: lectores de la TRM y del BCE, `MarketSync`, comando `portal:sync-markets`, horario y pruebas (RED primero).
- [ ] M3. Modelo de lectura `MarketBoard` con prueba de conteo de consultas.
- [ ] M4. Documentación `docs/despliegue.md` §1.16.

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

- 2026-11-03: documento creado antes de la primera escritura de código. Engram mirror: pending.

## Evidencia de verificación

(pendiente)
