# Lista de chequeo — Portal RYR

Pruebe en este orden. Marque cada punto con ✅ (bien) o ❌ (falla) y, si falla, anote qué hizo, qué esperaba y qué pasó (con captura). Para celular, tablet y computador use también `docs/manual-administracion.md` §6.

Cuentas necesarias: una persona de **Segurex**, una de **Derivados**, un **súper administrador**, un **administrador** (grupo `APP-PortalAdmin`) y un **editor de anuncios**.

## A. Automático (lo corre el coordinador antes de cada PR)

- [ ] `php artisan test`: todos pasan (902 al 2026-10-09; el número solo sube).
- [ ] `vendor/bin/pint --test`: sin errores de formato.
- [ ] `npm run build`: compila y `public/build` va en el commit del front end.
- [ ] `/code-review` sobre el PR: sin hallazgos graves abiertos.
- [ ] `/security-review` sobre los PR que tocan inicio de sesión, permisos, búsqueda de usuarios, subida de archivos o la API.
- [ ] Lighthouse móvil en inicio de sesión e inicio: rendimiento ≥ 90, accesibilidad 100.

## B. Entrada al portal

- [ ] Inicio de sesión con Microsoft (persona de Segurex): entra sin errores.
- [ ] Ídem con una persona de Derivados.
- [ ] Una cuenta de una organización que no es empresa activa: el portal la rechaza con un mensaje claro y queda en Auditoría › Seguridad.
- [ ] Cerrar sesión: vuelve a la pantalla de inicio de sesión; si «Al salir, cerrar también Microsoft» está activo, también cierra Microsoft.
- [ ] Inactividad: pasado el tiempo de Configuración › Sesión, pide iniciar sesión de nuevo.
- [ ] Inicio de sesión en escritorio: las apps cruzan el fondo como cometas detrás de la tarjeta; el movimiento es continuo y sin saltos.
- [ ] Inicio de sesión en celular: hay menos cometas y más pequeñas, sin tapar el botón de Microsoft.
- [ ] Con «Reducir movimiento» del sistema: no se mueve nada; las apps quedan fijas (en celular, tres mosaicos sobre la tarjeta) y la tarjeta aparece sin animación.
- [ ] Logos de las empresas activas visibles en el inicio de sesión (el oscuro sobre su color o el claro).
- [ ] «Escribe a soporte de TI» abre el enlace cuando Configuración › Soporte TI tiene una dirección; sin dirección, aparece solo el texto.

## C. Marca por empresa (nuevo manual SGX / SDC)

- [ ] Persona de Segurex: encabezado gris `#58595B` con el logo blanco de Segurex como imagen, acentos naranja, botones `#C6531C` con texto blanco.
- [ ] Persona de Derivados: encabezado azul profundo `#073B66` con su logo, acentos azul `#0B5FA5`.
- [ ] Ninguna pantalla mezcla naranja y azul.
- [ ] Toda la interfaz usa Helvetica/Arial (no se descargan otras fuentes en DevTools › Red).
- [ ] Modo oscuro: los textos se leen (naranja `#F26522` en Segurex, azul claro `#4D8ABD` en Derivados).
- [ ] Contraste AA en botones, enlaces y textos pequeños (Lighthouse o extensión axe).
- [ ] Pantalla de inicio de sesión, inicio, buscador, favoritos, perfil, página «Sin conexión», mantenimiento y todas las pantallas de Administración.
- [ ] Correos HTML que envía el portal (ej. aviso de respaldo fallido) con logo y colores de la empresa.
- [ ] Ícono de la app instalada (PWA) y pantalla de carga con la marca correcta.

## D. Inicio (persona normal)

- [ ] Solo ve las tarjetas de las apps a las que tiene acceso (por grupo, por usuario o por toda la empresa).
- [ ] Buscar una app por nombre; marcar y desmarcar favorita (la estrella salta al marcarla).
- [ ] **Logos animados:** al abrir el inicio, los logos entran uno tras otro y en unos 1,5 s están todos quietos.
- [ ] Con el mouse encima, o al llegar con Tab, la tarjeta se levanta y el logo se balancea una vez (el mismo movimiento en todas las apps). Funciona con íconos SVG, PNG, WebP y con la inicial.
- [ ] En el celular, al tocar, el logo se encoge un instante y la app abre normal (el gesto no exige doble toque).
- [ ] App en mantenimiento: logo gris y quieto. App con fallas: punto rojo que late.
- [ ] Con «Reducir movimiento» del sistema no se mueve nada.
- [ ] Con 30 apps o más, el desplazamiento sigue fluido en un celular de gama media.
- [ ] Abrir una app web en pestaña nueva y otra en la misma pestaña, según su ficha.
- [ ] Abrir una app «instalada en el equipo»: muestra las instrucciones y la descarga.
- [ ] App en mantenimiento: la tarjeta se ve, no abre y muestra el mensaje.
- [ ] App con fallas reportadas por la API: la tarjeta lo indica; vuelve a normal al pasar el tiempo configurado.
- [ ] App oculta: no aparece.
- [ ] Anuncios de su empresa visibles; los de otra empresa no.
- [ ] Botón Soporte TI abre la plataforma con `origen=portal`, la empresa y la última app; sin datos personales.

