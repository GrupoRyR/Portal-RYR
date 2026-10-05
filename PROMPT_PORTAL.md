# PROMPT — Portal de Aplicaciones RYR · Grupo Empresarial RYR

> Documento de contexto y especificación para construir **el Portal de aplicaciones (intranet)** de las dos empresas.
> Úsalo como prompt inicial en cada sesión de desarrollo de este proyecto.
> Versión 1.5 · 2026-10-05
>
> **Cambios v1.5:** las empresas ya no están fijas: se crean y editan en **Administración → Empresas** (sección 5.1) con su organización de Microsoft, dominios, logo y colores. Segurex y Derivados quedan como datos iniciales. Agregar una tercera empresa no requiere tocar código.
>
> **Cambios v1.4:** datos reales: catálogo inicial de apps (sección 1.1), IDs de inquilino, dominio de Derivados, identidad visual de cada empresa (sección 4b), grupos de Microsoft creados desde cero con una convención de nombres (sección 5), categorías iniciales y tipo de app "local" (instalada en el equipo).
>
> **Cambios v1.3:** nueva **API para sistemas externos** (sección 6b): leer el catálogo de apps y reportar el estado de cada app, con un token por sistema. El botón "Soporte TI" envía la app de origen en la URL. Nuevo estado automático "con fallas" en las tarjetas.
>
> **Cambios v1.1:** el diseño es prioridad: se construye con las skills **frontend-design** y **design**, con diseño aprobado antes de programar (sección 4b), sistema de íconos propio para cada app, experiencia optimizada para celular y tablet, y metas de rendimiento. El portal se aloja en GoDaddy junto con las apps.

---

## 0. Rol e instrucciones para el asistente

Eres un desarrollador senior full-stack (Laravel + MySQL) que construye el **Portal de Aplicaciones RYR**: la puerta de entrada donde cada colaborador inicia sesión con su cuenta de Microsoft y ve las aplicaciones a las que tiene acceso. Reglas de trabajo:

1. Responde y documenta en **español**. El código, las variables y las tablas van en inglés; los textos visibles de la interfaz, en español.
2. **El diseño es prioridad.** Todo el frontend se hace con la skill **`frontend-design`** y la skill **`design`**. Antes de la primera línea de UI, confirma con Jhon que las dos estén instaladas y cárgalas. **No se programa ninguna pantalla sin que su diseño esté aprobado** (sección 4b).
3. Construye por fases (sección 9). Al terminar cada fase, entrega un resumen de lo hecho, cómo probarlo y lo pendiente.
4. Nunca guardes contraseñas, tokens ni llaves en el código ni en el repositorio: van en `.env`. Tampoco se piden por chat.
5. Debe desplegarse en **hosting compartido cPanel de GoDaddy**, sin procesos permanentes (sin Redis, sin supervisor, sin websockets propios).
6. El portal es **simple y rápido**: es lo primero que ve todo el mundo cada día. Su función es mostrar y abrir aplicaciones; no incluye tickets, inventario ni otros módulos de gestión.
7. Si un requerimiento es ambiguo, pregunta antes de asumir.

---

## 1. Contexto

- **Grupo Empresarial RYR**, hoy con dos empresas totalmente independientes. **Pueden llegar más:** nada en el código puede depender de que sean dos (ver sección 5.1). Empresas iniciales:
  - **Segurex Latam** — `@segurex.com` — su propia organización (inquilino) de Microsoft 365.
  - **Soluciones y Derivados de Colombia S.A.S.** ("Derivados") — `@derivadoscol.com` — su propia organización de Microsoft 365.
- Algunos usuarios de Derivados existen como **invitados** en Segurex.
- **Administradores del portal:** Jhon Castro (`jhon.castro@segurex.com`), administrador de las dos organizaciones de Microsoft 365, y Leonardo Herrera (Gerente de TI).
- **Las aplicaciones que se publican en el portal son desarrollos propios de Jhon**: unas alojadas en **GoDaddy** y otras en **Vercel**. Algunas ya inician sesión con Microsoft (Graph) y otras con correo y contraseña propios.

### 1.1 Catálogo inicial de aplicaciones

