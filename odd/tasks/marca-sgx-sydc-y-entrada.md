# Marca SGX/SDC, logos animados y búsqueda de usuarios

Plan de coordinación (2026-10-26). Coordinador + 3 agentes: **Backend**, **Front end** y **Documentador**. El coordinador revisa, corre `/code-review` y `/security-review`, y decide el orden de fusión.

## Objetivo

1. Que **+ Usuario** en Aplicaciones › Quién la ve encuentre a las personas de la empresa elegida.
2. Pasar todo el front end al manual de marca oficial SGX / SDC (skill `frontend-marca-sgx-sydc`).
3. Logos animados en las tarjetas de las aplicaciones del inicio. (La animación de la puerta se descartó el 2026-10-26.)
4. Lista de chequeo de toda la app: `docs/lista-de-chequeo.md`.

## Hallazgo del punto 1 (verificado en el código, HEAD `50b0cf8`)

- La búsqueda **nunca** consultó Microsoft: `app/Livewire/Admin/AppAccessRules.php:255-272` busca en la tabla local `users` filtrando por `company_id`. Solo aparecen quienes **ya iniciaron sesión** en el portal.
- Ni el componente ni su vista cambiaron desde que se crearon (`9c2a925`). Los PR #44, #45 y #47 no los tocan.
- El portal no tiene permiso para leer el directorio: solo pide `User.Read GroupMember.Read.All` (`MicrosoftLoginController.php:28`).
- Las tres reglas usan lo que Microsoft entrega **al iniciar sesión**, no una consulta al directorio:
  - **Grupo:** guarda el nombre del grupo. Cuando la persona entra, el portal lee sus grupos de Microsoft (`MicrosoftLogin.php:143-144`). Sirve para personas que nunca han entrado.
  - **Toda la empresa:** la persona la cumple al entrar, según su organización. También sirve para quien nunca ha entrado.
  - **Usuario:** guarda el ID interno de la persona (`users.id`). Por eso necesita que ya exista, o sea, que haya entrado al menos una vez.
  - Las sugerencias de grupos `APP-…` salen de los grupos de quienes ya entraron (`AppAccessRules.php:235-241`).
- Causas probables, en orden:
  1. La persona nunca ha entrado al portal, así que no existe en `users`.
  2. Quedó en otra empresa: `company_id` se recalcula en cada inicio de sesión (`TenantDirectory::resolveCompany`), y un invitado entre inquilinos queda en la empresa del inquilino.
  3. Los inicios de sesión nuevos fallan en producción. El secreto de Entra ahora también lo usa el correo por Graph.
  4. `POST /livewire/update` falla en producción (419/500 o bloqueo de mod_security en GoDaddy). Escribir no hace nada.
- **Paso 1:** recoger evidencia en producción (consulta SQL, `laravel.log` y red del navegador). Sin esa evidencia no se escribe código.
- Si la causa es 1 y se quiere buscar en todo el directorio de Microsoft, es una **función nueva**: Graph `/users?$search`, el permiso `User.ReadBasic.All` con consentimiento de administrador, y crear la fila de la persona por `oid` al agregarla. Esa decisión es de Jhon.

## Modelo y esfuerzo por agente

| Agente | Modelo | Esfuerzo |
|---|---|---|
| Front end | Opus 5.5 | alto |
| Backend | Opus 5.5 | alto |
| Documentador | Haiku 5.5 (decisión de Jhon, 2026-10-26) | medio. Recibe instrucciones cerradas: qué PR se fusionó, qué sección cambia y qué dice el código. El coordinador revisa el texto contra el código antes del PR. |

## Reglas para que los PR no choquen

