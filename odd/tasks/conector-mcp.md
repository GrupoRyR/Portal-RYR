# Conector MCP del portal para Claude

Jhon, que es el líder de IA del grupo, lo aprobó el 2026-10-30.

## Objetivo

Que cada persona conecte su cuenta de Claude, incluso una personal (por ejemplo `ti.segurex@gmail.com`), al portal como «conector propio» (MCP remoto). Así Claude responde con los datos que esa persona ya ve en el portal: sus tareas y aprobaciones, los anuncios sin leer, las próximas fechas, el directorio y las apps. Debe funcionar con todas sus cuentas de Microsoft vinculadas (por ejemplo `jhon.castro@segurex.com` y `derivados.info@derivadoscol.com`).

## Cómo funciona

- El portal no guarda ni conoce el correo de Claude. Lo que se vincula es una **conexión**: Claude pide permiso al portal por OAuth 2.1 con PKCE, y el portal emite un token.
- **Al conectar, la persona entra con Microsoft en cada cuenta que quiere incluir.**
  - Para la primera basta su inicio de sesión normal.
  - Las vinculadas se confirman una por una, igual que el cambio de empresa: `prompt=none` con `login_hint` y, si hace falta, contraseña o MFA.
  - El token guarda la **lista explícita** de cuentas confirmadas.
  - `person_id` nunca autoriza: solo sirve para ofrecer qué cuentas confirmar.
- **En cada llamada**, el portal revisa que cada cuenta del token siga activa, que su empresa siga activa y que no sea invitada ni de emergencia. Una cuenta desvinculada o desactivada sale sola.
- **Lo que devuelve cada herramienta** se etiqueta por empresa («Segurex», «Derivados»). Cada empresa ve sus datos con sus propias reglas, igual que en el portal.

## Alcance (primera versión: solo lectura)

- **Herramientas:**
  - `mis_tareas` y `mis_aprobaciones`, desde `work_items`;
  - `anuncios_pendientes` y `anuncio` (el texto de uno);
  - `proximas_fechas`: festivos, eventos y fechas anuales;
  - `cumpleanos_y_aniversarios`;
  - `buscar_personas`;
  - `mis_apps`.
- **`/cuenta` › «Conexiones con Claude»:** muestra qué conexiones existen, con qué cuentas y cuándo se usaron, con un botón «Desconectar» para cada una.
- **Admin:** un interruptor que apaga el conector para todo el portal y una lista de conexiones activas.
- **Auditoría:** `mcp.connected`, `mcp.disconnected` y una línea por llamada (herramienta, cuenta y hora), sin guardar el contenido.

## Fuera de alcance (después)

- Acciones: «Confirmar lectura» y «Completar» tarea.
  - Pedirían confirmación en pantalla.
  - «Completar» usaría la llamada firmada a la app de tareas.
- Claude Duet: cuando esté disponible en Colombia, usa este mismo conector.

## Restricciones

- **Paquetes:** `laravel/mcp` y `laravel/passport` (verificado en C1, ver «Diseño OAuth»). Passport convive con Sanctum: `/api/v1` no cambia.
- **Redirecciones:** solo `https://claude.ai/api/mcp/auth_callback` (la única que usa claude.ai). Claude Code y Desktop con `localhost` quedan fuera de la primera versión.
- **Tokens:** cortos, con renovación; desconectar los revoca todos.
- **Límites:** por conexión. El inicio `/` no cambia.
- **Datos:**
  - Nunca se devuelve el celular personal, el año de nacimiento, ni cumpleaños sin la casilla «mostrar».
  - Nunca se devuelven datos de una empresa que no está entre las cuentas del token.
- **Riesgo aceptado por Jhon como líder de IA (2026-10-30):** los datos pasan por cuentas personales de Claude. Se recomienda que cada persona apague en Claude «Ayudar a mejorar Claude» (Configuración › Privacidad), y el manual lo dirá.
- **Pruebas:** `Http::fake`, nunca red real.

## Diseño OAuth (C1, verificado el 2026-10-30)

Ruta: investigación delegada (solo lectura) y documento escrito por el coordinador. Fuentes al final de esta sección.

### Lo que hay hoy

- Laravel 13.34, PHP 8.3 y Sanctum 4.3.3 (de `composer.lock`).
- `config/auth.php` solo tiene el guardia `web`. `config/sanctum.php` tiene `guard => []` y `expiration => null`.
- `/api/v1` usa `auth:sanctum` y el middleware `integration`.
- **El `HasApiTokens` de Sanctum está en `App\Models\Integration`, no en `User`.** Por eso el `HasApiTokens` de Passport puede ir en `User` sin choque de métodos (los dos traits definen `tokens()`, `createToken()`, `currentAccessToken()` y otros).

### Versiones

- `laravel/mcp` v1.1.2: PHP ^8.2, Laravel ^11.45.3, ^12.41.1 o ^13.
- `laravel/passport` v13.10.0: PHP ^8.2, Laravel ^11.35, ^12 o ^13.

### Cómo se arma

- **Instalación:** `composer require laravel/mcp laravel/passport`. No se usa `install:api --passport`, porque `routes/api.php` ya existe. Se publican las migraciones de Passport y las llaves salen de `PASSPORT_PRIVATE_KEY` y `PASSPORT_PUBLIC_KEY` en producción (o `passport:keys` fuera de `public/`).
- **`config/auth.php`:** solo se agrega `'api' => ['driver' => 'passport', 'provider' => 'users']`. `web` y Sanctum no cambian.
- **`User`:** `implements OAuthenticatable` y `use Laravel\Passport\HasApiTokens`. `Integration` no cambia.
- **`routes/ai.php`** (sin CSRF):
  - `Mcp::oauthRoutes()` dentro de `throttle`. Registra `/.well-known/oauth-protected-resource`, `/.well-known/oauth-authorization-server` y `POST /oauth/register` (registro dinámico de clientes, que es lo que usa Claude porque el paquete no anuncia CIMD).
  - `Mcp::web('/mcp/portal', PortalServer::class)->middleware(['auth:api', 'throttle:mcp'])`. La URL del conector es `https://apps.segurexlatam.net/mcp/portal`, y el `resource` de los metadatos debe ser exactamente esa.
