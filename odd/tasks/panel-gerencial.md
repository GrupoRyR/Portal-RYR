# Panel gerencial

Bloque «Panel gerencial» («Solo Gerencia») del prototipo del inicio aprobado por la gerencia el 28 de octubre de 2026: las cifras clave del mes «al cierre de ayer» (ventas contra la meta con un medidor, cartera vencida, pedidos y pedidos atrasados, días de inventario de un producto) y una línea con la fuente. Un robot nocturno de SAP (otro proyecto) publica las cifras. Esta línea construye solo el backend; el inicio lo conecta después otra línea.

## Objetivo

Que cada empresa tenga su propio juego de cifras gerenciales, publicado por un sistema externo con un token propio, y que solo las cuentas con el rol «Gerencia» de esa misma empresa puedan leerlo.

## Problema y por qué

- Las cifras viven en SAP Business One. La gerencia quiere verlas en el portal sin entrar a SAP.
- Son datos sensibles: nunca deben verse en otra empresa ni por personas fuera de la gerencia.

## Alcance

- Rol `management` («Gerencia») por cuenta, desde un grupo de seguridad del inquilino de su empresa (`companies.management_group_id`), aplicado en cada inicio con Microsoft como `portal_admin`.
- Permiso `kpis:write` con empresa obligatoria (`integrations.company_id`) en Administración › Integraciones.
- `PUT /api/v1/kpis`: reemplaza de forma atómica el juego de cifras de la empresa del token.
- Tabla `company_kpis`, modelo de lectura `App\Management\ManagementPanel` (una consulta) y permiso `viewManagementPanel`.
- Documentación: `docs/api.md`, `docs/despliegue.md` §1.19 y `docs/manual-administracion.md` §11.

## Fuera de alcance

- La tarjeta en el inicio (`resources/views/home.blade.php`, `app/View/Home/**`): otra línea.
- El robot de SAP.
- Historial de cifras: solo se guarda el juego vigente.

## Restricciones

- Coincidencia del grupo solo por Object ID, solo dentro del inquilino de la empresa, solo grupos de seguridad; nunca por nombre.
- La empresa de las cifras sale solo del token, nunca del cuerpo.
- Los valores nunca se escriben en registros ni en Auditoría (solo el número de cifras).
- MySQL en producción: nombres de índices y llaves foráneas explícitos y de 64 caracteres o menos. Migraciones `2026_11_05_4*`.
- Pruebas sin red, con `APP_KEY` en el entorno. Commits convencionales sin atribución de IA.

## Tareas

- [x] T1. **Rol Gerencia.** Ruta: delegada (escritor único de la línea; 2 o más archivos no triviales). Migración `management_group_id`, `App\Auth\ManagementGroup`, `MicrosoftLogin`, `RoleSeeder`, `RoleMatrix`, `AuditPresenter`, Administración › Empresas. Pruebas: `tests/Feature/Auth/ManagementRoleTest.php`, `tests/Feature/Admin/CompanyManagementGroupTest.php`, `RoleMatrixTest`.
- [x] T2. **Permiso `kpis:write` con empresa.** Ruta: delegada (mismo escritor). Migración `integrations.company_id`, `Integration`, `IntegrationManager` y su vista. Pruebas en `IntegrationsPageTest`.
- [x] T3. **`PUT /api/v1/kpis` y tabla `company_kpis`.** Ruta: delegada (mismo escritor). Migración, `CompanyKpi`, fábrica, `PutKpisRequest`, `KpiController`, `routes/api.php`, Auditoría, revocar borra las cifras. `docs/api.md` §8. Pruebas: `tests/Feature/Api/KpisApiTest.php`.
- [x] T4. **Modelo de lectura y permiso.** Ruta: delegada (mismo escritor). `ManagementPanel`, `ManagementSnapshot`, `ManagementKpi`, `ManagementPanelPolicy`, `AppServiceProvider`. `docs/despliegue.md` §1.19 y `docs/manual-administracion.md` §11. Pruebas: `tests/Feature/Management/ManagementPanelTest.php`.

## Decisiones