1. **Cada agente trabaja en su propio worktree y su propia rama**, creada desde `main` actualizado. Nunca dos agentes en la misma carpeta.
2. **Dueño único por archivo.** Un agente no edita archivos de otro. Si lo necesita, se lo pide al coordinador.
3. **`public/build` solo lo compila y sube el agente Front end**, y solo después de rebasar sobre `main`. El Backend nunca hace commit de `public/build`.
4. **Orden de fusión fijo** (abajo). El siguiente PR rebasa sobre `main` antes de pedir revisión.
5. **`package.json`/`package-lock.json`:** solo Front end. **`composer.json`/`composer.lock`:** solo Backend.
6. **Migraciones:** nombres con la fecha y hora reales. Solo Front end crea la migración de colores. Backend crea migraciones solo si hace la búsqueda en Graph.
7. **Tareas en `odd/tasks/`:** cada agente actualiza únicamente su sección de este archivo. El Documentador no reescribe secciones de otros.
8. **PR pequeños (~400 líneas):** si el rediseño crece, se parte en PR encadenados (skill `chained-pr`).
9. **Antes de cada PR:** `php artisan test`, `vendor/bin/pint --test` y, en Front end, `npm run build`. Después, `/code-review`. En PR de permisos, inicio de sesión o búsqueda de usuarios, también `/security-review`.

## Dueños de archivos

| Área | Backend | Front end | Documentador |
|---|---|---|---|
| `app/Livewire/Admin/AppAccessRules.php`, `app/Auth/**`, `app/Microsoft/**`, `app/Tenancy/**` | ✅ | — | — |
| `routes/**`, `config/**` (salvo marca), `composer.*` | ✅ | — | — |
| `tests/Feature/Admin/AppAccessRules*`, `tests/Feature/Auth/**`, `tests/Unit/Microsoft/**` | ✅ | — | — |
| `app/Http/Controllers/Auth/**` | ✅ | — | — |
| `resources/views/livewire/admin/app-access-rules.blade.php` | PR 1 (solo texto o lógica) | PR 2 (estilos), después de PR 1 | — |
| `app/Branding/**`, `app/View/Components/**`, `resources/**`, `public/**`, `public/build` | — | ✅ | — |
| `database/seeders/CompanySeeder.php` y la migración de colores | — | ✅ | — |
| `package.json`, `package-lock.json`, `vite.config.js` | — | ✅ | — |
| `tests/Unit/Branding/**`, `tests/Feature/Home/**` (marca y tarjetas) | — | ✅ | — |
| `docs/**`, `README.md`, `odd/tasks/*.md` (la sección de cada agente la escribe su dueño) | — | — | ✅ |

## Orden de fusión

