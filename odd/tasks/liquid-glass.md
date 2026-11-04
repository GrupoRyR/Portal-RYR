# Front end Liquid Glass

Aprobado por Jhon el 2026-11-04 con el boceto https://claude.ai/artifact/TAmG6Jgh1tWCZvembLyeuC. El inicio conserva las ubicaciones de los bloques del prototipo (https://claude.ai/artifact/V6aNj3e3ycJDz4fK4g2oFQ), con el estilo Liquid Glass del Kit v6. El inicio de sesión es la Vitrina, sin rótulos de «grupo».

## Objetivo

Pasar todo el portal a Liquid Glass y completar lo que falta del prototipo:

- encabezado común con selector de empresa, buscador, campanita, menú de la persona y cambio de tema claro/oscuro;
- banda de Mercados en movimiento, con verde para lo que sube y rojo para lo que baja;
- accesos rápidos como en el prototipo (sin el título «Microsoft 365»);
- columna «Mi día»;
- botón flotante «Asistente» con su panel en todas las páginas;
- inicio de sesión Vitrina;
- páginas secundarias y Administración en vidrio.

## Restricciones

- El inicio sigue sin Livewire ni Alpine. Lighthouse en celular debe quedar en 90 o más.
- Contraste AA en los dos temas. Se respeta `prefers-reduced-motion`.
- Una sola empresa por pantalla: el naranja de Segurex y el azul de SDC nunca se mezclan.
- Cada PR reconstruye y confirma `public/build` (`npm run build`).
- No se toca cómo el asistente crea anuncios ni las herramientas de Administración. Solo se cambia su estilo.
- Cada PR pasa `/code-review` y `/security-review` antes de abrirse (pedido de Jhon).
- Código e identificadores en inglés; textos de interfaz en español.

## Entrega

