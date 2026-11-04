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
- [ ] G8. **Inicio de sesión Vitrina** y las páginas de error y de emergencia.
- [ ] G9. **Páginas secundarias:** `/cuenta`, `/anuncios`, `/eventos`, `/personas`, `apps/*`, `errors/*` y `mcp/*`.
- [x] G10. **Administración:** hecho en `c09653c` (ruta: escritor delegado). Rama `feat/glass-10-admin`, un solo PR.
  - Barra lateral y barra del celular en vidrio sobre la foto; la página va en un panel casi opaco.
  - Las clases de tarjetas, campos y botones viven en cada vista: un «skin» central en `glass.css` les da el acabado de vidrio sin tocar las vistas.

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
- G10 hecho en `c09653c` (`feat/glass-10-admin`, sobre `feat/glass-1-base`).
  - Las cadenas `$card`, `$field`, `$primary`… están repetidas en el `@php` de 16 vistas y además hay unas 120 superficies escritas a mano. En vez de reescribirlas, `glass.css` §7 trae un «skin» en la capa `components`, dentro de `.g-admin-main`: solo agrega sombras y un tono más oscuro abajo en los botones llenos. Por la capa, ninguna utilidad de las vistas (color, borde, espacio) pierde.
  - El panel de contenido es `--color-ground` opaco, el mismo fondo con el que se midieron las vistas. Así se conservan el texto, los estados y la tinta de marca (BrandPalette la mide contra #1C1D20). El `--g-solid` oscuro, más claro, dejaba la tinta de SDC (#4D8ABD) en 3,9:1.
  - En la barra lateral y la del celular, `fg`, `fg-soft` y `fg-muted` pasan a `--g-ink*`, medidos sobre el vidrio. El `#5e6168` daba unos 4,4:1 en el peor caso.
  - El panel no lleva `backdrop-filter`, `transform` ni `z-index`, porque atraparían los diálogos fijos de Integraciones.
  - Logo sobre su placa (`bg-brand-dark` para el blanco de Segurex, placa blanca para SDC). Sin `brand-band`, la barra del navegador queda neutra.
  - Evidencia: `php artisan test` 3230 pasan; `vendor/bin/pint --test` sale en 0; `npm run build` bien. RED observado en 4 pruebas nuevas o cambiadas con el layout anterior.
  - Revisión visual de Anuncios (nuevo), Empresas y Auditoría con las dos marcas, en claro y oscuro: 375 px en el panel del navegador y 1440 px en Chrome sin cabeza.
  - Hallazgos previos, sin tocar porque son de comportamiento o texto: en `company-editor` los placeholders `todos@{{ … }}` e `info@{{ … }}` salen literales, porque Blade escapa `@{{`. A 375 px, el selector «Todas las empresas» de Auditoría se sale unos pocos px por el nombre largo de SDC (ya pasaba antes).
  - Corrección tras la revisión independiente (`fix(admin): keep the glass panel readable everywhere`).
    - El panel usa `background-color: var(--color-ground)` sin `color-mix()`: la compilación le ponía un respaldo claro fijo, y en oscuro quedaba ilegible en Safari < 16.2 y Chrome < 111.
    - El brillo de los botones llenos queda solo en `a`, `button` y `summary` habilitados.
    - Al imprimir, la barra lateral y la barra superior salen en blanco.
    - La sección de `glass.css` pasa a ser la «7», porque G5 también agrega una «6».
    - Prueba nueva `tests/Unit/Branding/AdminGlassSkinTest.php`: el panel no lleva `backdrop-filter`, `filter`, `transform` ni `z-index`, ni `color-mix`, y el brillo excluye `:disabled`.
