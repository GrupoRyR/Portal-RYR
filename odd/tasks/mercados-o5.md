# O5: Mercados con más datos

Pedido de Jhon (observación 4, 2026-11-04): «busca más mercados, por ejemplo petróleo Brent, los insumos de las empresas… si no encuentras más mercados, ¿cómo podemos buscar o estudiar esos valores para ponerlos?». La banda en movimiento y los colores de subida y bajada ya están en G5 (#105).

## Objetivo

La banda de Mercados del inicio muestra, además de la TRM y el euro:

- **Para todos:** Brent, tasa de política monetaria del Banco de la República y COLCAP (mensual).
- **Insumos de cada empresa**, elegidos en Admin:
  - Segurex: zinc, cobre, aluminio, latón (índice) y acero (índice);
  - SDC: cloro y soda (índice), resinas plásticas (índice) y envases plásticos (índice).
- **Precios reales** que un administrador ingresa a mano, con fecha y fuente (cotizaciones de proveedores).

## Fuentes (verificadas el 2026-11-04, detalle en `docs/despliegue.md` §1.17 y en la investigación)

- **Banco de la República, sin llave:** SDMX REST en `https://totoro.banrep.gov.co/nsi-jax-ws/rest/data/ESTAT,<FLOW>,1.0/all/ALL/`.
  - Tasa de política: `DF_CBR_DAILY_LATEST`, diaria.
  - COLCAP: `DF_COLCAP_MONTHLY_LATEST`, solo mensual.
  - El XML es SDMX-ML 2.1 genérico, sin DOCTYPE.
  - El servicio a veces está en mantenimiento, así que se guarda el último valor bueno.
- **FRED, con llave gratuita** (`FRED_API_KEY`, que pone Jhon en el `.env`): `https://api.stlouisfed.org/fred/series/observations`.
  - Precios en USD:
    - Brent `DCOILBRENTEU`: diario, con una semana de rezago.
    - Zinc `PZINCUSDM`, cobre `PCOPPUSDM` y aluminio `PALUMUSDM`: mensuales en USD/t, con unos dos meses de rezago.
  - Índices de precios al productor de EE.UU. (no son precios, se muestran como «índice» con su variación mensual):
    - latón `WPU102502`;
    - acero `WPU1017`;
    - cloro y soda `WPU06130302`;
    - resinas `WPU0662`;
    - envases plásticos `PCU326160326160`.
  - Hay que enviar un User-Agent que no sea de navegador, como `PortalRYR/1.0`.
- **Sin fuente gratuita:** precios reales de soda cáustica, cloro, hipoclorito, PET, HDPE, latón y acero terminado. Se ingresan a mano.

## Restricciones

- Solo el cron de cada minuto (`schedule:run`), sin worker. La sincronización usa el comando que ya existe, `portal:sync-markets`.
- Las pruebas nunca tocan la red (`Http::fake`).
- Solo https, el mismo cuidado que el resto de los clientes HTTP del portal y tiempos de espera cortos.
- Si una fuente falla, el resto sigue y se conserva el último valor.
- Sin llave de FRED, esas series se saltan con un aviso claro, nunca con un error.
- Las empresas son datos: nada de reglas por slug en el código. La selección de cada empresa se guarda en la base de datos.
- Una empresa por pantalla. La banda de G5 ya toma cualquier código de `MarketBoard`.
- Códigos de hasta 8 caracteres (`market_rates.code`).
- Código e identificadores en inglés; textos de interfaz en español.

## Entrega

- **Rama `feat/markets-o5`:** sale de `feat/glass-9-pages` (#111), con `feat/glass-10-admin` (#108) fusionada para que la nueva vista de Admin quede en vidrio.
- **Dos PR encadenados**, de unas 400 líneas cada uno como guía:
  - PR A: M1–M3;
  - PR B: M4–M5.

## Tareas

- [x] M1. **Catálogo de series:** código, nombre, unidad (pesos, USD/barril, USD/t, %, puntos, índice), fuente, frecuencia y alcance (todos o insumo). La frase leída y el formato de la banda salen de la unidad. Las series mensuales muestran el mes («sep 2026»), y los índices muestran la variación en % en vez del valor en USD.
- [x] M2. **Clientes del Banco de la República (SDMX) y de FRED.** `MarketSync` los usa además de la TRM y el BCE, cada fuente por separado. Sin llave de FRED, el comando lo dice y sigue. `config('services.fred.key')`.
- [x] M3. **Qué ve cada empresa:** `companies.market_codes` (JSON, puede estar vacío). Si está vacío, la empresa ve solo las series para todos. `MarketBoard` arma la banda con las series para todos más los insumos elegidos, sin consultas de más en el inicio.
- [ ] M4. **Admin › Mercados:**
  - por empresa, casillas para los insumos automáticos;
  - precios manuales en una tabla `market_inputs` (empresa, nombre, unidad, valor, fecha, fuente), con alta, edición y borrado;
  - queda en Auditoría;
  - los precios manuales aparecen en la banda de esa empresa con su fecha.
- [ ] M5. **Documentación:**
  - `docs/despliegue.md`: `FRED_API_KEY` y salida a `api.stlouisfed.org` y `totoro.banrep.gov.co`;
  - manual de administración: cómo elegir insumos y cargar precios;
  - qué significa cada índice.

## Comprobaciones

- `php artisan test`, `vendor/bin/pint --test`, `npm run build` y `node --test "tests/js/*.test.mjs"` en cada PR.
- Revisión de código y de seguridad en cada PR (SSRF y XXE en los clientes nuevos, autorización en Admin).
- Revisión visual de la banda con las dos marcas, en claro y oscuro, a 375 px.

## Avance

- 2026-11-04: rama creada (`fc25037` = #111 + #108 fusionada). Ruta: escritor delegado por PR (2+ archivos no triviales).
- PR A (M1–M3) hecho en `11ed2dd`, `870d8bd`, `e9f09ee`, `2283398` y `e75c878`, más `cbffd95` (compilación determinista: `@source not '../../public/build'`).
  - Catálogo `App\Markets\MarketSeries` con 13 series: `trm`, `eur`, `brent`, `tpm` y `colcap` para todos, y `zinc`, `copper`, `aluminum`, `brass`, `steel`, `alkali`, `resins` y `bottles` como insumos. Formato y frase leída según la unidad; las series mensuales muestran el mes y los lectores de pantalla oyen la fecha completa.
  - Banco de la República por los flujos `_HIST`. Respuesta real verificada el 2026-11-04: una sola serie, fechas `YYYYMMDD` diarias y `YYYY-MM` mensuales, y `startPeriod` ignorado en la tasa diaria (126 KB). De la tasa se guarda el valor de hoy y el último día antes de la última decisión, así que la banda muestra «▲ 0,25 pp» desde la primera sincronización. Del COLCAP se guardan los dos últimos meses.
  - FRED con `FRED_API_KEY`; sin llave se omite con aviso. User-Agent `PortalRYR/1.0`. La llave nunca llega a registros ni a la salida (probado). Series mensuales consultadas una vez al día. Tiempos de espera de 5 s para conectar y 8 s en total.
  - `companies.market_codes` (JSON, solo códigos de insumo). `MarketBoard` arma la banda por empresa en una consulta, con filas de los últimos 400 días.
  - Revisión independiente de código y seguridad: nada grave; siete puntos corregidos en `e75c878`.
  - Evidencia: `php artisan test` 3438 pasan; `vendor/bin/pint --test` sale en 0; `npm run build` sin cambios; `node --test` 48 pasan. RED observado en cada commit.
  - Para M4: etiqueta en español de `market_codes` en `AuditPresenter`. Para M5: `FRED_API_KEY` en `.env.example` y en la documentación.
