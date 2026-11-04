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
- [ ] G2. **Respuestas JSON del asistente** para el panel flotante (`expectsJson()` en `ask` y `clear`, más un GET de la conversación). Rama `feat/assistant-json`, en paralelo a G1.
- [x] G3. **Encabezado común.** Hecho en `aeb8827`, con `bf370d1` y la fusión de G1 en `271f787` (ruta: escritor delegado, 2+ archivos no triviales).
  - `<x-portal-header>` reemplaza el encabezado del inicio y `brand-bar`, que se borró.
  - Cambio de tema Claro/Oscuro/Sistema en el menú de la persona.
  - Rama `feat/glass-3-header`.
- [ ] G4. **Campanita:** anuncios obligatorios sin leer, aprobaciones y tareas pendientes, y próximos eventos, dentro del presupuesto de consultas del inicio.
- [ ] G5. **Banda de Mercados** en movimiento, con flechas verde y roja.
- [ ] G6. **Bloques del inicio** (posiblemente en dos PR): Favoritos, categorías, noticias, catálogo, accesos rápidos y «Mi día».
- [x] G7. **Botón flotante «Asistente» y su panel** en todas las páginas, más el restilo de `/asistente`. Hecho en `1ceb56f` (ruta: escritor delegado, 2+ archivos no triviales).
  - Rama `feat/glass-7-assistant`: G3 con `feat/assistant-json` (G2) fusionada.
- [ ] G8. **Inicio de sesión Vitrina** y las páginas de error y de emergencia.
- [ ] G9. **Páginas secundarias:** `/cuenta`, `/anuncios`, `/eventos`, `/personas`, `apps/*`, `errors/*` y `mcp/*`.
- [ ] G10. **Administración:** barra lateral, encabezado y clases compartidas de las vistas Livewire (posiblemente en dos PR).

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
