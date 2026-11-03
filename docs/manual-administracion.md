# Manual de administración — Portal RYR

Este manual explica cómo administrar el Portal RYR: cada pantalla de **Administración**, la cuenta de emergencia, las tareas más frecuentes, la lista de pruebas en celulares y tablets antes de publicar cambios y las cuentas vinculadas de las personas que trabajan en varias empresas (§8).

- **Dirección:** https://apps.segurexlatam.net
- **Para instalar o publicar el portal** en el servidor, vea `docs/despliegue.md`.
- **Para conectar otros sistemas por la API**, vea `docs/api.md`.
- **Para pasar una aplicación al portal**, vea `docs/migracion-apps.md`.

---

## 1. Antes de empezar

### 1.1 Cómo entrar a Administración

1. Inicie sesión en el portal con su cuenta de Microsoft.
2. En el inicio, toque su avatar (las iniciales, arriba a la derecha; en el celular también **Perfil** en la barra inferior).
3. Elija **Administración**.

También puede abrir directamente `https://apps.segurexlatam.net/admin`. Cada persona llega a la primera sección que puede usar.

### 1.2 Roles

Los roles no se reparten a mano, salvo el de editor de anuncios:

| Rol | Quién lo recibe | Qué puede hacer |
|---|---|---|
| **Súper administrador** (`super_admin`) | Quien tenga su correo en `PORTAL_SUPER_ADMIN_EMAILS` (archivo `.env` del servidor) y entre con Microsoft. | Todo: empresas, aplicaciones, categorías, anuncios, eventos, roles, auditoría, informe de uso, integraciones y configuración. |
| **Administrador** (`portal_admin`) | Quien esté en el grupo de seguridad `APP-PortalAdmin` de la organización de Segurex, identificado por su ID de objeto (`PORTAL_ADMIN_GROUP_ID`), y haya entrado con esa organización (`PORTAL_ADMIN_TENANT_ID`). Se asigna y se quita solo, en cada inicio de sesión. | Aplicaciones, categorías, anuncios, eventos, roles (solo editores de anuncios), auditoría e informe de uso. No ve Empresas, Integraciones ni Configuración. |
| **Editor de anuncios** (`announcements_editor`) | Se asigna a mano en **Roles**. | Solo **Anuncios** y **Eventos**, y solo para su propia empresa. |

Un grupo con el nombre `APP-PortalAdmin` en otra empresa, o en un Team, no da ningún rol. Si `PORTAL_ADMIN_GROUP_ID` o `PORTAL_ADMIN_TENANT_ID` no están configurados, nadie es administrador del portal. Solo hay un grupo de administradores, el de Segurex: las empresas no tienen grupo propio.

La tabla «Qué puede hacer cada rol» de la pantalla **Roles** siempre muestra los permisos reales.

### 1.3 Confirmar con Microsoft

Las acciones que abren el portal a una organización entera o entregan llaves a otros sistemas piden **volver a confirmar con Microsoft**:

- Crear una empresa o seguir su asistente.
- Cambiar el inquilino (organización de Microsoft) de una empresa.
- Crear o rotar un token de Integraciones.

El portal lo lleva a Microsoft y lo devuelve a la misma pantalla. Microsoft debe haber registrado el inicio de sesión hace menos de 5 minutos: por eso pide la contraseña otra vez. Después de confirmar, la confirmación vale 10 minutos.

Si la confirmación no es de la misma cuenta o llega con una hora antigua, el portal cierra la sesión por seguridad.

Si Microsoft no envía la hora del inicio de sesión (dato `auth_time`), aparece la página **«No pudimos confirmar tu identidad con Microsoft»**. En ese caso no se confirma nada y **no se cierra la sesión**. Quien debe corregirlo es TI: agregar el dato opcional `auth_time` al token de identificación de la app en Microsoft Entra (`docs/despliegue.md`, sección 2.3).

### 1.4 Todo queda en Auditoría

Cada cambio de configuración, cada aplicación abierta, cada inicio de sesión y cada llamada de la API que cambia algo queda registrado en **Auditoría**, con quién, qué, cuándo y desde qué tipo de dispositivo.

---

## 2. Pantallas de Administración

### 2.1 Empresas (solo súper administrador)

Cada empresa del grupo entra con su propia organización de Microsoft (inquilino) y ve el portal con su marca.

**Lista.** Muestra cada empresa con su estado (**Activa** / **Inactiva**) y si es la **Principal** de su inquilino. Una empresa que quedó a medias en el asistente muestra «Paso 2 de 4 · Consentimiento» y el botón **Continuar**.

**Ficha de una empresa** (tocar la empresa en la lista):

- **Datos de la empresa:** nombre, nombre corto (se usa en el ícono del celular) y el interruptor de empresa activa. Una empresa inactiva no deja entrar a sus usuarios; no se borra nada. Al desactivar, el portal pide confirmar. No puede desactivar la empresa con la que usted inició sesión.
- **Microsoft 365:** el ID de inquilino y si el consentimiento está verificado. **Cambiar la organización de Microsoft** pide confirmar con Microsoft, el nuevo ID de inquilino y escribir el nombre corto como confirmación. Los usuarios del inquilino actual pierden el acceso de inmediato; la empresa queda inactiva y vuelve al asistente (consentimiento y activación).
- **Dominios de correo:** los dominios de la empresa (por ejemplo `segurex.com`). Un dominio solo puede pertenecer a una empresa. Si dos empresas comparten inquilino, el dominio del correo decide a cuál entra cada persona.
- **Enlaces rápidos:** hasta 8 enlaces `https://` que aparecen en la sección «Microsoft 365» del inicio (por ejemplo SharePoint). Un enlace con el mismo nombre que uno predeterminado (Outlook, Teams, OneDrive) reemplaza su dirección.
- **Marca:** logo claro, logo oscuro (PNG, WebP o SVG de hasta 1 MB), ícono (PNG de exactamente 512 × 512 px) y los tres colores (`#RRGGBB`). Si el color principal no tiene buen contraste con texto blanco, el portal avisa y usa un tono más oscuro para los botones. La **Vista previa** muestra el resultado en claro, oscuro y celular antes de guardar.
- **Logos:** el **Logo oscuro** se ve sobre la franja oscura de la marca (en Segurex, el logo blanco). El **Logo claro** se ve sobre una pastilla blanca. Si el archivo falta, el portal escribe el nombre de la empresa en su lugar.
- **Colores del manual de marca:** si la empresa tiene los colores del manual, la ficha muestra la caja **Colores del manual de marca** con el color del botón, el texto de marca y el texto largo. Esos colores mandan sobre los calculados del principal. Si quiere que el portal los calcule otra vez a partir del principal, marque **Quitar colores del manual** y guarde. Si cambia el principal sin quitarlos, el portal lo avisa.
- El **ícono** también es el ícono del portal instalado en celulares y computadores de esa empresa (vea la sección 5). Si no hay ícono, se usa el ícono neutro del portal.

