# Guía de despliegue — Portal RYR

Esta guía explica cómo instalar el Portal RYR por primera vez en el hosting de GoDaddy (cPanel) y cómo publicar cambios después.

- **URL de producción:** https://apps.segurexlatam.net
- **Servidor:** GoDaddy, cPanel compartido, PHP 8.3, MariaDB 10.11 (compatible con MySQL; versión verificada el 2026-11-03 con `select version()`), acceso SSH y Git.
- **Importante:** el servidor no tiene Node. Los archivos de diseño (CSS/JS) se compilan en el equipo local y se suben ya compilados en `public/build`.

En los comandos, reemplace estos marcadores por los valores reales:

| Marcador | Significado |
|---|---|
| `<usuario>` | Usuario de cPanel |
| `<ruta-app>` | Carpeta de la aplicación dentro de `/home/<usuario>`, por ejemplo `portal-ryr` |
| `<url-del-repositorio>` | URL SSH del repositorio Git |

---

## 1. Instalación inicial (una sola vez)

### 1.1 Versión de PHP

1. En cPanel, abra **MultiPHP Manager**.
2. Asigne **PHP 8.3** al dominio `apps.segurexlatam.net` (después de crearlo en el paso 1.3).
3. Por SSH, confirme la versión de la línea de comandos:

   ```bash
   php -v
   ```

   Si no muestra 8.3, use la ruta completa del PHP 8.3 de cPanel (normalmente `/opt/cpanel/ea-php83/root/usr/bin/php`) en todos los comandos de esta guía y en la línea del cron.

### 1.2 Clonar el repositorio

1. En cPanel, abra **SSH Access** y cree o importe una llave SSH para el usuario `<usuario>`.
2. Registre la llave pública como **llave de despliegue de solo lectura** en el repositorio Git.
3. Conéctese por SSH y clone el proyecto:

   ```bash
   cd /home/<usuario>
   git clone <url-del-repositorio> <ruta-app>
   cd <ruta-app>
   ```

   También puede usar **Git Version Control** de cPanel con la misma URL y la misma ruta.

### 1.3 Crear el subdominio

1. En cPanel, abra **Domains** (o **Subdomains**) y cree `apps.segurexlatam.net`.
2. En **Document Root** escriba: `/home/<usuario>/<ruta-app>/public`

   > El document root debe apuntar a la carpeta `public`, nunca a la raíz del proyecto. Así el archivo `.env` y el código no quedan expuestos en internet.

### 1.4 Base de datos (MariaDB)

1. En cPanel, abra **MySQL Databases** (cPanel lo llama así aunque el motor sea MariaDB).
2. Cree una base de datos (cPanel le agrega el prefijo `<usuario>_`).
3. Cree un usuario con una contraseña segura y guárdela en un gestor de contraseñas.
4. Agregue el usuario a la base de datos con **ALL PRIVILEGES**.
5. Verifique que la base use `utf8mb4` (en phpMyAdmin, pestaña **Operaciones**, cotejamiento `utf8mb4_unicode_ci`).

### 1.5 Dependencias de PHP

Confirme que Composer está disponible:

```bash
composer --version
```

Si no lo está, descargue `composer.phar` desde https://getcomposer.org/download/ (verificando la firma como indica esa página) y úselo como `php ~/composer.phar` en lugar de `composer`.

Instale las dependencias:

```bash
cd /home/<usuario>/<ruta-app>
composer install --no-dev --optimize-autoloader --no-interaction
```

### 1.6 Archivo `.env`

El archivo `.env` contiene las contraseñas y **solo existe en el servidor**. Nunca se sube a Git.

```bash
cp .env.example .env
chmod 600 .env
php artisan key:generate
```

Edite `.env` (por ejemplo con `nano .env`) y deje estos valores:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://apps.segurexlatam.net
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=<usuario>_<base>
DB_USERNAME=<usuario>_<usuario-bd>
DB_PASSWORD=<contraseña-bd>
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database
LOG_CHANNEL=daily
```

### 1.7 Primer despliegue

```bash
cd /home/<usuario>/<ruta-app>
bash deploy/deploy.sh
```

El script crea las tablas, genera las cachés y enlaza `public/storage`.

Después cargue los datos iniciales (las dos empresas, los roles, las categorías, el catálogo de apps y sus reglas por grupo):

```bash
php artisan db:seed --force
```

Se puede repetir sin duplicar nada: actualiza lo que ya existe. No crea usuarios; las personas se crean al iniciar sesión con Microsoft.

### 1.8 HTTPS (AutoSSL)

1. En cPanel, abra **SSL/TLS Status**.
2. Seleccione `apps.segurexlatam.net` y pulse **Run AutoSSL**.
3. Espere a que el certificado quede activo y abra https://apps.segurexlatam.net.

En producción la aplicación genera todos sus enlaces con `https://`.

### 1.9 Cron (tareas programadas)

1. En cPanel, abra **Cron Jobs**.
2. En **Common Settings** elija **Once Per Minute (\* \* \* \* \*)**.
3. En **Command** escriba exactamente (con su usuario y ruta):

   ```text
   cd /home/<usuario>/<ruta-app> && php artisan schedule:run >> /dev/null 2>&1
   ```

   Si en el paso 1.1 necesitó la ruta completa de PHP 8.3, úsela aquí en lugar de `php`.

4. Pulse **Add New Cron Job**.

**Cómo comprobar que el cron funciona.** La aplicación tiene una tarea llamada `scheduler-heartbeat` que se ejecuta cada minuto y guarda la hora de su última ejecución. Espere dos minutos y ejecute:

```bash
cd /home/<usuario>/<ruta-app>
php artisan schedule:list
php artisan tinker --execute="echo cache('scheduler:last_run');"
```

- `schedule:list` debe mostrar `* * * * *  scheduler-heartbeat`.
- El segundo comando debe mostrar una fecha y hora de hace menos de dos minutos (hora de Bogotá, `-05:00`). Si no muestra nada o la hora es antigua, el cron no está corriendo: revise la línea del cron y la ruta de PHP.

### 1.10 Microsoft, correo y archivos de las empresas

**Inicio de sesión con Microsoft y consentimiento.** Agregue a `.env` (los valores salen del registro de la app en Microsoft Entra):

```dotenv
MICROSOFT_CLIENT_ID=<id-de-la-aplicación>
MICROSOFT_CLIENT_SECRET=<secreto-de-la-aplicación>
MICROSOFT_REDIRECT_URI=https://apps.segurexlatam.net/auth/microsoft/callback
PORTAL_SUPER_ADMIN_EMAILS=<correo1>,<correo2>
```

- `MICROSOFT_CLIENT_SECRET` también se usa para comprobar el consentimiento de una empresa nueva (paso 2 del asistente). Si falta o venció, el asistente muestra «No se pudo comprobar» y la empresa no se marca como verificada.
- El enlace de consentimiento vuelve a la misma dirección de `MICROSOFT_REDIRECT_URI`, así que no hay que registrar otra en Entra.
- Cuando venza el secreto, cree uno nuevo en Entra, cámbielo en `.env` y ejecute `php artisan config:cache`.

**Correo (avisos y alertas).** El portal envía un correo cuando se crea una empresa, cuando cambia su inquilino y cuando se activa, y las alertas de los respaldos (paso 1.12). Se envía en el momento (no hay cola), con Microsoft Graph, desde el buzón `segurex.info@segurex.com` de Microsoft 365 y con la misma app «Portal RYR» del inicio de sesión. No usa SMTP ni la contraseña de ningún buzón: Microsoft desactiva la autenticación básica de SMTP en Exchange Online a partir de finales de 2026.

1. **Datos de la app.** En el centro de administración de Microsoft Entra del inquilino de Segurex, abra **Aplicaciones empresariales** › **Portal RYR** y copie el **Id. de aplicación** y el **Id. de objeto**. Use los de *Aplicaciones empresariales*, no los de *Registros de aplicaciones* (el Id. de objeto es distinto). Copie también el **Id. de inquilino** (Entra › **Información general**).

2. **No agregue `Mail.Send` en Entra.** En **Registros de aplicaciones** › **Portal RYR** › **Permisos de API** no debe aparecer **Microsoft Graph › Mail.Send (Aplicación)**. Si ya está, pulse **Revocar el consentimiento del administrador** y quite el permiso. Ese consentimiento vale para *todos* los buzones de la organización y se suma al permiso de Exchange del paso 3, así que anularía la restricción.

3. **Permiso de envío solo para `segurex.info@segurex.com`** (una sola vez; lo hace un administrador de Exchange de Segurex en PowerShell). Se usa el control de acceso basado en roles (RBAC) para aplicaciones de Exchange Online, que Microsoft indica como reemplazo de las directivas de acceso de aplicaciones (`New-ApplicationAccessPolicy`). El ámbito se define con un filtro sobre el buzón, así que no hace falta un grupo de seguridad habilitado para correo:

   ```powershell
   Install-Module ExchangeOnlineManagement -Scope CurrentUser   # solo la primera vez
   Connect-ExchangeOnline -UserPrincipalName <administrador>@segurex.com

   # Puntero en Exchange a la app de Entra (Id. de aplicación e Id. de objeto del paso 1)
   New-ServicePrincipal -AppId <id-de-aplicación> -ObjectId <id-de-objeto> -DisplayName "Portal RYR"

   # Ámbito con un solo buzón; la vista previa debe mostrar únicamente segurex.info
   New-ManagementScope -Name "Portal RYR - buzon de envio" -RecipientRestrictionFilter "PrimarySmtpAddress -eq 'segurex.info@segurex.com'"
   Get-Recipient -RecipientPreviewFilter "PrimarySmtpAddress -eq 'segurex.info@segurex.com'"

   # Rol de envío de la app, limitado a ese ámbito
   New-ManagementRoleAssignment -Name "Portal RYR - Mail.Send" -App <id-de-objeto> -Role "Application Mail.Send" -CustomResourceScope "Portal RYR - buzon de envio"

   # Comprobación: InScope debe ser True para segurex.info y False para cualquier otro buzón
   Test-ServicePrincipalAuthorization -Identity <id-de-objeto> -Resource segurex.info@segurex.com | Format-Table
   Test-ServicePrincipalAuthorization -Identity <id-de-objeto> -Resource <otro-buzón>@segurex.com | Format-Table
   ```

   `Test-ServicePrincipalAuthorization` responde al instante, pero Exchange puede tardar entre 30 minutos y 2 horas en aplicar el permiso a los envíos reales. Si la prueba del paso 5 da `ErrorAccessDenied` justo después de este paso, espere y repítala.

4. **Archivo `.env`.** Además de `MICROSOFT_CLIENT_ID` y `MICROSOFT_CLIENT_SECRET` (arriba), agregue estas claves y borre las antiguas de SMTP (`MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`) si existen:

   ```dotenv
   MAIL_MAILER=graph
   MICROSOFT_MAIL_TENANT_ID=<id-de-inquilino-de-Segurex>
   MAIL_FROM_ADDRESS=segurex.info@segurex.com
   MAIL_FROM_NAME="Portal RYR"
   ```

   `MICROSOFT_MAIL_TENANT_ID` es siempre el inquilino de Segurex (dueño del buzón), aunque `MICROSOFT_TENANT` siga en `organizations` para el inicio de sesión.

5. **Aplicar y probar:**

   ```bash
   php artisan optimize
   php artisan portal:mail-test <su-correo>
   ```

   Debe decir «Correo de prueba enviado a …» y el correo debe llegar en unos minutos. Si falla, el comando muestra el código de error de Microsoft (vea la sección 5).

- Los correos no se guardan en **Elementos enviados** del buzón.
- Los adjuntos de un correo pueden sumar como máximo 3 MB; uno más grande no se envía y el error queda en el registro.
- Si el correo falla, la acción se guarda igual y el error queda en `storage/logs` («Company notice could not be sent.»).
- Cuando venza `MICROSOFT_CLIENT_SECRET`, el correo deja de salir con un error 401 hasta que se cambie (ver arriba).