| # | Rama | Agente | Contenido | Depende de |
|---|---|---|---|---|
| 0 | `docs/estado-del-proyecto` (PR #46, ya existe) | Documentador | Fusionar el documento de estado tal cual | — |
| 1 | `fix/admin-buscar-usuarios` | Backend | Corrección del punto 1 según la evidencia de producción, con pruebas | Evidencia de Jhon |
| 2a | `feat/marca-sgx-sydc-tokens` | Front end | Tokens del manual, `BrandPalette` con `accent/button/ink/text/dark`, migración de colores de `companies`, Helvetica/Arial (quitar Figtree y Bricolage), encabezado con logo | — (no comparte archivos con 1; corren en paralelo) |
| 2b | `feat/marca-sgx-sydc-pantallas` | Front end | Inicio, inicio de sesión, perfil, sin conexión, Administración, correos HTML y PWA | 2a |
| 3 | `feat/logos-animados` | Front end | Animación de los logos en `resources/views/components/home/app-card.blade.php` y `resources/css/app.css`, solo CSS (más JS mínimo en `home.js` para la estrella) | 2b (prototipo aprobado) |
| 4 | `docs/marca-y-logos` | Documentador | Manual (§2.2 + Usuario, marca, logos animados), `estado-del-proyecto.md` (la decisión de colores cambia al manual SGX/SDC) y la lista de chequeo | 3 |

El Documentador **redacta en paralelo** desde el día 1 en su rama, pero rebasa y abre su PR al final para describir lo que realmente se fusionó.

## Tareas

### Backend
- [x] B1. Evidencia de producción (2026-10-26): la consulta salió bien. **No hay bug**: las personas buscadas no habían entrado al portal, y eso funciona según el diseño.
- [x] B2. (Aprobado por Jhon; PR #49) Cuando no haya resultados, mostrar «Esta persona aún no ha entrado al portal. Use un grupo `APP-…` o pídale que entre una vez», con su prueba.
- Descartado por ahora: búsqueda en el directorio de Microsoft (`User.ReadBasic.All`). Se evalúa como función aparte si se necesita.

### Front end
- [x] F1. (PR #48) Tokens y paleta del manual (variables `--brand-*`, contraste AA, modo oscuro).
- [x] F2. (PR #48; Jhon sube los logos en Admin › Empresas) Logos reales: Segurex blanco (`Segurex - W.png`) para fondos oscuros y SDC (`logo-sdc.png`). **Falta** el logo de Segurex para fondo blanco.
- [x] F3. Pantallas restantes: PR #50 (inicio y pantallas públicas), #51 (Administración), #52 (correos y PWA), todos revisados con `/code-review`, corregidos y fusionados el 2026-10-26. También el PR #53 (mensaje de + Usuario tuteando).
- [x] F4. (PR #54, fusionado el 2026-10-27; falta probar el toque en celular real y Lighthouse) Logos animados según el prototipo aprobado por Jhon el 2026-10-26 (`docs/diseno/LogosAnimados.html`):
  - Entrada escalonada de los logos al abrir el inicio.
  - Al pasar el mouse o con el foco del teclado: la tarjeta se levanta y el logo se balancea (el movimiento «Cartera» del prototipo). Es el mismo movimiento para todas las apps y no es configurable (decisión de Jhon, 2026-10-26).
  - Al tocar en el celular: el logo se encoge un instante.
  - En mantenimiento: el logo queda gris y quieto. Con fallas: un punto rojo que late.
  - La estrella de favorita salta al marcarla.
  - Con «Reducir movimiento» no se mueve nada.
  - La animación se aplica al contenedor del logo, así que sirve para cualquier ícono subido (SVG, PNG o WebP) y para la inicial.

- [x] F5. Inicio de sesión de escritorio (opción B, cometas): PR #55, revisado con `/code-review`, corregido y fusionado el 2026-10-27. Lighthouse 12.8.2 en local sobre /login: móvil 99/100/96 y escritorio 100/100/96 (rendimiento/accesibilidad/buenas prácticas), TBT 0 ms, CLS 0. El 96 se debe a errores 404 de sw.js y del manifiesto en el servidor local. Falta confirmar en producción.

### Documentador
- [x] D1. (PR #46) Documentación de PR #46 actualizada con lo fusionado; la fusión la decide el coordinador.
- [x] D2. (PR #46) `docs/estado-del-proyecto.md` y `docs/manual-administracion.md` actualizados con #47–#55.
- [x] D3. (PR #46) `docs/lista-de-chequeo.md` con los chequeos de entrada y el mismo comportamiento de logos animados.

### Coordinador
- [x] C1. Diagnóstico de lectura del punto 1.
- [x] C2. Prototipo de la puerta: descartado por Jhon. Reemplazado por el prototipo de logos animados (archivo local, sin publicar).
- [x] C3. Lista de chequeo.
- [x] C5. `/code-review` del PR #48: 5 hallazgos, los 5 corregidos en `a0c94a5` y verificados por el coordinador.
- [ ] C4. Revisión y fusión en el orden de la tabla; despliegue lo corre Jhon (`bash deploy/deploy.sh`).

## Decisiones pendientes

- Punto 1: corregir con lo que hay (solo quienes ya entraron) o agregar la búsqueda en el directorio de Microsoft.
- Logo de Segurex para fondo blanco (la skill solo trae la versión blanca).