Pulse **Guardar cambios**. Cada cambio llega a Auditoría.

**Asistente de nueva empresa** (botón **Nueva empresa**, pide confirmar con Microsoft):

1. **Datos de la empresa:** nombre, nombre corto, identificador (va en las direcciones del portal y no se puede cambiar después), ID de inquilino (Microsoft Entra › Información general › ID de inquilino), dominios y colores. Si el inquilino ya lo usa otra empresa, elija si esta es la **principal** de ese inquilino (solo puede haber una). Pulse **Guardar y continuar**. La empresa queda inactiva: nadie de esa organización puede entrar todavía.
2. **Consentimiento:** copie el **Enlace de consentimiento** y envíelo a un administrador de Microsoft 365 de esa empresa. El portal comprueba cada minuto (o pulse **Comprobar ahora**). Si aparece «No se pudo comprobar», revise que el secreto de la app de Microsoft esté vigente en el servidor. Puede pulsar **Terminar más tarde** y volver con **Continuar** desde la lista.
3. **Grupos:** el administrador de esa empresa crea un grupo de **seguridad** `APP-<App>` por cada aplicación que use la empresa (por ejemplo `APP-Cartera`), con el script `docs/scripts/crear-grupos-app.ps1` (vea `docs/despliegue.md`, sección 1.10). No se crea un grupo de administradores por empresa: los administradores del portal vienen solo del grupo de Segurex.
4. **Activar:** **Activar empresa** solo se habilita con el consentimiento verificado. Desde ese momento todos los usuarios de esa organización pueden entrar. El portal avisa por correo a los administradores.

### 2.2 Aplicaciones

Lo que cada persona ve en su portal sale de aquí.

**Lista.** Arriba, los totales: **Publicadas**, **Con fallas**, **En mantenimiento** y **Entran con Microsoft**. Debajo, la lista con filtros por nombre, empresa, estado y categoría. **Editar** abre la ficha.

**Ficha de una aplicación** (**Editar**, o **Nueva aplicación**):

- **General:** nombre, identificador (minúsculas y guiones; no se puede cambiar después porque lo usan la API y el botón de Soporte TI), descripción corta (hasta 120 caracteres, se ve debajo del nombre en el inicio), categoría y **Empresas** a las que aplica. **Todas las empresas activas** incluye también las empresas que se agreguen después.
- **Cómo se abre:**
  - **Web:** dirección `https://` en un dominio permitido (vea Configuración), alojamiento, tipo de inicio de sesión (Microsoft / Propio) y **Abrir en** pestaña nueva o misma pestaña.
  - **Instalada en el equipo:** instrucciones (un paso por línea) y, opcional, una dirección de descarga. La tarjeta abre una ficha con esas instrucciones.
- **Quién la ve** (aparece después de crear la aplicación). Basta con cumplir **una** regla:
  - **+ Grupo de Microsoft:** el nombre del grupo tal como está en Microsoft 365 (por ejemplo `APP-Cartera`). Solo cuentan los **grupos de seguridad**: los grupos de Microsoft 365 y los de Teams no dan acceso. El portal sugiere solo los grupos `APP-…` de seguridad que ya tienen personas de esa empresa. Si una regla apunta a un grupo que es de Microsoft 365, la lista la marca con «Este grupo es de Microsoft 365 y no da acceso: debe ser un grupo de seguridad».
  - **+ Usuario:** una persona concreta. Escriba al menos 2 letras del nombre o del correo. Solo aparecen las personas de la empresa elegida que ya entraron al portal alguna vez: la búsqueda se hace en el portal, no en el directorio de Microsoft, y no muestra la cuenta de emergencia. Si nadie coincide, el portal dice: «Nadie con ese nombre ha entrado al portal en esta empresa. Solo aparecen las personas que ya iniciaron sesión al menos una vez.» Para dar acceso a alguien que aún no entra, use **+ Grupo de Microsoft** o pídale que entre una vez.
  - **+ Toda la empresa:** todas las personas de la empresa elegida.
  - Una regla para una empresa a la que la app no está asignada se marca «esta regla no aplica».
  - La aplicación también debe validar el acceso por su cuenta: el portal solo decide qué tarjetas se ven.
- **Estado que pone TI:**
  - **Activa:** normal.
  - **En mantenimiento:** la tarjeta se ve pero no abre; muestra el mensaje para las personas y, opcional, cuándo vuelve a estar disponible. El mantenimiento manda sobre lo que reporten otros sistemas.
  - **Oculta:** nadie la ve en el inicio. Sus registros de uso se conservan.
- **Estado reportado:** lo que reporta un sistema externo por la API (vea Integraciones), con los últimos reportes. Si un sistema deja de reportar una falla, la tarjeta vuelve a verse normal después del tiempo fijado en Configuración. **Limpiar estado** la vuelve normal de inmediato.
- **Ícono:** SVG, PNG o WebP de hasta 512 KB, con variante para modo oscuro, y un color de acento para la tarjeta sin ícono.

Pulse **Crear aplicación** o **Guardar cambios**. Las aplicaciones no se borran: se ocultan.

### 2.3 Categorías

Ordenan las tarjetas del inicio.

- Suba o baje una categoría con las flechas (**Subir** / **Bajar**); el inicio sigue ese orden.
- Con el botón del lápiz (**Renombrar**) cambie el nombre y pulse **Guardar**. El identificador no cambia.
- **Nueva categoría** + **Agregar** la pone al final.
- Las categorías vacías no se muestran a nadie (tampoco las que una persona no tiene). En la lista quedan agrupadas: **Mostrar** / **Ocultar vacías**.
- **Borrar** (papelera) solo funciona con una categoría vacía. Si tiene aplicaciones (incluso ocultas), muévalas antes a otra.

