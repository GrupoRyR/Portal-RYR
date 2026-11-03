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

- [ ] H1. Mercados (banda bajo el encabezado) y Noticias (pestañas Colombia / Internacional / Sector). Ruta: escritor delegado único de la línea (2 o más archivos no triviales).
- [ ] H2. Catálogos y categorías (chips y tarjetas de producto) y Panel gerencial (solo Gerencia de la misma empresa). Ruta: la misma.
- [ ] H3. «Mi día»: Tareas pendientes («Por aprobar» y «Tareas»), «Ver tarea», «Ver aprobación», «Completar» (POST con CSRF, confirmación, mensaje del endpoint, ancla `#tareas`). Ruta: la misma.
- [ ] H4. Estado de TI con «Reportar un problema». Prueba de consultas acotadas con todos los bloques. Documentación. Ruta: la misma.
- [ ] H5. Compilar `public/build` (incluye el cambio de cierre de sesión de `home.js`) en su propio commit. Ruta: la misma.

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

## Progreso

- 2026-11-03: documento creado. H1 en curso.

## Siguiente paso

H1.