| slug | Nombre | Empresa | Tipo | URL | Hosting | Stack | Login hoy | Quién la ve | Categoría |
|---|---|---|---|---|---|---|---|---|---|
| `toma-pedidos` | Toma Pedidos SAP | Segurex | Web | https://pedidos.segurex.com.co | GoDaddy | Laravel + MySQL | Microsoft (Graph) | Asesores, gerentes de ventas, analista de ventas, TI, Gerencia | Ventas |
| `portafolio` | Portafolio de productos | Derivados | Web | https://portafolio.derivadoscol.com.co/login.php | GoDaddy | PHP + MySQL | Correo y contraseña | Gerencia y las personas a quienes el gerente les da permiso | Ventas |
| `cuentas-por-pagar` | Cuentas por pagar (saldo y antigüedad) | Segurex | Web | https://sgx-cuentas-por-pagar.vercel.app/login | Vercel | Next.js | Correo y contraseña | Gerente de tesorería, auxiliar de tesorería, TI | Tesorería |
| `cartera` | Visualizador de cartera | Segurex | Web | https://sgx-visualizador-cartera.vercel.app/login | Vercel | Next.js | Correo y contraseña | Asesores, gerentes de ventas, TI, gerente de cartera, auxiliar de cartera | Contabilidad |
| `camaras` | Cámaras | Segurex | Local | — | Instalada en el equipo | Python | Instalación local | TI y Recursos Humanos | TI o Sistemas |

- **Orden sugerido para migrar a "Entrar con Microsoft"** (sección 6): Toma Pedidos ya usa Microsoft, solo se ajusta a multiinquilino y a los grupos; luego Cuentas por pagar y Cartera; Portafolio al final, porque es PHP sin framework y es la única de Derivados.
- **Apps de tipo "local"** (como Cámaras): no tienen URL. La tarjeta abre una ficha con instrucciones ("Esta aplicación está instalada en tu equipo; búscala en el menú Inicio"), un enlace de descarga opcional y el botón de Soporte TI. Igual se controla quién la ve.

---

## 2. Stack

| Elemento | Definición |
|---|---|
| Hosting | GoDaddy · cPanel · PHP 8.3 · SSH · MySQL. El portal vive en el mismo hosting de GoDaddy donde estarán las apps |
| Backend | Laravel 11 |
| Base de datos | MySQL 8 (utf8mb4) |
| Frontend | Blade + Livewire 3 + Tailwind CSS (o lo que la skill de diseño recomiende), compilado con Vite en local y subido ya compilado. **Mobile-first, responsive y PWA** (instalable en celular, tablet y escritorio) |
| Login | `laravel/socialite` + `socialiteproviders/microsoft`, aplicación de Entra **multiinquilino** |
| Graph | Lectura de perfil, foto y grupos del usuario |
| Tareas | Cron de cPanel cada minuto: `php artisan schedule:run` |
| Auditoría | `spatie/laravel-activitylog` |
| Despliegue | Git + SSH |

---

## 3. Autenticación (las dos empresas)

- **Solo login con Microsoft.** No hay usuarios locales, salvo una cuenta de emergencia de administrador (protegida, con contraseña fuerte y 2FA), por si Microsoft falla.
- Aplicación registrada **una vez** en el inquilino de Segurex como **multiinquilino**, con **consentimiento de administrador en los dos inquilinos**.
- Endpoint `organizations`; solo se aceptan los inquilinos de las **empresas activas** de la tabla `companies` (sección 5.1). Cualquier otro se rechaza. Datos iniciales (seeder):
  - Segurex: `97cd024c-cd7a-419b-b385-f47b5f5816c8`
  - Soluciones y Derivados: `54f53029-e6e2-4b5f-a862-3454ae886468`
- La lista de inquilinos permitidos se cachea y se invalida al guardar una empresa. Si la base de datos no responde, la cuenta de emergencia sigue funcionando.
- La **empresa del usuario se determina por el ID de inquilino** (`tid`) del token.
- Permisos de Graph: `openid`, `profile`, `email`, `User.Read`, `GroupMember.Read.All` (para saber a qué grupos pertenece cada usuario).
- **Invitados:** si un usuario de Derivados entra a través de Segurex como invitado, se vincula por correo a su cuenta de Derivados para no duplicarlo.
- Al iniciar sesión se actualizan nombre, cargo, foto y grupos del usuario.

---

## 4. Pantalla principal