### 2.4 Anuncios

Se muestran en el inicio entre su fecha de inicio y de fin: en el computador, en una tarjeta lateral (hasta 3); en celular y tablet, en una franja con el más importante. **Ver todos** lleva a la página de anuncios.

**Lista.** Pestañas **Todos**, **Publicados**, **Programados**, **Vencidos** y **Retirados**, y filtro por empresa (solo administradores).

**Nuevo anuncio / editar:**

- **Contenido:** título, texto (solo texto, hasta 280 caracteres; los saltos de línea se respetan) e imagen opcional (PNG, JPG o WebP de hasta 2 MB; se recorta a 16:9).
- **Publicación:** **Para quién** (todas las empresas o algunas), **Desde** y **Hasta** (sin fecha de fin, se muestra hasta que lo retire) y **Fijar arriba** (se muestra primero y destacado con el color de la empresa).
- **Lectura obligatoria:** cada persona que ve el anuncio debe pulsar **Confirmar lectura** (en el inicio y en la página de anuncios); después ve «Leído el …». Quien tiene cuentas vinculadas confirma una sola vez, con cualquiera de ellas. Se puede marcar al crear o al editar, y el cambio queda en Auditoría.
- **Vista previa:** cómo se verá en escritorio y en celular.
- **Retirar** lo quita del inicio sin borrarlo; **Volver a publicar** lo devuelve.

Un editor de anuncios solo publica para su propia empresa. Los anuncios no se borran.

**Quién lo leyó.** Al editar un anuncio con lectura obligatoria aparece la tarjeta **Lectura**, debajo del formulario: «X de Y personas lo leyeron» y, en **Personas que faltan**, las personas que no lo han confirmado, con el nombre y sus empresas (hasta 200, en orden alfabético; si faltan más, «Y N más»). Solo la ve quien puede editar ese anuncio. Las cifras se cuentan al abrir la página y se actualizan cada minuto o al recargarla.

- **Se cuenta por persona.** Quien tiene cuentas vinculadas en dos empresas es una sola persona: confirma una vez, con cualquiera de sus cuentas, y en todas ve «Leído el …» con la fecha de la primera confirmación. Una cuenta sin vincular es una persona aparte, aunque tenga el mismo nombre que otra. Si se desvinculan las cuentas, cada una vuelve a contar sola y solo queda leída la que confirmó.
- **Ver el anuncio sigue siendo por cuenta.** Un anuncio solo para Segurex no se muestra en la cuenta de Derivados de la misma persona. En **Personas que faltan** solo aparecen las empresas del anuncio: el editor no ve en qué otras empresas tiene cuenta esa persona.
- **Y** cuenta a las personas con cuentas en las empresas activas del anuncio que ya entraron al portal (las cuentas se crean al iniciar sesión), sin cuentas de emergencia ni invitados. Quien aún no ha entrado nunca al portal no aparece.
- Una cuenta de la que todavía no se sabe si es invitada (no ha vuelto a iniciar sesión desde que el portal lo guarda) **sí se cuenta**: es mejor que sobre una cuenta pendiente que dejar por fuera a alguien que debía leerlo. En su siguiente inicio de sesión queda clara.
- Se cuenta según la empresa actual de cada cuenta: si una cuenta pasa a otra empresa, sale de X y de Y de los anuncios de la empresa anterior (su lectura se guarda y vuelve a contar si regresa); en un anuncio para todas las empresas sigue contando. Una persona que confirmó con una cuenta vinculada cuenta como leída aunque esa cuenta ya no esté entre las empresas del anuncio, porque en sus otras cuentas también ve «Leído».
- Si alguien pulsa **Confirmar lectura** en una página vieja de un anuncio que ya no la pide (retirado, vencido o ya no obligatorio), el portal lo devuelve con el aviso «Este anuncio ya no pide confirmación.» y no guarda nada.

### 2.5 Eventos

Fechas de la empresa (reuniones, capacitaciones, jornadas) con **Agregar a mi calendario**. Las personas los ven en **Próximas fechas** del inicio, junto a los festivos, y en la página **Eventos** (`/eventos`), desde **Se muestra desde** hasta que el evento termina. Cada evento descarga un archivo `.ics` que abren Outlook, Google Calendar y el calendario del celular.

**Quién los maneja.** Las mismas personas que los anuncios, sin un rol nuevo: súper administrador y administrador, todos; el editor de anuncios, solo los de su propia empresa (no puede publicar para todas las empresas ni para otra).

**Lista.** Pestañas **Todos**, **Publicados**, **Programados**, **Pasados** y **Retirados**, con la fecha y las empresas de cada evento. Los que se repiten cada año muestran su próxima fecha y «Cada año».

**Nuevo evento / editar:**

- **Evento:** título (hasta 120 caracteres), descripción opcional (solo texto, hasta 500; los saltos de línea se respetan) y lugar opcional (hasta 120, por ejemplo «Sala de juntas» o «Teams»).
- **Fecha**, en hora de Colombia:
  - Con hora: **Empieza** y **Termina** (opcional; debe ser posterior al inicio). Sin fin, el evento sigue en el portal hasta que acaba ese día, y el calendario lo muestra como un momento.
  - **Todo el día:** **Primer día** y **Último día** (opcional; el mismo día o sin fecha es un solo día). En el calendario ocupa los días completos.
  - **Se repite cada año** (solo con **Todo el día**): para fechas que vuelven cada año, como el aniversario de la empresa, el cierre contable o la entrega de dotación. Las fechas que escriba son la primera vez. El portal muestra la próxima: la de este año hasta que termina y luego la del año siguiente (un 29 de febrero cae el 28 en los años que no son bisiestos). Nunca pasa a **Pasados** y en el inicio y en **Eventos** dice «Cada año» en lugar de «Evento». Su `.ics` lo repite cada año en el calendario de cada persona. Si quita **Todo el día**, la opción se desmarca: un evento con hora no puede repetirse. Debe durar menos de un año (el último día, antes de que vuelva el primero). Si desmarca la opción en una fecha cuyo primer día ya pasó, deja de verse en el portal, pero los calendarios que ya la importaron la siguen repitiendo hasta que cada persona la borre.
