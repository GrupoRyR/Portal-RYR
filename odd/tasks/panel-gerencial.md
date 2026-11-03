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
- [ ] T4. **Modelo de lectura y permiso.** Ruta: delegada (mismo escritor). `ManagementPanel`, `ManagementSnapshot`, `ManagementKpi`, `ManagementPanelPolicy`, `AppServiceProvider`. `docs/despliegue.md` §1.19 y `docs/manual-administracion.md` §11. Pruebas: `tests/Feature/Management/ManagementPanelTest.php`.

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
- **T3.** RED: `KpisApiTest` 45 pruebas (29 casos de validación incluidos), 45 fallan (la ruta respondía 404). GREEN: 45/45. `tests/Feature/Api`, `Audit` e `IntegrationsPageTest`: 222/222.

Engram mirror: `odd/panel-gerencial/tasks` (proyecto `portal_ryr`).
