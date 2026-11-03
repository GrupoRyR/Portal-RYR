# Estado de TI

Línea `feat/estado-ti` (worktree `PORTAL_RYR-worktrees/estado-ti`, desde `origin/main` 59f2209). Engram mirror: pending (topic `odd/estado-ti/tasks`).

## Objetivo

El prototipo del inicio aprobado por la gerencia tiene un bloque «Estado de TI»: de un vistazo, si algo está fallando **para mi empresa** ahora mismo, y un enlace «Reportar un problema». El plan aprobado: reutilizar el estado que ya tiene cada app y agregar servicios que se administran a mano; «Reportar un problema» sigue yendo a Gestor TI por el enlace de soporte existente (`/soporte`).

Esta línea construye el backend, la página de Administración y la documentación. El bloque del inicio lo arma otra línea (Home): aquí no se toca el inicio.

## Alcance

- Tabla `it_services` (por empresa: nombre, estado `operational`/`degraded`/`down`, mensaje corto opcional, posición, fechas), modelo, fábrica, política y auditoría con etiquetas en español.
- Administración › Estado de TI (Livewire): crear, editar, ordenar y borrar los servicios de una empresa. Validación, largos máximos, nombre único por empresa sin importar mayúsculas ni tildes.
- `App\ItStatus\ItStatusBoard`: lectura para la empresa activa de una persona: estado general, apps en mantenimiento o con fallas reportadas (solo las que la persona ve), servicios que no están operativos y el enlace de «Reportar un problema».
- Documentación: `docs/despliegue.md` §1.21 y `docs/manual-administracion.md` §13.

## Restricciones

- Producción en MariaDB 10.11 (pruebas en SQLite). Migraciones `2026_11_05_6xxxxx`; nombres de índices y claves de 64 caracteres o menos, explícitos.
- Sin `.env*`. Sin rebase, sin force-push, sin `git stash` a secas. Sin PR ni merge. Sin `public/build`.
- No se rediseña el inicio. No se toca `app/Catalog/**` más allá de leerlo.
- Pruebas primero (RED antes de GREEN). Unas 400 líneas por tarea es solo orientación.

## Decisiones

- **Quién edita los servicios:** las mismas personas que administran las apps (`App\Policies\AppPolicy::ROLES`: `super_admin` y `portal_admin`), para todas las empresas, como Catálogos. El editor de anuncios y Gerencia no entran. No se crea un rol nuevo.
- **Estado general:** «Hay servicios caídos» si algún servicio está `down` o alguna app tiene un reporte vigente `down`; «Con fallas» si algún servicio está `degraded`, alguna app tiene un reporte vigente `degraded` o alguna app está en mantenimiento; si no, «Todo funciona». El mantenimiento cuenta como falla parcial, no como caída: es planeado y tiene mensaje.
- **Sin caché:** los reportes externos vencen según la hora (`api_status_ttl_hours`), así que una caché necesitaría invalidarse también por tiempo. La lectura de servicios es una consulta por índice (`company_id`, `position`) sobre pocas filas, y las apps reutilizan `AppCatalog::visibleApps()` (el inicio ya la tiene y la puede pasar).
- **Orden:** servicios caídos primero, luego con fallas; dentro de cada grupo, el orden de Administración. Las apps siguen el orden del catálogo.
- **Nombres únicos:** se comparan en PHP sin mayúsculas ni tildes (como `utf8mb4_unicode_ci`), así que SQLite y MariaDB rechazan lo mismo; el índice único tiene la última palabra ante dos guardados a la vez.

## Tareas

- [x] T1. Tabla, modelo, fábrica, política, auditoría y Administración › Estado de TI (ruta, menú, Livewire). Ruta: delegada (este escritor). Commit `c1f7f59`.
- [x] T2. `App\ItStatus\ItStatusBoard` y sus objetos de valor (`ItStatus`, `ItStatusItem`, `ItStatusLevel`). Ruta: delegada (este escritor). Commit `fcc7eb0`.
- [x] T3. Documentación (§1.21 de despliegue y §13 del manual). Ruta: delegada (este escritor). Commit: `docs(estado-ti): ...` (el último de la rama).

## Criterios de aceptación

- Solo `super_admin` y `portal_admin` entran y actúan; se revisa en cada acción y en `render()`. Un id de otra empresa es 404.
- Nombre obligatorio (hasta 80 caracteres), único por empresa sin importar mayúsculas ni tildes; estado de la lista; mensaje opcional de hasta 160 caracteres.
- Crear, cambiar, mover y borrar un servicio queda en Auditoría con etiquetas y estados en español.
- La lectura nunca trae servicios de otra empresa ni apps que la persona no ve; una persona sin empresa activa (o la cuenta de emergencia) recibe un tablero vacío y no disponible.
- El enlace de «Reportar un problema» es `route('support')` solo cuando hay dirección de soporte configurada.

## Comprobaciones

`APP_KEY=... php artisan test --compact` (suite completa) y `vendor/bin/pint --test`.

## Declaración de ruta

Un solo escritor delegado (esta sesión) en su propio worktree. Disparadores: más de 2 archivos no triviales y lectura previa a la escritura.

## Progreso

- T1: RED 25 pruebas, 0 pasan (clase y ruta inexistentes). GREEN 25/25; `tests/Feature/{ItStatus,Admin,Products,Audit}` 516/516. Pint pasa.
- T2: RED 13 pruebas, 0 pasan (`ItStatusBoard` no existe). GREEN 13/13; `tests/Feature/ItStatus` 38/38. Pint pasa.
- T3: documentación pasiva, revisión estructural (sin prueba RED aplicable). Suite completa: 2768 pruebas, todas pasan. `vendor/bin/pint --test`: pasa.
- No se agregó una fila «Estado de TI» a `App\Admin\RoleMatrix` (tampoco la tienen Catálogos ni Noticias); la política se prueba contra `AppPolicy` en `ItServiceModelTest`.
- Engram mirror: pending.

## Siguiente paso

Rama empujada a `origin/feat/estado-ti`, sin PR. Siguiente: revisión y PR (decisión humana); el bloque del inicio lo arma la línea Home con `app(ItStatusBoard::class)->for($user, $visible)`.
