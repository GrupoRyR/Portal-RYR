# Guía de despliegue — Portal RYR

Esta guía explica cómo instalar el Portal RYR por primera vez en el hosting de GoDaddy (cPanel) y cómo publicar cambios después.

- **URL de producción:** https://apps.segurexlatam.net
- **Servidor:** GoDaddy, cPanel compartido, PHP 8.3, MySQL 8, acceso SSH y Git.
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

### 1.4 Base de datos MySQL

1. En cPanel, abra **MySQL Databases**.
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

**Logos de las empresas.** Se guardan en `storage/app/public/brand/<empresa>/` y se publican en `/storage/...`. El script de despliegue ya crea el enlace; si los logos no se ven, ejecútelo a mano:

```bash
php artisan storage:link
```

**Grupos APP-… de una empresa nueva.** En el paso 3 del asistente se indica el comando. Lo ejecuta un administrador de Microsoft 365 de esa empresa, en su equipo con Windows:

```powershell
Install-Module Microsoft.Graph -Scope CurrentUser   # solo la primera vez
cd <carpeta-del-proyecto>\docs\scripts
.\crear-grupos-app.ps1 -TenantId <id-de-inquilino>
```

El script pide iniciar sesión, crea solo los grupos que falten (por ahora `APP-PortalAdmin`) e informa cuáles creó y cuáles ya existían. Se puede ejecutar varias veces. Si PowerShell bloquea el script, ejecute antes `Set-ExecutionPolicy -Scope Process RemoteSigned`.

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
