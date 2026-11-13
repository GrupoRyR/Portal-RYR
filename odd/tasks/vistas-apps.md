# Vistas de aplicaciones en el inicio

Archivo: `odd/tasks/vistas-apps.md`. Copia en Engram: `odd/vistas-apps/tasks`.
Rama: `feat/vistas-apps`, creada desde `origin/main` en `82cfdae`.

## Objetivo

En la tarjeta «Mis aplicaciones» del inicio, cada persona puede escoger entre cuatro vistas. La que elige se guarda en su cuenta.

Jhon las eligió el 2026-11-13 sobre bocetos:

1. **Por categorías** (`categories`, por defecto): la actual. Primero Favoritos, luego Recientes, luego un grupo por categoría.
2. **Todas, sin categorías** (`all`): un solo mosaico en orden alfabético por nombre. No hay sección de favoritos ni de recientes; la estrella de favorito sigue en cada tarjeta.
3. **Lista compacta** (`list`): una fila por app con ícono, nombre, descripción corta y estado, en orden alfabético.
4. **Íconos tipo lanzador** (`launcher`): solo ícono y nombre, en una cuadrícula densa y en orden alfabético.

Descartadas: «Más usadas» y «Solo favoritos».

## Por qué

Las personas con muchas apps quieren ver todo de una vez, sin agrupaciones, o en un formato que ocupe menos espacio en el celular.

## Alcance y restricciones

- Las vistas se escogen con un selector `g-seg` (`button[aria-pressed]`) dentro de un formulario `POST` con CSRF, en el encabezado de la tarjeta. **Funciona sin JavaScript.**
- Columna `users.apps_view`: texto corto, nulo equivale a `categories`, y queda fuera de la asignación masiva. Los valores permitidos viven en una sola clase.
- Ruta autenticada que guarda la vista, rechaza valores desconocidos (422 o error de validación) y vuelve al inicio, al ancla de la tarjeta.
- **Ninguna consulta nueva en el inicio.** La preferencia está en la fila de `users`, que ya está cargada; `HomeQueryBudget` no sube.
- La búsqueda `?q=` filtra igual en las cuatro vistas. El estado (mantenimiento, caída) y el botón de favorito se ven en las cuatro.
- Liquid Glass:
  - todo el texto va sobre vidrio y se ven el contraste AA y el foco de 2 px;
  - los ids son únicos;
  - el diseño funciona a 375 px;
  - los colores salen de los tokens existentes, sin crear nuevos;
  - nada se mueve, así que no hay reglas nuevas de movimiento.
- Sin Livewire ni Alpine en el inicio.

## Fuera de alcance

- Vistas para Administración.
- Ordenar las apps por uso.

## Tareas

- [x] V1. Preferencia: migración `users.apps_view`, clase de valores, ruta y controlador, más las pruebas. Pruebas primero.
- [x] V2. Datos de cada vista en `HomePageBuilder` y `HomePage`: lista plana A-Z para `all`, `list` y `launcher`; sin consultas nuevas; pruebas de orden, búsqueda, favoritos y presupuesto de consultas.
- [x] V3. Marcado y CSS: el selector y las variantes `list` y `launcher` en `glass.css`, más las pruebas de HTML (escape, `aria-pressed` e ids únicos).
- [x] V4. Revisión visual: Segurex, SDC, claro, oscuro, 375 px y escritorio, en el panel del navegador.
- [ ] V5. `/code-review` y `/security-review`. Documentación del manual de usuario y del estado del proyecto, que va por el documentador.

## Criterios de aceptación

- Una cuenta nueva ve la vista por categorías, idéntica a la de hoy.
- Al cambiar la vista y recargar, se mantiene la elegida; en otro dispositivo también.
- Un valor inválido no se guarda.
- El presupuesto de consultas del inicio no cambia.
- La suite completa queda en verde y Pint, limpio.

## Entrega

