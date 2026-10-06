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

**Correo (avisos a los administradores).** El portal envía un correo cuando se crea una empresa, cuando cambia su inquilino y cuando se activa. Se envía en el momento (no hay cola). Configure el buzón SMTP de cPanel:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=mail.<dominio>
MAIL_PORT=465
MAIL_USERNAME=<buzón>@<dominio>
MAIL_PASSWORD=<contraseña-del-buzón>
MAIL_FROM_ADDRESS=<buzón>@<dominio>
MAIL_FROM_NAME="Portal RYR"
```

Si el correo falla, la acción se guarda igual y el error queda en `storage/logs` («Company notice could not be sent.»).

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

Si el cambio incluía una migración que alteró tablas, revise antes con el equipo de desarrollo si hace falta `php artisan migrate:rollback --step=1`. Ese comando puede borrar datos: no lo ejecute sin respaldo de la base de datos.

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