- **`config/mcp.php`:** `redirect_domains => ['https://claude.ai']` (por defecto acepta cualquier dominio: hay que cerrarlo) y `custom_schemes => []`.
- **Tokens (`AppServiceProvider`):** acceso de 1 hora y renovación de 30 días (por defecto Passport da un año). La renovación rota en cada uso (`revokeRefreshTokenAfterUse`, ya activo), como pide Claude.
- **Consentimiento:** la vista de Passport (`Passport::authorizationView`) con la marca del portal, el nombre del cliente y el dominio de la redirección. Quien no tiene sesión va al inicio de sesión de Microsoft y vuelve con `intended()` a `/oauth/authorize`. Passport es el servidor OAuth; Entra solo inicia la sesión del portal.
- **Limpieza:** `passport:purge` diario en `routes/console.php`.

### Las cuentas de la conexión

Passport da un token a **una** cuenta: la que aprobó. Las demás cuentas se guardan aparte:

- Tabla `mcp_connections`: el cliente de Passport, la cuenta que aprobó, cuándo se creó y cuándo se usó.
- Tabla `mcp_connection_accounts`: las cuentas confirmadas con Microsoft al conectar (la misma confirmación del cambio de empresa: `prompt=none` con `login_hint` y, si hace falta, contraseña o MFA).
- **En cada llamada**, una cuenta de la lista cuenta solo si sigue activa, su empresa sigue activa, no es invitada ni de emergencia y sigue vinculada a la cuenta que aprobó. Esta última revisión solo **quita** cuentas: `person_id` nunca agrega una cuenta que no se confirmó.
- Desconectar borra la conexión y revoca sus códigos de autorización y sus tokens de acceso y de renovación.

### Riesgos

- **El registro de clientes es público:** cada intento crea una fila en `oauth_clients`. Se mitiga con `throttle` (10 por minuto por dirección), con aceptar solo el regreso exacto de claude.ai y con `portal:purge-mcp-clients`, que cada día borra los clientes de más de un día sin conexión. `passport:purge` no borra clientes: solo tokens y códigos revocados o vencidos.
- **Phishing de consentimiento:** cualquiera puede registrar un cliente con el regreso de claude.ai y enviar el enlace de aprobación. La pantalla lo advierte («Aprueba solo si acabas de agregar el conector en tu propio Claude…») y muestra cuándo se registró el cliente; el código solo vuelve a claude.ai, a la cuenta de Claude de quien inició el flujo.
- **Reutilizar un token de renovación no se detecta** (comportamiento de Passport): un token de renovación usado dos veces solo falla la segunda vez; no revoca toda la familia de tokens. Se acepta: el token rota en cada uso, dura 30 días y desconectar revoca todos.
- **Passport no valida el parámetro `resource`** (RFC 8707), así que el token no queda atado al conector. Se acepta porque en el portal Passport solo sirve al conector (alcance único `mcp:use`).
- **`/.well-known/*` en cPanel:** AutoSSL o ModSecurity podrían interceptarlo. Jhon lo prueba con `curl` después del deploy.
- **Permisos de las llaves:** league/oauth2-server solo avisa si no son 600 o 660. En cPanel el proceso web corre como el usuario de la cuenta, así que 600 debería servir. Se prueba en el servidor.
- **Sin streaming:** las herramientas devuelven una respuesta normal (JSON), no un generador. Así no se abre SSE, que en Apache o LiteSpeed compartido podría cortarse.
- **Las herramientas de tareas necesitan `work_items`**, que está en #74, otra cadena desde `main`. C3 parte de una rama que tenga #74 y la cadena del inicio, o espera a que se fusionen.

### Fuentes

- https://laravel.com/docs/13.x/mcp
- https://laravel.com/docs/13.x/passport
- https://packagist.org/packages/laravel/mcp
- https://packagist.org/packages/laravel/passport
- https://github.com/laravel/mcp/blob/main/src/Server/Http/Controllers/OAuthRegisterController.php
- https://github.com/laravel/mcp/blob/main/config/mcp.php
- https://github.com/laravel/passport/blob/13.x/src/Passport.php
- https://github.com/laravel/sanctum/blob/4.x/src/HasApiTokens.php
- https://claude.com/docs/connectors/custom
- https://claude.com/docs/connectors/building/authentication.md

## Tareas

- [x] C1. **Verificar** la versión de `laravel/mcp` y Passport, y su convivencia con Sanctum, en la documentación oficial. Escribir el diseño de OAuth en este documento.
- [x] C2. **Servidor OAuth y conexión:** consentimiento, confirmación de cada cuenta con Microsoft y token con la lista de cuentas.
  - [x] C2a. **OAuth, consentimiento y conexión con la cuenta que aprueba:** paquetes, interruptor, registro de clientes solo para claude.ai, pantalla de consentimiento, `mcp_connections`, revisión de cuentas en cada llamada y la herramienta de prueba `quien_soy`. Commits `5648b31` y, tras la revisión, `46e57c7` (C2a-R).
  - [x] C2b. **Cuentas vinculadas en la conexión:** confirmar cada cuenta vinculada con Microsoft (`prompt=none` con `login_hint`) durante el consentimiento y guardarla en `mcp_connection_accounts`. Commit `c773266`.
- [ ] C3. **Herramientas de solo lectura** con su visibilidad y pruebas.
  - [x] C3a. **Anuncios, fechas, cumpleaños, personas y apps:** `anuncios_pendientes`, `anuncio`, `proximas_fechas`, `cumpleanos_y_aniversarios`, `buscar_personas` y `mis_apps`. Commit `c6afd73` y, tras la revisión, `e6d30a0` (C3a-R).
  - [ ] C3b. **Tareas y aprobaciones:** `mis_tareas` y `mis_aprobaciones`, desde `work_items`. Espera a que #74 esté en la base.
- [x] C4. **`/cuenta` y Admin:** «Conexiones con Claude», el interruptor general y Auditoría. Commit `5f4dde3`.
- [ ] C5. **Manual:** cómo conectar Claude (Configuración › Conectores › Agregar conector propio con la URL del portal), y la recomendación de privacidad.

## Registro del C2a

Ruta: escritor delegado (más de dos archivos no triviales), en `feat/conector-mcp-1-oauth` sobre `docs/conector-mcp`. Commit `5648b31` (`feat(mcp): connect Claude to the portal over OAuth (C2a)`).

### Qué quedó