- Estrategia `ask-on-risk`. Estimado: 450 a 600 líneas, la mayoría pruebas.
- Si pasa de unas 400 líneas, se pregunta si va como PR único con `size:exception` o encadenado (V1 y V2, luego V3).

## Ruta

- Delegada: un solo escritor, porque son 2 o más archivos no triviales y hay que leer antes de escribir.
- La sesión coordinadora revisa, corre las comprobaciones y hace la revisión visual.

## Progreso

- 2026-11-13: documento creado.
- 2026-11-13, V1 (ruta delegada, escritor único). `users.apps_view` (texto de 16, nulo), `App\View\Home\AppsView` (enum con `DEFAULT`, `fromStored()`, `isFlat()` y `label()`), `PUT /inicio/vista` (`home.apps-view`, `auth`, `throttle:20,1`) con `AppsViewController`, que valida con `Rule::enum`, guarda con `forceFill` y vuelve a `/#mis-aplicaciones` conservando `?q=`. La columna queda fuera de `Fillable`.
  - RED: `php artisan test tests/Unit/View/AppsViewTest.php tests/Feature/Home/AppsViewPreferenceTest.php` → 22 pruebas, 1 pasa, 1 falla y 20 con error (no existen la clase, la columna ni la ruta).
  - GREEN: el mismo comando → `29 passed, 82 assertions`.
  - Suite completa: `3542 tests, 3538 passed, 4 skipped`, 0 fallas. `vendor/bin/pint --test` sobre los archivos tocados: limpio.
  - Commit: `07e1a0c` (`feat(home): save the apps view preference per account`).
- 2026-11-13, V2 (ruta delegada). `HomePage` expone `appsView` y `apps`; `HomePageBuilder` lee la vista de la fila de `users` ya cargada. En «Categorías» todo queda igual; en `all`, `list` y `launcher`, `apps` lleva todas las apps visibles una vez, de la A a la Z (nombre normalizado como la búsqueda, sin tildes ni mayúsculas, con números en orden natural; empata el orden del catálogo), los favoritos en su sitio con la estrella marcada y sin Recientes ni su consulta. `?q=` filtra igual en las cuatro; `sections()` enlaza `#aplicaciones` en las vistas planas.
  - RED: `php artisan test tests/Feature/Home/HomeAppsViewDataTest.php` → 21 pruebas, 5 pasan, 6 fallan y 10 con error (`Undefined property: HomePage::$appsView`). Las 5 que pasan son las del presupuesto de consultas, que ya se cumplía.
  - GREEN: `php artisan test tests/Feature/Home/HomeAppsViewDataTest.php tests/Feature/Home/HomePageBuilderTest.php tests/Feature/Home/HomeScreenTest.php` → `60 passed, 278 assertions`.
  - Suite completa: `3563 tests, 3559 passed, 4 skipped`, 0 fallas. Pint limpio.
  - Consultas: ninguna nueva; las vistas planas corren una menos (Recientes). `HomeQueryBudget::MAX` sigue en 18.
  - Commit: `0988d81` (`feat(home): build a flat A-Z apps list for the flat views`).