- **Publicación:** **Para quién** (todas las empresas o algunas) y **Se muestra desde** (antes de esa fecha queda programado y nadie lo ve).
- **Retirar** lo quita del portal de inmediato sin borrarlo; **Volver a publicar** lo devuelve. Los eventos no se borran.

Todo cambio queda en **Auditoría**. Quien ya agregó un evento a su calendario no recibe los cambios: si cambia la fecha, avísele para que lo descargue de nuevo.

### 2.6 Roles

- **Qué puede hacer cada rol:** la tabla de permisos (siempre la real).
- **Súper administrador** y **Administradores:** solo lectura. Para quitar a un administrador, sáquelo del grupo `APP-PortalAdmin` de la organización de Segurex en Microsoft; pierde el rol en su siguiente inicio de sesión. Después de cambiar la configuración del grupo, TI puede ejecutar `php artisan portal:sync-portal-admins` para aplicar la regla a todas las personas de una vez, según sus grupos guardados.
- **Editores de anuncios:** **+ Agregar persona**, busque por nombre o correo (al menos 2 letras; solo personas que ya entraron al portal) y pulse **Agregar**. **Quitar** pide confirmación.
- **Cuenta de emergencia:** muestra si existe, si tiene el segundo factor confirmado y su último uso. Se gestiona solo desde el servidor (sección 3).

### 2.7 Integraciones (solo súper administrador)

Tokens para que otros sistemas (monitores, herramientas de TI) lean el catálogo o reporten el estado de las aplicaciones. Si nadie los usa, el portal funciona igual. Detalle técnico en `docs/api.md`.

- **Lista:** sistema, permisos, token enmascarado (`ryr_…xxxx`), último uso y estado (**Activo** / **Revocado**).
- **Nuevo token** (pide confirmar con Microsoft): nombre del sistema, permisos (leer el catálogo, reportar estados) e IP permitidas (opcional; vacío = cualquier IP). Pulse **Crear token**.
- **El token se muestra una sola vez.** Cópielo con **Copiar**, guárdelo en un lugar seguro, marque «Ya lo guardé en un lugar seguro» y pulse **Listo**. El portal no lo puede volver a mostrar.
- **Detalle de un sistema:** permisos (se pueden cambiar), IP permitidas (**+ Agregar IP**, **Quitar**), últimos cambios de estado que hizo.
- **Rotar** (pide confirmar con Microsoft): crea un token nuevo; el anterior deja de funcionar de inmediato y hay que poner el nuevo en el sistema.
- **Revocar:** el token deja de funcionar de inmediato y no se puede deshacer. No pide confirmar con Microsoft, para poder cortar rápido un token filtrado.

### 2.8 Auditoría

Cada cambio de configuración, cada app abierta, cada inicio de sesión y lo que hacen los sistemas externos por la API.