- **Paquetes:** `laravel/mcp` v1.1.2 y `laravel/passport` v13.10.0 (con `league/oauth2-server` 9.4.1). Migraciones de Passport publicadas; `config/mcp.php` publicado con `redirect_domains => ['https://claude.ai']`, `custom_schemes => []` y sin clientes MCP de ejemplo. `config/passport.php` no se publicó: basta el de Passport, que ya lee `PASSPORT_PRIVATE_KEY` y `PASSPORT_PUBLIC_KEY`.
- **Interruptor:** `PORTAL_MCP_ENABLED` (por defecto apagado) en `config('portal.mcp.enabled')`, leído solo por `App\Mcp\McpSwitch` y el middleware `mcp.enabled`. Apagado, todo `routes/ai.php` responde 404, incluido `GET /mcp/portal`.
- **Rutas (`routes/ai.php`):** `Mcp::oauthRoutes(registration: false)` y `POST /oauth/register` propio, con `throttle:mcp-oauth` (registro 10 por minuto y por IP; descubrimiento 60). De Passport solo `POST /oauth/token`, `GET`, `POST` y `DELETE /oauth/authorize` (`Passport::ignoreRoutes()`): sin tokens personales, API de clientes, flujo de dispositivo ni `/oauth/token/refresh`. `/mcp/portal` con `auth:api`, `mcp.connection` y `throttle:mcp` (120 por minuto por conexión).
- **Redirecciones:** el prefijo de `laravel/mcp` ya rechaza `https://claude.ai.evil.com` (le agrega `/` al dominio), pero acepta cualquier ruta de claude.ai. Por eso `RegisterClientController` exige antes que **cada** URI sea exactamente `https://claude.ai/api/mcp/auth_callback` (`App\Mcp\ClaudeRedirect`); si no, 400 `invalid_redirect_uri` y no se crea el cliente.
- **Consentimiento:** `resources/views/mcp/authorize.blade.php` con la marca del portal, sin selector de cuenta: cliente, dominio de regreso, lista de solo lectura, cuenta (nombre, correo, empresa), «Aprobar» y «Denegar». Se muestra **siempre** (`AuthorizeController` anula `hasGrantedScopes`), para que todo código pase por el paso que registra la conexión. Sin sesión, Passport manda a `/login` y `intended()` vuelve a `/oauth/authorize`. Quien no puede conectar (invitada, sin confirmar, de emergencia, sin identidad de Microsoft o de empresa inactiva) ve `mcp/refused.blade.php` (403) y no recibe código; la aprobación también exige que la cuenta sea la misma a la que se le mostró la pantalla.
- **Conexión:** `ApproveController` (subclase del de Passport) emite el código y llama a `App\Mcp\Connections::approved()` en la misma transacción: crea o reutiliza la conexión activa del cliente y la cuenta, deja como única cuenta la que aprobó y escribe `mcp.connected` (registro `security`, etiqueta en `AuditPresenter`). Se eligió el paso de aprobación y no el evento `AccessTokenCreated` porque ese evento también se dispara al renovar el token y podría revivir una conexión revocada.
- **En cada llamada (`EnsureMcpConnection`):** el token debe pertenecer a una conexión activa de su cliente y su cuenta (si no, 401, como un token inválido); si la cuenta que aprobó ya no puede conectar, 403. `ConnectionAccounts::for()` deja en la petición las cuentas usables; solo quita cuentas (`LinkedAccounts::stillLinked()`, con una entrada razonada en `PersonNeverAuthorizesTest`). `last_used_at` se escribe como mucho una vez por minuto.
- **Revocar:** `McpConnection::revoke()` marca `revoked_at` y revoca los tokens de acceso y renovación del cliente para esa cuenta (lo usará C4 al desconectar).
- **Servidor:** `PortalServer` («Portal RYR», instrucciones en español) con `quien_soy` (`#[IsReadOnly]`): nombre, correo y empresa de la cuenta que aprobó, nada más.
- **Tokens:** acceso de 1 hora, renovación de 30 días, rotación activa (`$revokeRefreshTokenAfterUse`). `passport:purge` diario a las 4:00 (Bogotá).
- **Despliegue:** `docs/despliegue.md` §1.13 (variables, llaves, permisos, `curl` de comprobación) y dos filas en «Problemas frecuentes».

### Pruebas

- RED (antes de implementar): 62 pruebas en `tests/Feature/Mcp` y `tests/Unit/Mcp`, 1 pasó, 47 fallaron y 14 con error (clases inexistentes).
- GREEN: las 62 pasan, junto con `tests/Unit/Auth` (246 en total en esa corrida).
- Suite completa: 1.755 pruebas, todas pasan (la base era 1.678). `HomeScreenTest` y las pruebas de `/api/v1` siguen igual.
- Las llaves de Passport de las pruebas se generan en memoria una vez por proceso (`phpseclib`); no hay llaves en el repositorio.
- Pint: sin cambios pendientes.

### No hecho o no verificado

- C2b: confirmar las cuentas vinculadas con Microsoft durante el consentimiento.
- No se probó contra claude.ai real ni en cPanel (`/.well-known/` con AutoSSL o ModSecurity, permisos de las llaves): queda para después del despliegue, con los `curl` del §1.13.
- `.env.example` no se tocó (los `.env*` están bloqueados para el agente): faltan `PORTAL_MCP_ENABLED`, `PASSPORT_PRIVATE_KEY` y `PASSPORT_PUBLIC_KEY`.
- No se revisa el alcance `mcp:use` del token en cada llamada: Passport solo sirve al conector y el único alcance que existe es ese.
- Tamaño: unas 910 líneas de código (incluidas vistas, rutas y la migración propia) y 895 de pruebas, sin contar `composer.lock` (779) ni los archivos publicados de los paquetes (migraciones de Passport y `config/mcp.php`, 283). Pasa de la cifra orientativa de 400 porque el servidor OAuth, el consentimiento y la conexión solo funcionan juntos.

## Registro del C2a-R

Ruta: el mismo escritor delegado, después de una revisión independiente del C2a (1 hallazgo medio y 9 bajos). Commit `46e57c7` (`fix(mcp): harden the Claude connector OAuth after review`).

### Qué cambió

