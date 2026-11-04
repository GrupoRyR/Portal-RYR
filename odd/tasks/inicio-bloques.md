# Inicio: bloques de las olas 1 y 2

Línea `feat/inicio-bloques` (desde `origin/main` `e9f48e4`), la línea Home. Conecta al inicio los bloques que las olas 1 y 2 dejaron listos como modelos de lectura, siguiendo el prototipo aprobado por la gerencia el 28 de octubre de 2026. Es la única línea que compila y versiona `public/build`.

## Objetivo

Que el inicio muestre, a quien corresponde y solo de su empresa: Mercados (TRM y euro), Noticias (Colombia, Internacional, Sector), Catálogos y categorías, Panel gerencial (solo Gerencia), Tareas pendientes con «Ver tarea», «Ver aprobación» y «Completar», y Estado de TI con «Reportar un problema».

## Problema y por qué

Los modelos de lectura existen y tienen pruebas (`App\Markets\MarketBoard`, `App\News\NewsBoard`, `App\Products\ProductBoard`, `App\Management\ManagementPanel`, `WorkItem::visibleTo`, `App\ItStatus\ItStatusBoard`), pero el inicio todavía no los muestra.

## Alcance

- `app/View/Home/**` (constructor y modelos de vista), `HomeController`, `resources/views/**`, `resources/js/home.js`, `resources/css/**`, pruebas, `public/build`.
- Documentación: `docs/manual-administracion.md` (solo lo que describe el inicio) y `docs/estado-del-proyecto.md`.

Fuera de alcance: el simulador «Ver como» del prototipo (los roles vienen de Microsoft), Brent y precios de insumos, clima, asistente, resumen semanal.

## Restricciones

- Sin Livewire ni Alpine en el inicio; JavaScript simple solo para pestañas, filtros y la confirmación de «Completar». Todo funciona sin JavaScript.
- Mobile-first, se conservan «Secciones» y la barra inferior. Meta Lighthouse móvil ≥ 90: sin fuentes nuevas, fotos del catálogo diferidas (`loading="lazy"`, ancho y alto fijos), sin saltos de diseño.
- Cada bloque se oculta solo cuando no tiene nada que mostrar o la persona no puede verlo. Nunca datos de otra empresa.
- Número de consultas acotado con todos los bloques presentes (prueba).
- Colores solo por variables de marca o de estado; contraste AA y modo oscuro.
- Commits convencionales sin atribución de IA. Sin rebase, sin push forzado, sin PR, sin merge.

## Tareas

- [x] H1. Mercados (banda bajo el encabezado) y Noticias (pestañas Colombia / Internacional / Sector). Ruta: escritor delegado único de la línea (2 o más archivos no triviales). Commit `3fb43a4`.
- [x] H2. Catálogos y categorías (filtros y tarjetas de producto) y Panel gerencial (solo Gerencia de la misma empresa). Ruta: la misma. Commit `7fa76b4`.
- [x] H3. «Mi día»: Tareas pendientes («Por aprobar» y «Tareas»), «Ver tarea», «Ver aprobación», «Completar» (POST con CSRF, confirmación, mensaje del endpoint, ancla `#tareas`). Ruta: la misma. Commit `c243663`.
- [x] H4. Estado de TI con «Reportar un problema». Prueba de consultas acotadas con todos los bloques. Documentación. Ruta: la misma. Commit `94e7be9`.
- [x] H5. Compilar `public/build` (incluye el cambio de cierre de sesión de `home.js`) en su propio commit. Ruta: la misma. Commit `8db44dd`.
- [x] R1. Revisión independiente (sin altos, 1 medio, bajos). Ruta: la misma.
  - M1 + L1: «Completar» guarda su mensaje en `work_status` (no en `status`), sin comparar textos; el bloque lo muestra con `data-flash`, que el service worker quita de la copia guardada. `7d3334f` (incluye `docs/api.md` §7).
  - L4: el Panel gerencial y su entrada de «Secciones» llevan `data-offline-omit` y el service worker los quita de la copia guardada (`OFFLINE_OMIT`). `7a96405`.
  - L2: Noticias y Catálogos solo con empresa activa, como las apps y Estado de TI. `30ff52f`.
  - L5: solo enlaces http(s) desde datos (`WebLink::href()`) en productos, tareas y titulares. `541a2a3`.
  - L6: cambio de mercado menor de medio centavo (o 0,00 %) se ve «= 0,00 %» sin flecha; los pesos del panel se redondean antes de elegir la unidad («$1 millón»). `6e8db10`.
  - L3: «Ver todas» sale de una consulta de las apps de todo el grupo, solo apps que la cuenta ve en el catálogo. `753756e`.
  - L7 (dos consultas de `work_items`): no se cambia, por decisión del coordinador.
  - `public/build`: sin cambios (los hashes no cambiaron); `sw.js` no pasa por Vite.

## Criterios de aceptación