## E. Administración

Con el súper administrador, salvo que se indique otro rol.

- [ ] **Empresas:** crear (pide confirmar con Microsoft), editar, cambiar el inquilino (pide confirmar), subir logo e ícono.
- [ ] **Aplicaciones — lista:** totales y filtros por nombre, empresa, estado y categoría.
- [ ] **Aplicaciones — ficha:** crear una app web y una instalada; dominio no permitido es rechazado; subir ícono SVG/PNG/WebP ≤ 512 KB y uno de 600 KB (rechazado).
- [ ] **Quién la ve › + Usuario (el bug a corregir):** elegir empresa Segurex, escribir al menos 2 letras del nombre de una persona que ya entró al portal → aparece en la lista → agregarla. Repetir con Derivados. Una persona de otra empresa **no** aparece. (Si se aprueba la búsqueda en el directorio de Microsoft, probar también con alguien que nunca ha entrado.)
- [ ] **Quién la ve › + Grupo de Microsoft:** sugiere los grupos `APP-…` de la empresa; agregar uno; una persona del grupo ve la app al volver a iniciar sesión.
- [ ] **Quién la ve › + Toda la empresa:** todas las personas de esa empresa ven la app.
- [ ] Regla para una empresa no asignada a la app: se marca «esta regla no aplica».
- [ ] Estado: Activa / En mantenimiento (con mensaje y fecha) / Oculta; **Limpiar estado**.
- [ ] **Categorías:** crear, ordenar, editar.
- [ ] **Anuncios:** crear con imagen, programar, despublicar. El editor de anuncios solo ve y edita los de su empresa.
- [ ] **Roles:** asignar y quitar editor de anuncios; la tabla de permisos coincide con lo que cada rol puede hacer.
- [ ] Con el **administrador**: no ve Empresas, Integraciones ni Configuración, ni entrando por la dirección directa (`/admin/...` responde 403 o lo redirige).
- [ ] **Integraciones:** crear token (pide confirmar con Microsoft; se muestra una sola vez), cambiar permisos, IP permitida, rotar, revocar.
- [ ] **Auditoría:** cada acción de esta lista aparece con quién, qué y cuándo; filtros; **Exportar CSV** abre bien en Excel.
- [ ] **Informe de uso:** 7, 30 y 90 días; «Sin uso en 90 días».
- [ ] **Configuración:** Soporte TI, sesión, dominios permitidos, estados de la API, respaldo (**Respaldar ahora** queda «Programado» y luego «correcto»).

## F. API e integraciones

- [ ] Leer el catálogo con un token de lectura (ver `docs/api.md`).
- [ ] Reportar el estado de una app con un token de reportes; la tarjeta cambia.
- [ ] Token revocado, token sin el permiso, IP no permitida y app oculta: responden 401/403/404 y no cambian nada.

## G. Servidor (después de cada despliegue)

- [ ] `php artisan portal:mail-test` envía el correo.
- [ ] Cron: el latido de Configuración › Respaldo está al día.
- [ ] `grep -n -E "^(APP_ENV|APP_DEBUG|APP_URL)" .env` → `production`, `false`, `https://apps.segurexlatam.net`.
- [ ] `storage/logs/laravel.log` sin errores nuevos tras recorrer esta lista.
- [ ] Manual §6.6: el celular toma la versión nueva al reabrir.

## H. Seguridad (base para `/security-review`)

- [ ] Una persona normal no entra a `/admin` ni a ninguna ruta de Livewire de Administración (403).
- [ ] La búsqueda de usuarios no devuelve personas de empresas que el administrador no gestiona ni datos de más (solo nombre y correo).
- [ ] No se puede cambiar el ID de una app, regla o empresa en una petición para tocar datos ajenos.
- [ ] Ícono SVG con `<script>` dentro: se rechaza o se sirve sin ejecutarse.
- [ ] Dirección de app con `javascript:` o dominio no permitido: rechazada.
- [ ] Formularios con token CSRF; cabeceras de seguridad presentes (HSTS, `X-Content-Type-Options`, `frame-ancestors`).
- [ ] Ningún secreto, token ni contraseña en registros, Auditoría ni código fuente de la página.

## Cómo reportar

Por cada ❌: sección y punto (ej. «E · + Usuario»), cuenta usada, equipo y navegador, pasos, resultado esperado, resultado real y captura. Con eso el coordinador asigna la corrección al agente dueño del área.