- Saludo con nombre y foto, logo de la empresa del usuario.
- **Tarjetas de las aplicaciones a las que tiene acceso**, agrupadas por categoría. Cada tarjeta: ícono, nombre, descripción corta, indicador de estado (activa / en mantenimiento / con fallas).
- Al dar clic se abre la aplicación real (misma pestaña o nueva, configurable por app). Si la app está en mantenimiento, se muestra el aviso en vez de abrirla. Si está **con fallas** (reportado por un sistema externo, sección 6b), la tarjeta se marca en rojo con el mensaje ("Estamos revisando") pero **se deja abrir**, porque la falla puede ser parcial.
- **Favoritos** (fijar arriba) y **recientes**.
- **Buscador** de aplicaciones.
- **Enlaces rápidos** de Microsoft 365: Outlook, Teams, OneDrive, SharePoint.
- **Anuncios** por empresa (o para las dos): texto, imagen opcional, fecha de inicio y fin, fijado sí/no.
- Botón **"Soporte TI"** (visible en celular y escritorio) que **redirige a la plataforma de tickets de soporte**. La URL se configura en Administración → Configuración (`support_url`); si no está configurada, el botón no se muestra.
  - Al abrirla, el portal agrega a la URL de dónde viene el usuario: `?origen=portal&empresa={slug de la empresa}&app={slug}`. `app` es la última app que abrió el usuario en los últimos 30 minutos (si no hay, se omite), para que el ticket llegue con la app ya seleccionada. Nunca se envían datos personales en la URL: el usuario se identifica en el destino con su propio login de Microsoft.
- Si el usuario no tiene ninguna app asignada, mensaje claro: "Aún no tienes aplicaciones asignadas. Solicítalas a TI."

---

## 4b. Diseño, íconos y experiencia en celular y tablet

### Proceso de diseño
1. **Dirección visual:** con la skill `design`, proponer 2–3 direcciones visuales (paleta, tipografía, estilo de tarjetas, ilustración) que funcionen para **cualquier empresa del grupo**: un diseño base neutro que toma el logo y los colores de la empresa de quien inicia sesión. Probar el diseño con las dos marcas actuales y con una tercera inventada, para asegurar que una empresa nueva se vea bien sin rediseñar.
2. **Mockups** de las pantallas clave, en **celular, tablet y escritorio**: inicio de sesión, inicio con tarjetas, búsqueda, sin apps asignadas, app en mantenimiento, anuncios, y las pantallas de administración.
3. Jhon aprueba; luego se implementa con la skill `frontend-design`, fiel al diseño aprobado.
4. Se documenta un **mini sistema de diseño** (colores, tipografía, espaciados, componentes, modo claro y oscuro) para reutilizarlo en las demás apps del grupo.

### Identidad de cada empresa
- **No se crea un logo del Grupo RYR.** El portal toma la marca de la empresa de quien inicia sesión, según su **inquilino** (no por el dominio del correo, porque un usuario de Derivados puede existir también como invitado en Segurex):
  - **Segurex:** logo `logo-segurex.png`; naranja `#FF671F` (acento: botones, enlaces, estados activos) y gris `#666666` (textos secundarios, íconos).
  - **Derivados:** logo `logo-derivados.png`; azules `#183F94` (principal), `#3E60A8`, `#6D94E8` (acento claro, útil en modo oscuro), `#03256C` y `#031D54` (fondos oscuros, encabezados).
- **Antes de iniciar sesión** todavía no se sabe la empresa: la pantalla de login es neutra (fondo claro, sin color de empresa) con los logos de las empresas activas y un solo botón "Entrar con Microsoft". Con 4 o más empresas, los logos pasan a una fila desplazable o se reemplazan por un texto neutro.
- Todo el diseño usa variables de color (`--brand-primary`, `--brand-accent`, etc.) que se llenan con los colores de la empresa guardados en Administración, así un solo diseño sirve para todas. Verificar el contraste: el naranja `#FF671F` con texto blanco no llega a AA en texto pequeño; en botones usar texto oscuro o un naranja más oscuro solo para el fondo del botón.
- Los logos están en la carpeta `portal/marca/` del proyecto (PNG con fondo transparente). Si existen en SVG, se prefieren.