- **M1, códigos y tokens de una conexión anterior:** `McpConnection::revoke()` también revoca los códigos de autorización sin usar (`oauth_auth_codes`). Además, cuando se crea una conexión **nueva**, `Connections::approved()` revoca antes todos los códigos y tokens que el cliente tenga para esa cuenta (`McpConnection::revokeGrants()`), y solo después se emite el código nuevo (en `ApproveController` la conexión va ahora antes que el código, en la misma transacción). Así, nada emitido para una conexión anterior funciona en la siguiente. Se eligió revocar y no comparar fechas porque league/oauth2-server fecha los tokens con el reloj real y Eloquent no.
- **L1, PKCE:** `/oauth/authorize` solo acepta `code_challenge_method=S256`; `plain` o sin método responde 400 `invalid_request`, sin pantalla ni sesión.
- **L2, `prompt=login`:** se ignora (`AuthorizeController` lo quita antes de llamar a Passport), así que ningún cliente puede cerrar la sesión del portal de alguien.
- **L3, phishing de consentimiento:** la pantalla muestra «Aprueba solo si acabas de agregar el conector en tu propio Claude. Si no lo pediste, elige Rechazar.» y la fecha de registro del cliente. El botón «Denegar» pasó a «Rechazar» para coincidir con el aviso.
- **L4, clientes sin conexión:** `portal:purge-mcp-clients` (`App\Mcp\StaleClients`, diario a las 4:15) borra los clientes del conector (sin dueño y con el regreso de claude.ai como única dirección) de más de 24 horas que no tienen ninguna fila en `mcp_connections`, con sus códigos y tokens. Un cliente con una conexión, aunque esté revocada, se conserva como historial.
- **L5, tokens inválidos:** el middleware `mcp.failures` (`ThrottleFailedMcpCalls`) va antes de `auth:api` en `/mcp/portal`: más de 30 respuestas 401 por minuto desde una dirección dan 429 a todo lo de esa dirección. Laravel siempre ordena `throttle` después de la autenticación, por eso es un middleware propio. Las llamadas que se autentican no cuentan, así que las conexiones que comparten las direcciones de Claude no se afectan entre sí.
- **L6, dos «Aprobar» a la vez:** la columna `active_key` («cliente|cuenta» mientras está activa, nula al revocar) tiene índice único, válido en MySQL y SQLite. Si el `INSERT` choca, `approved()` reutiliza la conexión que ganó (el `INSERT` va en un *savepoint*). Se editó la migración del C2a porque nada está desplegado.
- **L7, textos:** `routes/console.php` y `docs/despliegue.md` §1.13 ya dicen bien qué borra `passport:purge` (revocados siempre; vencidos con más de 7 días). La línea de `curl -w` usa `\n`. El §1.13 suma la tarea nueva y los límites.
- **L8, interruptor:** `PORTAL_MCP_ENABLED` se lee con `filter_var(…, FILTER_VALIDATE_BOOL)`: `off`, `no`, `false`, `0`, vacío o cualquier otro texto lo dejan apagado.
- **L9:** la reutilización de un token de renovación queda como riesgo aceptado en «Riesgos».

### Pruebas

- RED: 87 pruebas en `tests/Feature/Mcp` y `tests/Unit/Mcp`: 70 pasan, 12 fallan y 5 con error. Las de PKCE con un método desconocido (`S512`) ya pasaban: league lo rechaza solo.
- GREEN: las 87 pasan, junto con `tests/Unit/Auth` (273 en esa corrida).
- Suite completa: 1.782 pruebas, todas pasan (1.755 antes de esta revisión).
- La carrera real de dos `INSERT` no se puede reproducir en SQLite: se prueba que la base rechaza una segunda conexión activa y que una revocada deja lugar a una nueva. La rama que reutiliza la conexión ganadora tras el choque no tiene prueba propia.
- Pint: sin cambios pendientes.

## Registro del C2b