- Mercados: sin cifras (antes de la primera sincronización) no hay banda; con una sola cifra se ve solo esa.
- Noticias: solo pestañas con titulares de fuentes activas de la empresa de la cuenta; sin titulares no hay bloque. Las pestañas funcionan sin JavaScript (`?noticias=`) y con JavaScript sin recargar.
- Catálogos: solo categorías con productos publicados de la empresa; sin productos no hay bloque. Fotos diferidas con tamaño fijo. Filtro por categoría sin JavaScript (`?catalogo=`) y con JavaScript sin recargar.
- Panel gerencial: solo para quien pasa `viewManagementPanel` en su propia empresa y solo cuando hay cifras publicadas.
- Tareas: solo `WorkItem::visibleTo` de la cuenta activa; primero «Por aprobar», después «Tareas», hasta 20 por grupo, vencidas primero y sin fecha al final; «Completar» solo cuando `canBeCompletedFromPortal()`; las aprobaciones solo «Ver aprobación». El mensaje de «Completar» se ve dentro del bloque.
- Estado de TI: no aparece sin empresa activa; «Todo funciona con normalidad» o la lista de lo que falla; «Reportar un problema» solo con URL de soporte.
- El inicio con todos los bloques corre un número fijo de consultas.

## Chequeos

- RED antes de GREEN para cada comportamiento (quién ve qué bloque, estados vacíos, visibilidad de «Completar»).
- `vendor/bin/pint --test`.
- Suite completa: `APP_KEY=... OPENSSL_CONF=... php artisan test --compact` (base 2.934).
- `npm run build`.

## Declaración de ruta

Un solo escritor delegado (esta sesión). Disparadores: 2 o más archivos no triviales por tarea y lectura previa a la escritura.

## Entrega

Estrategia `ask-on-risk`; el orquestador decide PR y cadena. Pronóstico: unas 1.500 a 2.000 líneas en total (vista, modelos de vista, JS y pruebas), sin contar `public/build`.

## Decisiones tomadas en la línea

- **Mercados sin cinta animada:** la franja es fija y se acomoda en dos líneas en el celular (el prototipo la desplaza). Las flechas del cambio van en gris, sin verde ni rojo: que el dólar suba no es bueno ni malo para todos.
- **Pestañas y filtros sin JavaScript:** son enlaces (`?noticias=`, `?catalogo=`) que el servidor marca; con JavaScript cambian sin recargar. Así no hay salto de diseño al cargar.
- **«Completar» con `<details>`:** el botón abre la confirmación («Sí, completar») con o sin JavaScript; el JavaScript solo deja una abierta y bloquea la segunda pulsación.
- **El mensaje de «Completar» va dentro de «Tareas pendientes»** (donde aterriza `#tareas`), no arriba, con su propia clave de sesión (`work_status`), y no se guarda en la copia sin conexión. Si era la última tarea, el bloque queda con el mensaje y «No tienes tareas ni aprobaciones pendientes».
- **Orden:** Panel gerencial antes de las apps; Noticias y Catálogos después; en «Mi día», Tareas primero y Estado de TI antes de Microsoft 365 (como el prototipo, conservando los bloques actuales).
- **Búsqueda sin JavaScript (`?q=`):** sin panel, noticias ni catálogos (solo las apps, como ya pasaba con el directorio). Tareas y Estado de TI siguen en «Mi día».
- **«Ver todas»** de cada grupo de tareas va a la `url` de cada app del grupo que la cuenta ve en el catálogo, solo cuando hay más de 20.
- **Panel:** quien no tiene el rol Gerencia no pasa por el permiso (`viewManagementPanel`): evita la consulta de permisos de Spatie; `forViewer()` sigue comprobando todo para quien sí lo tiene.
- **Catálogo «Todas»** muestra todos los productos publicados, sin tope; las fotos se difieren.

## Evidencia

- RED antes de GREEN: H1 11/11 en rojo (500 por el parámetro nuevo), H2 7/11, H3 10/12, H4 3/4 (las que pasaban eran estados ocultos).
- GREEN: `tests/Feature/Home` y relacionadas en verde en cada unidad.
- Suite completa: 2.981 pruebas, todas pasan (base 2.934); tras R1, 2.994. `vendor/bin/pint --test`: pasa.
- Consultas: cuenta normal 17 (`HomeQueryBudget::MAX`, antes 12); Gerencia con todos los bloques 22 (`WITH_EVERY_BLOCK`), sin crecer con más filas.
- `npm run build`: pasa; el CSS trae las clases nuevas y `home.js` el cambio de cierre de sesión.
- No hecho: revisión visual en el navegador ni Lighthouse (no hay sesión de prueba local en esta línea).
- Espejo en Engram: pendiente.

## Progreso

- 2026-11-03: documento creado. H1 a H5 hechos (`3fb43a4`, `7fa76b4`, `c243663`, `94e7be9`, `8db44dd`).
- 2026-11-03: correcciones de la revisión R1 (M1, L1 a L6).

## Siguiente paso

Revisión visual y Lighthouse móvil en el navegador; PR (decisión humana).
