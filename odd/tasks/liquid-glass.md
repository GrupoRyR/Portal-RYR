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
- [ ] G3. **Encabezado común** `<x-portal-header>`, que reemplaza `brand-bar` y el encabezado del inicio, con el cambio de tema.
- [ ] G4. **Campanita:** anuncios obligatorios sin leer, aprobaciones y tareas pendientes, y próximos eventos, dentro del presupuesto de consultas del inicio.
- [ ] G5. **Banda de Mercados** en movimiento, con flechas verde y roja.
- [ ] G6. **Bloques del inicio** (posiblemente en dos PR): Favoritos, categorías, noticias, catálogo, accesos rápidos y «Mi día».
- [ ] G7. **Botón flotante «Asistente» y su panel** en todas las páginas, más el restilo de `/asistente`.
- [x] G8. **Inicio de sesión Vitrina** y las páginas de error y de emergencia. Hecho en `887f69a`, `2db9d5a` (título en `PlaceholderPagesTest`) y el arreglo de la revisión `fix(glass): lighten the Vitrina slideshow` (ruta: escritor delegado, 2+ archivos no triviales).
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
- G8 hecho en `887f69a` (`feat/glass-8-login`).
  - Vitrina en `auth/login`: la foto de planta 1 de fondo y tres fotos (stand, planta 2 y 3) que entran por turnos (CSS, 7 s cada una), con un velo neutro. Corre dos ciclos y se queda en la foto de fondo (WCAG 2.2.2). Con movimiento reducido las tres quedan en `display: none`. En celular, las fotos pequeñas.
  - Arreglo de la revisión: solo se anima la opacidad (sin escala). Mientras hay movimiento, el vidrio de la Vitrina usa la superficie sólida `--g-solid` sin `backdrop-filter`, así que no se recalcula ningún desenfoque. Cada diapositiva recibe su foto con una animación de un solo paso 4 s antes de su turno (a los 3, 10 y 17 s), así que la página carga solo la foto de fondo y un logo. Comprobado con Resource Timing en Chrome: al cargar no hay fotos de diapositivas, y aparecen cuando corre su animación.
  - Bytes de imagen en celular al cargar: 103 274 en claro (planta 1 pequeña 89 260 + One color 14 014) y 110 832 en oscuro (One cristal 21 572). Las diapositivas suman 191 628 después, y nada con movimiento reducido.
  - Tarjeta de vidrio: logo One (`public/images/one/`, webp de 14 y 22 KB) en un `<picture>` con `<source media="(prefers-color-scheme: dark)">`, así que se descarga una sola variante. Lleva `fetchpriority="high"`. Sin JavaScript sigue al sistema; el `theme.js` de G3 reescribe el `media` de esas fuentes cuando hay un tema elegido. Además: «Bienvenido a One», «Iniciar sesión con Microsoft», el error, los logos de `companies` y la nota de soporte.
  - Sin «grupo»: el texto de soporte dice «Solo para colaboradores de Segurex y Soluciones y Derivados». Se quitaron la franja naranja y azul del boceto y su velo de dos colores.
  - Pie: los nombres legales con el año actual, y solo los enlaces con dirección conocida: «Soporte TI» (si hay URL de soporte) y segurex.com (del boceto). La política, los términos y el sitio de SDC no tienen URL todavía, así que no salen.
  - Se borraron el cielo de cometas (`components/auth/sky`) y su CSS. Las páginas de emergencia, de consentimiento y de error ahora son tarjetas de vidrio sobre `glass`. Los botones sin empresa usan `.g-btn--ink` (17,6:1 en claro y 15,7:1 en oscuro).
  - Sin botón de tema en la Vitrina: lo agrega G3.
  - Evidencia: RED en 18 pruebas antes del código, y en la prueba del `<picture>` antes del arreglo. `PlaceholderPagesTest` ya espera «Bienvenido a One» (`2db9d5a`). Después del arreglo: `php artisan test` pasan 3237 de 3237; `vendor/bin/pint --test` sale en 0; `npm run build` funciona. Se revisó en Chrome sin interfaz, en claro y oscuro, a 375 px y 1440 px.