Ruta: escritor delegado (más de dos archivos no triviales), en `feat/conector-mcp-2-cuentas` sobre `feat/conector-mcp-1-oauth` (#82). Commit `c773266` (`feat(mcp): include linked accounts in the Claude connection (C2b)`).

### Qué quedó

- **Oferta:** la pantalla de consentimiento muestra «Las cuentas que usará». La que aprueba va primero, «Siempre incluida». Debajo, las cuentas vinculadas que podrían conectar, según `LinkedAccounts::switchTargets()` (activa, de empresa activa, no invitada, no de emergencia) y `ConnectionAccounts::mayConnect()`. Cada una tiene «Incluir». Las confirmadas muestran «Confirmada a las …» y «Quitar». Vincular solo ofrece: ninguna cuenta entra sin su propio inicio con Microsoft.
- **Confirmar (`App\Mcp\AccountConfirmation`):**
  - «Incluir» es un `POST /oauth/authorize/cuentas/{cuenta}` (`mcp.consent.include`, con CSRF y el `auth_token` de la pantalla). Inicia la intención `mcp_include` (`AccountIntent`, de un solo uso, con su `state`, la misma cuenta y 600 s) y manda a Microsoft con `prompt=none` y el `login_hint` de esa cuenta.
  - Si Microsoft responde `login_required`, `interaction_required`, `consent_required` o `account_selection_required`, se reintenta una sola vez con `prompt=login`, igual que el cambio de empresa.
  - Una cuenta no ofrecida da 404, como en el cambio de empresa, para no revelar qué cuentas están vinculadas.
- **La respuesta de Microsoft** (callback compartido, `MicrosoftLoginController`) solo cuenta si cumple todo esto:
  - Es exactamente esa cuenta (inquilino y `oid`).
  - Pasa las reglas de un inicio: primero `MicrosoftLogin::preview()`, que no escribe nada, más `mayConnect`; luego `admit()`, que refresca su fila con los datos probados, sin registrar un inicio.
  - Sigue vinculada y ofrecida a la que aprueba.
- **Si algo falla:** la sesión sigue en la cuenta que aprueba (nunca se llama `Auth::login`), no se confirma nada y se vuelve a la pantalla con un mensaje en español. Una intención vencida, ajena o de otro `state` también vuelve a la pantalla si sigue pendiente.
- **La autorización pendiente (`App\Mcp\ConsentAccounts`):**
  - Passport guarda en la sesión una sola autorización pendiente: `authRequest` y un `authToken` al azar. Cada `GET /oauth/authorize` los reemplaza; aprobar, rechazar o un `auth_token` distinto los borran.
  - La lista de confirmadas vive en la sesión (`mcp.consent`), atada a ese `authToken` y a una huella del pedido: cliente, cuenta, regreso, `state`, reto PKCE y alcances.
  - Al volver de Microsoft se abre otra vez la misma dirección de `/oauth/authorize`. Passport crea un `authToken` nuevo y la lista pasa a él solo si el anterior seguía vivo y la huella es la misma.
  - Otro pedido, otro cliente u otra cuenta empiezan con la lista vacía.
  - Si la autorización pendiente ya no existe al volver de Microsoft (por ejemplo, se aprobó o rechazó en otra pestaña), sale el error normal de Passport (`InvalidAuthTokenException`, 403), no se confirma nada y nunca hay código.
- **Aprobar:** `ApproveController` toma la lista solo si está atada al `auth_token` que Passport acaba de validar y a la huella de su `authRequest`. `Connections::approved()` guarda, en la misma transacción del código, la cuenta que aprueba y las confirmadas que todavía se pueden usar (`ConnectionAccounts::usable()`), cada una con su `confirmed_at`. Las confirmaciones sin terminar no existen para la lista. Aprobar de nuevo reemplaza la lista: las cuentas no confirmadas otra vez salen.
- **En cada llamada:** sin cambios en la regla (solo quita). Nuevas pruebas de punta a punta: una cuenta incluida que se desvincula, se borra, pasa a invitada o a emergencia, o cuya empresa se desactiva, sale en la siguiente llamada y la que aprueba sigue funcionando. Las cuentas no tienen un «activa» propio: «desactivada» se probó como borrada.
- **Auditoría (registro `security`):** `mcp.account_included` («Confirmó su cuenta de … para Claude («…»)») y `mcp.account_include_failed` con su razón (`wrong_account`, `target_unavailable`, `account_not_allowed`, `authorization_lost`, el código de Microsoft o la razón de un inicio rechazado). `mcp.connected` lista todas las cuentas (`accounts`) y dice «… e incluyó su cuenta de …».
- **`quien_soy`:** devuelve `cuentas`, la lista de las cuentas que la llamada puede usar (nombre, correo y empresa), la que aprobó primero.
- **Guardia:** `PersonNeverAuthorizesTest` suma `app/Mcp/ConsentAccounts.php` (solo `LinkedAccounts`, para ofrecer).
- Sin migraciones, sin variables nuevas y sin clases nuevas de Tailwind (todas ya estaban en `public/build`). `docs/despliegue.md` no cambia.

### Pruebas

- RED (antes de implementar), con `tests/Feature/Mcp`, `tests/Unit/Mcp`, `tests/Feature/Auth` y `PersonNeverAuthorizesTest`: 437 pruebas, 390 pasan, 4 fallan y 43 con error (rutas y constantes inexistentes).
- GREEN: `tests/Feature/Mcp`, `tests/Unit/Mcp` y `tests/Feature/Auth`, 251 pruebas, todas pasan. `IncludeAccountsTest` tiene 45.
- Cubre: cuenta equivocada (otra del mismo inquilino, mismo `oid` en otro inquilino, la que aprueba); cuenta desvinculada, invitada, de emergencia, borrada o de empresa inactiva; inicio como invitado; regla de inicio fallida; intención vencida, ajena o de otro `state`; repetir el callback; un solo reintento; autorización pendiente perdida; la lista no pasa a otro cliente ni a otro pedido; «Quitar»; aprobar de nuevo reemplaza la lista; `/` sin cambios.
- Suite completa: 1.831 pruebas, todas pasan (1.782 antes).
- Pint: sin cambios pendientes.

### Decisiones

- **La lista vive en la sesión y no en la base** hasta «Aprobar», porque así muere con la autorización pendiente y nunca hay una cuenta en `mcp_connection_accounts` sin código emitido.
- **Se ata al `authToken` y a una huella**, no solo al `authToken`, porque Passport cambia el `authToken` en cada pantalla, incluida la de vuelta de Microsoft.
- **Se usa `admit()` después de `preview()`**, como al vincular. El costo es el mismo del cambio de empresa: una revisión posterior a `admit()` puede rechazar con la fila ya refrescada con datos probados de Microsoft.
- **La pantalla recibe los datos con un decorador** de la respuesta de Passport (`ConsentView`), porque `Passport::authorizationView` no permite agregar parámetros.

### No hecho o no verificado

- No se probó contra Microsoft ni claude.ai reales: el ida y vuelta con `prompt=none` dentro de una autorización pendiente se probó con Socialite simulado.
- `npm run build` no se corrió: las clases usadas ya estaban en el CSS compilado.
- Tamaño: unas 850 líneas de código (con la vista y las rutas) y 900 de pruebas. Pasa de la cifra orientativa de 400 porque la oferta, la confirmación, la autorización pendiente y el guardado solo funcionan juntos.

## Registro del C2b-R

Ruta: el mismo escritor delegado, después de una revisión independiente del C2b (1 hallazgo medio y 1 bajo, los dos reproducidos con pruebas de sondeo). Commit `f54e692` (`fix(mcp): bind each account confirmation to its pending authorization`).

### Qué cambió

- **M1, la confirmación no estaba atada a su autorización pendiente:**
  - Problema: la intención `mcp_include` guardaba la cuenta, la cuenta destino, el intento y el `state`, pero no la autorización pendiente. Si en el mismo navegador se abría otra pantalla de consentimiento antes de que Microsoft respondiera (otra pestaña, o una página que abre el enlace de un cliente registrado por cualquiera), la cuenta quedaba confirmada para esa otra autorización. «Aprobar» la guardaba en la conexión de ese otro cliente.
  - Arreglo: al empezar la confirmación, la intención guarda la huella de la autorización pendiente (`binding`), no su `auth_token`, que cambia en cada pantalla. El reintento con `prompt=login` la conserva.
  - En el callback, `answered()` y `microsoftError()` exigen que la autorización viva tenga esa misma huella y la misma cuenta. Si no, se registra `mcp.account_include_failed` con `authorization_lost`, no se confirma nada, no se reintenta y sale el error de Passport (403), sin llevar a la otra pantalla.
  - Volver a abrir la misma pantalla mientras Microsoft responde (otro `auth_token`, el mismo pedido) sigue funcionando.
- **L1, hora de `confirmed_at`:** `pullForApproval()` creaba la hora en UTC (Carbon 3 lee así una marca de tiempo sin zona) y la app guarda en America/Bogota, así que las cuentas incluidas quedaban 5 horas corridas. Ahora usa la zona de la app, igual que la pantalla (`ConsentAccounts::confirmedAt()`).

### Pruebas

- RED: 50 pruebas en `IncludeAccountsTest`: 44 pasan y 6 fallan (las 4 nuevas de la autorización pendiente, el reintento atado y la hora guardada en la prueba de «Aprobar»).
- GREEN: las 50 pasan. `tests/Feature/Mcp`, `tests/Unit/Mcp` y `tests/Feature/Auth`: 256, todas pasan.
- Nuevas: la confirmación no cae en otra autorización pendiente (otro cliente, u otro pedido del mismo cliente), y la otra pantalla no queda con la cuenta y guarda solo la que aprueba; un error de Microsoft con otra autorización abierta no reintenta; el reintento conserva el `binding`; reabrir la misma pantalla sigue confirmando. La prueba de «Aprobar» revisa `confirmed_at` en la base (09:20 para la que aprueba, 09:15 para la incluida).
- Suite completa: 1.836 pruebas, todas pasan (1.831 antes).
- Pint: sin cambios pendientes.

## Registro del C3a

Ruta: escritor delegado (más de dos archivos no triviales), en `feat/conector-mcp-3-herramientas` sobre `feat/conector-mcp-2-cuentas` (#84). Commit `c6afd73` (`feat(mcp): add the read-only portal tools for Claude (C3a)`).

### Qué quedó

- **Base común (`App\Mcp\Tools\PortalTool`):** cada herramienta lee una vez por cada cuenta usable de la llamada (las que `mcp.connection` deja en la petición), primero la que aprobó, con esa cuenta como la que mira. Etiqueta cada resultado con el nombre corto de su empresa («Segurex», «Derivados»). Fechas en ISO 8601 con la hora de Bogotá, más un texto en español. `quien_soy` usa la misma base.
- **Una sola vez (`App\Mcp\SeenByAccounts`):** si dos cuentas ven lo mismo (un anuncio para todas, un evento, una app), sale una vez con las dos empresas. Solo se listan las empresas de las cuentas que lo ven, nunca las del anuncio o el evento, que pueden estar fuera de la conexión.
- **Herramientas** (todas `#[IsReadOnly]`, con descripción y esquema en español):

| Herramienta | Entrada | Máximo | Reutiliza |
|---|---|---|---|
| `anuncios_pendientes` | — | 20, del más reciente | `Announcement::scopeVisibleTo()` y el nuevo `scopeUnreadByPersonOf()` (la misma regla por persona de `scopeWithReadAtFor()`) |
| `anuncio` | `id` | 1 | `scopeVisibleTo()` y `scopeWithReadAtFor()`; el mismo error si no existe o no se ve |
| `proximas_fechas` | `dias` 1–90 (30) | 30 | `ColombianHolidays`, `HolidayDay` («Festivo» / «Puente festivo»), `Event::visibleSoonest()`, `EventDate` (fechas anuales y su texto) y `HomePageBuilder::sortKey()` |
| `cumpleanos_y_aniversarios` | `dias` 0–31 (7) | 30 | `DirectoryEntry::companyIdFor()`, el nuevo `DirectoryEntry::scopeWithShownBirthday()` y `Celebration::within()` |
| `buscar_personas` | `texto` 2–60 | 20 | `companyIdFor()`, `scopeMatching()` y las columnas de /personas |
| `mis_apps` | — | 100 | `AppCatalog::visibleApps()` |

- **Datos que nunca salen:** celular (el directorio no lo tiene), año de nacimiento (el portal no lo guarda), cumpleaños sin la casilla «mostrar», enlaces de Teams ni datos de una empresa que no está entre las cuentas. Los cumpleaños dicen día y mes. De los aniversarios sale solo el día del aniversario y los años cumplidos (lo mismo que ya muestra el inicio).
- **Servidor:** `PortalServer` registra las siete herramientas y sus instrucciones las describen.

### Pruebas

- RED (antes de implementar), `tests/Feature/Mcp` y `tests/Unit/Mcp`: 212 pruebas, 136 pasan, 70 fallan y 6 con error (herramientas, clases y consultas inexistentes).
- GREEN: las 212 pasan; con `tests/Unit/Mcp` y `PersonNeverAuthorizesTest`, 410 en esa corrida.
- Por herramienta (`tests/Feature/Mcp/Tools`): dos cuentas vinculadas en dos empresas ven lo de las dos, etiquetado; lo de «Otra» (fuera de la conexión) nunca sale, tampoco en las empresas de un anuncio o evento compartido con ella; al desvincular, la cuenta pasa a invitada o a emergencia, o su empresa se desactiva, lo de esa empresa sale; los límites; y las claves prohibidas no aparecen. Además, la lista de herramientas (solo lectura, descripción y esquema) y las dos consultas nuevas.
- `ConnectionTest` espera ahora siete herramientas en `tools/list`.
- Suite completa: 1.919 pruebas, todas pasan (1.836 antes).
- Pint: sin cambios pendientes.

### Decisiones

- **Las consultas nuevas van en los modelos** (`Announcement`, `DirectoryEntry`), junto a las que ya existían. `HomePageBuilder::people()` todavía tiene su propia copia de la unión con el cumpleaños: estaba fuera de los archivos permitidos. Queda como seguimiento que use `scopeWithShownBirthday()` (hecho en el C3a-R).
- **`Celebration::within()` mira 7 días** y estaba fuera de los archivos permitidos. Para ventanas de hasta 31 días se llama con ventanas seguidas de 8 días (de hoy, de hoy + 8…) y se descarta lo que pasa del límite. Seguimiento posible: darle un parámetro de días (hecho en el C3a-R).
- **Festivos:** son los mismos para todas, así que salen con todas las empresas de la conexión.
- **`mis_apps` enlaza al lanzador del portal** (`/apps/{slug}`), que revisa el acceso otra vez con la cuenta de la sesión del navegador.
- **`anuncio` devuelve el texto tal como está guardado:** los anuncios son texto plano y el portal nunca los guarda en HTML.
- **Validación:** errores en español que nombran el campo. Un `texto` de solo espacios o que no deja palabras se rechaza, para no devolver todo el directorio.
- **Sin registro de contenido:** las herramientas no escriben nada en los registros (la línea de auditoría por llamada es del C4).

### No hecho o no verificado

- C3b (`mis_tareas`, `mis_aprobaciones`): espera a #74.
- No se probó contra claude.ai real.
- El inicio `/` no cambió: no se tocó su código (`HomeScreenTest` pasa en la suite completa).
- Tamaño: unas 930 líneas de código (con comentarios) y 1.240 de pruebas. Pasa de la cifra orientativa de 400 porque las seis herramientas comparten la base, la regla de una sola vez y las pruebas de la conexión con dos cuentas.

## Registro del C3a-R

Ruta: el mismo escritor delegado, después de una revisión independiente del C3a (sin hallazgos altos ni medios, 6 bajos). Para esta ronda también se permitió editar `app/View/Home/**` y `app/Catalog/AppCatalog.php`, solo para quitar la duplicación. Commit `e6d30a0` (`fix(mcp): harden the portal tools after review (C3a-R)`).

### Qué cambió

- **L1, cumpleaños de homónimos:** las filas se unían por tipo, fecha, nombre y texto. Dos «Juan Pérez» con el mismo cumpleaños quedaban en una fila, y dos «María Gómez» de dos empresas, en una sola con las dos empresas.
  - Ahora `Celebration` lleva la entrada del directorio (`entryId`) y sale una fila por entrada y por empresa.
  - Solo se unen las filas de la persona de la propia conexión: sus cuentas ya se confirmaron con Microsoft y siguen vinculadas (`ConnectionAccounts`). Por eso su cumpleaños en los dos directorios sale una vez, con las dos empresas.
  - La persona de nadie más se lee: las cuentas vinculadas de otra persona quedan en una fila por empresa. Así no hace falta `LinkedAccounts` ni ampliar `PersonNeverAuthorizesTest`.
- **L2, el mismo directorio que el inicio:** la consulta del inicio pasó a `DirectoryEntry::homeDirectory()` (por nombre, máximo `HomeSearchIndex::PEOPLE_LIMIT`, con `scopeWithShownBirthday()`). `HomePageBuilder::people()` y la herramienta usan esa misma consulta. Antes la herramienta filtraba antes del tope de 1.000 personas y el inicio no. El inicio sigue con una consulta para el directorio.
- **Ventana:** `Celebration::within()` recibe los días (7 por defecto, lo del inicio). La herramienta ya no recorre ventanas de 8 días.
- **L3, el texto del documento:** en «Datos que nunca salen», de los aniversarios sale solo el día del aniversario y los años cumplidos, lo mismo que muestra el inicio.
- **L4, `mis_apps`:**
  - `en_mantenimiento` sale del mismo estado que la tarjeta del inicio (`AppCardStatus::of()`).
  - `nota` dice que el enlace abre la app con la cuenta del portal que tenga la sesión en el navegador, y con qué cuenta abrirla: «… usa tu cuenta de Segurex (jhon.castro@segurex.com) o de Derivados (…)».
- **L5, `buscar_personas`:** pide al menos una palabra de 2 caracteres o más. «a e» se rechaza.
- **L6:** una categoría vacía sale como `null`, y `quien_soy` usa el nombre corto de la empresa, como las demás herramientas.

### Pruebas

- RED, `tests/Feature/Mcp` y `tests/Unit/Mcp`: 219 pruebas, 205 pasan, 13 fallan y 1 con error. Fallaban los dos casos de homónimos, el tope de 1.000, el mantenimiento y la categoría, la nota, «a e» y `quien_soy` con el nombre corto. La de cuentas vinculadas de otra persona y «  a  » ya pasaban.
- GREEN: las 219 pasan. Con `tests/Feature/Home`, `tests/Feature/Search` y `PersonNeverAuthorizesTest`, 564, incluidos `HomeScreenTest` (no más de 12 consultas) y `HomeCelebrationsTest`.
- Suite completa: 1.926 pruebas, todas pasan (1.919 antes).
- Pint: sin cambios pendientes.
- Tamaño: unas 220 líneas de código y 100 de pruebas.

### No hecho

- `AppCatalog.php` no cambió: no tenía duplicación que quitar.

## Registro del C4

Ruta: escritor delegado (más de dos archivos no triviales), en `feat/conector-mcp-4-cuenta-admin` sobre `feat/conector-mcp-3-herramientas` (#85). Commit `5f4dde3` (`feat(mcp): manage Claude connections from /cuenta and Admin (C4)`).

### Qué quedó

- **Interruptor (`App\Mcp\McpSwitch`):** el conector está encendido solo si lo están la variable `PORTAL_MCP_ENABLED` (el corte de fondo del servidor) y el interruptor «Conector de Claude» de Administración.
  - El interruptor se guarda en la tabla que ya existía, `portal_settings`, con la clave `mcp_enabled` (JSON `true`/`false`). `PortalSettings` no se tocó: ignora las claves que no conoce.
  - Mientras nadie lo toque está encendido, así que con la variable basta, como antes.
  - Con la variable apagada no se lee la tabla.
  - Cambiarlo escribe `mcp.switched` (registro `security`), con el estado nuevo y el de la variable.
- **Sin caché:** el interruptor se lee de la tabla en cada petición que lo necesita: una consulta por clave primaria, guardada solo mientras vive el objeto. Apagarlo cierra todas las rutas del conector desde la siguiente petición de cualquier proceso. En producción la caché es la base de datos (`CACHE_STORE=database`), así que una caché costaría la misma consulta y además podría quedar vieja.
- **`/cuenta` › «Conexiones con Claude»** (Blade, sin Livewire ni Alpine):
  - Lista las conexiones activas en las que está la cuenta, como la que aprobó (`mcp_connections.user_id`) o como incluida (`mcp_connection_accounts.user_id`), con el scope `McpConnection::scopeWithAccount()`.
  - De cada una muestra el cliente y su fecha de registro, las cuentas (nombre, correo y empresa; la que aprobó primero), cuándo se conectó y su último uso.
  - Con el conector apagado, la sección no aparece.
- **«Desconectar»** (`POST /cuenta/claude/{conexión}/desconectar`, `ClaudeConnectionController`, con CSRF, `mcp.enabled` y `throttle:20,1`):
  - Corta la conexión completa con `Connections::disconnect()`, que usa `McpConnection::revoke()` (códigos, tokens y tokens de renovación) y escribe `mcp.disconnected` con quién la cortó, el cliente y las cuentas.
  - Una cuenta incluida también puede cortarla.
  - Una conexión ajena, ya cortada o inexistente da 404. Ser la misma persona no basta: es por cuenta, nunca por `person_id`.
  - **Sin `microsoft.recent`:** desconectar solo quita acceso, volver a conectar exige Microsoft y la pantalla de permiso, y quien perdió su cuenta de Claude debe poder cortarla de inmediato.
- **Admin (`App\Livewire\Admin\McpConnector`, tarjeta «Conector de Claude» dentro de Configuración):**
  - Va dentro de Configuración porque las vistas `resources/views/admin/**` y el menú (`app/View/Components/Layouts/Admin.php`) estaban fuera de los archivos permitidos. La vista de Configuración pasó de un `<form>` a un `<div>` que contiene el formulario y la tarjeta.
  - Solo súper administradores (`AdminSections::SETTINGS`), con autorización en cada acción.
  - El interruptor se aplica al instante y avisa en rojo si `PORTAL_MCP_ENABLED` está apagado.
  - Lista las conexiones activas (cliente, cuentas con «(aprobó)», conectada y último uso), las 50 más recientes, con el total si hay más.
  - «Revocar» (con `wire:confirm`) usa `Connections::revokeByAdmin()` y escribe `mcp.revoked_by_admin`.
- **Una línea por llamada (`App\Mcp\AuditedCallTool`):**
  - `PortalServer::boot()` cambia el `tools/call` de laravel/mcp por una subclase que, después de correr la herramienta, escribe `mcp.tool_called` en el registro `security`.
  - La línea lleva la herramienta y el id de la conexión, a nombre de la cuenta que aprobó, con la hora de la entrada.
  - Un nombre de herramienta que no existe no se registra (laravel/mcp lo rechaza antes).
  - Nunca guarda los argumentos ni el resultado.
  - Cuesta un `INSERT` y nada más: la conexión y su cuenta ya vienen cargadas por `mcp.connection`.
- **Auditoría:** etiquetas en español en `AuditPresenter` para los cuatro eventos nuevos. Salen en «Seguridad» (por el registro `security`), en la búsqueda por persona y en el CSV. La vista de Auditoría no cambió.
- **Documentos:** `docs/manual-administracion.md` (§2.8 y §2.10) y `docs/despliegue.md` (§1.13 y «Problemas frecuentes»).
- Sin migraciones, sin variables nuevas y sin clases nuevas de Tailwind: `npm run build` no cambió `public/build`.

### Pruebas

- RED (antes de implementar), las 47 pruebas nuevas: 3 pasan, 9 fallan y 35 con error (clases, constantes y rutas inexistentes). Dos de los fallos eran de las pruebas mismas, no del código:
  - Una segunda cuenta no puede pasar por la pantalla de permiso en la misma prueba, porque Passport guarda su primer guardia. Ahora esa conexión se crea directo en la base.
  - Después de una llamada MCP, el guardia por defecto queda en `api`. Ahora las pruebas vuelven a `web`.
- GREEN: las 47 pasan. Con `tests/Feature/Mcp`, `tests/Feature/Account`, `tests/Feature/Admin`, `tests/Feature/Audit` y `tests/Unit`: 1.156, todas pasan.
- Pruebas nuevas:
  - `AdminSwitchTest`: el interruptor parte encendido; apagarlo da 404 en todo, incluso con un token válido; encenderlo de nuevo funciona con los mismos tokens; la variable apagada manda sobre el interruptor; un cambio hecho por otro proceso se ve en la siguiente petición; la auditoría; con la variable apagada no hay consultas.
  - `ToolCallAuditTest`: una línea por llamada, sin argumentos ni resultado; nada al listar herramientas, al llamar una que no existe o con un token rechazado; un solo `INSERT` en `activity_log`.
  - `ClaudeConnectionsTest`: solo las conexiones propias; la cuenta incluida las ve; la misma persona sin estar en la conexión no las ve; desconectar como la que aprobó y como la incluida, y después 401 en `/mcp/portal` y la renovación falla; 404 para una ajena, una cortada, una inexistente o con el conector apagado; la auditoría.
  - `McpConnectorTest`: la tarjeta; 403 para otros roles al montar y en cada acción; el interruptor cierra y abre `/mcp/portal`; el aviso de la variable; la lista y su tope; «Revocar» mata los tokens y queda auditado.
  - `McpAuditLabelsTest`: las etiquetas en la página, en el filtro «Seguridad» (no en «Cambios»), en la búsqueda y en el CSV.
- Suite completa: 1.976 pruebas, todas pasan (1.926 según el registro del C3a-R; las 47 nuevas explican 1.973).
- Pint: sin cambios pendientes.

### Decisiones

- **El volumen de la auditoría:** cada llamada es una fila en `activity_log`, como mucho 120 por minuto por conexión (el límite `mcp`). Van en «Seguridad», mezcladas con los inicios de sesión. Para ver las de una persona se busca por nombre o correo. Un tipo propio («Claude») en el filtro exigía tocar la vista de Auditoría, que estaba fuera de los archivos permitidos.
- **Quién aparece en cada línea:** en `mcp.disconnected`, la cuenta que pulsó; en `mcp.revoked_by_admin`, el administrador. En las dos, la cuenta que aprobó es el sujeto.

### No hecho o no verificado

- **Retención:** `config/activitylog.php` dice 365 días, pero `activitylog:clean` no está programado en `routes/console.php`, que estaba fuera de los archivos permitidos. Hoy nada borra las líneas de Auditoría, tampoco las de las llamadas de Claude. Seguimiento: programar `activitylog:clean` a diario.
- La tarjeta no tiene página ni entrada propia en el menú de Administración; está en Configuración.
- No se probó contra claude.ai real ni en el servidor.
- Tamaño: unas 565 líneas de código (con vistas, rutas y comentarios), 951 de pruebas y 90 de documentos. Pasa de la cifra orientativa de 400 porque `/cuenta`, la tarjeta de Administración, el interruptor y la auditoría comparten el fin de una conexión y sus pruebas.

## Siguiente paso

C5, el manual: cómo conectar Claude (Configuración › Conectores › Agregar conector propio con la URL del portal), cómo desconectar desde `/cuenta` y la recomendación de privacidad. C3b (`mis_tareas` y `mis_aprobaciones`), cuando #74 (`work_items`) esté en la base.

Fuentes de la idea: https://claude.com/docs/connectors/custom