- **Una sola tabla `company_kpis`** (una fila por cifra, con `period_label`, `as_of` y `source_label` repetidos en cada fila de la empresa). Con 8 cifras como máximo la repetición no pesa, el reemplazo es «borrar las de la empresa e insertar» en una transacción y la lectura es una sola consulta sin unir tablas.
- **El permiso `viewManagementPanel` vuelve a comprobar el grupo**, no solo el rol: rol `management`, misma empresa, cuenta que no es de emergencia y pertenencia guardada al grupo vigente de la empresa (`ManagementGroup::contains`). Así, quitar o cambiar el grupo en Administración › Empresas corta el acceso al instante, sin esperar al siguiente inicio de sesión, aunque la fila del rol se actualice en ese inicio.
- **Revocar** una integración borra las cifras que publicó (como las tareas). Quitar `kpis:write` o cambiar la empresa no las borra: son las cifras reales de esa empresa hasta que otro envío las reemplace.
- Dar `kpis:write` o cambiar su empresa pide un inicio con Microsoft reciente, como `work:write`.
- `RoleMatrix` gana la columna «Gerencia» sin filas nuevas: Gerencia no tiene acceso a ninguna sección de Administración, y el panel vive en el inicio.

## Criterios de aceptación

- El rol se da y se quita en cada inicio con Microsoft según el grupo de seguridad de la empresa; un grupo de otro inquilino, un grupo que no es de seguridad o un grupo con el mismo nombre y otro ID no dan nada; sin ID de grupo nadie tiene el rol.
- Un token de la empresa A nunca escribe las cifras de B; sin `kpis:write` responde 403; la lista de IP se aplica.
- `PUT` valida (422 con el campo), reemplaza todo el juego de forma atómica y deja `kpis.updated` en Auditoría con el número de cifras, sin valores.
- `ManagementPanel` lee el juego de una empresa en una consulta y nunca muestra las de otra.
- `MicrosoftLoginTest` e `IntegrationsPageTest` siguen en verde.

## Comprobaciones

- `APP_KEY=base64:… php artisan test --compact` (suite completa).
- `vendor/bin/pint --test`.

## Progreso y evidencia

Ver la sección «Evidencia» al final; se actualiza al cerrar cada tarea.

## Evidencia

- Línea base (`main` 31e7cdd): la suite completa se lanzó en paralelo con las primeras ediciones de T1: 2263 pruebas, 2260 pasan; las 3 que fallaron (`AdminBrandTest` ×2, `SecurityHeadersTest`) eran la vista del editor a medio editar (`$managementGroupId` aún sin propiedad) y pasan 23/23 al repetirlas.
- **T1** (commit `ea3c14f`). RED: 22 pruebas (`ManagementRoleTest`, `CompanyManagementGroupTest`, `RoleMatrixTest`), 18 fallan (4 fallos, 14 errores: no existían la columna, `ManagementGroup` ni el rol). GREEN: 22/22. `tests/Feature/Auth`, `Admin`, `Companies` y `Audit`: 562 pruebas, 4 fallos esperados en `AuthSetupTest` (contaba 3 roles), corregidos; `tests/Feature/Auth` 131/131 después. Revisión nativa: según el interruptor de RDD del coordinador.
- **T2** (commit `8fc7ca4`). RED: `IntegrationsPageTest` 31 pruebas, 6 fallan (las nuevas: no existían `KPIS_WRITE`, `companyId` ni `kpiCompanyId`). GREEN: 31/31. `tests/Feature/Api` y `Audit`: 146/146.
- **T3** (commit `b791f63`). RED: `KpisApiTest` 45 pruebas (29 casos de validación incluidos), 45 fallan (la ruta respondía 404). GREEN: 45/45. `tests/Feature/Api`, `Audit` e `IntegrationsPageTest`: 222/222.
- **T4.** RED: `ManagementPanelTest` 13 pruebas, 13 errores (no existía `ManagementPanel`). GREEN: 13/13, con la prueba que cuenta una sola consulta y la de otras empresas.
- **T4** quedó en el commit `dafd0e3`.
- **Cierre.** Suite completa: 2353/2353 (2263 de la línea base + 90 nuevas). `vendor/bin/pint --test`: pasa.

