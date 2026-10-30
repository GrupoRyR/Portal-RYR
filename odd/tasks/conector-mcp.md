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
- Desconectar borra la conexión y revoca sus tokens de acceso y de renovación.

### Riesgos

- **El registro de clientes es público:** cada intento crea una fila en `oauth_clients`. Se mitiga con `throttle`, la lista de dominios y `passport:purge`.
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
- [ ] C2. **Servidor OAuth y conexión:** consentimiento, confirmación de cada cuenta con Microsoft y token con la lista de cuentas.
- [ ] C3. **Herramientas de solo lectura** con su visibilidad y pruebas.
- [ ] C4. **`/cuenta` y Admin:** «Conexiones con Claude», el interruptor general y Auditoría.
- [ ] C5. **Manual:** cómo conectar Claude (Configuración › Conectores › Agregar conector propio con la URL del portal), y la recomendación de privacidad.

## Siguiente paso

C2, servidor OAuth y conexión, en `feat/conector-mcp-1-oauth` sobre esta rama. C3 necesita `work_items` (#74).

Fuentes de la idea: https://claude.com/docs/connectors/custom
