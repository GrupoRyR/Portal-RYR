# Catálogos de productos por empresa

Línea `feat/catalogos`. Engram mirror: pending (topic `odd/catalogos/tasks`).

## Objetivo

Aprobado por la gerencia (prototipo del inicio, 28 de octubre de 2026): una sección «Catálogos y categorías» con chips de categoría y tarjetas de producto (foto, nombre, categoría). Ejemplos: Segurex tiene «Línea smart», «Residencial», «Comercial», «Control de acceso»; SDC tiene «Hipoclorito», «Aseo hogar», «Institucional», «Fichas técnicas y FDS».

Esta línea construye solo el backend y las páginas de Administración. El cableado del inicio lo hace otra línea.

## Alcance

- Tablas `product_categories` y `products`, modelos, fábricas y políticas.
- `App\Products\ProductPhotoStore` (mismo criterio que `App\Catalog\AppIconStore`) y `ProductLinkPolicy`.
- `App\Products\ProductBoard`: lectura de una empresa con sus categorías y productos publicados, máximo 2 consultas.
- Administración › Catálogos (Livewire): crear, editar, ordenar, publicar/despublicar y borrar categorías y productos.
- Auditoría con etiquetas en español, rutas, menú y documentación (§1.18 de despliegue y una sección nueva del manual).

## Restricciones

- No se toca `app/Catalog/**` (el catálogo de apps), el inicio, `resources/js/**`, `public/build/**`, `composer.*`, `config/portal.php`.
- MySQL en producción: nombres de índices y claves foráneas de 64 caracteres o menos, explícitos. Migraciones `2026_11_05_3*`.
- Pruebas primero (RED antes de GREEN). Sin `.env*`.
- Unas 400 líneas por tarea es solo orientación.

## Decisiones

- `AppUrlPolicy` no encaja: valida contra la lista de dominios permitidos para apps, y una ficha técnica puede estar en cualquier sitio. Se usa `ProductLinkPolicy` (https, host DNS simple, sin usuario ni puerto) sin cambiar `AppUrlPolicy`.
- Fotos: JPG, PNG o WebP, máximo 2 MB, vuelta a codificar con GD (quita EXIF y cargas escondidas), lado mayor de 1200 px, disco `public`, carpeta `products/{empresa}/`.
- Permiso: `super_admin` y `portal_admin` (igual que `AppPolicy`).

## Tareas

- [ ] T1. Esquema, modelos, fábricas y políticas.
- [ ] T2. `ProductPhotoStore` y `ProductLinkPolicy`.
- [ ] T3. `ProductBoard` (lectura, 2 consultas).
- [ ] T4. Administración › Catálogos (Livewire), rutas, menú y auditoría.
- [ ] T5. Documentación (§1.18 y manual).

## Criterios de aceptación

- Solo `super_admin` y `portal_admin` entran y actúan; se revisa en cada acción y en `render()`.
- La lectura de una empresa nunca trae categorías ni productos de otra, ni productos sin publicar.
- Fotos inválidas y enlaces que no son https se rechazan.
- Borrar un producto o reemplazar su foto borra el archivo anterior.
- Cada cambio queda en Auditoría con etiquetas en español.

## Comprobaciones

`APP_KEY=... php artisan test --compact` y `vendor/bin/pint --test`.

## Declaración de ruta

Un solo escritor delegado (esta sesión). Disparadores: más de 2 archivos no triviales y lectura previa a la escritura.

## Progreso

Sin avances todavía.

## Evidencia de verificación

Pendiente.