## Revisión (R1)

Revisiones independientes de seguridad y de corrección: nada de seguridad con 8/10 o más y ningún hallazgo alto. Correcciones con prueba primero, en commits nuevos (sin enmendar):

| # | Nivel | Hallazgo | Corrección |
|---|---|---|---|
| 1 | Medio | `as_of` aceptaba los años 0001 a 9999, y el `TIMESTAMP` de MySQL solo llega a 2038: 500 en modo estricto. | Columna `dateTime`; `as_of` antes del 2000-01-01 o más de un día adelante responde 422 (4 casos nuevos en el proveedor y una prueba de bordes). |
| 2 | Bajo | Un `PUT` en curso podía volver a escribir cifras después de revocar. | `KpiPublisher` bloquea la fila de la integración dentro de la transacción y vuelve a comprobar que no está revocada, que tiene `kpis:write` y que su empresa no cambió (si no, 403); `Integration::revoke()` bloquea la misma fila primero. |
| 3 | Bajo | Auditoría no mostraba el número de cifras. | Se guarda como `attributes.items` con la etiqueta «Cifras»; la prueba de Auditoría la ve. |
| 4 | Bajo | La prueba del bloqueo pasaba sin probarlo. | Las consultas de bloqueo (`KpiPublisher::integrationRow` y `companyRow`) compiladas con `MySqlGrammar` terminan en `for update`; el orden integración → empresa también queda probado, y el de `revoke()`. |
| 5 | Bajo | Con `\uXXXX`, un cuerpo válido podía pasar de 8 KB. | Límite de 16 KB; `docs/api.md` §8.1 pide UTF-8 (`ensure_ascii=False`). |
| 6 | Bajo | Las comparaciones estrictas de `company_id` suponían enteros. | Cast `'company_id' => 'integer'` en `User` e `Integration`; `kpiCompanyError(?int)` solo recibe `idOf()` o ese entero. |
| 7 | Bajo | Huecos de prueba. | La prueba de administradores guarda el grupo como de seguridad (solo falla el rol); nueva prueba de inicio con los grupos de administradores y de Gerencia: `super_admin` y `portal_admin` sobreviven cuando `management` entra y sale. |
| 8 | Seguridad (bajo el umbral) | Cambiar `management_group_id` no pedía confirmar con Microsoft. | Como el inquilino: pide un inicio con Microsoft de los últimos 10 minutos y se niega a la cuenta de emergencia; guardar el mismo valor no pide nada. |

Respuestas del coordinador: `as_of` futuro, máximo un día; revocar sigue borrando las cifras; el manual §2.7 gana una línea sobre `kpis:write` que remite a §11.

Evidencia:

- **R1a** (commit `fa93bb0`, puntos 1 a 5). RED: `KpisApiTest` 56 pruebas, 14 fallan. GREEN: 56/56; `tests/Feature/Api`, `Audit`, `Management` e `IntegrationsPageTest`: 246/246.
- **R1b** (puntos 6 a 8 y documentos). RED: 68 pruebas (`CompanyManagementGroupTest`, `ManagementPanelTest`, `IntegrationsPageTest`, `ManagementRoleTest`), 5 fallan (4 fallos, 1 error). Las dos pruebas del punto 7 pasan desde el principio: cubren lo que ya funcionaba. GREEN: 68/68.
- **Cierre R1.** Suite completa: 2371/2371. `vendor/bin/pint --test`: pasa.

## Seguimientos

- `WorkItemController` (`due_at` de las tareas) tiene el mismo hueco del punto 1: acepta años fuera del rango de `TIMESTAMP` de MySQL. Está fuera de esta línea y no se cambió.

## Siguiente paso

La línea del inicio conecta el bloque: `app(ManagementPanel::class)->forViewer($user)` (null si la cuenta no es Gerencia de su empresa o no hay cifras) y `ManagementKpi::ratio()` para el medidor. Push, PR y despliegue son decisión humana.

Engram mirror: `odd/panel-gerencial/tasks` (proyecto `portal_ryr`).