Referencias de Microsoft Learn: [user: sendMail](https://learn.microsoft.com/en-us/graph/api/user-sendmail?view=graph-rest-1.0), [adjuntos de más de 3 MB](https://learn.microsoft.com/en-us/graph/outlook-large-attachments), [RBAC para aplicaciones en Exchange Online](https://learn.microsoft.com/en-us/exchange/permissions-exo/application-rbac), [flujo de credenciales de cliente](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-client-creds-grant-flow).

**Directorio de personas (copia nocturna desde Microsoft).** El buscador del inicio y la página `/personas` muestran a las personas de la empresa con su cargo, área, oficina, teléfono de la oficina y correo. El portal los copia de Microsoft Entra cada noche con la misma app «Portal RYR» y el mismo `MICROSOFT_CLIENT_SECRET`; no hay claves nuevas en `.env`.

1. **Permiso en Entra (una sola vez).** En el centro de administración de Microsoft Entra del inquilino de Segurex, abra **Registros de aplicaciones** › **Portal RYR** › **Permisos de API** › **Agregar un permiso** › **Microsoft Graph** › **Permisos de aplicación**, busque **User.Read.All**, márquelo y pulse **Agregar permisos**. Debe ser de tipo *Aplicación*, no *Delegado*.
2. **Consentimiento en Segurex.** En la misma página pulse **Conceder consentimiento de administrador para Segurex** y confirme. La columna **Estado** de `User.Read.All` debe quedar en verde («Concedido»). Antes de pulsar, revise la lista: el botón concede todos los permisos que aparezcan en ella.
3. **Consentimiento en las demás empresas.** Cada empresa con otro inquilino debe dar su propio consentimiento. Un administrador global de esa empresa abre este enlace (cambie `<inquilino>` por el Id. de inquilino de la empresa, `<id>` por `MICROSOFT_CLIENT_ID` y `<MICROSOFT_REDIRECT_URI>` por el valor de `.env`):

   ```text
   https://login.microsoftonline.com/<inquilino>/adminconsent?client_id=<id>&redirect_uri=<MICROSOFT_REDIRECT_URI>
   ```

   Si la empresa dio el consentimiento del asistente antes de agregar `User.Read.All`, tiene que volver a abrir el enlace para aceptar el permiso nuevo. Las empresas que comparten el inquilino de Segurex no necesitan nada más.
4. **Primera copia a mano**, por SSH:

   ```bash
   cd /home/<usuario>/<ruta-app>
   php artisan portal:sync-directory
   ```

   Muestra cuántas personas copió y cuántas quitó en cada empresa. Si un inquilino falla (sin consentimiento, permiso no concedido, Microsoft sin respuesta, una redirección, o una respuesta sin nadie que copiar cuando el directorio ya tiene personas de ese inquilino), lo dice, termina con error y ese inquilino conserva las personas de la copia anterior; el motivo, sin secretos, queda en `storage/logs` («Directory copy failed…», con el código HTTP y los códigos AADSTS, o solo el tipo de error si fue otra cosa). Un 403 casi siempre es `User.Read.All` sin consentimiento en ese inquilino. Solo corre una copia a la vez: si ya hay otra en curso (por ejemplo la de las 03:30), la copia a mano dice «Ya hay una copia del directorio en curso», termina con error sin copiar nada y basta repetirla cuando la otra termine.
5. **Después no hay que hacer nada:** el cron del paso 1.9 la ejecuta cada noche a las 03:30 (hora de Bogotá). `php artisan schedule:list` debe mostrar `30 3 * * *  php artisan portal:sync-directory`.

- Solo se copian las cuentas habilitadas de miembros con correo: nunca los invitados (B2B), las cuentas deshabilitadas ni el celular personal (`mobilePhone`).
- Cada persona queda en la empresa que le daría el inicio de sesión (la única empresa del inquilino; si lo comparten varias, la dueña del dominio de su correo; si no, la principal). Las de empresas inactivas no se copian, y si una empresa se desactiva, sus personas salen del directorio en la siguiente copia.
- Quien sale de Entra, o queda deshabilitado, desaparece en la siguiente copia.

Referencias de Microsoft Learn: [List users](https://learn.microsoft.com/en-us/graph/api/user-list?view=graph-rest-1.0), [consentimiento de administrador](https://learn.microsoft.com/en-us/entra/identity-platform/v2-admin-consent).

**Logos de las empresas.** Se guardan en `storage/app/public/brand/<empresa>/` y se publican en `/storage/...`. El script de despliegue ya crea el enlace; si los logos no se ven, ejecútelo a mano:

```bash
php artisan storage:link
```

**Grupos APP-… de una empresa nueva.** En el paso 3 del asistente se indica el comando. Lo ejecuta un administrador de Microsoft 365 de esa empresa, en su equipo con Windows:

```powershell
Install-Module Microsoft.Graph -Scope CurrentUser   # solo la primera vez
cd <carpeta-del-proyecto>\docs\scripts
.\crear-grupos-app.ps1 -TenantId <id-de-inquilino> -Grupos 'APP-Cartera'
```

Cambie `APP-Cartera` por los grupos de las apps que use esa empresa, separados por comas (`-Grupos 'APP-Cartera','APP-TomaPedidos'`).

El script pide iniciar sesión, crea solo los grupos de seguridad que falten e informa cuáles creó y cuáles ya existían. Hay que indicar cada grupo con `-Grupos` (por ejemplo `-Grupos 'APP-Cartera','APP-TomaPedidos'`): no tiene un valor por defecto. `APP-PortalAdmin` solo se crea en el inquilino de Segurex, y no hace falta crearlo en las demás empresas. Se puede ejecutar varias veces. Si PowerShell bloquea el script, ejecute antes `Set-ExecutionPolicy -Scope Process RemoteSigned`.

### 1.11 App instalable y modo sin conexión (PWA)

El portal se puede instalar en celulares, tablets y computadores, y guarda una copia del inicio para mostrarla sin conexión. No hay que configurar nada en `.env`, pero sí revisar el servidor:

1. **HTTPS obligatorio.** Los navegadores solo instalan el portal y activan el modo sin conexión con HTTPS (paso 1.8). Con `http://` el portal funciona, pero sin instalación ni copia guardada.
2. **Direcciones que responde Laravel.** `/manifest.webmanifest` (nombre, colores e íconos de la empresa de quien inicia sesión) y `/sw.js` (el *service worker*) no son archivos: los responde la aplicación con sus propios encabezados (`application/manifest+json` y `text/javascript`, `Cache-Control: no-cache`, `Service-Worker-Allowed: /`). No cree archivos con esos nombres en `public/`, porque Apache los serviría en lugar de la aplicación. Compruébelo después del despliegue:

   ```bash
   curl -sI https://apps.segurexlatam.net/sw.js | grep -i -E "content-type|cache-control|service-worker-allowed"
   curl -s https://apps.segurexlatam.net/manifest.webmanifest | head -c 200
   ```

3. **`public/.htaccess`** ya trae la compresión (`mod_deflate`), un año de caché para `public/build/assets` (sus nombres cambian con cada compilación) y `no-cache` para `offline.html`. Si el CSS o el JS llegan sin comprimir o sin caché, pida a GoDaddy que active `mod_deflate` y `mod_headers`:

   ```bash
   curl -sI -H "Accept-Encoding: gzip" https://apps.segurexlatam.net/build/assets/<archivo>.css | grep -i -E "content-encoding|cache-control"
   ```

4. **Íconos de cada empresa.** Se generan una sola vez a partir del ícono de la empresa (PNG de 512 × 512) en `storage/app/public/brand/<empresa>/pwa/`, con la extensión GD de PHP. Si GD no está instalada (`php -m | grep -i gd`), el portal usa el ícono neutro de `public/icons/portal-*.png`. Requiere el enlace `public/storage` (paso 1.10).
5. **Actualizaciones.** No hay que borrar cachés a mano: cada `npm run build` cambia los nombres de los archivos y la versión del *service worker*; al abrir el portal con conexión, el navegador descarga la versión nueva y borra la anterior. Por eso `public/build` siempre debe ir en el commit.

### 1.12 Respaldos diarios

Cada noche, en hora de Bogotá, el portal guarda una copia de la base de datos y de los archivos subidos (logos, íconos e imágenes de anuncios) en un solo `.zip`, en el mismo servidor y fuera de la carpeta pública. Lo hace el cron del paso 1.9; no hay que crear otro cron.

| Hora | Tarea | Qué hace |
|---|---|---|
| 02:00 | `backup:clean` | Borra las copias viejas (ver sección 6.1) |
| 02:30 | `backup:run --isolated` | Crea la copia del día |
| 08:00 | `backup:monitor` | Revisa que haya una copia de menos de un día y que no ocupen más de 2500 MB |
| cada minuto | `portal:run-requested-backup` | Ejecuta la copia pedida con **Respaldar ahora** (Administración › Configuración), si hay una |

Si una copia falla o el monitor encuentra un problema, se envía un correo a las direcciones de `PORTAL_SUPER_ADMIN_EMAILS` con el correo configurado en el paso 1.10. Las copias correctas no envían correo: se ven en Configuración.

Haga esto una sola vez, por SSH:

1. **Compruebe `mysqldump`**, el programa que copia la base de datos:

   ```bash
   which mysqldump
   ```

   - Si responde `/usr/bin/mysqldump`, no hay que hacer nada.
   - Si responde otra ruta, por ejemplo `/usr/local/bin/mysqldump`, agregue a `.env` **la carpeta**, sin el nombre del programa, y regenere la caché de configuración:

     ```dotenv
     DB_DUMP_BINARY_PATH=/usr/local/bin
     ```

     ```bash
     php artisan optimize
     ```

   - Si no responde nada, pida a GoDaddy la ruta de `mysqldump` para su plan.

2. **Compruebe PHP:** la extensión `zip` y la función `proc_open` (con ella PHP ejecuta `mysqldump`):

   ```bash
   php -m | grep -i zip
   php -r "var_dump(function_exists('proc_open'));"
   ```

   Deben mostrar `zip` y `bool(true)`. Si no, actívelos en cPanel › **Select PHP Version** › **Extensions** o pídalo a GoDaddy.

3. **Haga la primera copia a mano** y revise que quedó:

   ```bash
   cd /home/<usuario>/<ruta-app>
   php artisan backup:run
   php artisan backup:list
   ```

   `backup:run` debe terminar con `Backup completed!`. `backup:list` debe mostrar una fila `portal-ryr` en el disco `backups` con `Healthy` en ✅, `# of backups` en 1, la fecha (`Newest backup`) y el espacio usado. Si falla, el mensaje dice por qué (por ejemplo `mysqldump: not found`): corrija y repita.

4. Abra **Administración › Configuración**: la tarjeta **Respaldo diario de la base de datos** debe mostrar la fecha de esa copia y «correcto».

### 1.13 Anuncios por correo a las listas de cada empresa

Un anuncio con la casilla **Enviar también por correo a la lista de cada empresa** sale, cuando se publica, a la lista de todo el personal de cada empresa del anuncio (por ejemplo `todos@segurex.com`), una vez por empresa. Lo envía el cron del paso 1.9 (`portal:send-announcement-list-emails`, cada minuto) con Microsoft Graph y la misma app «Portal RYR»; no usa SMTP, ni la contraseña de un buzón, ni `MAIL_FROM_ADDRESS`. No hay claves nuevas en `.env`.

Las listas de Exchange solo aceptan remitentes de su propia organización. Por eso cada empresa envía **desde un buzón de su propio inquilino** y el portal pide el permiso con un token de ese inquilino: Segurex desde `segurex.info@segurex.com` (inquilino de Segurex) y Derivados desde `derivados.info@derivadoscol.com` (inquilino de Derivados). Las direcciones son datos de **Administración › Empresas**, no del código.

Haga los pasos 1 a 4 **en cada inquilino** (primero Segurex, luego Derivados). Necesita un administrador global o de aplicaciones (paso 1) y un administrador de Exchange del grupo **Organization Management** (pasos 2 a 4) de ese inquilino.

1. **Consentimiento de la app en el inquilino.** En el centro de administración de Microsoft Entra de ese inquilino abra **Aplicaciones empresariales** y busque **Portal RYR**. Si no aparece, la empresa todavía no dio el consentimiento: un administrador global abre el enlace del paso 1.10 («Directorio de personas», punto 3) con el Id. de inquilino de la empresa. Después copie de **Aplicaciones empresariales › Portal RYR** el **Id. de aplicación** (es el mismo en todos los inquilinos, `MICROSOFT_CLIENT_ID`) y el **Id. de objeto** (es **distinto en cada inquilino**). No use los de *Registros de aplicaciones*.

2. **Permiso de envío solo para el buzón remitente** (RBAC para aplicaciones de Exchange Online), en PowerShell:

   ```powershell
   Install-Module ExchangeOnlineManagement -Scope CurrentUser   # solo la primera vez
   Connect-ExchangeOnline -UserPrincipalName <administrador>@<dominio-de-la-empresa>

   # Puntero en Exchange a la app de este inquilino. Si Get-ServicePrincipal ya la muestra, sáltese New-ServicePrincipal.
   Get-ServicePrincipal | Where-Object AppId -eq '<id-de-aplicación>'
   New-ServicePrincipal -AppId <id-de-aplicación> -ObjectId <id-de-objeto-de-este-inquilino> -DisplayName "Portal RYR"

   # Ámbito con el buzón remitente; la vista previa debe mostrar solo ese buzón
   New-ManagementScope -Name "Portal RYR - buzon de envio" -RecipientRestrictionFilter "PrimarySmtpAddress -eq '<buzón-remitente>'"
   Get-Recipient -RecipientPreviewFilter "PrimarySmtpAddress -eq '<buzón-remitente>'"

   # Rol de envío de la app, limitado a ese ámbito
   New-ManagementRoleAssignment -Name "Portal RYR - Mail.Send" -App <id-de-objeto-de-este-inquilino> -Role "Application Mail.Send" -CustomResourceScope "Portal RYR - buzon de envio"

   # Comprobación: InScope debe ser True para el buzón remitente y False para cualquier otro buzón
   Test-ServicePrincipalAuthorization -Identity <id-de-objeto-de-este-inquilino> -Resource <buzón-remitente> | Format-Table
   Test-ServicePrincipalAuthorization -Identity <id-de-objeto-de-este-inquilino> -Resource <otro-buzón> | Format-Table
   ```

   - **Derivados:** `<buzón-remitente>` es `derivados.info@derivadoscol.com`.
   - **Segurex:** el buzón de los avisos del portal (`MAIL_FROM_ADDRESS`, paso 1.10) también envía con este permiso, así que **debe quedar dentro del mismo ámbito**. Si ya hizo el paso 1.10 y el remitente de la lista es el mismo `segurex.info@segurex.com`, el ámbito «Portal RYR - buzon de envio» ya lo cubre: no cree otro y pase a la comprobación. Si el remitente de la lista es otro buzón, amplíe el ámbito con los dos (no lo reemplace):

     ```powershell
     Set-ManagementScope -Identity "Portal RYR - buzon de envio" -RecipientRestrictionFilter "PrimarySmtpAddress -eq 'segurex.info@segurex.com' -or PrimarySmtpAddress -eq '<otro-buzón-remitente>'"
     ```

     Y compruebe los dos buzones con `Test-ServicePrincipalAuthorization` (`InScope True` en ambos).

   `Test-ServicePrincipalAuthorization` responde al instante, pero Exchange tarda **entre 30 minutos y 2 horas** en aplicar el permiso a los envíos reales.

3. **Quitar el permiso `Mail.Send` sin límite de Entra.** El permiso de Exchange del paso 2 **se suma** al de Entra: si la app también tiene **Microsoft Graph › Mail.Send (Aplicación)** concedido en Entra, puede enviar desde cualquier buzón del inquilino y el ámbito no limita nada. Hágalo solo cuando el paso 2 dé `InScope True`, porque desde ese momento el portal envía únicamente con el permiso de Exchange.
   - **Antes, en Segurex:** confirme que `MAIL_FROM_ADDRESS` está en el ámbito (paso 2). Si no, los avisos del portal y las alertas de respaldo dejan de salir con `ErrorAccessDenied`.
   - **En el inquilino de Segurex** (dueño del registro de la app): **Registros de aplicaciones › Portal RYR › Permisos de API**, en **Mail.Send (Aplicación)** pulse **…** › **Revocar el consentimiento del administrador** y luego **Quitar permiso**. Así la app ya no lo pide a ningún inquilino nuevo.
   - **En cada inquilino que ya lo había concedido** (por ejemplo Derivados): revocar el permiso del registro no lo borra allí. Quítelo con Microsoft Graph PowerShell, conectado a ese inquilino:

     ```powershell
     Install-Module Microsoft.Graph -Scope CurrentUser   # solo la primera vez
     Connect-MgGraph -TenantId <id-de-inquilino> -Scopes "AppRoleAssignment.ReadWrite.All","Application.Read.All"

     $app = Get-MgServicePrincipal -Filter "appId eq '<id-de-aplicación>'"
     $graph = Get-MgServicePrincipal -Filter "appId eq '00000003-0000-0000-c000-000000000000'"
     $mailSend = $graph.AppRoles | Where-Object { $_.Value -eq 'Mail.Send' -and $_.AllowedMemberTypes -contains 'Application' }

     # Muestra la asignación de Mail.Send; si no muestra nada, ya no está concedido
     Get-MgServicePrincipalAppRoleAssignment -ServicePrincipalId $app.Id | Where-Object AppRoleId -eq $mailSend.Id

     # La quita
     Get-MgServicePrincipalAppRoleAssignment -ServicePrincipalId $app.Id | Where-Object AppRoleId -eq $mailSend.Id |
         ForEach-Object { Remove-MgServicePrincipalAppRoleAssignment -ServicePrincipalId $app.Id -AppRoleAssignmentId $_.Id }
     ```

   - Esto cierra el riesgo aceptado de `Mail.Send` sin límite. Revise también en **Aplicaciones empresariales › Portal RYR › Permisos** de cada inquilino que ya no aparezca **Mail.Send**.

4. **Que la lista acepte al remitente.** Por defecto una lista de Exchange acepta a cualquier remitente interno y no hace falta nada. Si la lista solo acepta mensajes de algunas personas, agregue el buzón remitente:

   ```powershell
   # Si AcceptMessagesOnlyFromSendersOrMembers sale vacío, la lista ya acepta a todos los internos
   Get-DistributionGroup -Identity <lista> | Format-List AcceptMessagesOnlyFromSendersOrMembers,RequireSenderAuthenticationEnabled
   Set-DistributionGroup -Identity <lista> -AcceptMessagesOnlyFromSendersOrMembers @{Add="<buzón-remitente>"}
   ```

   Si la lista es un grupo de Microsoft 365, use `Get-UnifiedGroup` y `Set-UnifiedGroup` con los mismos parámetros. También se puede hacer en el centro de administración de Exchange: **Destinatarios › Grupos ›** la lista **› Configuración › Administración de entrega**, agregando el buzón en **Remitentes especificados**. Si falta este paso, Graph acepta el envío pero Exchange devuelve al buzón remitente un aviso de no entrega `5.7.124`.

5. **Datos de cada empresa.** En **Administración › Empresas**, abra la empresa y en la sección **Microsoft 365** escriba la **Lista de todo el personal** y el **Buzón remitente** (los dos deben ser de un dominio de la empresa). Guarde. El **Buzón remitente** debe ser la dirección SMTP principal del buzón (la de `Get-Mailbox <buzón> | Format-List PrimarySmtpAddress`), no un alias: el ámbito del paso 2 filtra por `PrimarySmtpAddress`, así que con un alias `Test-ServicePrincipalAuthorization` da `InScope False` y todos los envíos fallan con `ErrorAccessDenied`.

6. **Comprobar.**
   1. `php artisan schedule:list` debe mostrar `* * * * *  php artisan portal:send-announcement-list-emails`.
   2. Para no escribirle a toda la empresa en la prueba, ponga primero como **Lista de todo el personal** una lista pequeña del mismo dominio (por ejemplo una con solo usted) y vuelva a la lista real al terminar.
   3. Cree un anuncio solo para esa empresa con la casilla **Enviar también por correo a la lista de cada empresa** marcada y **Desde** ahora. En uno o dos minutos la tarjeta **Correo a las listas** del anuncio debe decir «Enviado el …», el correo debe llegar a la lista y quedar en **Elementos enviados** del buzón remitente. Retire después el anuncio de prueba.
   4. Si dice «No se pudo enviar (ErrorAccessDenied)», el permiso del paso 2 todavía no se aplica (espere hasta 2 horas) o el buzón no está en el ámbito: compruebe con `Test-ServicePrincipalAuthorization` y pulse **Reintentar**. Con `MailboxNotEnabledForRESTAPI` o `HTTP 404`, el buzón remitente no existe o no es de Exchange Online. Con `AADSTS…`, falta el consentimiento de la app en ese inquilino (paso 1) o el secreto venció. El código, sin el token ni el texto del anuncio, queda también en `storage/logs` («Announcement list email could not be sent.»).
   5. En Segurex, después del paso 3, ejecute `php artisan portal:mail-test <su-correo>` para confirmar que los avisos del portal siguen saliendo.

- Cada lista recibe un anuncio una sola vez: editarlo después no lo reenvía. El portal no reintenta solo; **Reintentar** (en el anuncio) lo hace a pedido.
- Los correos se anotan al guardar el anuncio con la casilla marcada. Configurar la lista de una empresa no le envía los anuncios ya publicados: hay que guardar de nuevo el anuncio que se quiera enviar.
- Cada vuelta del cron envía como máximo 10 correos; si hay más, siguen en el minuto siguiente.

Referencias de Microsoft Learn: [RBAC para aplicaciones en Exchange Online](https://learn.microsoft.com/en-us/exchange/permissions-exo/application-rbac) (incluye por qué el permiso de Entra se suma al de Exchange), [user: sendMail](https://learn.microsoft.com/en-us/graph/api/user-sendmail?view=graph-rest-1.0), [consentimiento de administrador](https://learn.microsoft.com/en-us/entra/identity-platform/v2-admin-consent), [Remove-MgServicePrincipalAppRoleAssignment](https://learn.microsoft.com/en-us/powershell/module/microsoft.graph.applications/remove-mgserviceprincipalapproleassignment?view=graph-powershell-1.0), [Set-DistributionGroup](https://learn.microsoft.com/en-us/powershell/module/exchangepowershell/set-distributiongroup?view=exchange-ps), [error de no entrega 5.7.124](https://learn.microsoft.com/en-us/troubleshoot/exchange/email-delivery/ndr/fix-error-code-5-7-124-in-exchange-online).

### 1.14 Resumen de los lunes

El **Resumen de los lunes** de una empresa (interruptor en **Administración › Empresas**, apagado por defecto) sale a su lista de todo el personal desde su buzón remitente, con la misma app y los mismos permisos de la sección 1.13: no hay claves nuevas en `.env` ni pasos nuevos en Exchange o Entra. Lo envía el cron del paso 1.9 (`portal:send-weekly-digest`) los lunes cada hora de 7:00 a 12:00, hora de Bogotá; cada empresa lo recibe una vez por semana y, si la semana no tiene nada que contar, no se envía. `APP_TIMEZONE` debe seguir en `America/Bogota` (el valor por defecto): la semana se cuenta en Bogotá y las fechas guardadas se leen en esa zona.

1. **Migración.** Crea la tabla `weekly_digests` y el campo del interruptor: el despliegue de rutina la aplica con `php artisan migrate --force` (sección 2.2).
2. **Comprobar el horario.** `php artisan schedule:list` debe mostrar `0 7-12 * * 1  php artisan portal:send-weekly-digest` (en la zona America/Bogota).
3. **Encender.** En la ficha de la empresa, con la lista y el buzón ya probados (sección 1.13), encienda **Resumen de los lunes** y guarde. Para la primera prueba, use una lista pequeña del mismo dominio, como en el paso 6.2 de la sección 1.13.
4. **Revisar.** El lunes, debajo del interruptor, **Último resumen** dice «Enviado el …», «Sin contenido», «No se pudo enviar (código)» o «Interrumpido». Los códigos son los de la sección 1.13 (paso 6.4) y quedan también en `storage/logs` («Weekly digest could not be sent.», sin el token ni el contenido).

- No hay reenvío manual: un resumen que falló no sale otra vez; el siguiente sale el lunes siguiente. Fuera del lunes de 7:00 a 12:59, el comando no envía nada aunque se ejecute a mano.

### 1.15 Conector de Claude (MCP)

El portal puede funcionar como «conector propio» de Claude: cada persona conecta su cuenta de Claude y Claude consulta, solo para leer, lo que esa persona ya ve en el portal. Está **apagado** hasta que se active aquí. Mientras está apagado, `/mcp/portal`, `/oauth/*` y `/.well-known/oauth-*` responden 404 y no se registra nada. El diseño está en `odd/tasks/conector-mcp.md`.

1. **Migraciones.** El despliegue de rutina (paso 2.2) crea las tablas de OAuth (`oauth_clients`, `oauth_access_tokens`, `oauth_refresh_tokens`, `oauth_auth_codes` y `oauth_device_codes`) y las de las conexiones (`mcp_connections` y `mcp_connection_accounts`) con `php artisan migrate --force`.
2. **Llaves para firmar los tokens.** Se usa **una** de estas dos formas:
   - **En `.env` (recomendado).** Genere el par una sola vez, en el servidor y fuera de `public/`:

     ```bash
     cd /home/<usuario>/<ruta-app>
     php artisan passport:keys
     cat storage/oauth-private.key   # va en PASSPORT_PRIVATE_KEY
     cat storage/oauth-public.key    # va en PASSPORT_PUBLIC_KEY
     ```

     Copie cada llave completa en `.env`, entre comillas dobles y con sus saltos de línea (`"-----BEGIN PRIVATE KEY-----` … `-----END PRIVATE KEY-----"`). Después borre los dos archivos (`rm storage/oauth-*.key`) y ejecute `php artisan optimize`.
   - **En archivos.** Deje los archivos que crea `php artisan passport:keys` en `storage/` (nunca en `public/`) y no ponga `PASSPORT_PRIVATE_KEY` ni `PASSPORT_PUBLIC_KEY` en `.env`. Ajuste los permisos: `chmod 600 storage/oauth-private.key` y `chmod 600 storage/oauth-public.key` (también sirven 400, 440, 640 o 660). Con otros permisos la librería de OAuth lanza un aviso que Laravel convierte en error 500 al conectar o al llamar al conector.

   Cambiar las llaves invalida todos los tokens: cada persona tendrá que volver a conectar Claude. Nunca las suba al repositorio ni las comparta por chat.
3. **Encender el conector.** En `.env`:

   ```dotenv
   PORTAL_MCP_ENABLED=true
   ```

   y `php artisan optimize`. Para apagarlo, `PORTAL_MCP_ENABLED=false` y otra vez `php artisan optimize`: todo vuelve a responder 404 al instante.

   **Variable e interruptor de Administración.** El conector funciona solo si están encendidos los dos:
   - `PORTAL_MCP_ENABLED` en `.env` es el corte de fondo del servidor. Apagada, el conector está apagado aunque Administración diga otra cosa, y el interruptor no puede encenderlo.
   - **Administración › Configuración › Conector de Claude** lo apaga o lo enciende sin tocar el servidor, al instante y sin `php artisan optimize` (se guarda en la tabla `portal_settings`, clave `mcp_enabled`). Mientras nadie lo toque, está encendido: con la variable en `true` basta para empezar. Un valor guardado que no sea exactamente `true` (por ejemplo, editado a mano) cuenta como apagado.
   - Con el conector apagado por cualquiera de los dos, cada persona sigue viendo sus conexiones en `/cuenta` y puede desconectarlas; al encenderlo, las que no desconectó vuelven a funcionar.

   Para una emergencia, use el interruptor de Administración. La variable sirve para dejarlo apagado del todo, por ejemplo mientras no se ha probado en el servidor.
4. **Compruebe las direcciones públicas** después del despliegue. AutoSSL o ModSecurity de cPanel podrían interceptar `/.well-known/`:

   ```bash
   curl -s https://apps.segurexlatam.net/.well-known/oauth-protected-resource/mcp/portal
   curl -s https://apps.segurexlatam.net/.well-known/oauth-authorization-server
   curl -s -o /dev/null -w "%{http_code}\n" -X POST https://apps.segurexlatam.net/mcp/portal
   ```

   La primera debe devolver un JSON con `"resource":"https://apps.segurexlatam.net/mcp/portal"`; la segunda, un JSON con `authorization_endpoint`, `token_endpoint` y `registration_endpoint` bajo `https://apps.segurexlatam.net/oauth/`; la tercera, `401`. Si responden 404 con el conector encendido, revise que `php artisan optimize` corrió después de cambiar `.env`. Si responden una página de cPanel o un 403 de ModSecurity, pida a GoDaddy que deje pasar `/.well-known/oauth-*` a la aplicación.
5. **Limpieza diaria.** El cron (paso 1.9) ejecuta tres tareas:
   - `passport:purge` a las 4:00: borra los tokens y códigos revocados, sin importar su edad, y los vencidos hace más de 7 días.
   - `portal:purge-mcp-clients` a las 4:15: borra los clientes del conector con más de un día que nunca llegaron a ser una conexión, con sus códigos y tokens. `passport:purge` no borra clientes, y registrar uno es público.
   - `activitylog:clean --force` a las 4:30: borra de Auditoría (`activity_log`) todo lo de más de 365 días (`delete_records_older_than_days` en `config/activitylog.php`), también los cambios de configuración y los inicios de sesión, no solo las líneas de Claude.
6. **Límites.** Registrar clientes: 10 por minuto por dirección; los metadatos `/.well-known/*`: 60. Cada conexión: 120 llamadas por minuto. Una dirección con más de 30 llamadas rechazadas (401) en un minuto recibe 429 en todas hasta que pase el minuto.
7. **Tamaño de Auditoría.** Cada consulta de Claude escribe una fila en `activity_log` (unos 300 bytes, sin el contenido). Con el límite de 120 por minuto, una sola conexión podría escribir hasta unas 170 000 filas al día (unos 50 MB); el uso normal es de decenas o cientos. Si la tabla crece mucho, revise en Auditoría › Seguridad qué conexión consulta tanto y revóquela en Configuración. Para guardar menos tiempo, baje `delete_records_older_than_days`.

Solo se acepta la dirección de regreso de claude.ai (`https://claude.ai/api/mcp/auth_callback`). Claude Code y Claude Desktop con `localhost` no pueden conectarse en esta versión.

### 1.16 Mercados

El portal guarda la **TRM** y el **euro en pesos** para la banda de mercados del inicio (la banda se conecta en una entrega posterior; esta guarda los datos). No hay claves ni cuentas: las dos fuentes son públicas. El diseño está en `odd/tasks/mercados.md`.

- **TRM:** conjunto de datos oficial de datos.gov.co (`www.datos.gov.co`), las últimas 10 vigencias.
- **Euro:** los dólares por euro del tipo de cambio de referencia del Banco Central Europeo (`www.ecb.europa.eu`) multiplicados por la TRM que estaba vigente en la fecha de ese tipo de cambio. El BCE no publica el peso colombiano.

**Qué hace el cron.** El cron del paso 1.9 ejecuta `portal:sync-markets` dos veces al día, a las 7:10 y a las 13:10 (hora de Bogotá). Repetirla con los mismos datos no duplica filas ni cambia valores ya guardados. El BCE publica hacia las 16:00 de Frankfurt (9:00 a 10:00 en Bogotá), así que la corrida de las 7:10 todavía ve el archivo del día hábil anterior y la de las 13:10 trae el del día; en fines de semana y festivos del BCE todas ven el del último día hábil. Cada fila del euro es la TRM vigente en la fecha del BCE por la tasa de esa fecha, por eso una corrida con un archivo viejo no reescribe la fila del día anterior. El candado contra corridas simultáneas vence a los 10 minutos, de modo que una corrida cancelada no bloquea las siguientes.

1. **Migración.** Crea la tabla `market_rates`: el despliegue de rutina la aplica con `php artisan migrate --force` (sección 2.2).
2. **Salida a internet.** Desde el servidor, ambas direcciones deben responder `200`; un error de conexión o de certificado significa que el servidor no tiene salida a ese sitio:

   ```bash
   curl -sS -o /dev/null -w "%{http_code}\n" "https://www.datos.gov.co/resource/32sa-8pi3.json?\$limit=1"
   curl -sS -o /dev/null -w "%{http_code}\n" "https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml"
   ```

   Si alguna falla, pida a GoDaddy que permita la salida HTTPS a ese servidor. Las direcciones están en `config/markets.php`.
3. **Primera carga.** No espere al cron:

   ```bash
   cd /home/<usuario>/<ruta-app>
   php artisan portal:sync-markets
   ```

   Debe responder `TRM: 10 valores guardados.` y `Euro: 1 valor guardado.`
4. **Comprobar el horario.** `php artisan schedule:list` debe mostrar `10 7,13 * * *  php artisan portal:sync-markets`.

- **Si una fuente falla**, el comando termina con error, esa cifra conserva su último valor y la otra fuente se actualiza igual. El motivo es un código corto en `storage/logs` («Market rates could not be updated…», con `source` `trm` o `eur`), nunca el contenido de la respuesta: `connection` (sin conexión o la fuente dejó de responder), `timeout` (la respuesta no terminó de llegar en 10 segundos en total, aunque la fuente siga enviando datos), `http_503` (u otro código HTTP; `http_302` es una redirección, que no se sigue), `too_large` (más de 1 MB), `invalid_json`, `invalid_xml`, `doctype` (el XML trae una declaración DOCTYPE, que se rechaza), `invalid_data` (el formato cambió), `no_trm` (no hay ninguna TRM guardada para calcular el euro).
- Mientras la TRM no se pueda leer, el euro sigue calculándose con la última TRM guardada vigente en la fecha del BCE.
- El euro lleva la fecha de referencia del BCE y la TRM su vigencia. El tablero muestra, de cada cifra, el último valor cuya vigencia ya empezó hoy (hora de Bogotá); una TRM publicada por adelantado se guarda, pero no se muestra hasta que llegue su fecha.

### 1.17 Noticias

Los titulares de **Noticias** (pestañas Colombia, Internacional y Sector de cada empresa) salen de fuentes RSS o Atom que el súper administrador registra en **Administración › Noticias** (`docs/manual-administracion.md` §2.11). El servidor las lee cada hora y guarda solo el título, el enlace y la fecha de cada noticia. No hay claves nuevas en `.env`: los límites están en `config/news.php`.

1. **Migración.** Crea las tablas `news_sources` y `news_items`. El despliegue de rutina la aplica con `php artisan migrate --force` (paso 2.2).
2. **Comprobar el horario.** `php artisan schedule:list` debe mostrar `0 * * * *  php artisan portal:fetch-news` en la zona America/Bogota. Lo ejecuta el cron del paso 1.9.
3. **Salida a internet.** El servidor debe poder abrir conexiones `https` (puerto 443) hacia los sitios de las fuentes y resolver sus nombres (DNS). En cPanel compartido suele estar permitido. Si todas las fuentes quedan con `connection_failed` o `dns_failed`, pida a GoDaddy que permita las conexiones salientes por el puerto 443.
4. **Probar a mano.** Con al menos una fuente activa:

   ```bash
   php artisan portal:fetch-news
   ```

   Responde, por ejemplo, «Noticias: 3 fuentes leídas, 0 con error.». En Administración › Noticias, cada fuente muestra su última lectura y, si falló, el código.

**Protecciones.** Cada lectura sale del servidor de producción, por eso el portal la limita así:

- Solo direcciones `https` en el puerto 443, sin usuario ni contraseña en la dirección y con un nombre de dominio (nunca una IP escrita).
- Antes de conectar, el portal resuelve el nombre y rechaza la fuente si alguna de sus IP es interna: local (`127.0.0.1`, `::1`), privada (`10.x`, `172.16–31.x`, `192.168.x`, `fc00::/7`), de enlace local (`169.254.x`, `fe80::/10`), CGNAT (`100.64.0.0/10`), multidifusión, reservada, de documentación o una IPv6 que contiene una IPv4. La conexión usa exactamente la IP comprobada, así que el nombre no puede cambiar de IP entre la comprobación y la conexión.
- Sigue hasta 3 redirecciones y comprueba cada una con las mismas reglas.
- Espera hasta 10 segundos por solicitud (cada redirección es una solicitud nueva) y deja de leer al pasar de 1 MB.
- Pide la respuesta sin comprimir. Si el sitio la envía comprimida (gzip o deflate), el portal la descomprime y aplica el mismo límite de 1 MB a lo descomprimido.
- Rechaza cualquier documento con `<!DOCTYPE` o `<!ENTITY`, no reemplaza entidades y no descarga nada más mientras lee el XML.
- Del título quita las etiquetas HTML y los espacios de más. Completa los enlaces relativos con la dirección de la fuente (y `xml:base` en Atom) y descarta las noticias cuyo enlace no queda en `https`. No guarda descripciones ni HTML.
- Una fecha sin zona horaria se lee en hora de Bogotá. Una noticia sin fecha, o con fecha futura, queda con la hora en que se vio por primera vez.
- Guarda las 30 noticias más recientes de cada fuente y borra las de más de 60 días.

**Códigos de error** (columna `last_error_code`; nunca guarda el mensaje ni la respuesta):

| Código | Qué significa |
|---|---|
| `invalid_url` | La dirección o una redirección no es `https` en el puerto 443, tiene usuario o es una IP. |
| `dns_failed` | El nombre no resuelve. |
| `blocked_address` | El nombre resuelve a una IP interna. |
| `too_many_redirects` | Más de 3 redirecciones. |
| `timeout`, `connection_failed` | No respondió en 10 segundos o no se pudo conectar. |
| `http_404`, `http_503`, … | El sitio respondió con ese estado. |
| `too_large` | La respuesta pasa de 1 MB. |
| `doctype` | El documento trae `<!DOCTYPE` o `<!ENTITY`. |
| `invalid_xml`, `unsupported_format` | No es XML válido, o no es RSS ni Atom. |
| `no_entries` | Es RSS o Atom, pero ninguna noticia tiene título y enlace `https`. |
| `unsupported_encoding` | La respuesta viene con una compresión distinta de gzip o deflate, o dañada. |
| `unexpected` | Otro error. En `storage/logs` queda «News source could not be read.» con el número de la fuente y el tipo de error, sin el mensaje. |

Si una fuente falla, las demás se leen igual y sus noticias anteriores se conservan.

### 1.18 Catálogos

Administración › **Catálogos** guarda las categorías y los productos de cada empresa en las tablas `product_categories` y `products`, y las fotos de los productos en el disco `public`: `storage/app/public/products/<id de la empresa>/`.

1. **Migraciones.** `php artisan migrate --force` crea las dos tablas (el script de despliegue ya lo hace, paso 1.7). Los nombres de índices y claves son cortos a propósito: MariaDB y MySQL admiten 64 caracteres como máximo.
2. **Enlace de almacenamiento.** Las fotos se publican en `/storage/products/...`, con el mismo enlace `public/storage` de los logos (paso 1.10). Si las fotos no se ven, ejecute `php artisan storage:link` y compruebe que `public/storage` apunta a `storage/app/public`.
3. **Permisos de carpeta.** El usuario de PHP debe poder escribir en `storage/app/public/`. La carpeta `products/` y la de cada empresa se crean solas con la primera foto. No hace falta crearlas a mano.
4. **Extensión GD.** Cada foto se vuelve a codificar con GD antes de guardarse (quita metadatos y cualquier dato escondido tras la imagen) y las mayores de 1200 px se reducen. Compruebe que está instalada con `php -m | grep -i gd`. Sin GD no se pueden subir fotos.
5. **Límites.** JPG, PNG o WebP, de 200 px o más por lado, de hasta 2 MB y de hasta 16 megapíxeles (4000 × 4000): GD necesita unos 4 bytes por píxel para abrir la foto, y con `memory_limit` de 128 MB una mayor agotaría la memoria. Una foto de 12 megapíxeles de un celular cabe; una de 48 hay que reducirla antes de subirla. Si el servidor rechaza fotos menores de 2 MB, revise `upload_max_filesize` y `post_max_size` en cPanel › **MultiPHP INI Editor**.
6. **Copia de seguridad.** Las fotos están en `storage/app/public`, que ya incluyen los respaldos diarios (sección 6). Al restaurar una copia, el paso 6 de §6.3 también recupera las fotos.
7. **Qué se borra.** Al borrar un producto o reemplazar su foto, el archivo anterior se borra del disco. Una categoría con productos no se puede borrar.

### 1.19 Panel gerencial

El bloque «Panel gerencial» del inicio muestra a la gerencia de cada empresa las cifras clave del mes «al cierre de ayer». Las publica cada noche un robot de SAP (otro proyecto) con `PUT /api/v1/kpis` (`docs/api.md` §8), y solo las ven las personas del grupo de Gerencia de **esa** empresa. No hay claves nuevas en `.env`. El diseño está en `odd/tasks/panel-gerencial.md`.

1. **Migraciones.** El despliegue de rutina (paso 2.2) agrega `companies.management_group_id` e `integrations.company_id` y crea la tabla `company_kpis` con `php artisan migrate --force`.
2. **Crear el grupo en cada empresa.** En el inquilino de cada empresa que vaya a usar el panel, un administrador de Microsoft 365 crea el grupo de **seguridad** `APP-PortalGerencia`. Puede usar el mismo script de los grupos `APP-…` (sección 1.10):

   ```powershell
   cd <carpeta-del-proyecto>\docs\scripts
   .\crear-grupos-app.ps1 -TenantId <id-de-inquilino> -Grupos 'APP-PortalGerencia'
   ```

   O a mano: Entra admin center (https://entra.microsoft.com) › **Groups** › **New group** › **Group type: Security**. Un grupo de Microsoft 365 o de Teams **no sirve**: el portal lo ignora aunque tenga el mismo nombre. Agregue como miembros a las personas de la gerencia de esa empresa.
3. **Copiar el Object ID.** En el mismo inquilino: **Groups** › **All groups** › `APP-PortalGerencia` › copie **Object ID**. En el portal, **Administración › Empresas** › la empresa › **Microsoft 365** › **Grupo de Gerencia**, pegue el Object ID y guarde. Lo puede hacer solo un súper administrador con su cuenta de Microsoft (no la de emergencia); si su última confirmación con Microsoft tiene más de 10 minutos, el portal la pide de nuevo. El cambio queda en Auditoría. El portal compara solo el Object ID, nunca el nombre, y solo con cuentas de esa empresa que entran por su propio inquilino: el grupo de una empresa no da acceso en otra.
4. **Primer inicio de sesión.** Cada persona del grupo recibe el rol **Gerencia** la próxima vez que entra con Microsoft; quien sale del grupo lo pierde en su siguiente inicio. Si cambia o borra el Object ID en la empresa, el panel se cierra de inmediato para quien ya no está en el grupo vigente. Sin Object ID, nadie de esa empresa tiene el rol.
5. **Crear el token del robot.** **Administración › Integraciones › Nuevo token**: nombre (por ejemplo «Robot de SAP · Segurex»), permiso `kpis:write`, **Empresa de las cifras** y, de preferencia, la IP del equipo del robot en **IP permitidas**. Pide confirmar con Microsoft. Se necesita un token por empresa: cada token solo puede reemplazar las cifras de su empresa. Entregue el token al responsable del robot por el gestor de secretos, nunca por correo ni chat.
6. **Comprobar.** Después de la primera noche, en **Auditoría** con el filtro **API** debe aparecer «Publicó las cifras del panel gerencial de la empresa …», con el número de cifras (nunca los valores). Revocar el token en Integraciones borra las cifras que publicó.

### 1.20 Tareas: «Completar»

«Completar» deja que una persona termine desde el portal una tarea suya de la app de tareas y aprobaciones: el servidor del portal llama a la app con `POST {URL base}/api/portal/v1/tasks/{external_id}/complete`, firmada con HMAC-SHA256 (`docs/contrato-tareas-aprobaciones.md` §6). Las aprobaciones nunca se completan en el portal. No hay claves nuevas en `.env`. El diseño está en `odd/tasks/tareas-3-completar.md`.

1. **Migración.** El despliegue de rutina (paso 2.2) agrega `integrations.signing_secret` e `integrations.signing_secret_hint` con `php artisan migrate --force`. No crea índices.
2. **Salida HTTPS del servidor.** La llamada sale del servidor del portal hacia la URL base de la app, por el puerto 443, durante la petición de la persona (no hay cola). Si el hosting filtra las conexiones de salida, permita el dominio de la app. Cada llamada espera como máximo 5 segundos, no sigue redirecciones y siempre verifica el certificado TLS.
3. **La URL base, en el dominio de la app.** El portal solo llama si la **URL base de la app** es `https` y tiene exactamente el mismo host (y puerto) que la dirección de la **App del catálogo** de la integración. **Integraciones** no deja guardar una URL base en otro host, otro subdominio u otro puerto; si la dirección de la app del catálogo cambia después, el portal deja de llamar y el botón no aparece hasta corregir la URL base.
4. **Generar el secreto.** **Administración › Integraciones** › el sistema con `work:write` › **Secreto de firma** › **Generar secreto**. Pide confirmar con Microsoft (la cuenta de emergencia no puede). El secreto (`ryrsig_` y 43 caracteres) **se muestra una sola vez**: entréguelo al equipo de la app por el gestor de secretos, nunca por correo ni chat. Sin secreto, «Completar» no aparece. **Rotar secreto** reemplaza el anterior de inmediato; la app puede aceptar los dos unos minutos (contrato §2.2). Revocar el token borra también el secreto.
5. **El secreto depende de `APP_KEY`.** Se guarda cifrado con `APP_KEY`, porque el portal lo necesita para firmar. Si cambia `APP_KEY`, ponga la anterior en `APP_PREVIOUS_KEYS` o genere un secreto nuevo y entréguelo otra vez a la app.
6. **Comprobar.** Cuando la app tenga su endpoint, en **Auditoría** con el filtro **API** cada pulsación deja «Completó una tarea de …» o «No pudo completar una tarea de …», con el `external_id`, el pedido (`request_id`), la respuesta HTTP (o `timeout`) y el motivo. Nunca el título, el secreto ni la firma. Un «No pudo completar» con `401` o `unexpected_response` suele ser el secreto, la URL base o una respuesta sin el `code` del contrato (§6.3); con `secret_unavailable`, el secreto se cifró con otra `APP_KEY` (punto 5).

### 1.21 Estado de TI

El bloque «Estado de TI» del inicio responde de un vistazo si algo está fallando para la empresa de la persona. Junta dos fuentes: el estado que ya tiene cada app (en mantenimiento, o con fallas reportadas por la API, `docs/api.md`) y los **servicios** que TI registra a mano en **Administración › Estado de TI** (correo, internet, VPN, telefonía...). «Reportar un problema» usa el mismo enlace de **Soporte TI** (`/soporte`, hacia Gestor TI). No hay claves nuevas en `.env` ni tareas programadas. El diseño está en `odd/tasks/estado-ti.md`.

1. **Migración.** El despliegue de rutina (paso 2.2) crea la tabla `it_services` con `php artisan migrate --force`. Los nombres de índices y claves son cortos a propósito (MariaDB admite 64 caracteres como máximo): `it_svc_company_fk`, `it_svc_company_name_uq` y `it_svc_company_pos_ix`. Borrar una empresa borra sus servicios.
2. **Quién lo administra.** El súper administrador y el administrador del portal, todas las empresas; el editor de anuncios, solo su propia empresa (como sus anuncios). No hay rol nuevo.
3. **«Reportar un problema».** Necesita la **Dirección de soporte TI** de **Administración › Configuración** (la misma del botón «Soporte TI»). Sin ella, el bloque no muestra el enlace.
4. **Cuándo vence un reporte de una app.** Igual que «Con fallas» en las tarjetas: según **Quitar «Con fallas» después de (horas)** de Configuración. Un servicio manual no vence: queda como está hasta que alguien lo cambie.
5. **Sin caché.** El estado se calcula en cada carga del inicio, con una sola consulta a `it_services` por el índice de la empresa. Un cambio en Administración se ve en la siguiente carga; no hace falta limpiar nada.
6. **Comprobar.** Después de desplegar, entre a **Administración › Estado de TI**, elija una empresa y cree un servicio de prueba con estado **Con fallas**. En **Auditoría** debe aparecer «Creó el servicio de TI «…»». Bórrelo al terminar.

> El bloque del inicio se conecta en una entrega posterior (línea del inicio). Mientras tanto, lo que se cargue en Administración queda guardado y listo.

### 1.22 Avisos en el celular

Los anuncios publicados con la casilla **Enviar al celular** llegan como notificación a los dispositivos de quienes los ven y activaron **Avisos en el celular** en su cuenta (`/cuenta`). Usa *web push* con el paquete `minishlink/web-push`. No hay colas: lo envía el cron de cada minuto (paso 1.9), que ya está configurado. El diseño está en `odd/tasks/avisos.md`; el uso, en `docs/manual-administracion.md` §14.

Mientras falte cualquiera de las tres claves de abajo, la función está apagada: `/cuenta` no muestra el control, el editor no muestra la casilla y el cron no envía nada. Nada falla.

1. **Migraciones.** El despliegue de rutina (paso 2.2) crea las tablas `push_subscriptions` y `push_deliveries` y agrega `announcements.push_phone` y `announcements.push_finished_at` con `php artisan migrate --force`. Los nombres de índices y claves son cortos a propósito (64 caracteres como máximo).
2. **Extensiones de PHP.** `curl`, `mbstring` y `openssl` con curvas P-256, y `gmp` o `bcmath` (la firma de cada aviso hace cuentas con números grandes; sin ninguna de las dos funciona, pero mucho más lento). Compruébelo con `php -m | grep -i -E "curl|mbstring|openssl|gmp|bcmath"`; si faltan `gmp` y `bcmath`, actívelas en cPanel › **Select PHP Version** › **Extensions**. En Linux (el servidor) OpenSSL ya trae su configuración; en Windows, PHP necesita la variable `OPENSSL_CONF` apuntando a un `openssl.cnf` para crear llaves (sin ella, las pruebas del emisor real se saltan).
3. **Crear el par de llaves VAPID (una sola vez).** Por SSH, en la carpeta del portal:

   ```bash
   php artisan portal:push-keys
   ```

   Imprime tres líneas y no escribe nada. Cópielas en el `.env` del servidor:

   ```dotenv
   PUSH_VAPID_PUBLIC_KEY=<llave pública>
   PUSH_VAPID_PRIVATE_KEY=<llave privada>
   PUSH_VAPID_SUBJECT=mailto:<buzón de soporte>
   ```

   `PUSH_VAPID_SUBJECT` debe empezar por `mailto:` o `https://`: es el contacto que ven los servicios de *push* (Google, Mozilla, Apple y Microsoft) si hay un problema con los envíos. La llave privada es un secreto: no la suba al repositorio ni la comparta por correo o chat.
4. **Aplicar.** `php artisan config:cache`. No hay que tocar el cron: `portal:send-announcement-pushes` corre cada minuto dentro de `schedule:run`, envía como máximo 100 avisos por vuelta y deja de tomar nuevos a los 45 segundos (`config/push.php`). Cada noche, a las 04:45, `portal:prune-push-subscriptions` borra los dispositivos de cuentas de empresas inactivas y de cuentas que no entran al portal hace más de 90 días (`push.max_inactive_days`); esas cuentas tampoco reciben avisos mientras tanto: si la cuenta no entra al portal en 90 días deja de recibir avisos.
5. **No cambie las llaves sin motivo.** Un par nuevo corta todas las suscripciones hechas con el anterior: ningún dispositivo recibe avisos hasta que la persona vuelve a abrir `/cuenta` en él (el control nota la llave nueva y lo suscribe de nuevo solo). Cámbielas solo si la llave privada se filtró.
6. **iPhone y iPad.** Solo reciben avisos con iOS o iPadOS 16.4 o posterior **y** con el portal instalado en la pantalla de inicio (Safari › Compartir › «Agregar a inicio»), activándolos desde la app instalada. En Safari sin instalar, el control dice que el navegador no puede recibir avisos. Android, Windows y Mac funcionan en el navegador sin instalar nada.
7. **Red de salida.** El servidor hace peticiones HTTPS a `fcm.googleapis.com` (Chrome y Android), `*.push.services.mozilla.com` (Firefox), `*.push.apple.com` (Safari e iPhone) y `*.notify.windows.com` (Edge). Son los únicos destinos permitidos (`push.allowed_hosts` en `config/push.php`): una suscripción a cualquier otra dirección se rechaza al guardarla y nunca se llama.
8. **Comprobar.** Active los avisos en `/cuenta` desde un celular, publique un anuncio de prueba para su empresa con **Enviar al celular** y espere un minuto. Si no llega, en el servidor:

   ```bash
   php artisan portal:send-announcement-pushes
   grep -i "phone notice" storage/logs/laravel.log | tail -n 5
   ```

   Un aviso rechazado deja en el registro el código HTTP, nunca la dirección del dispositivo. Un dispositivo que el servicio ya no reconoce (404/410) se borra solo. Un aviso fallido no se reintenta: cada dispositivo recibe cada anuncio una sola vez.

   Una falla general no hace perder avisos: si las llaves están mal («Phone notices could not start» en el registro), el cron no anota nada; si ningún aviso de un bloque recibe respuesta de los servicios (sin red, DNS), descarta ese bloque, se detiene y lo intenta de nuevo al minuto siguiente («got no answer from any push service»).

### 1.23 Asistente

La página **Asistente** (`/asistente`) responde preguntas sobre lo que cada cuenta ya ve en el portal (apps, anuncios, fechas, cumpleaños, directorio, tareas, aprobaciones y estado de TI) con Claude, de Anthropic, por la API de mensajes y el SDK oficial de PHP (`anthropic-ai/sdk`). Las herramientas son de solo lectura y usan las mismas consultas del conector de Claude (§1.15) con la cuenta activa. A los editores de anuncios les propone borradores que solo se publican si la persona los confirma en pantalla. No hay migraciones, tareas programadas ni colas. El diseño está en `odd/tasks/asistente.md`; el uso, en `docs/manual-administracion.md` §15.

Está **apagado** hasta que se cumplan los tres puntos: `PORTAL_ASSISTANT_ENABLED=true`, el interruptor de **Administración › Configuración › Asistente del portal** encendido y `ANTHROPIC_API_KEY` en `.env`. Mientras falte la variable o el interruptor, `/asistente` responde 404 y el enlace no aparece. Si falta la llave, la página dice que el asistente no está disponible y no envía nada.

1. **Dependencias.** El despliegue de rutina (paso 2.2) instala el SDK con `composer install`: el paquete `anthropic-ai/sdk` y sus dependencias (`open-telemetry/api`, `open-telemetry/context` y `standard-webhooks/standard-webhooks`) ya están en `composer.lock`. Usa Guzzle, que el portal ya tiene, y las extensiones `curl`, `json` y `mbstring` de PHP. Compruébelo con `php -m | grep -i -E "curl|json|mbstring"`.
2. **Crear la llave de la API.** En la consola de Anthropic (https://platform.claude.com), en el espacio de trabajo de la organización, cree una llave de API para el portal (por ejemplo «Portal RYR · producción»). Es un secreto: entréguela a quien administra el servidor por el gestor de secretos, nunca por correo ni chat, y nunca la suba al repositorio.
3. **Segunda barrera de gasto en la consola (Jhon).** Además del presupuesto del portal (punto 6), ponga un **límite de gasto** al espacio de trabajo de esa llave en la consola de Anthropic. Si el portal fallara o se configurara mal, la consola corta igual.
4. **Variables en `.env` del servidor.** Solo las dos primeras son obligatorias; las demás tienen el valor por defecto que se muestra (`config/assistant.php`):

   ```dotenv
   ANTHROPIC_API_KEY=<llave de la API>
   PORTAL_ASSISTANT_ENABLED=true

   # Opcionales
   PORTAL_ASSISTANT_MODEL=claude-haiku-5-5
   PORTAL_ASSISTANT_EDITOR_MODEL=claude-sonnet-5-5
   PORTAL_ASSISTANT_FALLBACK_MODEL=claude-opus-5-5
   PORTAL_ASSISTANT_EFFORT=low
   PORTAL_ASSISTANT_MAX_TOKENS_CHAT=2000
   PORTAL_ASSISTANT_MAX_TOKENS_DRAFT=2000
   PORTAL_ASSISTANT_TIMEOUT=60
   PORTAL_ASSISTANT_DAILY_QUESTIONS=40
   PORTAL_ASSISTANT_CONVERSATION_TURNS=10
   ASSISTANT_MONTHLY_BUDGET_USD=20
   ```

   | Variable | Qué hace |
   |---|---|
   | `ANTHROPIC_API_KEY` | Llave de la API (`services.anthropic.key`). Sin ella, el asistente dice que no está disponible. |
   | `PORTAL_ASSISTANT_ENABLED` | Corte de fondo del servidor. Solo `true`, `1`, `on` o `yes` lo encienden. |
   | `PORTAL_ASSISTANT_MODEL` (o `ASSISTANT_MODEL`) | Modelo de las personas que solo preguntan, como «proveedor:modelo» (punto 12). Por defecto `anthropic:claude-haiku-5-5`. |
   | `PORTAL_ASSISTANT_EDITOR_MODEL` | Modelo de los editores de anuncios (quienes reciben borradores). Por defecto Claude Sonnet 5.5. Vacío: el mismo de `PORTAL_ASSISTANT_MODEL`. |
   | `PORTAL_ASSISTANT_FALLBACK_MODEL` | Solo cuando el modelo de la conversación termina con `refusal` (no quiso responder): la pregunta se repite como una **petición nueva** a este modelo, con la conversación en texto, sin el razonamiento anterior. Por defecto Claude Opus 5.5. Haiku 5.5 no tiene respaldo en el servidor de Anthropic, por eso lo hace el portal. Vacío: sin segundo intento. |
   | `PORTAL_ASSISTANT_EFFORT` | Esfuerzo del modelo: `low`, `medium`, `high`, `xhigh` o `max`. Más alto responde con más cuidado, tarda más y cuesta más. Cualquier otro valor cuenta como `low`. |
   | `PORTAL_ASSISTANT_MAX_TOKENS_CHAT`, `PORTAL_ASSISTANT_MAX_TOKENS_DRAFT` | Tope de salida de cada llamada al modelo, para las personas y para los editores. Incluye el razonamiento del modelo, que no se puede apagar en estos modelos: no lo baje mucho. Mínimo 1024. |
   | `PORTAL_ASSISTANT_TIMEOUT` | Segundos que el servidor espera cada llamada al modelo. Se reintenta una vez ante errores 429, 5xx o de conexión. |
   | `ASSISTANT_DEADLINE_SECONDS` | Tiempo total de una pregunta (90 por defecto): todas sus llamadas, reintentos, rondas de herramientas, el secundario y el respaldo. Cada llamada recibe como máximo el tiempo que queda; al pasarlo, la página dice «La respuesta tardó demasiado; intenta de nuevo.» |
   | `PORTAL_ASSISTANT_DAILY_QUESTIONS` | Preguntas por cuenta y día (hora de Bogotá). Se cuentan con las líneas de Auditoría del día. |
   | `PORTAL_ASSISTANT_CONVERSATION_TURNS` | Preguntas de una conversación. Al llegar, la conversación está llena y la página ofrece **Nueva conversación** (nunca se recorta el historial). |
   | `ASSISTANT_MONTHLY_BUDGET_USD` | Presupuesto del mes en dólares (punto 6). Por defecto 20. |

   Después, `php artisan optimize`. **El portal guarda la configuración en caché:** cada vez que cambie una de estas variables (también el presupuesto), ejecute `php artisan optimize` (o `php artisan config:cache`); sin eso, el portal sigue usando el valor anterior. `deploy/deploy.sh` ya ejecuta `php artisan optimize` al final de cada despliegue, así que un despliegue también toma los valores nuevos. Para apagar el asistente desde el servidor, `PORTAL_ASSISTANT_ENABLED=false` y otra vez `php artisan optimize`.

   **Modelos y precios** (dólares por millón de tokens, tomados de la guía de la API de Anthropic en 2026-10; confírmelos en la página de precios antes de cambiar el presupuesto):

   | Rol | Modelo | Entrada | Salida | Caché leída |
   |---|---|---|---|---|
   | Personas que preguntan | Claude Haiku 5.5 (`claude-haiku-5-5`) | 0,10 | 0,50 | 0,01 (por verificar) |
   | Editores de anuncios | Claude Sonnet 5.5 (`claude-sonnet-5-5`) | 2,00 | 10,00 | 0,20 |
   | Respaldo por `refusal` | Claude Opus 5.5 (`claude-opus-5-5`) | 4,00 | 20,00 | 0,20 |

   Haiku 5.5 cobra 0,50 / 2,50 cuando la petición pasa de 100.000 tokens. Escribir en la caché cuesta 1,25 veces la entrada. La tabla completa está en `config/assistant.php` (`prices`): los valores marcados «verify» salen de los multiplicadores generales y conviene confirmarlos. Un modelo que no esté en la tabla se cobra con la tarifa más cara, para que el presupuesto no se quede corto.

   Los tres modelos se llaman igual: razonamiento adaptativo con el esfuerzo configurado, sin `budget_tokens`, sin `temperature`, `top_p` ni `top_k`, sin `tool_choice` forzado y sin mensaje final del asistente (*prefill*). Cada una de esas cosas da error 400 en alguno de ellos.
5. **Salida HTTPS a la API.** Cada pregunta sale del servidor hacia `https://api.anthropic.com` por el puerto 443, durante la petición de la persona (no hay cola). Si el hosting filtra las conexiones de salida, permita ese dominio. Compruebe desde el servidor, por SSH:

   ```bash
   curl -sS -o /dev/null -w "%{http_code}\n" https://api.anthropic.com/v1/messages
   ```

   Debe responder un código HTTP (por ejemplo `401` o `405`, porque no lleva llave): eso confirma que hay salida. Un error de conexión o de DNS significa que el hosting bloquea la salida. No pegue la llave en la línea de comandos para probar: queda en el historial del shell.
6. **Presupuesto mensual.** `ASSISTANT_MONTHLY_BUDGET_USD` (20 por defecto) es el tope de gasto del mes calendario en hora de Bogotá. El portal calcula el costo de cada pregunta con sus tokens y la tabla de precios, y lo guarda en su línea de Auditoría. Al llegar al tope, ninguna pregunta llega a la API: la página dice «El asistente alcanzó el límite de uso de este mes. Vuelve a estar disponible el 1.º del próximo mes.» y queda en Auditoría. El tope se revisa también antes de cada llamada de una misma pregunta (con lo que ya costó): una pregunta con varias rondas de herramientas se detiene después de la llamada que cruza el tope, sin dejar el historial a medias. El uso y el costo quedan en Auditoría aunque una llamada falle a mitad de la pregunta. Al 80 % y al 100 % los súper administradores reciben un correo, que sale después de enviar la respuesta a la persona (no hay trabajador de colas en el servidor; uno por umbral y mes; el envío queda en `portal_settings`, clave `assistant_budget_alert:AAAA-MM:80`). Se cambia solo en `.env`, con `php artisan optimize` después (punto 4). Configuración muestra el gasto del mes y el tope, sin poder editarlos.
7. **Tiempo de las peticiones.** Una pregunta puede hacer varias llamadas al modelo; `ASSISTANT_DEADLINE_SECONDS` (90) es su tope total, porque en Linux `max_execution_time` no cuenta el tiempo de espera de la red. El portal sube además el límite de PHP a 180 segundos para esa petición cuando es menor (nunca lo baja). Si cPanel no deja cambiarlo, súbalo en **MultiPHP INI Editor** o baje `PORTAL_ASSISTANT_TIMEOUT`.
8. **Encender el interruptor.** Un súper administrador entra a **Administración › Configuración › Asistente del portal** y enciende **Mostrar el asistente a las personas** (se guarda en `portal_settings`, clave `assistant_enabled`). A diferencia del conector de Claude, **empieza apagado**. Un valor guardado que no sea exactamente `true` cuenta como apagado. La tarjeta muestra el modelo de cada rol, el gasto del mes y avisa en rojo si falta la variable o la llave.
9. **Consumo de tokens.** Lo que ahorra:
   - **Caché del prompt.** Las herramientas y las instrucciones son siempre iguales (sin nombres, fechas ni empresa: esos datos van en el mensaje de la persona) y quedan en caché; un segundo punto de caché se mueve con el último mensaje, así lo anterior de la conversación se lee de la caché a una fracción del precio. Por debajo de 512 tokens la API no guarda caché, sin error.
   - **Historial que solo crece.** Cada conversación guarda en la sesión todo lo que el modelo devolvió, con sus bloques de razonamiento y su firma, y lo reenvía igual. Nunca se recorta ni se resume: si se editara, la API rechazaría el razonamiento anterior (error 400 en cuentas nuevas) y se perdería la caché. Por eso, cuando la conversación se llena, hay que empezar una nueva.
   - **Todo fijo por conversación.** El modelo, el esfuerzo, las instrucciones y las herramientas (que dependen del rol) se fijan al empezar. Si cambian (un rol nuevo, otra cuenta, otra configuración o un despliegue que cambia una herramienta), la conversación vuelve a empezar y la página lo avisa.
   - **Resultados cortos.** Cada herramienta devuelve pocas filas para el asistente (`assistant.tool_limits` en `config/assistant.php`) y la consulta misma las limita: nunca se recorta un resultado después.
   - **Lotes (Batch API).** No aplica: el asistente responde en el momento.
   - **Reporte de consumo.** Por SSH: `php artisan portal:assistant-usage --days=7` muestra por día y por uso (`chat` o `borrador`) las preguntas, las llamadas al modelo, los tokens de entrada, de caché escrita y leída y de salida, el porcentaje de aciertos de caché y el costo, y al final el gasto del mes frente al presupuesto. Sirve para comparar antes y después de un cambio.
   - **Perillas de costo:** el modelo de cada rol, `PORTAL_ASSISTANT_EFFORT`, los topes de salida, `PORTAL_ASSISTANT_DAILY_QUESTIONS`, `PORTAL_ASSISTANT_CONVERSATION_TURNS`, los límites de filas y el presupuesto.
10. **Comprobar.** Entre a `/asistente`, pregunte «¿Qué apps tengo?» y compruebe que responde con sus apps. En **Auditoría › Seguridad** debe aparecer «Preguntó al asistente · usó mis_apps · claude-haiku-5-5 · …» con los tokens y el costo, nunca la pregunta. Si responde «El asistente no pudo responder en este momento», el motivo está al final de esa línea:

    | Motivo | Qué revisar |
    |---|---|
    | `auth` | La llave es incorrecta, se revocó o no tiene permiso. |
    | `connection`, `timeout` | Salida a `api.anthropic.com` (punto 5) o `PORTAL_ASSISTANT_TIMEOUT` muy bajo. |
    | `rate_limited`, `overloaded` | Límite de la cuenta de Anthropic o API ocupada. Se pasa solo. |
    | `http_400`, `http_404` | Modelo o parámetro que la API no acepta: revise los modelos y `PORTAL_ASSISTANT_EFFORT`. |
    | `not_configured` | Falta `ANTHROPIC_API_KEY` o no se corrió `php artisan optimize`. |

11. **Privacidad.** El portal no guarda las conversaciones (solo en la sesión de la persona) y Auditoría nunca guarda la pregunta ni la respuesta. Lo que el modelo necesita para responder (la pregunta, la conversación de la sesión y lo que devuelven las herramientas) sí viaja a la API de Anthropic.

12. **Proveedores de modelos (DeepSeek u otro).** Cada rol apunta a «proveedor:modelo» de `config/assistant.php` (`providers`). Hay dos tipos (*drivers*): `anthropic` (el SDK oficial, por defecto) y `openai_compatible` (Chat Completions por HTTPS, con el cliente HTTP de Laravel), con DeepSeek ya preparado:

    ```dotenv
    ASSISTANT_MODEL=anthropic:claude-haiku-5-5
    ASSISTANT_EDITOR_MODEL=anthropic:claude-sonnet-5-5
    ASSISTANT_FALLBACK_MODEL=anthropic:claude-opus-5-5

    # Para usar DeepSeek en un rol, por ejemplo:
    # ASSISTANT_MODEL=deepseek:deepseek-chat
    DEEPSEEK_API_KEY=<llave de DeepSeek>
    DEEPSEEK_BASE_URL=https://api.deepseek.com
    ```

    Los nombres `PORTAL_ASSISTANT_MODEL`, `PORTAL_ASSISTANT_EDITOR_MODEL` y `PORTAL_ASSISTANT_FALLBACK_MODEL` siguen funcionando; `ASSISTANT_*` tiene prioridad. Un modelo sin proveedor es de Anthropic. Después, `php artisan optimize`. Cambiar el proveedor o el modelo de un rol empieza una conversación nueva para cada persona en su siguiente pregunta: el modelo nunca cambia a mitad de conversación.

    - **Por verificar.** La dirección, los nombres de modelo (`deepseek-chat`, `deepseek-reasoner`), los campos de uso (`prompt_cache_hit_tokens`, `prompt_cache_miss_tokens`) y los precios de DeepSeek en `config/assistant.php` son provisionales: confírmelos en https://api-docs.deepseek.com antes de usarlos; el presupuesto depende de esos precios.
    - **Ley 1581.** Se ha informado que la API propia de DeepSeek guarda los datos en China. Antes de usarla con datos de empleados hace falta una revisión legal de la transferencia internacional de datos (Ley 1581 de 2012). Configuración lo advierte en rojo cuando algún rol usa un proveedor que no es Anthropic. Una alternativa es el modelo de pesos abiertos alojado por la empresa o por un tercero con otra dirección (`DEEPSEEK_BASE_URL` o un proveedor nuevo con el mismo *driver*), que también requiere revisar dónde quedan los datos.
    - **Solo en Anthropic.** La caché del prompt, el razonamiento adaptativo, el esfuerzo (`PORTAL_ASSISTANT_EFFORT`) y el historial con razonamiento preservado existen solo con el *driver* `anthropic`. El *driver* `openai_compatible` nunca los envía; DeepSeek tiene su propia caché automática, que el portal cuenta como «caché leída».
    - **Igual en todos.** Las mismas herramientas, las mismas reglas de visibilidad y el mismo borrador que solo propone. Un `finish_reason` `content_filter` cuenta como `refusal` (pasa al modelo de respaldo). El uso se guarda en los mismos campos (`prompt_tokens` → entrada, `completion_tokens` → salida) y el presupuesto mensual suma todos los proveedores.
    - Solo direcciones `https://`; espera `PORTAL_ASSISTANT_TIMEOUT` segundos y reintenta una vez ante 429, 5xx o errores de conexión. Sin la llave del proveedor de un rol, la página dice que el asistente no está disponible.
13. **Proveedor secundario (respaldo por fallas).** Anthropic es el proveedor principal; DeepSeek puede ser el segundo. Es distinto del modelo de respaldo por `refusal` (`ASSISTANT_FALLBACK_MODEL`), que solo actúa cuando el modelo no quiere responder.

    ```dotenv
    ASSISTANT_SECONDARY_MODEL=deepseek:deepseek-chat
    # Opcional, para los editores; vacío: el mismo de arriba.
    ASSISTANT_SECONDARY_EDITOR_MODEL=
    ASSISTANT_PRIMARY_COOLDOWN_MINUTES=30
    ```

    Vacío `ASSISTANT_SECONDARY_MODEL`: no hay respaldo y todo sigue como antes. Después, `php artisan optimize`.

    - **Cuándo pasa al secundario.** Por el **tipo** de error (el `->type` del SDK de Anthropic, o el código HTTP del otro proveedor), nunca por el texto, y solo después de los reintentos del propio cliente: 402 `billing_error` (sin saldo), 401 `authentication_error` o falta la llave, 429 `rate_limit_error`, 529 `overloaded_error`, cualquier 5xx, y errores de conexión o de tiempo de espera. En DeepSeek: 402 (saldo insuficiente; verifique el código en https://api-docs.deepseek.com), 401, 429, 5xx, conexión y tiempo de espera.
    - **Cuándo no.** Un 400 `invalid_request_error`, un 413 o un mensaje demasiado largo son errores del portal: no pasan al secundario, quedan en `storage/logs` («The assistant model rejected the request.», con el motivo) y la persona ve el error. El **presupuesto mensual** del portal es uno solo para todos los proveedores: al alcanzarlo el asistente se detiene, nunca pasa al secundario.
    - **Límite de gasto de la consola de Anthropic.** Por confirmar: la guía de la API no documenta con qué error responde Anthropic cuando se alcanza el límite de gasto de un espacio de trabajo. Se espera un 402 `billing_error` o un 429; los dos pasan al secundario. Al configurarlo, compruebe en Auditoría el motivo con el que llegó.
    - **Cómo pasa.** Un proveedor no puede leer el razonamiento ni las herramientas de otro, así que el secundario recibe la conversación como texto (preguntas y respuestas visibles). Solo cuando el secundario responde, la conversación queda en él el resto de su vida (si los dos fallan, el historial no cambia): nunca vuelve al principal a mitad de conversación. La página muestra «Respondiendo con el proveedor secundario.» La siguiente **Nueva conversación** prueba de nuevo el principal.
    - **Espera (circuito).** Tras un error de saldo o de llave, el principal se marca como no disponible en la caché durante `ASSISTANT_PRIMARY_COOLDOWN_MINUTES` (30); tras un 429, 5xx o un tiempo de espera, 2 minutos. Mientras tanto, las conversaciones nuevas empiezan directo en el secundario, sin pagar primero una llamada fallida.
    - **Aviso.** En el primer error de saldo o de llave, los súper administradores reciben un correo (sale después de la respuesta), una vez por cada espera: «Anthropic sin saldo o llave inválida; el asistente usa el proveedor secundario.» Cada cambio de proveedor queda en Auditoría con el de origen, el de destino y el tipo de error (nunca el mensaje).
    - **Si los dos fallan**, la página dice «El asistente no está disponible en este momento.» y queda en Auditoría.
    - El uso y el costo se guardan con el proveedor y el modelo que respondieron de verdad, y todos cuentan para el mismo presupuesto.
    - **Ley 1581.** Con el secundario en DeepSeek, una falla de Anthropic envía a DeepSeek los datos del portal que la respuesta necesita (también datos de empleados). Aplica la misma revisión legal del punto 12 antes de configurarlo.
---

## 2. Publicar cambios (despliegue de rutina)

### 2.1 En el equipo local

```bash
npm install
npm run build
php artisan test
git add <archivos-modificados> public/build
git commit -m "feat: descripción del cambio"
git push
```

`npm run build` es obligatorio cuando cambian estilos, scripts o vistas, porque el servidor no puede compilarlos.

### 2.2 En el servidor (por SSH)

```bash
cd /home/<usuario>/<ruta-app>
bash deploy/deploy.sh
```

El script hace, en orden:

1. Pone el sitio en mantenimiento (`php artisan down --retry=60`).
2. Descarga el código nuevo (`git pull --ff-only`).
3. Instala dependencias de PHP sin las de desarrollo.
4. Ejecuta migraciones (`php artisan migrate --force`).
5. Regenera cachés de configuración, rutas, vistas y eventos (`php artisan optimize`).
6. Crea el enlace `public/storage` si falta.
7. Vuelve a poner el sitio en línea (`php artisan up`).

Si algún paso falla, el script se detiene y **siempre** vuelve a poner el sitio en línea. Lea el error en pantalla antes de reintentar.

Si Composer no está disponible como `composer`, indique el comando así:

```bash
COMPOSER_BIN="php ~/composer.phar" bash deploy/deploy.sh
```

### 2.3 Seguridad: administradores, grupos y cabeceras (una sola vez)

Cambios de las PR #56 y #57 (fusionadas el 2026-10-27). Hágalos en este orden: los pasos 1 a 5 **antes** de `deploy.sh`; los pasos 6 a 12, **después**.

**Antes del despliegue**

1. **Revisar los grupos de las reglas.** Después del despliegue, un grupo `APP-…` que sea de Microsoft 365 o de Teams deja de dar acceso. Este comando solo lee y muestra `Empresa | Grupo` de cada regla:

   ```bash
   cd /home/<usuario>/<ruta-app>
   php artisan tinker --execute='App\Models\AppAccessRule::where("type", "group")->with("company")->get()->map(fn ($r) => $r->company?->short_name." | ".$r->value)->unique()->values()->dump();'
   ```

   Para cada grupo, en el Entra admin center (https://entra.microsoft.com) del inquilino de esa empresa › **Groups** › **All groups** › busque el nombre. La columna **Group type** debe decir **Security**. Si dice **Microsoft 365**, cree un grupo de **seguridad** con el mismo nombre, pase los miembros y quite o renombre el de Microsoft 365.

2. **ID del grupo de administradores.** En el inquilino de Segurex: Entra admin center › **Groups** › **All groups** › busque `APP-PortalAdmin` › copie **Object ID**. Debe ser un grupo de **seguridad**.

3. **ID del inquilino de Segurex.** Entra admin center › **Overview** › copie **Tenant ID**.

4. **Dato `auth_time` en la app.** Entra admin center › **App registrations** › *Portal RYR* › **Token configuration** › **Add optional claim** › tipo **ID** › marque **auth_time** › **Add**. Se configura una sola vez, en el registro de la app (es multiinquilino). Sin este dato, las acciones que piden confirmar con Microsoft muestran «No pudimos confirmar tu identidad con Microsoft» (manual, sección 1.3).

5. **Variables en `.env`** del servidor. Sin las dos primeras, nadie queda como administrador del portal:

   ```dotenv
   PORTAL_ADMIN_GROUP_ID=<Object ID del paso 2>
   PORTAL_ADMIN_TENANT_ID=<Tenant ID del paso 3>
   PORTAL_REAUTH_MAX_AGE_SECONDS=300
   ```

   La tercera es opcional: 300 segundos (5 minutos) es el valor por defecto. Después, ejecute `bash deploy/deploy.sh`.

**Después del despliegue** (`deploy.sh` ya corrió las migraciones)

6. **Aplicar la configuración y los administradores:**

   ```bash
   php artisan config:cache
   php artisan portal:sync-portal-admins
   ```

   Revise la lista que imprime: `-` son quienes salen del rol y `+` quienes entran. Solo deben quedar los miembros del grupo de Segurex. Cada cambio queda en Auditoría › Seguridad como «Sistema».

7. **Cerrar todas las sesiones**, para que todos vuelvan a entrar una vez y el portal lea de nuevo sus grupos. Revise qué driver usa el servidor:

   ```bash
   grep SESSION_DRIVER .env
   ```

   - Si es `database` (o la línea no existe, porque ese es el valor por defecto):

     ```bash
     php artisan tinker --execute='dump(DB::table(config("session.table"))->delete());'
     ```

     Imprime cuántas sesiones se cerraron.
   - Si es `file`:

     ```bash
     find storage/framework/sessions -type f ! -name .gitignore -delete
     ```

   Qué ve la gente: en su siguiente clic llega a la página de inicio de sesión. Si estaba en un formulario de Administración, puede aparecer antes un aviso de «página expirada»; al recargar, llega al inicio de sesión. Entra con Microsoft como siempre (normalmente sin escribir la contraseña) y vuelve a ver sus apps. No se pierde nada, salvo un formulario que no se había guardado. Mejor hacerlo fuera de las horas de más uso.

8. **Borrar las fotos públicas** que guardaban versiones anteriores (una sola vez):

   ```bash
   php artisan portal:purge-public-avatars --force
   ```

   Borra `storage/app/public/avatars` y pone en blanco `users.photo_path`. Se puede repetir sin riesgo. Después, `https://apps.segurexlatam.net/storage/avatars/1.jpg` debe responder 404.

9. **HTTPS obligatorio.** Lo hace `public/.htaccess`: toda petición por `http://` se redirige a `https://`. En cPanel no se puede activar **Force HTTPS Redirect** para `apps.segurexlatam.net` porque es un dominio adicional (muestra «?»); no hace falta.

10. **Comprobar cabeceras** (por SSH, desde cualquier equipo):

    ```bash
    curl -sI http://apps.segurexlatam.net/login
    ```

    Esperado: `301` con `Location: https://apps.segurexlatam.net/login`.

    ```bash
    curl -sI https://apps.segurexlatam.net/login | grep -i strict-transport
    ```

    Esperado: `strict-transport-security: max-age=31536000; includeSubDomains`, una sola línea. Si no aparece, el TLS termina antes de Apache: avise al equipo de desarrollo, porque la redirección de `.htaccess` haría un bucle y hay que quitarla.

    ```bash
    curl -sI https://apps.segurexlatam.net/login
    ```

    Esperado: `x-frame-options: DENY`, `x-content-type-options: nosniff`, `referrer-policy`, `permissions-policy`, y **sin** `x-powered-by`.

11. **Dejar de crear grupos de seguridad a los usuarios.** En cada inquilino (Segurex, Derivados y cada empresa que se agregue): Entra admin center › **Groups** › **General** › **Users can create security groups in Azure portals, API or PowerShell** = **No** › **Save**. Hasta hacerlo, la regla de los grupos de seguridad no queda cerrada del todo.

12. **Probar:**
    - Confirmar una acción sensible (por ejemplo, Integraciones › crear token): debe pedir la contraseña y volver a la página.
    - Un usuario con un grupo `APP-…` de seguridad ve su app después de volver a entrar. Un Team con el mismo nombre no da acceso, y en Administración › Aplicaciones › «Quién la ve» la regla de un grupo de Microsoft 365 aparece marcada.

---

## 3. Volver a una versión anterior (rollback)

1. Identifique la versión buena:

   ```bash
   git log --oneline -10
   ```

2. En el equipo local, revierta el commit problemático y publíquelo como un cambio normal:

   ```bash
   git revert <commit>
   git push
   ```

3. En el servidor, ejecute `bash deploy/deploy.sh`.

Si el cambio incluía una migración que alteró tablas, revise antes con el equipo de desarrollo si hace falta `php artisan migrate:rollback --step=1`. Ese comando puede borrar datos: antes haga una copia con `php artisan backup:run` (sección 6).

---

## 4. Lo que NO se debe hacer

- No instalar ni ejecutar Node, `npm` ni Vite en el servidor. Los archivos se compilan en local.
- No subir el archivo `.env` a Git ni copiarlo en correos o chats.
- No apuntar el document root a la raíz del proyecto; siempre a `public`.
- No editar archivos directamente en el servidor: todo cambio entra por Git.
- No dejar `APP_DEBUG=true` en producción.
- No resolver conflictos de Git ni usar `git reset --hard` en el servidor. Si `git pull --ff-only` falla, revise `git status` y avise al equipo de desarrollo.
- No crear procesos que queden corriendo (workers, websockets): el hosting compartido no los permite. Las tareas periódicas van en el scheduler y las ejecuta el cron.

---

## 5. Problemas frecuentes

| Síntoma | Qué revisar |
|---|---|
| Error 500 | `storage/logs/laravel-AAAA-MM-DD.log` |
| Estilos rotos | Que `public/build` esté en el commit desplegado (`npm run build` en local y commit) |
| Sitio en mantenimiento | `php artisan up` |
| Cambios en `.env` no se aplican | `php artisan optimize` (regenera la caché de configuración) |
| Error de permisos | `chmod -R u+rwX storage bootstrap/cache` |
| El celular no ofrece instalar el portal | Que el sitio abra con HTTPS y que `/manifest.webmanifest` y `/sw.js` respondan 200 (paso 1.11) |
| Un equipo sigue viendo la versión anterior | Cerrar todas las pestañas o ventanas del portal y abrirlo de nuevo con conexión |
| El ícono instalado es el neutro | Que la empresa tenga ícono (Administración › Empresas), que PHP tenga GD y que exista `public/storage` |
| La tarjeta de respaldo dice «terminó con error» | El correo enviado a los súper administradores y `storage/logs/laravel-AAAA-MM-DD.log`; casi siempre es la ruta de `mysqldump` (paso 1.12) |
| «Respaldar ahora» se queda en «Programado…» | Que el cron esté corriendo (paso 1.9); la copia empieza en el minuto siguiente |
| `portal:mail-test` dice `HTTP 403 (ErrorAccessDenied)` | Que la app tenga el rol `Application Mail.Send` con el ámbito del buzón (`Test-ServicePrincipalAuthorization` debe dar `InScope True`), que `MAIL_FROM_ADDRESS` sea ese buzón y que hayan pasado hasta 2 horas desde el cambio (paso 1.10) |
| `portal:mail-test` dice `HTTP 401`, `invalid_client`, `AADSTS7000215` o `AADSTS7000222` | El secreto de la app es incorrecto o venció: cree uno nuevo en Entra, cámbielo en `MICROSOFT_CLIENT_SECRET` y ejecute `php artisan optimize` |
| `portal:mail-test` dice «The graph mailer is not configured» | Falta la clave de `.env` que nombra el mensaje (paso 1.10); luego `php artisan optimize` |
| Un anuncio dice «No se pudo enviar (ErrorAccessDenied)» en **Correo a las listas** | El permiso de envío del buzón remitente en el inquilino de esa empresa (paso 1.13): `Test-ServicePrincipalAuthorization` debe dar `InScope True`; después de un cambio, espere hasta 2 horas y pulse **Reintentar** |
| Claude dice que no puede conectarse al portal | Que `PORTAL_MCP_ENABLED=true` esté en `.env` y que corrió `php artisan optimize`, que el interruptor de Administración › Configuración › Conector de Claude esté encendido, y que los tres `curl` del paso 1.15 respondan como allí se indica |
| Error 500 al aprobar la conexión o al pedir el token, con `Key path`, `Unable to read key` o `permissions are not correct` en el log | Faltan las llaves de Passport, están incompletas en `.env` o los archivos tienen otros permisos (paso 1.15) |

---

## 6. Respaldos: dónde están, descargarlos y restaurarlos

### 6.1 Dónde están y cuántas se guardan

Las copias están en el servidor, fuera de la carpeta pública (nadie las puede abrir desde el navegador):

```text
/home/<usuario>/<ruta-app>/storage/app/private/backups/portal-ryr/AAAA-MM-DD-HH-MM-SS.zip
```

El nombre es la fecha y hora (Bogotá) en que se hizo. Cada `.zip` tiene:

- `db-dumps/mysql-<base-de-datos>.sql`: la base de datos completa (empresas, apps, accesos, anuncios, configuración, auditoría, uso).
- `storage/app/public/...`: los archivos subidos (logos e íconos de empresas y apps, imágenes de anuncios).

No incluye el código (está en Git) ni el archivo `.env` (guárdelo aparte, en un lugar seguro).

Se conservan **todas las copias de los últimos 7 días** y después **una por semana durante 4 semanas más**; las más viejas se borran a las 02:00. La copia más reciente nunca se borra. Si entre todas pasan de 2000 MB, se borran las más antiguas.

Para ver cuánto ocupan:

```bash
cd /home/<usuario>/<ruta-app>
php artisan backup:list
du -sh storage/app/private/backups
```

En cPanel, **Disk Usage** muestra el espacio total del plan. Las copias cuentan dentro de ese espacio.

### 6.2 Descargar una copia

Las copias tienen datos personales (nombres y correos). Guárdelas en un lugar seguro y bórrelas de su equipo cuando ya no las necesite; no las envíe por correo ni chat.

**Con el administrador de archivos de cPanel:**

1. Abra cPanel › **File Manager**.
2. Entre a `<ruta-app>/storage/app/private/backups/portal-ryr`.
3. Seleccione el `.zip` de la fecha que necesita y pulse **Download**.

**Por SSH, desde su equipo:**

```bash
scp <usuario>@<servidor>:/home/<usuario>/<ruta-app>/storage/app/private/backups/portal-ryr/<archivo>.zip .
```

### 6.3 Restaurar una copia paso a paso

Restaurar reemplaza los datos actuales por los de la copia: todo lo que se hizo después de esa fecha se pierde. Hágalo solo si es necesario y avise antes a los administradores.

1. Entre por SSH y vaya a la aplicación:

   ```bash
   cd /home/<usuario>/<ruta-app>
   ```

2. Haga una copia del estado actual, por si necesita volver atrás, y anote su nombre:

   ```bash
   php artisan backup:run
   php artisan backup:list
   ```

3. Ponga el sitio en mantenimiento:

   ```bash
   php artisan down
   ```

4. Descomprima la copia elegida en una carpeta de trabajo **fuera** de la aplicación:

   ```bash
   mkdir -p ~/restaurar
   unzip storage/app/private/backups/portal-ryr/<archivo>.zip -d ~/restaurar
   ls ~/restaurar/db-dumps
   ```

5. Restaure la base de datos. Use los valores `DB_HOST`, `DB_USERNAME` y `DB_DATABASE` del `.env`; el comando pide la contraseña (`DB_PASSWORD`):

   ```bash
   mysql -h <DB_HOST> -u <DB_USERNAME> -p <DB_DATABASE> < ~/restaurar/db-dumps/mysql-<DB_DATABASE>.sql
   ```

   El archivo borra y vuelve a crear cada tabla, así que no hay que vaciar la base antes. Si no tiene SSH, puede importarlo en cPanel › **phpMyAdmin** › base de datos › **Import**, si el tamaño lo permite.

6. Restaure los archivos subidos (agrega y reemplaza; no borra los que no estén en la copia):

   ```bash
   cp -a ~/restaurar/storage/app/public/. storage/app/public/
   ```

7. Borre la caché (la configuración del portal se guarda en caché) y vuelva a poner el sitio en línea:

   ```bash
   php artisan cache:clear
   php artisan optimize
   php artisan up
   ```

8. Revise el portal: entre, abra Administración › Empresas y Aplicaciones, y confirme que los datos son los esperados.

9. Borre la carpeta de trabajo:

   ```bash
   rm -rf ~/restaurar
   ```