- PR encadenados a `main` de unas 400 líneas, sin force-push: cada hijo se actualiza fusionando el padre.
- La base es `fix/observaciones` (PR #101), porque las vistas de Administración cambiaron ahí.

## Tareas

- [x] G1. **Base.** Hecho en `0ef5feb` (ruta: escritor delegado, 2+ archivos no triviales).
  - Kit en `resources/css/glass.css`, importado por `app.css`.
  - Modo oscuro por atributo (`data-theme`), con el del sistema por defecto.
  - `data-brand` en `<html>`, script contra el destello del tema y `<meta name="csrf-token">`.
  - Foto de fondo optimizada (webp).
  - Rama `feat/glass-1-base`.
- [x] G2. **Respuestas JSON del asistente** para el panel flotante (`expectsJson()` en `ask` y `clear`, más un GET de la conversación). Rama `feat/assistant-json`, en paralelo a G1. Hecho en `c4d71a4` (PR #102).
- [x] G3. **Encabezado común.** Hecho en `aeb8827`, con `bf370d1` y la fusión de G1 en `271f787` (ruta: escritor delegado, 2+ archivos no triviales).
  - `<x-portal-header>` reemplaza el encabezado del inicio y `brand-bar`, que se borró.
  - Cambio de tema Claro/Oscuro/Sistema en el menú de la persona.
  - Rama `feat/glass-3-header`.
- [x] G4. **Campanita:** anuncios obligatorios sin leer, aprobaciones y tareas pendientes, y próximos eventos, dentro del presupuesto de consultas del inicio. Hecho en `4d9269d` (ruta: escritor delegado, 2+ archivos no triviales). Rama `feat/glass-4-bell`, sobre G3.
- [x] G5. **Banda de Mercados** en movimiento, con flechas verde y roja. Hecho en `6de88f0` (ruta: escritor delegado, 2+ archivos no triviales). Rama `feat/glass-5-markets`.
- [x] G6. **Bloques del inicio:** Favoritos, categorías, noticias, catálogo, accesos rápidos y «Mi día». Hecho en `e1d39d5`, `b7af6db` y `bbca5c6` (ruta: escritor delegado, 2+ archivos no triviales). Rama `feat/glass-6-home`, sobre G4 con G5 y G7 fusionados.
- [x] G7. **Botón flotante «Asistente» y su panel** en todas las páginas, más el restilo de `/asistente`. Hecho en `1ceb56f` (ruta: escritor delegado, 2+ archivos no triviales).
  - Rama `feat/glass-7-assistant`: G3 con `feat/assistant-json` (G2) fusionada.
- [x] G8. **Inicio de sesión Vitrina** y las páginas de error y de emergencia. Hecho en la rama `feat/glass-8-login` (PR #106).
- [x] G9. **Páginas secundarias:** `/cuenta`, `/anuncios`, `/eventos`, `/personas`, `apps/*` y `mcp/*` (`errors/*` quedó en G8). Hecho en `93bc248`, `3bb479a`, `62f797f` y `3644641` (ruta: escritor delegado, 2+ archivos no triviales). Rama `feat/glass-9-pages`, sobre G6.
- [x] G10. **Administración:** barra lateral, encabezado y clases compartidas de las vistas Livewire (posiblemente en dos PR). Hecho en la rama `feat/glass-10-admin` (PR #108).

## Comprobaciones

- `php artisan test` y `vendor/bin/pint --test` en cada PR.
- Revisión visual con las dos marcas, en claro y oscuro, a 375 px y en escritorio.
- Lighthouse del inicio en celular, con 90 o más.

## Avance

- Plan creado a partir del mapeo del front end actual (ruta: exploración delegada).
- G1 hecho en `0ef5feb` (`feat/glass-1-base`).
  - Modo oscuro: `@custom-variant dark` en bloque, con `prefers-color-scheme` cuando no hay `data-theme="light"` y con `[data-theme=dark]`. Las utilidades `dark:` siguen funcionando sin JavaScript (comprobado en el navegador con el sistema oscuro y sin atributo). Los tokens de `app.css`, `.signin-stage` y `BrandPalette::toCss()` emiten los dos selectores.
  - `data-brand` es el slug de la empresa (`segurex`, `derivados`) o `neutral`. Los tokens del Kit van con prefijo `--g-*`. El velo oscuro de SDC quedó solo en azules.
  - Fotos webp en `public/images/glass/`: grande ≤ 186 KB y pequeña ≤ 90 KB (la pequeña bajo 768 px). Incluye las de la Vitrina (planta 2 y 3) para G8.
  - Evidencia: `php artisan test` 3227 pasan; `vendor/bin/pint --test` sale en 0; `npm run build` bien. RED observado en 8 pruebas nuevas o cambiadas antes del código.
  - Pendiente para G3: el `theme-color` sigue al sistema, no a `data-theme`; el botón de tema debe actualizarlo. Ninguna página usa aún `glass`.
- G3 hecho en `aeb8827` (`feat/glass-3-header`), más `bf370d1` (impresión de `g-sheet`) y la fusión de `feat/glass-1-base` (`271f787`).
  - `App\View\Components\PortalHeader`: el inicio le pasa su `HomePage` (no agrega consultas); en las otras páginas lee a la persona. Allí la búsqueda es un GET a `/?q=` y no hay «Secciones» ni «Ctrl K».
  - Menú de la persona: «Tus empresas» (`data-account-companies`), sus enlaces, «Tema» (`data-theme-switch`, oculto sin JavaScript) y «Cerrar sesión». En celular el selector de la barra se oculta y queda «Tus empresas». Los ids y `data-*` de `home.js` se mantienen.
  - `resources/js/header.js` (0,68 kB gzip) carga en todas las páginas y `home.js` lo importa (queda en la copia sin conexión). Aplica el tema guardado, cierra los menús `<details data-menu>` (salió de `home.js`) y muestra el cambio de tema. `resources/js/theme.js` es la lógica pura: cambia `data-theme`, `g-theme` y el `media` de los `theme-color` y de los `<source>` de íconos oscuros (`all` / `not all`; «Sistema» devuelve el original). Sin JavaScript todo sigue al sistema.
  - Ranura `bell` (`data-header-bell`) antes del menú de la persona, para G4.
  - Las páginas con encabezado usan `glass` y dejaron `brand-band`: la barra del navegador queda con un color por esquema. El contenido va sobre un `g-sheet` provisional (inicio y `main` de las secundarias).
  - `vite.config.js` suma la entrada `header.js` (autorizado por el coordinador).
  - Evidencia:
    - `php artisan test`: 3240 pasan.
    - `vendor/bin/pint --test`: sale en 0.
    - `npm run build`: bien.
    - `node --test tests/js/theme.test.mjs` (6 pruebas) corre dentro de la suite con `ThemeScriptTest`.
    - RED observado antes del código: 15 pruebas fallaron (nuevas y actualizadas).
    - Revisión visual de inicio y `/anuncios`, con las dos marcas, en claro y oscuro, a 375 px (panel del navegador) y a 1280 px (Chrome sin interfaz), incluido el menú abierto.
    - En el navegador: elegir Oscuro con el sistema claro cambia el atributo, `localStorage` y las metas; «Sistema» las restaura. El clic afuera y Escape cierran los menús, y «Perfil» de la barra inferior abre el menú.
  - Sin medir: Lighthouse en celular (no está instalado aquí); la foto de fondo ya entra en el inicio.
  - Pendiente:
    - G4: la campanita dentro de `<x-slot:bell>`.
    - G5: la banda de Mercados (sigue `bg-surface`).
    - G6: los bloques del inicio y quitar el `g-sheet`.
    - ~~G7: el botón flotante del asistente (hoy está en el menú).~~ Hecho; el enlace del menú se queda.
    - G9: las páginas secundarias y quitar el `g-sheet`.
    - `mcp/authorize` y Administración siguen con `brand-band` (G9 y G10).
    - En iPhone con muesca, la barra fija baja hasta `env(safe-area-inset-top)` sin un relleno propio; revisar en G6.
- Revisión independiente de G3: sin hallazgos graves. Se corrigió en un commit (`fix(glass): purge the saved home on any logout and tighten the header`).
  - Privacidad: «Cerrar sesión» en cualquier página vuelve a pedir `purge` al service worker y anula la suscripción de avisos. El oyente pasó de `home.js` a `header.js`.
  - `header.js` exporta `setUpHeader(doc, win)`. Las pruebas de Node (`tests/js/header.test.mjs`, sobre `tests/js/fake-dom.mjs`, que evalúa los selectores de verdad) cubren el clic afuera, Escape (con un respaldo cuando el foco está fuera del menú), los botones de tema, la aparición del grupo de tema y la purga.
  - Accesibilidad: el logo lleva `aria-label="Ir al inicio"`. Los formularios de búsqueda se llaman «Buscar en el portal» y «Buscar personas».
  - Las páginas secundarias ocultan la búsqueda si la persona no tiene apps (`AppCatalog`). El encabezado allí hace 6 consultas, con un tope de 8 en la prueba.
  - No se extrajo un ayudante compartido con `HomePageBuilder`, porque `app/View/Home` queda fuera de la superficie de G3. En su lugar hay pruebas en páginas secundarias para Administración, «Soporte TI» y «Mi cumpleaños».
- G4 hecho en `4d9269d` (`feat/glass-4-bell`).
  - `App\View\Notifications\NotificationList` y `Notification`: hasta 8 avisos. Primero los anuncios obligatorios sin leer (los más nuevos primero), luego las aprobaciones, las tareas (como «Tareas pendientes») y los eventos de los próximos 7 días.
  - La insignia cuenta solo lo que pide algo a la persona: anuncios sin leer más pendientes. Es exacta hasta 9 y luego dice «9+». El nombre accesible es «Avisos, 3 sin leer» y la insignia lleva `aria-hidden`.
  - `<x-notification-bell>` es un `<details data-menu>` con `.g-pop`: funciona sin JavaScript y `header.js` lo cierra (comprobado: Escape y clic afuera). En celular la lista ocupa el ancho del encabezado. Pie «Ver todo» a `/#mi-dia`, o «No tienes avisos nuevos».
  - Los enlaces: un anuncio va a `announcements.show`; un pendiente abre su app en otra pestaña solo con un enlace seguro (`WebLink`), y si no, va a `/#tareas`; un evento va a `/eventos#e-{id}`.
  - Se quitó la ranura `bell` de G3: el encabezado pinta la campanita por sí solo, así que las 9 páginas secundarias no cambiaron.
  - Consultas: en el inicio, +1 (los anuncios sin leer); reutiliza `WorkList` y los eventos que ya lee `HomePageBuilder`. `HomeQueryBudget` pasa de 17 a 18 y de 22 a 23. En las otras páginas, la campanita usa como máximo 3 (anuncios, pendientes, eventos) y ninguna para la cuenta de emergencia.
  - `WorkRow::due()` comparte el texto del vencimiento con la campanita.
  - Evidencia:
    - `php artisan test`: 3253 pasan.
    - `vendor/bin/pint --test`: sale en 0.
    - `npm run build`: bien.
    - RED observado antes del código: 11 pruebas (10 nuevas en `NotificationBellTest` y la del encabezado).
    - Revisión visual en el inicio, `/anuncios` y el estado vacío, con las dos marcas, en claro y oscuro, a 375 px (panel del navegador) y a 1280 px (Chrome sin interfaz).
  - Tamaño: unas 740 líneas, la mitad son pruebas.
- Revisión independiente de G4: sin hallazgos graves. Se corrigió en `b9d8471` (`fix(glass): keep the cached bell honest and its ids unique`).
  - El id del título pasó a `bell-titulo`: `avisos-titulo` ya estaba en «Avisos en el celular» de `/cuenta`. Una prueba revisa que el inicio, `/anuncios` y `/cuenta` no repitan ids.
  - Copia guardada del inicio: las filas de eventos de la campanita llevan `data-event-date` y `data-event-end`, y `home.js` las oculta cuando terminan, como en «Próximas fechas». Muestra «Guardado sin conexión» y el estado vacío si no queda ninguna. Los eventos no cuentan en la insignia. Comprobado en el navegador con una copia `cache`.
  - Los eventos nunca dicen «Hoy», porque la copia guardada puede tener un día. La ventana son 7 días contando hoy.
  - Pruebas nuevas: lectura con una cuenta vinculada; anuncios retirados, programados y vencidos; pendientes borrados o de una integración revocada; un evento en curso y el borde de la ventana.
  - RED antes del código: 3 pruebas (ids repetidos en `/cuenta`, borde de la ventana y marcas de la copia guardada). Las demás cubren reglas que ya se cumplían.
- Fusión de `feat/glass-3-header` (`90c757b`) en `4c5cd31`. Se conservaron los dos lados del encabezado y se reconstruyó `public/build`. El tope de consultas del encabezado en páginas secundarias (prueba de G3) suma las de la campanita: `8 + NotificationList::QUERIES`.
  - Evidencia después de la fusión:
    - `php artisan test`: 3268 pasan.
    - `vendor/bin/pint --test`: sale en 0.
    - `npm run build`: bien.
    - `node --test "tests/js/*.test.mjs"`: 19 pasan.
- G5 hecho en `6de88f0` (`feat/glass-5-markets`).
  - Banda de vidrio con marquesina CSS, sin JavaScript: `App\View\Home\MarketTicker` decide cuántas copias imprimir (cubren la banda más ancha) y la vuelta dura 4 s por cifra. Las copias van con `aria-hidden` y sin `data-market`. Se detiene al pasar el cursor y con el foco del teclado.
  - Con una sola cifra no se mueve. Con `prefers-reduced-motion` se queda quieta, oculta las copias y se acomoda en varias líneas.
  - Sube: ▲ verde (`--g-good`). Baja: ▼ rojo (`--g-crit`). Sin cambio: ● neutro (antes «=»). Contraste medido sobre el vidrio: claro ≥ 4,99 (sobre negro, el peor caso); oscuro ≥ 5,2 sobre el fondo oscuro del inicio, en las dos marcas.
  - Toma cualquier código que entregue `MarketBoard`: nuevos `MarketRate::CODES` aparecen sin tocar la vista.
  - Evidencia: `php artisan test` 3240 pasan; `vendor/bin/pint --test` sale en 0; `npm run build` bien. RED observado en 12 pruebas nuevas o cambiadas antes del código. Revisión visual en Chrome sin interfaz: dos marcas, claro y oscuro, 375 px y escritorio, con y sin movimiento reducido.
  - Cerrado en un segundo commit de G5 (`fix(glass)`, pedido del coordinador):
    - Control «Pausar» / «Reanudar» sin JavaScript al final de la banda (WCAG 2.2.2): una casilla que detiene la pista con `:has()`, con foco visible y texto ≥ 7,5:1 en las dos marcas y los dos temas. No aparece con menos de dos cifras ni con movimiento reducido.
    - La frase leída usa la unidad de cada código (`MarketFigure::UNITS`): pesos para TRM y euro (igual que antes), dólares para el Brent cuando se sincronice y «puntos» para cualquier otro.
    - Evidencia: `php artisan test` 3246 pasan; `vendor/bin/pint --test` sale en 0; `npm run build` bien. RED observado en 4 pruebas nuevas antes del código. En Chrome sin interfaz: marcada la casilla, la pista queda en `paused`, el texto dice «Reanudar» y el foco muestra el contorno.
  - Revisión independiente de G5 sin hallazgos graves; corregido en `fix(glass): keep the ticker focus ring visible`:
    - El desvanecido y el recorte pasan a una ventana interior (`.g-ticker-window`): el contorno de foco de la banda ya no queda enmascarado (WCAG 2.4.7). Comprobado en Chrome sin interfaz enfocando la banda por script, en las dos marcas y los dos temas: contorno completo de 2 px y pista en pausa.
    - La etiqueta del grupo es neutra («Indicadores de mercado»), porque con movimiento reducido la banda no se mueve.
    - Pruebas directas de `MarketTicker::for` (0, 1, 2, 9 y 40 cifras) y de la banda sin cifras.
    - El nombre de la casilla «Pausar o reanudar…» queda igual (compromiso aceptado).

- G7 hecho en `1ceb56f` (`feat/glass-7-assistant`).
  - `<x-assistant-fab>` (`App\View\Components\AssistantFab`) usa la misma condición que el enlace del menú (`AssistantLink`) y no sale en `/asistente`.
    - El layout lo pone solo en las páginas que lo piden con la prop `assistant` (por defecto `false`): el inicio, `/anuncios`, `/eventos`, `/personas`, `/cuenta*` y `apps/*`. Nunca sale en el inicio de sesión, las páginas de error ni Administración, aunque sean de vidrio (G8 las pasa a vidrio).
    - Sin la clave de la API se muestra igual, y el panel dice «no disponible».
    - Sin JavaScript es un enlace a `/asistente`. En el inicio sube por encima de la barra inferior (`g-fab--raised`). En celular queda solo el ícono, con el texto oculto para lectores de pantalla.
  - `resources/js/assistant-panel.js` es una entrada de Vite (3,6 kB gzip).
    - `header.js` lo carga en el primer clic con un `<script type="module">`, no con `import()`: Vite 8 agrega su ayudante de precarga (otra petición en cada página) a cualquier `import()`. Como el script no recibe exportaciones, el módulo deja `window.openPortalAssistant`.
    - Si no carga (sin conexión o con otra compilación), se sigue el enlace. Un clic con Ctrl, Shift, Alt o Cmd abre el enlace.
    - Solo lee la conversación al abrir el panel la primera vez: el inicio guardado sin conexión nunca muestra una conversación vieja.
  - Panel (diálogo modal, `aria-modal="true"`, con Tab encerrado en todos los anchos):
    - el foco entra al campo (o a «Cerrar» si está cerrado); Escape y «Cerrar» devuelven el foco al botón;
    - si un envío deshabilita o quita el control con el foco, el foco pasa al campo (o a «Cerrar»);
    - al reabrirlo, muestra el último turno;
    - lo que la persona escribe mientras espera se queda: solo se borra el campo si aún tiene la pregunta enviada;
    - la respuesta nueva se anuncia en una región `aria-live="polite"` y los errores en `role="alert"`;
    - «Enviar» está deshabilitado mientras espera, con «Pensando…»;
    - un 409, un 422 o un 429 vuelven a leer la conversación (así se cierran los límites); 422, 429 y 503 muestran el `{error}` del servidor, y un 429 sin `error` (el freno de «Nueva conversación») dice «Estás preguntando muy rápido»; si la relectura tras un 409 falla, se muestra ese error; 419 pide recargar, 404 dice «no disponible» y un fallo de red da un mensaje general;
    - el campo se cierra con `full`, `budget_reached` o sin preguntas del día;
    - el borrador (título y extracto como texto) solo enlaza a «Revisar y publicar» en `/asistente#borrador`. Un `review_url` que no sea una ruta del portal vuelve a ese enlace.
    - Las sugerencias son las cinco de `/asistente`.
  - `/asistente` pasó a tarjetas `g-card`, con burbujas `g-msg` y campos `g-field`. Los formularios, los ids (`#respuesta`, `#borrador`), los mensajes y la publicación del borrador no cambiaron.
  - Los enlaces de las respuestas llevan `text-brand-ink`. Sobre la burbuja de vidrio se usa `--g-accent-ink`, el color medido (con `!important`, porque la utilidad está en una capa posterior).
  - `tests/js/fake-dom.mjs` ahora tiene nodos de texto, `innerHTML` (guarda el marcado, y su texto sin etiquetas), `createElement`, `replaceChildren`, `remove`, `head` y `body`.
  - Evidencia:
    - RED antes del código: 6 de 11 pruebas nuevas de PHP, 3 pruebas nuevas de `header.test.mjs`, y `assistant-panel.test.mjs` sin el módulo.
    - `php artisan test`: 3281 pasan.
    - `vendor/bin/pint --test`: sale en 0.
    - `npm run build`: bien.
    - `node --test "tests/js/*.test.mjs"`: 39 pasan (16 del panel y 4 del botón en `header.test.mjs`).
    - Revisión visual de las dos marcas, en claro y oscuro: en escritorio (Chrome sin interfaz, 1280 px) y a 375 px (en un iframe de 375 px; el panel del navegador escala la vista).
    - En el navegador: enviar deshabilita «Enviar», pone `aria-busy` y oculta el botón; la respuesta llega, se anuncia y el borrador sale como texto; un 429 muestra su mensaje y conserva la pregunta; Escape cierra y devuelve el foco.
  - Sin medir: Lighthouse en celular. El inicio solo suma el enlace (unos 650 bytes) y unos 2 kB de CSS. Nada se pide hasta el primer clic.
  - El botón lee `AssistantSwitch` una vez más (una consulta por clave primaria) cuando el interruptor de despliegue está encendido, porque `AssistantSwitch` no es singleton y queda fuera de la superficie de G7.
- Revisión independiente de G7: sin hallazgos graves. Se corrigió en un commit (`fix(glass): opt pages into the assistant and steady the panel`).
  - El botón pasó a ser opcional por página (`assistant`), porque G8 vuelve de vidrio las páginas de error y `auth/admin-consent`. Hay una prueba de una página de vidrio sin la opción.
  - En el panel: se conserva lo escrito mientras espera; 422 y 429 releen la conversación; el foco no se pierde; diálogo modal con Tab encerrado; al reabrir, baja al último turno; mensajes para un 409 que no se puede releer y para un 429 sin `error`.
  - Evidencia:
    - RED antes del código: la prueba nueva de PHP y 10 pruebas de Node.
    - `node --test "tests/js/*.test.mjs"`: 48 pasan.
    - `vendor/bin/pint --test`: sale en 0.
    - `npm run build`: bien (`assistant-panel` 3,83 kB gzip).
    - En el navegador: Tab desde «Enviar» vuelve a «Nueva conversación»; el foco queda en el campo mientras espera; un 429 relee la conversación y conserva el texto nuevo.
- G6 hecho en `e1d39d5` (`feat/glass-6-home`), más `b7af6db` (prueba de anuncios) y `bbca5c6` (correcciones de la revisión).
  - Base: `feat/glass-4-bell` con `feat/glass-5-markets` y `feat/glass-7-assistant` fusionados (`147fb4c`); suite 3328 en verde antes de empezar.
  - Inicio sin `.g-sheet`: `div.g-home` con `<main>` y `<aside id="mi-dia">` (al lado desde 1024 px, debajo en celular). Saludo en `g-banner`; bloques como `g-card` en el orden del prototipo: Panel gerencial, Mis aplicaciones (Favoritos, Recientes y categorías en `g-tile`), Accesos rápidos (chips, sin «Microsoft 365»), Anuncios (en todos los anchos; se quitó la franja del celular), Noticias, Catálogos y categorías.
  - «Mi día»: Tareas pendientes, Por confirmar (anuncios obligatorios sin leer, de `NotificationList`; «Ver todos» al llegar al límite de 8), Cumpleaños y aniversarios, Próximas fechas, Personas (búsqueda GET a `/personas`) y Estado de TI. Sin consultas nuevas.
  - CSS en la sección 7 de `glass.css`; dentro de `.g-home` los tokens de Tailwind apuntan a los `--g-*` medidos. Sin JS nuevo ni fuentes.
  - Fuera por falta de datos: resumen semanal, «Instalar en tu celular», clima, «Agregar a mi calendario».
  - Revisión independiente de código y seguridad: nada grave; 8 hallazgos menores corregidos en `bbca5c6`.
  - Evidencia: `php artisan test` 3344 pasan; `vendor/bin/pint --test` sale en 0; `npm run build` bien; `node --test` 48 pasan. RED observado en 11 + 2 pruebas nuevas. Revisión visual en Chrome sin interfaz: dos marcas, claro y oscuro, 1280 px y 375 px sin desborde.
- G9 hecho en `93bc248`, `3bb479a` y `62f797f` (`feat/glass-9-pages`), más `3644641` (correcciones de la revisión) y la fusión de G6 (`b2fb22e`).
  - Sin `.g-sheet`: `<main class="g-page">`, columna de 760 px con márgenes de 16 px; `g-banner` con enlace de vuelta y h1, y `g-card` con cabecera y cuerpo. Las listas van como filas dentro de una sola tarjeta (hasta 100 eventos), sin brillo al pasar el cursor.
  - CSS en `resources/css/glass-pages.css`, importado desde `app.css`; dentro de `.g-page` los tokens de Tailwind apuntan a los `--g-*` medidos. `.g-btn--danger` queda ahí hasta moverlo a `glass.css`.
  - Rutas, formularios, CSRF, campos, anclas y ganchos sin cambios. La página de autorización de Claude usa su propia barra solo con el logo, sin menús ni asistente.
  - «empresa del grupo» → «otra empresa» en `/cuenta`; «de las empresas del grupo» → «de las empresas con acceso al portal» en `mcp/refused`. No queda «grupo» en esas vistas.
  - Revisión independiente de código y seguridad: nada grave; 7 hallazgos menores corregidos en `3644641`, más un solo enlace al inicio en `apps/local` y sin doble punto después de «p. m.».
  - Evidencia: `php artisan test` 3345 pasan; `vendor/bin/pint --test` sale en 0; `npm run build` bien; `node --test` 48 pasan. RED observado en las pruebas nuevas. Revisión visual en Chrome sin interfaz: 10 páginas, dos marcas, claro y oscuro, 375 px sin desborde.