### Íconos de las aplicaciones
- **Cada app tiene un ícono propio diseñado** dentro de un mismo sistema: misma retícula, mismo grosor de trazo, mismas esquinas y paleta, para que el portal se vea uniforme aunque las apps sean distintas.
- Formato **SVG** (nítido en cualquier pantalla) con variante para modo oscuro; se exportan también PNG de 192 y 512 px para usarlos como **ícono PWA y favicon de la propia app**.
- Se diseñan a partir de la tabla de apps (nombre y función de cada una). Las apps nuevas siguen la misma plantilla.
- Desde Administración se puede subir o reemplazar el ícono de una app.

### Celular y tablet (optimización)
- **Mobile-first:** se diseña primero para celular y luego se amplía.
  - Celular: 2 columnas de tarjetas, buscador arriba, barra inferior con Inicio · Favoritos · Buscar · Perfil.
  - Tablet: 3–4 columnas, aprovechando horizontal y vertical.
  - Escritorio: 5–6 columnas con anuncios a un lado.
- Zonas táctiles de **mínimo 44 × 44 px**, sin acciones que dependan de pasar el mouse por encima.
- **PWA instalable**: ícono en la pantalla de inicio del celular, pantalla de carga con el logo, se abre como app sin barra del navegador.
- Funciona con conexión lenta: el **catálogo de tarjetas queda en caché** y se muestra al instante; si no hay internet, aviso claro.
- Respeta el modo oscuro del sistema y el tamaño de letra del usuario.
- **Metas de rendimiento:** Lighthouse ≥ 90 en rendimiento y accesibilidad en celular; la pantalla principal carga en menos de 2 s en 4G; imágenes en WebP o SVG, sin librerías pesadas.
- Se prueba en Android, iPhone (Safari) y tablet antes de publicar cada fase.

---

## 5. Administración

### 5.1 Empresas

Las empresas se administran desde **Administración → Empresas**; no hay nada fijo en el código. Para cada una:
- nombre, nombre corto y `slug` (por ejemplo `segurex`);
- **ID de inquilino de Microsoft** y **dominios de correo** (uno o varios);
- logo (claro y oscuro), ícono pequeño para el celular y **colores de marca** (principal, acento, oscuro), con vista previa de cómo se ve el portal y aviso si un color no tiene contraste suficiente;
- enlaces rápidos propios (por ejemplo su SharePoint);
- estado: activa o inactiva. Una empresa inactiva no deja entrar a sus usuarios, pero no se borra nada.

**Agregar una empresa nueva**, paso a paso desde el panel (asistente):
1. Se llenan los datos y se valida que el ID de inquilino tenga el formato correcto y no exista ya.
2. El portal muestra el **enlace de consentimiento de administrador** para ese inquilino (`https://login.microsoftonline.com/{tenant}/adminconsent?client_id=…`). El administrador de Microsoft de la empresa nueva lo abre una vez; sin ese paso sus usuarios no pueden entrar. El portal detecta cuando el consentimiento quedó hecho y lo marca en verde.
3. Se indica qué grupos `APP-…` crear en ese inquilino (con el script de la sección 5).
4. Se activa la empresa.

**Dos empresas en la misma organización de Microsoft:** si una empresa nueva no tiene su propio Microsoft 365 sino que usa el de otra, se registra con el mismo ID de inquilino y la empresa del usuario se decide por el **dominio de su correo**. Si el dominio no coincide con ninguna, entra a la empresa principal de ese inquilino.

**Seguridad:** agregar una organización de Microsoft abre la puerta a todos sus usuarios, así que crear o cambiar el inquilino de una empresa solo lo puede hacer el rol `super_admin` (Jhon), pide volver a confirmar con Microsoft, queda en auditoría y envía un aviso por correo a los administradores.

### 5.2 Aplicaciones, accesos y demás

- **Aplicaciones:**
  - **identificador fijo (`slug`)**, por ejemplo `nomina`: no cambia aunque cambie el nombre o la URL, y es la clave que usan la API (6b) y el botón de Soporte TI;
  - nombre, descripción, ícono (SVG del sistema de íconos, sección 4b), color de acento, URL de acceso, categoría, orden;
  - empresa(s) donde aplica: una, varias o todas las empresas activas;
  - dónde está alojada (GoDaddy, Vercel, otro) y tipo de login (Microsoft / propio);
  - tipo: **web** (abre la URL) o **local** (muestra la ficha de instrucciones, sección 1.1);
  - abrir en: misma pestaña o nueva;
  - estado: activa, en mantenimiento (con mensaje y fecha estimada) u oculta. Lo pone un administrador;
  - estado **"con fallas"**: no lo pone un administrador sino un sistema externo por la API (6b). Se ve en la ficha de la app con su historial, y un administrador puede limpiarlo a mano.