- **Filtros:** **Buscar persona o app** (nombre, correo, aplicación o sistema), **Tipo de evento** (Todo, Cambios, Apps abiertas, Seguridad, API), **Empresa** y **Periodo** (Hoy, últimos 7, 30 o 90 días, o un rango **Desde**/**Hasta**). Pulse **Filtrar**. La dirección de la página guarda los filtros: puede guardarla como marcador.
- Columnas **Cuándo**, **Quién** («Sistema» cuando no hay una persona), **Qué hizo**, **Sobre qué** y **Desde** (tipo de dispositivo al abrir una app).
- **(ver cambios)** despliega **Campo**, **Antes** y **Después**.
- **Exportar CSV** descarga lo filtrado (separado por `;` para Excel con configuración regional de Colombia), hasta 50 000 filas.
- **Seguridad** incluye inicios de sesión con Microsoft (aceptados y rechazados), los de la cuenta de emergencia y las confirmaciones con Microsoft. Nunca se guardan contraseñas, códigos ni tokens.

### 2.9 Informe de uso

Qué apps se usan, quién entra y qué se puede retirar. Periodo de 7, 30 o 90 días.

- **Personas activas por empresa:** entraron al portal o abrieron una app al menos una vez en el periodo.
- **Aperturas de apps** y **Desde dónde abren las apps** (escritorio, tablet, celular).
- **Apps más abiertas:** las 10 primeras; **Ver como tabla** muestra los números exactos.
- **Sin uso en 90 días:** aplicaciones publicadas que nadie abrió desde el portal en 90 días. Revise si sus accesos están bien asignados o si conviene ocultarlas.

### 2.10 Configuración (solo súper administrador)

Ajustes que aplican a todo el portal y a todas las empresas. Se guardan con **Guardar cambios** y quedan en Auditoría con el antes y el después.

- **Soporte TI:** dirección de la plataforma de tickets. Si queda vacía, el botón «Soporte TI» no aparece. **Así llega el ticket** muestra el enlace que se abre: lleva `origen=portal`, la empresa y la última app que abrió la persona en los últimos 30 minutos. Nunca datos personales.
- **Sesión:** cerrar la sesión por inactividad después de 4, 8 o 24 horas; **Al salir, cerrar también Microsoft** (si está apagado, solo se cierra el portal). Este tiempo también limita cuánto tiempo el celular muestra la copia guardada del inicio sin conexión (sección 5).
- **Dominios permitidos para apps:** solo se aceptan direcciones `https://` en estos dominios. Un dominio incluye sus subdominios; `*` reemplaza parte de una sola etiqueta (`sgx-*.vercel.app`). No se puede quitar un dominio que usa una app publicada.
- **Estados reportados por la API:** después de cuántas horas sin reportes una tarjeta deja de mostrarse «Con fallas» (1, 2 o 4).
- **Respaldo diario de la base de datos:** cada noche, a las 2:30 a. m. (hora de Bogotá), el portal guarda en el servidor una copia de la base de datos y de los archivos subidos (logos, íconos e imágenes de anuncios). Esta tarjeta no depende de **Guardar cambios** y muestra datos reales:
  - **Último respaldo:** fecha y hora de la copia más reciente y, si el último intento salió bien, «correcto». Si nunca se ha hecho una copia, dice «nunca».
  - Debajo, el tamaño de esa copia y cuántas copias hay guardadas (las de los últimos 7 días y una por semana del mes anterior).
  - Si el último intento falló, lo dice en rojo con su fecha. Los súper administradores reciben además un correo con el motivo. La copia buena anterior sigue guardada. Avise al equipo de TI: la solución está en la guía de despliegue (`docs/despliegue.md`, sección 5).
  - **Respaldar ahora** pide una copia inmediata, por ejemplo antes de un cambio grande. La copia no se hace en ese instante: queda **Programado** y empieza en el minuto siguiente; mientras tanto el botón dice «Programado…». La tarjeta se actualiza sola. Solo se permite una a la vez y hasta 3 por hora. Cada pedido queda en Auditoría como «Pidió un respaldo inmediato de la base de datos».
  - Las copias no se descargan ni se restauran desde el portal: lo hace TI en el servidor (guía de despliegue, sección 6).

---

## 3. Cuenta de emergencia

Sirve para entrar a Administración **cuando el inicio de sesión con Microsoft no funciona** (secreto de la app vencido, consentimiento revocado, caída de Microsoft, acceso condicional mal configurado). No sirve para el uso diario.

- **Cómo entrar:** abra `https://apps.segurexlatam.net/acceso-emergencia` (o la ruta definida en `PORTAL_EMERGENCY_PATH`). No hay ningún enlace a esa página en el portal. Escriba el correo y la contraseña, y luego el código de 6 dígitos de la app autenticadora.
- **Qué puede hacer:** lo mismo que un súper administrador, **excepto** crear empresas, cambiar inquilinos y crear o rotar tokens (esas acciones exigen confirmar con Microsoft). Sí puede revocar tokens.
- **Crear o cambiar la contraseña** (por SSH en el servidor):

  ```bash
  cd /home/<usuario>/<ruta-app>
  php artisan portal:emergency-account <correo>
  ```

  Pide una contraseña de al menos 16 caracteres, muestra **una sola vez** la dirección `otpauth://` y el secreto para agregar la cuenta a la app autenticadora (Microsoft Authenticator, Google Authenticator…; escriba el secreto a mano o convierta la dirección en QR en un equipo de confianza) y pide un código para confirmar. Si el código no es válido, no se guarda nada. Use un correo que **no** sea de una cuenta de Microsoft.

- **Cambiar solo el segundo factor** (por ejemplo, si se perdió el celular): `php artisan portal:emergency-account <correo> --reset-2fa`
- **Desactivarla:** `php artisan portal:emergency-account <correo> --disable` (borra la contraseña, el segundo factor y el rol; la fila se conserva para la auditoría).
- Guarde la contraseña en el gestor de contraseñas de TI. Después de cinco intentos fallidos por minuto, el acceso se bloquea un momento.
- Cada uso, correcto o fallido, queda en **Auditoría › Seguridad**.

---

## 4. Tareas frecuentes

### 4.1 Agregar una aplicación

1. **Aplicaciones › Nueva aplicación.**
2. Escriba el nombre, revise el identificador sugerido, la descripción y la categoría.
3. Marque las empresas (o **Todas las empresas activas**).
4. En **Cómo se abre**, elija **Web** y escriba la dirección `https://`. Si el dominio no está permitido, un súper administrador debe agregarlo en **Configuración › Dominios permitidos para apps**.
5. Suba el ícono (claro y oscuro).
6. Pulse **Crear aplicación**.
7. En **Quién la ve**, agregue al menos una regla (normalmente **+ Grupo de Microsoft** con `APP-<NombreApp>`). Sin reglas, nadie la ve.
8. Abra el portal con una cuenta de prueba que esté en el grupo y compruebe que la tarjeta aparece y abre.

### 4.2 Dar acceso a un grupo de Microsoft

1. En Microsoft 365 (centro de administración o Entra), cree el grupo de **seguridad** `APP-<NombreApp>` en la empresa correspondiente (o use el script `crear-grupos-app.ps1`) y agregue a las personas. En Entra, la columna **Group type** debe decir **Security**; un grupo de Microsoft 365 o de Teams no da acceso.
2. En el portal: **Aplicaciones › Editar › Quién la ve › + Grupo de Microsoft**, elija la empresa, escriba el nombre exacto del grupo y pulse **Agregar grupo**.
3. Las personas del grupo ven la app **la próxima vez que inicien sesión** en el portal: los grupos se leen de Microsoft al iniciar sesión. Si alguien no la ve, pídale que cierre sesión y vuelva a entrar.

### 4.3 Poner una aplicación en mantenimiento

1. **Aplicaciones › Editar › Estado que pone TI › En mantenimiento.**
2. Escriba el mensaje para las personas (hasta 200 caracteres) y, si sabe, cuándo vuelve a estar disponible.
3. Pulse **Guardar cambios**. La tarjeta muestra «En mantenimiento» y, al tocarla, el mensaje en lugar de abrir la app.
4. Al terminar, vuelva a **Activa** y guarde (el mensaje se borra solo).

### 4.4 Rotar un token de integración

1. **Integraciones**, elija el sistema y pulse **Rotar** (pide confirmar con Microsoft).
2. Copie el token nuevo; el anterior ya no funciona.
3. Póngalo en la configuración del sistema externo y compruebe que **Último uso** se actualiza en la siguiente llamada.
4. Si el token se filtró, use **Revocar** primero y cree uno nuevo después.

### 4.5 Una persona no ve una aplicación

Revise en este orden:

1. **¿La app está publicada?** En **Aplicaciones**, su estado no debe ser **Oculta**.
2. **¿Aplica a la empresa de la persona?** En la ficha, **Empresas** debe incluir su empresa (o **Todas las empresas activas**).
3. **¿Cumple alguna regla de «Quién la ve»?** Si la regla es un grupo, confirme en Microsoft 365 que la persona está en ese grupo **de su empresa**, que el nombre es idéntico y que el grupo es de **seguridad**. Si la regla dice «esta regla no aplica», la app no está asignada a esa empresa.
4. **¿Volvió a iniciar sesión** después de que la agregaron al grupo? Los grupos se actualizan al entrar.
5. **¿Su empresa está activa?** En **Empresas**.
6. **¿Entra con la cuenta correcta?** En **Auditoría**, busque su nombre o correo con el tipo **Seguridad**: verá sus inicios de sesión y con qué empresa entró. Un invitado de otra empresa entra con la marca y las apps de la empresa de su propia organización de Microsoft.
7. **¿Ve una copia guardada?** Si el portal muestra «Sin conexión · mostrando tus apps guardadas» o «Conexión lenta…», la lista puede ser vieja: pídale que recargue con conexión.
8. **¿Está en la cuenta de la otra empresa?** Si tiene cuentas vinculadas, el encabezado marca la empresa en la que está. Cada cuenta ve solo las apps de su empresa (§8).

### 4.6 Desvincular la cuenta de una persona

Cuando alguien deja una empresa del grupo, no reconoce un vínculo o una de sus cuentas pudo estar comprometida, un súper administrador la desvincula con `portal:unlink-account` (§8.5). La persona también puede hacerlo sola desde **Cuentas vinculadas** (§8.4).

---

## 5. El portal como aplicación (celular, tablet y computador)

El portal se puede **instalar** como una aplicación: queda un ícono en la pantalla de inicio o en el escritorio y se abre sin la barra del navegador.

- **Foto de perfil:** el portal ya no guarda la foto de Microsoft. Las personas ven sus iniciales.
- **Nombre e ícono:** los de la empresa de quien inicia sesión (nombre corto e ícono de la ficha de la empresa). Antes de iniciar sesión se usa el nombre y el ícono neutro del portal. La pantalla de carga usa el color claro de la marca.
- **Color de la barra del celular:** en las páginas que abren con la franja de marca, la barra del navegador toma el color oscuro de la empresa. En las demás páginas usa el color neutro del portal.
- **Sin conexión o con conexión lenta:** el portal guarda una copia del inicio de cada persona. Si no hay internet (o la red tarda más de 3 segundos), muestra esa copia con el aviso «Sin conexión · mostrando tus apps guardadas» (o «Conexión lenta…»). Las aplicaciones necesitan internet para abrirse: al tocar una sin conexión aparece la página «Sin conexión» con **Reintentar** e **Ir al inicio**.
- **Privacidad en equipos compartidos:**
  - **Cerrar sesión** borra todo lo que el navegador guardó del portal en ese equipo.
  - Si otra persona inicia sesión en el mismo equipo, la copia guardada de la anterior se borra al entrar.
  - Si la sesión venció (inactividad), la copia se borra en cuanto el equipo vuelve a abrir el portal con conexión.
  - La copia nunca se muestra después del tiempo de inactividad de **Configuración › Sesión**.
  - Límite: un equipo que se queda **sin conexión** después de que venció la sesión de una persona, sin que nadie abra el portal con conexión, puede mostrar las tarjetas guardadas de esa persona hasta que se cumpla ese tiempo. Por eso, en equipos compartidos pida siempre **Cerrar sesión**.
- Las páginas de Administración, los inicios de sesión, la API y Soporte TI nunca se guardan.

---

## 6. Lista de pruebas en dispositivos reales

La lista completa de pruebas de la aplicación (entrada, marca, inicio, Administración, API, servidor y seguridad) está en `docs/lista-de-chequeo.md`. Esta sección cubre solo los equipos.

Haga estas pruebas antes de publicar cada fase. Use `https://apps.segurexlatam.net` (la instalación y el modo sin conexión **solo funcionan con HTTPS**). Anote el modelo del equipo, la versión del sistema y del navegador, y el resultado.

### 6.1 Android (Chrome)

- [ ] Inicie sesión con Microsoft. El inicio muestra la marca, el ícono y los colores de su empresa.
- [ ] Menú ⋮ › **Instalar aplicación** (o **Agregar a la pantalla principal**). El ícono instalado es el de la empresa, recortado sin bordes raros (círculo o «squircle» según el teléfono).
- [ ] Abra el ícono: la pantalla de carga muestra el ícono sobre el color claro de la marca y el portal se abre **sin barra del navegador**.
- [ ] Las tarjetas se ven en 2 columnas, la barra inferior (Inicio · Favoritos · Buscar · Perfil) no tapa contenido y respeta la barra de gestos.
- [ ] Busque una app, marque y desmarque una favorita.
- [ ] Abra una app web y una «instalada en el equipo».
- [ ] Active el **modo avión**, cierre y vuelva a abrir el portal: aparecen sus tarjetas y el aviso «Sin conexión · mostrando tus apps guardadas».
- [ ] Sin conexión, toque una app: aparece la página «Sin conexión». Vuelva a conectar y pulse **Reintentar**: la app abre.
- [ ] Ajustes del teléfono › **Tamaño de fuente** al máximo: el texto crece, nada se corta y no hay que desplazarse de lado.
- [ ] Ajustes › **Tema oscuro**: el portal cambia a oscuro y el texto se lee bien.

### 6.2 iPhone (Safari)

- [ ] Inicie sesión con Microsoft en **Safari**.
- [ ] Botón **Compartir** › **Agregar a inicio**. El nombre propuesto es el nombre corto de la empresa y el ícono es el de la empresa (sin fondo negro).
- [ ] Abra el ícono: el portal se abre a pantalla completa, sin barra de Safari, y el contenido no queda debajo de la muesca ni de la barra de inicio.
- [ ] Repita búsqueda, favoritos, abrir una app web y una local.
- [ ] Modo avión: el inicio guardado aparece con el aviso; una app muestra «Sin conexión».
- [ ] Ajustes › Pantalla y brillo › **Tamaño del texto** grande y **Modo oscuro**: se ve bien en ambos.
- [ ] Nota: en iPhone la app instalada guarda su propia sesión; puede pedir iniciar sesión con Microsoft la primera vez aunque Safari ya tuviera la sesión.

### 6.3 Tablet (Android o iPad)

- [ ] En vertical y horizontal: tarjetas en 3–4 columnas, el buscador y el botón «Soporte TI» (si está configurado) visibles arriba, la franja de anuncios si hay anuncios.
- [ ] Instale el portal (Chrome: **Instalar aplicación**; iPad: **Compartir › Agregar a inicio**) y ábralo desde el ícono.
- [ ] Prueba sin conexión como en el celular.
- [ ] En iPad, Safari se identifica como computador: en el Informe de uso esas aperturas cuentan como «escritorio».

### 6.4 Computador (Chrome o Edge)

- [ ] En la barra de direcciones aparece el ícono **Instalar** (o menú › **Instalar Portal…**). Instale y abra: ventana propia con el ícono de la empresa.
- [ ] Con 1440 px de ancho: 5–6 columnas y anuncios a la derecha.

### 6.5 Cerrar sesión en un equipo compartido

- [ ] Persona A inicia sesión y abre el inicio (se guarda su copia).
- [ ] Persona A pulsa **Cerrar sesión**.
- [ ] Active el modo avión y abra el portal: **no** aparecen las tarjetas de A (aparece «Sin conexión»).
- [ ] Sin cerrar sesión: A abre el inicio; luego B inicia sesión en el mismo navegador y abre el inicio. Con modo avión, el inicio guardado es el de **B**, nunca el de A.

### 6.6 Después de un despliegue

- [ ] Abra el portal con conexión, cierre y vuelva a abrir: los cambios nuevos aparecen (el portal se actualiza solo al publicar archivos nuevos).
- [ ] Si un equipo sigue viendo la versión anterior, cierre todas las pestañas o ventanas del portal y ábralo de nuevo.

---

## 7. Lo que ven las personas: inicio de sesión e inicio

### 7.1 Inicio de sesión

- Las apps cruzan el fondo como cometas detrás de una tarjeta con el botón de Microsoft. En el celular hay menos cometas, y las apps se ven más pequeñas.
- Debajo del botón, en «Empresas del grupo», aparecen los logos de las empresas: el logo oscuro sobre su color, o el logo claro.
- **Escribe a soporte de TI** es un enlace solo si **Configuración › Soporte TI** tiene una dirección. Si está vacía, el texto aparece sin enlace.
- Con **Reducir movimiento** activo en el equipo, no hay movimiento: las apps se muestran fijas (en el celular, tres mosaicos sobre la tarjeta) y la tarjeta aparece sin animación.

### 7.2 Inicio

- Al abrir el inicio, los logos de las apps entran uno tras otro y en unos 1,5 segundos quedan quietos.
- Con el mouse encima, o al llegar con la tecla Tab, la tarjeta se levanta y el logo se balancea una vez. En el celular, al tocar, el logo se encoge un instante y la app abre.
- Apps en mantenimiento: logo gris y quieto. Apps con fallas: un punto rojo que late en la esquina del logo.
- Con **Reducir movimiento** activo, nada se mueve: los logos quedan fijos y el punto de fallas también.
- **Mi día › Próximas fechas:** hasta 5 fechas de los próximos 60 días, festivos y eventos juntos y en orden («Festivo» o «Evento»). **Ver eventos** lleva a la página de eventos, con **Agregar a mi calendario**.
  - Un festivo en lunes o viernes dice «Puente festivo», en el inicio y en el buscador (Ctrl K). De martes a jueves, o en fin de semana, dice «Festivo».

---

## 8. Cuentas vinculadas: una persona en varias empresas

Quien tiene una cuenta de Microsoft en cada empresa del grupo (por ejemplo, `edward.ramanan@segurex.com` y `edward.ramanan@derivadoscol.com`) puede vincularlas y cambiar de empresa desde el encabezado, sin cerrar la sesión. Sirve para cualquier número de empresas.

### 8.1 Reglas

- **Vincular nunca da una sesión.** Cambiar de empresa es siempre un inicio de sesión real de Microsoft con la cuenta de destino. Si Microsoft responde con otra cuenta, o la cuenta de destino ya no sirve (desactivada en Microsoft, empresa inactiva), la sesión queda como estaba.
- **Vincular no da permisos.** Cada cuenta conserva sus propios roles, grupos, aplicaciones, favoritos y anuncios. Ser súper administrador en una cuenta no lo hace súper administrador en la otra.
- **Vincular exige dos pruebas:** confirmar con Microsoft la cuenta actual (§1.3) y luego entrar con la otra cuenta. Nunca se vincula por correo ni por nombre.
- **No se pueden vincular** las cuentas de invitado (B2B) ni la cuenta de emergencia.
- **Solo empresas activas.** Si una empresa se desactiva, su cuenta deja de aparecer en el selector de empresas; el vínculo se conserva.
- **Una cuenta por empresa por persona.** Para cambiar la cuenta de una empresa, primero se desvincula la que hay.
- **Cambio de empresa automático.** Si una cuenta pasa a otra empresa (por ejemplo, porque se movió un dominio) y la persona ya tenía una cuenta en esa empresa, el portal la desvincula solo. En Auditoría aparece «por cambio de empresa».
- **Todo queda en Auditoría › Seguridad:** vínculos, rechazos, cambios de empresa (también los fallidos) y desvinculaciones. Cada cuenta de la persona recibe un correo cuando ella o un administrador vincula o desvincula sus cuentas (la desvinculación automática no envía correo).

### 8.2 Vincular (lo hace la persona)

1. En el inicio, toque su avatar y elija **Cuentas vinculadas**.
2. Pulse **Vincular otra empresa** y confirme con Microsoft (pide la contraseña).
3. Pulse **Continuar con Microsoft**, elija la cuenta de la otra empresa y entre con ella.
4. El portal vuelve al inicio con «Vinculaste tu cuenta de …». La sesión sigue en la cuenta con la que estaba.

### 8.3 Cambiar de empresa

- El selector aparece en el encabezado del inicio y de las páginas secundarias, solo si hay otra cuenta a la que se puede cambiar. Con hasta 3 empresas son botones; desde 4, un menú. La empresa actual está marcada y no es un botón.
- Al tocar otra empresa, Microsoft entra con esa cuenta, normalmente sin preguntar nada. Si pide la contraseña o el segundo factor, lo pide una sola vez.
- Al terminar aparece «Ahora estás en tu cuenta de …». Si no se pudo: «No pudimos cambiar de empresa. Sigues en tu cuenta actual.»

### 8.4 Desvincular (lo hace la persona)

1. Avatar › **Cuentas vinculadas** › **Desvincular** en la cuenta que quiere separar.
2. Confirme con Microsoft, revise la página de confirmación y pulse **Desvincular**.

- Puede desvincular la cuenta con la que está: sigue en ella, pero ya no cambia a las otras.
- Si solo le quedaban dos cuentas, las dos quedan separadas.
- La cuenta no se borra: conserva sus permisos y puede volver a vincularse.

### 8.5 Desvincular como administrador: `portal:unlink-account`

Mientras no exista **Administración › Usuarios**, Jhon o un súper administrador lo hace por SSH en el servidor:

```bash
cd /home/<usuario>/<ruta-app>
php artisan portal:unlink-account <correo>
```

- Muestra las cuentas de la persona, marca la que se desvincula y pide confirmar. `--force` omite la pregunta.
- Si la persona queda con una sola cuenta, lo avisa: esa también se desvincula.
- Si varias cuentas tienen ese correo (por ejemplo, la propia y una de invitado), las lista con su id y pide elegir una con `--id=<id>`.
- El correo solo sirve para encontrar la cuenta: nunca vincula ni da acceso.
- En Auditoría queda como «Sistema» con «por un administrador». Todas las cuentas de la persona reciben el correo.
- Si la cuenta no está vinculada, no cambia nada.

---

## 9. Directorio de personas

El buscador del inicio (**Ctrl K**, grupo **Personas**) y la página **Personas** (`/personas`, enlace **Ver directorio** en **Mi día**) muestran a las personas de la empresa: nombre, cargo, área, oficina, teléfono de la oficina, correo y **Chat en Teams**. La página busca por nombre, cargo, área, oficina o correo, sin importar tildes ni mayúsculas, y muestra 50 personas por página. El buscador del inicio lleva hasta 1.000 personas, por nombre; la página siempre las tiene todas.

### 9.1 De dónde salen los datos

No se escriben en el portal: se copian de Microsoft Entra. Para corregir un dato, cámbielo en Entra (centro de administración › **Usuarios** › la persona › **Propiedades**) y aparecerá en el portal después de la siguiente copia.

| En el portal | Campo de Entra |
|---|---|
| Nombre | Nombre para mostrar |
| Cargo | Puesto (Cargo) |
| Área | Departamento |
| Oficina | Oficina |
| Teléfono | Teléfono de la oficina (el primero) |
| Correo | Correo electrónico; si no tiene, el nombre principal de usuario |
| Fecha de contratación | Fecha de contratación del empleado (para los aniversarios de **Mi día**, sección 9.6; no se muestra la fecha) |

### 9.2 Cada cuánto se actualiza

Todas las noches a las 03:30 (hora de Bogotá), con `php artisan portal:sync-directory`. Quien se crea en Entra aparece al día siguiente; quien sale de Entra o queda deshabilitado desaparece en la misma copia. Si Microsoft no responde, falta el permiso en una empresa o Microsoft responde sin nadie que copiar cuando la empresa ya tenía personas, esa empresa conserva los datos de la copia anterior (`docs/despliegue.md`, sección 1.10). Solo corre una copia a la vez: si se lanza a mano mientras otra está en curso, no copia nada y avisa; repítala cuando la otra termine.

### 9.3 Qué no se copia nunca

- El celular personal.
- Los invitados de otras organizaciones (B2B) y las cuentas deshabilitadas.
- Las cuentas sin correo.
- Las personas de empresas inactivas.

### 9.4 Quién ve qué

Cada persona ve solo el directorio de su propia empresa, la de la cuenta con la que entró: con una cuenta vinculada de otra empresa, ve el de esa otra. Las personas de la empresa se reparten como al iniciar sesión (la empresa dueña del dominio del correo, o la principal del inquilino). La cuenta de emergencia, las cuentas de invitado y las cuentas sin empresa no ven ningún directorio.

El buscador del inicio guarda el directorio en el equipo hasta 24 horas, junto con la copia del inicio para usarlo sin conexión, igual que la libreta de direcciones de Outlook. En un equipo compartido, cierre la sesión al terminar (sección 6.5).

### 9.5 Cumpleaños

El cumpleaños no sale de Microsoft: cada persona lo escribe, si quiere, en **Mi cumpleaños** (menú de la cuenta › **Mi cumpleaños**, en la página `/cuenta`).

- Solo se guardan el día y el mes, nunca el año. El 29 de febrero se acepta.
- Nadie más lo ve hasta que la persona marca **Mostrar mi cumpleaños a las personas de mi empresa** (desmarcada al empezar, Ley 1581). Puede desmarcarla, cambiar la fecha o tocar **Quitar mi cumpleaños** cuando quiera; quitarlo borra el día, el mes y la casilla.
- Quitarlo o desmarcar la casilla vale de inmediato en el servidor, pero el celular o el computador de un compañero puede seguir mostrándolo hasta 24 horas en la copia del inicio que guardó para usar sin conexión.
- Es por cuenta: con cuentas vinculadas en dos empresas, cada cuenta tiene el suyo.
- Solo aparece para las cuentas que ven un directorio (sección 9.4): la cuenta de emergencia, las cuentas de invitado y las cuentas sin empresa no lo tienen. Si una cuenta deja de ver el directorio (pasa a invitado, queda sin empresa o la empresa se desactiva) con un cumpleaños guardado, en `/cuenta` solo le queda **Quitar mi cumpleaños**, para que siempre pueda borrarlo.
- Ninguna pantalla de Administración muestra ni cambia el cumpleaños.

### 9.6 Cumpleaños y aniversarios en «Mi día»

El inicio muestra, en **Mi día**, el bloque **Cumpleaños y aniversarios** con lo de hoy y los próximos 7 días (hora de Bogotá), solo de la empresa de la cuenta con la que se entró:

- **Cumpleaños**: solo de quien lo escribió en **Mi cumpleaños** y marcó la casilla (sección 9.5), y solo si está en el directorio de esa empresa (la misma cuenta de Microsoft y la misma empresa). Quien nació el 29 de febrero aparece el 28 de febrero en los años que no son bisiestos.
- **Aniversarios**: salen de la **Fecha de contratación** de Entra (sección 9.1), desde el primer año cumplido: «1 año en Segurex», «5 años en Segurex» (con el nombre corto de la empresa). Si la fecha está vacía en Entra, la persona no tiene aniversario; para corregirla, cámbiela en Entra y aparece después de la siguiente copia.
- Cada fila dice **Hoy**, **Mañana** o el día de la semana, y **Felicitar** abre un chat de Teams con la persona y un saludo escrito, sin enviarlo. La fila propia no tiene **Felicitar**.
- Van por fecha y luego por nombre, hasta 8; si hay más, **Ver directorio** lleva a `/personas`. Sin nada en esos días, el bloque no aparece.
- Salen de las mismas 1.000 personas que lleva el buscador del inicio (sección 9). En una búsqueda sin JavaScript (`/?q=`) el bloque no aparece.
- La copia del inicio guardada en el equipo (hasta 24 horas) quita las filas de días pasados y corrige **Hoy** y **Mañana**; los días nuevos aparecen con la siguiente conexión.
