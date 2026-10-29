# Cuentas vinculadas: una persona, una cuenta por empresa

Plan aprobado por Jhon el 2026-10-28, después de que el gerente aprobara el prototipo del nuevo inicio (https://claude.ai/artifact/V6aNj3e3ycJDz4fK4g2oFQ). Esta es la fase 1 del nuevo inicio. Las demás fases (base del inicio, anuncios y eventos, personas, contenido externo, integraciones, asistente, avisos) tendrán su propio documento.

## Objetivo

Que una persona con una cuenta de Microsoft en cada empresa del grupo cambie de empresa con un clic desde el encabezado. Ejemplo:

- `edward.ramanan@segurex.com`
- `edward.ramanan@derivadoscol.com`
- `edward.ramanan@<futura empresa>`

Debe funcionar con cualquier número de empresas, sin código nuevo por empresa.

## Problema y por qué

- Hoy cada cuenta es una fila aparte de `users`, con `UNIQUE(tenant_id, azure_oid)` y una sola empresa.
- Para pasar de Segurex a SDC hay que cerrar la sesión y volver a entrar, y Microsoft suele entrar solo con la última cuenta usada.
- El gerente quiere que el cambio sea rápido.

## Diseño (regla de oro)

- **Vincular nunca da una sesión.** Cambiar de empresa siempre es un inicio de sesión real de Microsoft con la cuenta de destino: silencioso con `prompt=none` + `login_hint`, y con un solo reintento interactivo si hace falta.
- **`person_id` nunca autoriza nada.** Roles, grupos, favoritos y anuncios siguen siendo de cada cuenta.
- **Vincular exige dos pruebas:** una reautenticación reciente de la cuenta actual y el inicio de sesión en la otra cuenta. Nunca se vincula por correo ni por nombre.
- **Un rechazo no deja rastro:** no crea filas ni vínculos.

## Alcance

Personas y vínculos, flujo de vincular, flujo de cambiar, selector en el encabezado, desvincular (perfil y comando), auditoría, aviso por correo, documentación de administración y la corrección de `TenantDirectory::resolveCompany` en inquilinos compartidos.

## Fuera de alcance

- Sección Admin › Usuarios (se usa el comando `portal:unlink-account`).
- Contenido unificado por persona, como tareas de todas las empresas (fase de integraciones).
- El resto del nuevo inicio.

## Restricciones

- No hay cola ni procesos largos: el correo sale al instante.
- No se guardan tokens de Microsoft.
- Las pruebas nunca usan la red (`Http::fake`).
- Las pruebas corren con `APP_KEY` en el entorno, porque `.env*` está bloqueado.
- No se agregan variables nuevas a `.env`.
- Commits convencionales sin atribución de IA.

## Tareas

- [x] T1. **PR1, bases del callback.** Ruta: delegada (escritor), porque son 2 o más archivos no triviales: controlador, `MicrosoftLogin`, `RecentMicrosoftLogin`, middleware, migración y pruebas.
  - `AccountIntent` de un solo uso, ligado al `state`, al usuario y a 600 s.
  - Orden del callback: consentimiento › `error` › intención › reautenticación › inicio.
  - `MicrosoftLogin::admit()` separado del registro.
  - Confirmación reciente ligada al usuario.
  - `users.microsoft_login_hint`.
- [x] T2. **PR2, personas y vinculación.** Ruta: delegada (escritor), porque son 2 o más archivos no triviales: migraciones, `LinkedAccounts`, controladores, vista, aviso y pruebas.
  - `people`, `users.person_id` y `UNIQUE(person_id, company_id)`.
  - `LinkedAccounts::link/unlink`.
  - GET y POST `/cuenta/vincular`.
  - Desvinculación automática con el motivo `company_changed`.
  - Corrección de `resolveCompany`.
  - Eventos de `SecurityLog` y aviso `LinkedAccountNotice`.
- [x] T3. **PR3, cambio de empresa.** Ruta: delegada (escritor), porque son 2 o más archivos no triviales: controlador nuevo, callback, `LinkedAccounts`, auditoría y pruebas.
  - POST `/cuenta/cambiar/{user}` con intención `switch`, `prompt=none` y un reintento.
  - Secuencia de sesión: invalidar, iniciar sesión, nuevo token CSRF, `Clear-Site-Data` y redirigir a `/`.
  - Eventos `account.switched` y `account.switch_failed`.
- [ ] T4. **PR4, interfaz y desvinculación.** Ruta: delegada.
  - Componente `account-switcher`: botones hasta 3 empresas y menú desde 4.
  - Página `/cuenta`.
  - Comando `portal:unlink-account`.
  - `docs/manual-administracion.md` y `docs/estado-del-proyecto.md`.

## Criterios de aceptación

- Una persona vincula N cuentas de N empresas activas y cambia entre ellas desde el encabezado.
- Las cuentas de invitado (B2B) y las de emergencia no se pueden vincular. Tampoco la misma cuenta, una empresa ya vinculada ni una cuenta de otra persona.
- Un cambio cuya respuesta de Microsoft no es la cuenta de destino deja la sesión como estaba.
- Una cuenta desactivada en Microsoft no deja cambiar.
- Una confirmación reciente no pasa de una cuenta a otra.
- Ninguna política ni regla de acceso lee `person_id`.

## Chequeos

- `APP_KEY=base64:$(php -r 'echo base64_encode(random_bytes(32));') php artisan test --compact`, con la suite completa en verde.
- `vendor/bin/pint --test`.
- `/code-review` y `/security-review` en cada PR.
- Prueba manual de Jhon con dos cuentas reales antes de fusionar PR3 y PR4:
  - cambio silencioso;
  - cambio con MFA;
  - cuenta desactivada.

## Entrega

- **Estrategia:** PR encadenados a `main` (stacked-to-main), según la convención del repositorio. Cada PR puede llegar a `main` solo.
- **Ramas y PR.** T1 salió con 857 líneas, así que se partió en dos PR:
  - `feat/cuentas-vinculadas-1-confirmacion`: commits `49efaf4` y `5164e0a` (308 líneas).
  - `feat/cuentas-vinculadas-1-callback`: el commit de intenciones, la corrección de auditoría y este documento (~570 líneas, de las cuales 120 son este documento y ~250 son pruebas). Lleva la nota `size:exception`.
  - PR #61 (`-1-confirmacion`) y PR #62 (`-1-callback`, encima de #61) se abrieron el 2026-10-29.
  - T2 salió con ~2.525 líneas y se partió en tres PR encadenados, todos con `size:exception`:
    - PR #63 `feat/cuentas-vinculadas-2a-personas` (`ab678de`), base `-1-callback`.
    - PR #64 `feat/cuentas-vinculadas-2b-vincular` (`61080ad`), base `-2a-personas`.
    - PR #65 `feat/cuentas-vinculadas-2c-ajustes` (`93573e4` en adelante), base `-2b-vincular`. Las correcciones de revisión del código de 2a y 2b van aquí.
  - Después vienen `-3-cambio` y `-4-interfaz`.
- **Pronóstico:** ~1.600 líneas, unas 400 por PR.
- **Revisión nativa (RDD):** apagada (global: off). Se usan los chequeos ordinarios.

## Progreso

- 2026-10-28: plan aprobado. Rama `feat/cuentas-vinculadas-1-callback` creada desde `origin/main` (`f0ed83c`).
- 2026-10-28: T1 hecha (ruta delegada, un escritor). Sin `push` ni PR.
  - Base: `tests/Feature/Auth` + `tests/Feature/Admin` con 373 de 373 en verde.
  - RED: 393 pruebas, 355 en verde, 25 fallas y 13 errores. Eran las pruebas nuevas y las que guardaban la confirmación como un número suelto.
  - GREEN, Auth + Admin: 393 de 393 (1.962 aserciones).
  - GREEN, suite completa: 1.041 de 1.041 (5.263 aserciones). Son 20 pruebas nuevas.
  - `vendor/bin/pint --test`: sin cambios.
  - Commits (cada uno en verde por separado: Auth + Admin y `pint --test`):
    - `49efaf4` `refactor(auth)`: confirmación reciente ligada al usuario.
    - `5164e0a` `feat(auth)`: `admit()` y `microsoft_login_hint`.
    - `feat(auth)`: intenciones y `error` de Microsoft en el callback. Este registro va en ese commit.
  - Corrección de revisión: un `error` de Microsoft con sesión iniciada siempre vuelve a `/`. La página de la reautenticación pendiente está detrás de `microsoft.recent` y mandaría al usuario de nuevo a Microsoft.
  - `/code-review` (alto) del coordinador encontró 4 cosas:
    1. Una reautenticación que termina en `error` ya no quedaba en Auditoría. **Corregido:** registra `reauth.failed`. RED observado sin la corrección, GREEN con ella; 113 de 113 en Auth.
    2. `/` todavía no muestra el mensaje `status`. Va en el PR4, que toca el inicio.
    3. La rama de intenciones y `bindState()` no se usan hasta PR2 y PR3. Queda anotado en el PR.
    4. Ya pasaba antes: en las cuentas de invitado (B2B), la pista de inicio de sesión apunta a su inquilino de origen, y la reautenticación puede cerrar la sesión. Seguimiento: mandar `domain_hint` o la autoridad del inquilino en una fase posterior.
  - Riesgo según `gentle-ai review assess`: alto (ruta de autenticación). RDD apagado, así que la revisión independiente fue `/code-review`.
- 2026-10-29: PR #61 y #62 abiertos.
- 2026-10-29: T2 hecha (ruta delegada, un escritor), en `feat/cuentas-vinculadas-2-personas`. Sin `push` ni PR.
  - Base: `tests/Feature/Auth` + `Tenancy` + `Admin` con 412 de 412 en verde.
  - RED antes de cada parte (las fallas eran solo las pruebas nuevas):
    1. Datos y reglas: 109 pruebas, 82 en verde, 1 falla y 26 errores.
    2. Flujo, aviso y auditoría: 51 pruebas, 26 en verde, 19 fallas y 6 errores.
    3. `resolveCompany`: 69 pruebas, 66 en verde y 3 fallas (el error de hoy).
    4. Desvinculación automática: 4 pruebas, 1 en verde, 2 fallas y 1 error. Hoy ese inicio de sesión fallaba por el índice único.
  - GREEN, Auth + Tenancy + Admin + Account + `tests/Unit/Auth`: 509 de 509.
  - GREEN, suite completa: 1.139 de 1.139 (5.746 aserciones). Son 97 pruebas nuevas.
  - `vendor/bin/pint --test`: sin cambios.
  - Commits (cada uno en verde por separado en Auth + Tenancy):
    - `ab678de` `feat(accounts)`: personas, `LinkedAccounts` y la marca de invitado.
    - `61080ad` `feat(accounts)`: GET y POST `/cuenta/vincular`, el callback `link`, el aviso y la auditoría.
    - `93573e4` `fix(tenancy)`: `resolveCompany` en inquilinos compartidos.
    - `feat(accounts)`: desvinculación automática con `company_changed`. Este registro va en ese commit.
  - Decisiones:
    - `users.is_guest` (nuevo). Hoy el código solo sabe si es invitado al iniciar sesión (claim `idp`). Se guarda en cada inicio y en cada reautenticación confirmada. Null es «no se sabe» y se rechaza como invitado.
    - `MicrosoftLogin::preview()` corre las comprobaciones de `admit()` sin escribir. El callback valida todo con ella y después hace `admit()` + `link()` en una sola transacción.
    - Una prueba de `TenantDirectory` afirmaba el error; se cambió a lo correcto.
    - Motivos de rechazo: los de `LinkRefused` y los de `LoginFailed` (por ejemplo `tenant_not_allowed`).
  - Seguimiento:
    - `/` aún no muestra el mensaje `status` (PR4).
    - La vista 403 `errors.microsoft-required` habla de crear empresas. Falta un texto general.
    - `preview()` no simula la desvinculación automática. Si la otra cuenta cambió de empresa, puede salir `different_people` aunque `admit()` la habría desvinculado. Es un rechazo seguro.
  - Tamaño: ~2.190 líneas (~950 de código con comentarios, ~1.240 de pruebas). Supera las 400: se puede partir en un PR por commit.
  - Corrección de revisión (2026-10-29). Una revisión independiente no halló defectos graves; se corrigió:
    1. Los roles que `admit()` cambia en la otra cuenta al vincular ahora quedan en Auditoría (`roles.synced`).
    2. Un error de base de datos durante la lectura de Graph ya no se toma por una falla de Graph: se propaga. En MySQL, un bloqueo mutuo ya deshizo la transacción.
    3. La regla «empresa ya vinculada» es una lectura con bloqueo, y una violación del índice único se rechaza como `company_already_linked`. La prueba de carrera ahora pasa por el índice, sin sustituir `link()`.
    4. `link()` y `unlink()` bloquean las cuentas en el mismo orden (por id).
    5. Al disolver una persona, su última cuenta también registra `account.unlinked`.
    6. `resolveCompany` decide entre todas las empresas del inquilino (dominio y luego principal). Si gana una inactiva, devuelve null. Así, una empresa principal desactivada ya no manda sus usuarios a la otra.
    7. Textos: «ya está vinculada con otras cuentas» en Auditoría y un motivo propio, `guest_unknown`, con «Confirma de nuevo tu cuenta y vuelve a intentarlo.».
    8. La prueba de guarda revisa todo `app/` y `routes/` (sin comentarios), salvo una lista explícita de archivos.
    - No se cambió: `LinkRefused::NOT_MICROSOFT` sí se alcanza desde `link()` con una cuenta local, y hay una prueba de eso.
    - Quedan anotados por el coordinador: el tiempo del correo síncrono en el callback y el texto de `errors.microsoft-required`.
    - RED antes de cada parte: roles, errores, carreras y bitácora (234 pruebas, 226 en verde, 6 fallas y 2 errores); `resolveCompany` (22, 21 en verde y 1 falla); textos (59, 56 en verde, 1 falla y 2 errores); la guarda falló con un archivo de prueba que leía `person_id`.
    - GREEN, suite completa: 1.249 de 1.249 (5.874 aserciones). `pint --test`: sin cambios.
    - Commits: `c4a6eaf` `fix(accounts)`, `5bddccb` `fix(tenancy)` y `fix(accounts)` de textos y guarda, que lleva este registro.
- 2026-10-29: PR #63, #64 y #65 abiertos (T2).
- 2026-10-29: #61 a #65 fusionados en `main` (`54cb72c`).
- 2026-10-29: T3 hecha (ruta delegada, un escritor), en `feat/cuentas-vinculadas-3-cambio` desde `origin/main` (`54cb72c`). Sin `push` ni PR.
  - RED: `tests/Feature/Account/SwitchAccountTest.php` nueva, 38 pruebas: 13 en verde, 9 fallas y 16 errores. Las 13 en verde eran los 10 casos de 404 (la ruta aún no existía, así que también daba 404) y los 3 de intenciones inservibles, que T1 ya rechazaba.
  - GREEN: `SwitchAccountTest` 38 de 38. Account + Auth + `tests/Unit/Auth`: 355 de 355 (1.538 aserciones).
  - GREEN, suite completa: 1.288 de 1.288 (6.348 aserciones). Son 39 pruebas nuevas (38 y la entrada nueva de la guarda).
  - `vendor/bin/pint --test`: sin cambios.
  - Commits:
    - `252e082` `feat(accounts)`: cambio de empresa con un inicio de sesión de Microsoft.
    - `docs(odd)`: este registro.
  - Qué hace:
    - POST `/cuenta/cambiar/{user}` (`account.switch`, `auth`, CSRF, `throttle:20,1`). Responde 404 si la cuenta no es otra de la misma persona, si su empresa no está activa, si es de invitado (o no se sabe), de emergencia o local, o si la actual es de emergencia. Si no, inicia la intención `switch` (intento 1) y manda a Microsoft con `prompt=none` y `login_hint` (o el correo).
    - Callback, `error`: con `login_required`, `interaction_required`, `consent_required` o `account_selection_required` en el intento 1, un solo reintento con `prompt=login` (intento 2). Cualquier otro error, o el intento 2, deja la sesión y registra `account.switch_failed` con el código limpio. Las demás intenciones y la reautenticación siguen como en T1.
    - Callback, éxito: solo vale la cuenta de destino (mismo `tid` y `oid`). Luego `MicrosoftLogin::handle()` (comprobaciones, perfil, grupos, roles, `last_login_at` y `login.microsoft`). Después: invalidar la sesión, `Auth::login`, nueva sesión y token CSRF, `Clear-Site-Data: "cache"` y `/`. `PortalUserKey` ya nombra al usuario nuevo en esa respuesta, sin cambios. Registra `account.switched` (de y hacia: cuenta y empresa).
    - Otra cuenta (`wrong_account`), una cuenta que dejó de servir (`target_unavailable`) o una comprobación fallida (por ejemplo `missing_email`) no cambian la sesión ni ninguna fila. La excepción es la misma que en un inicio de sesión normal: un error de infraestructura después de que `admit()` guarda la cuenta (Graph, roles, `last_login_at` o la auditoría) deja la sesión y registra `provider_error`, pero la fila de destino queda actualizada con sus datos reales de Microsoft. `handle()` no va en una transacción a propósito, porque la dejaría abierta durante las llamadas a Graph.
  - Decisiones:
    - La regla «otra cuenta de la misma persona» vive en `LinkedAccounts::canSwitch()`. `SwitchAccountController` entra en la lista de la guarda solo para llamarla; el comentario de la prueba dice por qué.
    - Un cambio silencioso no es una confirmación reciente: solo se confirma si `RecentMicrosoftLogin::verify()` da `confirmed` (`auth_time` reciente), como en la reautenticación. El inicio de sesión normal sigue contando como confirmación (decisión de T1).
    - La causa de `account.switched` y `account.switch_failed` es la cuenta de la que se sale; el sujeto es la de destino.
    - Textos: «No pudimos cambiar de empresa. Sigues en tu cuenta actual.» y «Microsoft respondió con otra cuenta. Sigues en tu cuenta actual.». Al cambiar: «Ahora estás en tu cuenta de …».
  - Seguimiento:
    - Autoridad del inquilino de destino: no se pudo. El proveedor de Microsoft toma el inquilino de su configuración para la autorización y para el token, así que hoy no se elige por solicitud. Se sigue usando `organizations` con `login_hint`. Va con el seguimiento de `domain_hint` de T1.
    - `regenerate()` y `regenerateToken()` van después de `invalidate()` + `Auth::login`, como pide el diseño. La prueba ve que cambian el ID y el token, pero no puede ver cada llamada por separado: quitar una sola no hace fallar la prueba.
    - `/` aún no muestra el mensaje `status` (PR4).
  - Tamaño: ~975 líneas (~340 de código con comentarios, ~635 de pruebas). Supera las 400 por las pruebas; el código solo queda cerca. Se puede abrir como un PR con `size:exception`.
- 2026-10-29: la revisión independiente de T3 salió bien, con un solo hallazgo bajo: un error de infraestructura después de `admit()` puede dejar la fila de destino actualizada. Se documentó en `switchAccount()` y en este registro, sin cambiar el comportamiento. Commit `docs(accounts)`: lo que un cambio fallido puede dejar actualizado.

## Siguiente paso

Revisar T3 (`/code-review` y `/security-review`), abrir el PR de `feat/cuentas-vinculadas-3-cambio` a `main` y hacer la prueba manual con dos cuentas reales (cambio silencioso, con MFA y con una cuenta desactivada). Después, delegar el escritor del T4.