- 2026-11-13, V3 (ruta delegada). Selector en el encabezado de la tarjeta: `form` POST con `@csrf` y `@method('PUT')`, `q` oculto si hay búsqueda, `.g-seg` con `role="group"` y `aria-label="Vista de aplicaciones"`, cuatro `button name="view"` («Categorías», «Todas», «Lista», «Íconos») y `aria-pressed` en la elegida. Las vistas planas pintan un solo `ul#aplicaciones.g-apps` (`--list` o `--launcher`). `x-home.app-card` recibe `view` y elige entre la tarjeta de siempre, la fila (`.g-app--row`) y el ícono (`.g-app--icon`); el logo, el estado y la estrella pasan a `x-home.app-logo`, `x-home.app-status` y `x-home.app-star`, que se usan en las tres. «Íconos» muestra el estado solo en mantenimiento o con fallas y la estrella de 32 px en la esquina. `glass.css` suma `.g-apps-view`, `.g-apps--list` y `.g-apps--launcher`, sin tokens nuevos. `home.js` ya no mueve la tarjeta cuando no existe `#favoritos`: antes lanzaba un error y reenviaba el formulario, lo que habría quitado la estrella recién puesta.
  - Regresión de «Categorías»: `tests/Feature/Home/fixtures/apps-card-categories*.html` se generaron con el código de `82cfdae` antes de tocar las vistas; la prueba compara el cuerpo de la tarjeta (espacios normalizados y token CSRF fijo) con y sin `?q=`.
  - RED PHP: `php artisan test tests/Feature/Home/HomeAppsViewMarkupTest.php` → 35 pruebas, 14 pasan, 20 fallan y 1 con error.
  - RED JS: `node --test tests/js/*.test.mjs` → 50 pruebas, 49 pasan y falla «in a flat view the card keeps its place from A to Z» (el formulario se reenviaba). `fake-dom.mjs` ahora entiende `#id`, descendientes e `insertBefore`.
  - GREEN: `php artisan test tests/Feature/Home/` → `299 passed`; `node --test tests/js/*.test.mjs` → `50 pass, 0 fail`.
  - Suite completa: `3598 tests, 3594 passed, 4 skipped`, 0 fallas. Pint limpio. `vite build` a una carpeta temporal compila sin errores; `public/build` no se tocó.
  - 375 px: el escritor no pudo verla en el navegador (el panel solo muestra una instantánea del HTML local, sin cambiar el ancho). Por cálculo: el selector ocupa unos 265 px de 307 disponibles, «Íconos» deja 3 columnas de unos 97 px y «Lista» deja unos 183 px al texto. Queda para V4.
  - Commit: `33f50aa` (`feat(home): add the apps view selector and the list and launcher layouts`).
- 2026-11-13, V4, revisión visual (sesión coordinadora): Chrome sin interfaz a 375 px y 1280 px, 24 capturas. Encontró 2 defectos en «Íconos», que se corrigieron abajo junto con 3 hallazgos de la revisión de código.
- 2026-11-13, correcciones de V4 y de la revisión (ruta delegada):
  - «Íconos», estado: en vez de la etiqueta de texto (a 375 px «En mantenimiento» se salía 14 px por los dos lados y «Con fallas · SAP no responde» ocupaba 4 líneas), una marca redonda de 24 px en la esquina superior izquierda (`x-home.app-mark`, `.g-app__mark`): llave para mantenimiento, alerta para fallas, monitor para instalada y candado para inicio de sesión propio, nada si está activa. El texto queda en `sr-only` y en `title`. Así también se ven Local y Restringida, que antes no mostraban estado. `AppCardStatus::icon()` reúne los íconos.
  - «Íconos», alto: `grid-auto-rows: 1fr`, todas las filas miden lo que la más alta.
  - «Íconos», estrella: se ve de 32 px, pero un `before:absolute before:-inset-1.5` le da 44 px de área táctil. La franja superior de 38 px hace que esa área termine donde empieza el logo.
  - «Lista», nombre: `line-clamp-2 break-words` en vez de `truncate`; la descripción sigue en una línea.
  - `home.blade.php`: la rama de «Categorías» queda sangrada dentro del `@if/@else`. Solo cambian espacios y la prueba de los fixtures sigue en verde.
  - RED: `php artisan test tests/Feature/Home/HomeAppsViewMarkupTest.php` → 38 pruebas, 34 pasan y fallan 4 (marca de estado, área táctil, nombre de la lista y filas iguales).
  - GREEN: `php artisan test tests/Feature/Home/` → `302 passed`; `node --test tests/js/*.test.mjs` → `50 pass, 0 fail`.
  - Suite completa: `3601 tests, 3597 passed, 4 skipped`, 0 fallas. Pint limpio.
  - `public/build`: se recompila con `npm run build` y va en su propio commit `build:` (ver `docs/despliegue.md`, 1.11).