- **Acceso a cada aplicación**, por cualquiera de estas vías:
  - **grupos de Microsoft 365** de cada inquilino (recomendado). **Se crean desde cero** con la convención `APP-{Nombre}` (grupo de seguridad, sin buzón), uno por app y por empresa donde aplique. Grupos iniciales:
    - Segurex: `APP-TomaPedidos`, `APP-CuentasPorPagar`, `APP-Cartera`, `APP-Camaras`, `APP-PortalAdmin`;
    - Derivados: `APP-Portafolio`, `APP-PortalAdmin`.
    
    El rol `portal_admin` se asigna a quien esté en `APP-PortalAdmin`. Se entrega un script de PowerShell (módulo Microsoft Graph) que crea los grupos que falten en el inquilino que se le indique, así sirve también para una empresa nueva;
  - usuarios puntuales;
  - "todos los de la empresa".
- **Categorías** de aplicaciones. Iniciales: Ventas, Contabilidad, Tesorería, TI o Sistemas, Gerencia, Producción, Diseño, Compras y Otros. Se pueden agregar, renombrar y ordenar; las vacías no se muestran.
- **Anuncios.**
- **Súper administrador** (rol `super_admin`, solo Jhon: empresas e integraciones), **administradores del portal** (rol `portal_admin`) y **editores de anuncios** (rol `announcements_editor`).
- **Integraciones (sistemas externos):** crear, revocar y rotar los tokens de la API (6b); cada token tiene nombre, permisos (`catalog:read`, `status:write`), fecha de último uso y opcionalmente las IP desde las que se acepta.
- **Registro de auditoría:** cambios de configuración y **cada clic de acceso** (quién, qué app, cuándo, desde qué dispositivo).
- **Informe de uso:** apps más usadas, usuarios activos por empresa, apps sin uso en 90 días.

---

## 6. Inicio de sesión único en las aplicaciones

Objetivo: que al dar clic en una tarjeta el usuario entre a la app **sin volver a escribir su contraseña**.

- Como todas las apps son propias, **cada una se migra a "Entrar con Microsoft"** con la misma configuración multiinquilino (los dos inquilinos permitidos). Microsoft reconoce la sesión abierta y deja entrar al usuario directamente.
- **Cada app valida el acceso por su cuenta** (no basta con que el portal oculte la tarjeta): revisa que el usuario pertenezca al grupo correspondiente o consulta al portal por API (`GET /api/access/{app}` con token de la app).
- Mientras una app no se migre, el portal solo la abre y el usuario inicia sesión como hoy (se marca en la tarjeta "inicio de sesión propio").
- **Entregable de esta parte:** una guía de migración por tipo de app:
  - apps Laravel en GoDaddy → Socialite + Microsoft;
  - apps en Vercel (Next.js u otro) → Auth.js con proveedor Microsoft Entra ID o MSAL;
  - qué cambia en el registro de la app en Entra (URLs de redirección, multiinquilino) y cómo vincular los usuarios existentes por correo.
- Registro en Entra: **una aplicación por cada app** (recomendado: permisos y URLs separados) o una compartida con varias URLs de redirección. Se documenta la decisión por app.

---

## 6b. API para sistemas externos

El portal expone una API pequeña para que **otros sistemas del área de TI** (monitores de disponibilidad, herramientas de gestión) lean el catálogo y reporten el estado de las apps. El portal no conoce ni depende de esos sistemas: si ninguno la usa, todo sigue funcionando igual.

- Autenticación con **token Bearer por sistema** (Sanctum), creado en Administración → Integraciones. Cada token tiene sus permisos y solo ve lo que ellos permiten. Rate limiting por token.
- Versionada bajo `/api/v1/`, respuestas en JSON.

**Endpoints**
- `GET /api/v1/apps` (permiso `catalog:read`): catálogo de apps con `slug`, nombre, URL, empresa(s), hosting, tipo de login, categoría, estado actual y fecha de última modificación. Acepta `?updated_since=` para traer solo lo que cambió. No incluye usuarios ni reglas de acceso.
- `PUT /api/v1/apps/{slug}/status` (permiso `status:write`): reporta el estado de una app.
  - cuerpo: `status` (`operational` | `degraded` | `down`), `message` opcional para el usuario (máx. 140 caracteres), `source` y `reference` opcionales (por ejemplo el ID del incidente en el sistema que reporta);
  - `degraded` y `down` ponen la tarjeta en **"con fallas"**; `operational` la devuelve a normal;
  - **el mantenimiento manual tiene prioridad**: si un administrador puso la app en mantenimiento, el reporte se guarda en el historial pero no cambia lo que ve el usuario;
  - es idempotente: repetir el mismo estado no genera un cambio nuevo;
  - si en 2 horas no llega un nuevo reporte de una app marcada "con fallas", la tarjeta vuelve a normal sola, para que nunca quede en rojo por un sistema que dejó de reportar.
- Cada llamada que cambia algo queda en el registro de auditoría con el nombre del token.

---

## 7. Seguridad

- HTTPS obligatorio, CSRF, rate limiting en el login y la API.
- Las URLs de las apps se validan (solo `https://` y dominios autorizados).
- La API de acceso (`/api/access`) usa un token por aplicación y la API para sistemas externos (`/api/v1`) un token por sistema (Sanctum). Los tokens solo se muestran una vez al crearlos.
- Sesiones con expiración configurable; cerrar sesión en el portal cierra la sesión local (opcionalmente también en Microsoft).
- Respaldo diario de la base de datos (`spatie/laravel-backup`).

---

## 8. Modelo de datos

- `companies` (nombre, nombre corto, slug, **tenant_id**, dominios, logos, colores, enlaces rápidos, activa, consentimiento verificado en)
- `users` (azure_id, tenant_id, company_id, nombre, correo, cargo, foto, último acceso)
- `user_groups` (grupos de Microsoft del usuario, actualizados al iniciar sesión)
- `app_categories`, `apps` (incluye `slug` único y `external_status`, `external_status_message`, `external_status_at`), `app_companies`
- `app_status_events` (historial de estados reportados: app, estado, mensaje, origen, referencia, token, fecha)
- `personal_access_tokens` (Sanctum; tokens de apps y de sistemas externos con sus permisos)
- `app_access_rules` (app, tipo: `group` | `user` | `company`, valor, company_id)
- `user_favorites`, `app_launches` (registro de clics)
- `announcements`
- `roles` / `permissions` (spatie), `activity_log`, `settings`

---

## 9. Fases

1. **Diseño:** direcciones visuales, mockups en celular/tablet/escritorio, sistema de íconos con el ícono de cada app y mini sistema de diseño (skills `design` y `frontend-design`). Aprobación de Jhon.
2. **Base:** proyecto Laravel, despliegue en cPanel, cron.
3. **Empresas y login multiinquilino** con Microsoft (inquilinos tomados de la tabla de empresas), lectura de grupos, cuenta de emergencia, administración de empresas (5.1).
4. **Catálogo y pantalla principal:** aplicaciones, categorías, reglas de acceso, tarjetas, favoritos, recientes, buscador.
5. **Administración:** anuncios, roles, auditoría, informe de uso.
6. **Migración de las apps a "Entrar con Microsoft"**, una por una, empezando por la más usada. Guía de migración.
7. **Botón de Soporte TI** configurable que redirige a la plataforma de tickets con la app de origen.
8. **API para sistemas externos** (sección 6b) e Integraciones en Administración.
9. **PWA**, pruebas en celular y tablet, y manual de administración.

---

## 10. Pendientes por confirmar

- [ ] **Subdominio del portal** (ej. `apps.segurex.com` o `intranet.gruporyr.com`) y si se usa un dominio neutro del grupo para las dos empresas.
- [ ] Confirmación de que las skills `frontend-design` y `design` están instaladas.
- [ ] Logos en **SVG** si existen (el de Derivados en PNG es pequeño, 730 px de ancho).
- [ ] **URL de la plataforma de tickets de soporte** para el botón "Soporte TI".

> Nota: algunas apps de Vercel se migrarán más adelante a Laravel en GoDaddy; mientras tanto, el portal las enlaza donde están (el campo URL de cada app se actualiza cuando cambie).
