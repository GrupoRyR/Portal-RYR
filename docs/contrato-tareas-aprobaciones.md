# Contrato de integración: app de tareas y aprobaciones — Portal RYR

> **Planeado: el portal todavía no implementa esto. Versión 0.1.1 (2026-10-30).**
> Nada de lo que describe este documento existe hoy en el portal. Es el acuerdo entre el portal y la futura «app de tareas y aprobaciones» para que ambos lados se construyan por separado y encajen.

Este documento es para el equipo que construye la app de tareas y aprobaciones, y para quien implemente la parte del portal. Lo que no se dice aquí sigue las reglas de la API actual ([`docs/api.md`](api.md)).

- **Dirección base del portal:** `https://apps.segurexlatam.net/api/v1`
- **Formato:** JSON, en UTF-8. Las fechas van en ISO 8601 con zona horaria (`2026-11-05T17:00:00-05:00`). El portal responde con la hora de Bogotá.

---

## 1. Propósito y papeles

El inicio del portal muestra a cada persona sus **tareas** y lo que tiene **por aprobar**. Esos elementos viven en la app de tareas y aprobaciones.

| Quién | Qué hace |
|---|---|
| **App de tareas y aprobaciones** | Es la **fuente de verdad**. Crea, cambia y borra las tareas y aprobaciones. Envía al portal una copia de cada una. Recibe del portal la orden de completar una tarea. |
| **Portal RYR** | Guarda la copia y la muestra en el inicio. Abre la app con «Ver tarea» o «Ver aprobación». Pide a la app completar una tarea cuando la persona pulsa «Completar». |

Reglas de fondo:

1. **El inicio nunca llama a la app.** Muestra la copia que el portal ya tiene. Si la app está caída, el inicio sigue funcionando.
2. **Un elemento pertenece a una sola persona.** Una tarea con tres responsables son tres elementos.
3. **Una persona se identifica por `{tenant_id, oid}` de su cuenta de Microsoft**, nunca por el correo.
4. **Las aprobaciones nunca se aprueban en el portal.** Solo se abren.
5. **El portal no guarda tokens de Microsoft** ni actúa en nombre de la persona ante la app. La única llamada del portal a la app es «completar», firmada por el servidor.

```text
App de tareas ── PUT / DELETE /api/v1/work-items/{external_id} ──▶ Portal (copia)
                       (token Bearer, permiso work:write)

Portal ── POST {app_base_url}/api/portal/v1/tasks/{external_id}/complete ──▶ App de tareas
                       (firma HMAC-SHA256, solo tareas)

Persona ── «Ver tarea» / «Ver aprobación» (enlace url) ──▶ App de tareas (SSO propio)
```

---

## 2. Registro de la app en el portal

Lo hace un súper administrador. Son dos pasos.

### 2.1 Administración › Aplicaciones

Registre la app de tareas en el catálogo como una app `web`, con su `url` (por ejemplo `https://tareas.segurexlatam.net`).

El **host** de esa `url` es el único permitido en los enlaces «Ver» (sección 5). Si la app cambia de dirección, cambie primero la `url` del catálogo.

### 2.2 Administración › Integraciones

Cree una integración para la app (igual que en [`docs/api.md`](api.md), sección 1):

| Dato | Qué es |
|---|---|
| **Nombre del sistema** | Por ejemplo «App de tareas y aprobaciones». Es el nombre que aparece en Auditoría. |
| **Permiso `work:write`** | Nuevo. Permite publicar tareas y aprobaciones en el inicio (sección 3). No hace falta ningún otro permiso. |
| **IP permitidas** | Las del servidor de la app. Se recomienda no dejar la lista vacía. |
| **App del catálogo** | Nuevo. La app registrada en 2.1. De ella sale el host permitido para los enlaces. Es obligatoria con `work:write`. |
| **URL base de la app** | Nuevo. `app_base_url`, por ejemplo `https://tareas.segurexlatam.net`. Debe ser `https`, sin usuario ni contraseña, sin `?` ni `#`. El portal le agrega `/api/portal/v1/...`. Es obligatoria con `work:write`. |
| **Secreto de firma** | Nuevo. Lo genera el portal (sección 6.2). Firma las llamadas del portal a la app. |

Del token y del secreto:

- El token (`ryr_...`) y el secreto (`ryrsig_...`) **se muestran una sola vez**, al crearlos o rotarlos. Guárdelos en el gestor de secretos de la app.
- El portal guarda del token solo su huella, como hoy. El secreto lo guarda **cifrado**, porque lo necesita para firmar.
- Crear o rotar el token o el secreto exige confirmar de nuevo la sesión con Microsoft (10 minutos), como hoy. La cuenta de emergencia no puede hacerlo.
- **Rotar el secreto:** el portal firma con el nuevo de inmediato. Para no perder llamadas, la app puede aceptar el secreto anterior y el nuevo durante unos minutos.
- **Revocar la integración:** sus elementos dejan de verse en el inicio de inmediato y se borran del portal.

---

## 3. App → Portal

Las dos llamadas usan el token de la integración, como en [`docs/api.md`](api.md):

```http
Authorization: Bearer ryr_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
Content-Type: application/json
```

### 3.1 El identificador `external_id`

- Lo elige la app. Es fijo: identifica el mismo elemento en todas las llamadas.
- Es único **dentro de la integración**. Dos integraciones pueden usar el mismo valor sin chocar, y una integración nunca ve ni cambia los elementos de otra.
- De 1 a 190 caracteres: letras sin tilde, números, `.`, `_`, `-` y `:`. Debe empezar por letra o número. Expresión: `^[A-Za-z0-9][A-Za-z0-9._:-]{0,189}$`.
- Un `external_id` con otros caracteres responde `404`, como una dirección que no existe.
- **Uno por persona.** Si la tarea `8812` tiene dos responsables, envíe `8812:<oid de la primera>` y `8812:<oid de la segunda>`.
- No ponga datos personales en el `external_id`: queda en Auditoría.

### 3.2 Crear o actualizar: `PUT /api/v1/work-items/{external_id}`

Permiso `work:write`. Es idempotente: enviar dos veces lo mismo deja el mismo resultado.

```bash
curl -s -X PUT https://apps.segurexlatam.net/api/v1/work-items/8812:0b7d5e3a-0000-4000-8000-000000000002 \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d @tarea.json
```

Tarea (`tarea.json`):

```json
{
  "kind": "task",
  "title": "Enviar el acta de la reunión de HSE",
  "status": "pending",
  "due_at": "2026-11-05T17:00:00-05:00",
  "url": "https://tareas.segurexlatam.net/tareas/8812",
  "completable_from_portal": true,
  "version": 3,
  "assignee": {
    "tenant_id": "6f1c2a9e-0000-4000-8000-000000000001",
    "oid": "0b7d5e3a-0000-4000-8000-000000000002"
  }
}
```

Aprobación:

```json
{
  "kind": "approval",
  "title": "Compra de EPP para la bodega de Funza",
  "status": "pending",
  "due_at": null,
  "url": "https://tareas.segurexlatam.net/aprobaciones/431",
  "completable_from_portal": false,
  "version": 1,
  "assignee": {
    "tenant_id": "6f1c2a9e-0000-4000-8000-000000000001",
    "oid": "5c2e8d14-0000-4000-8000-000000000003"
  }
}
```

| Campo | Obligatorio | Descripción |
|---|---|---|
| `kind` | Sí | `task` (tarea) o `approval` (aprobación). No puede cambiar para un mismo `external_id`. |
| `title` | Sí | Texto que se ve en el inicio. De 1 a 140 caracteres después de quitar espacios al inicio y al final. Texto plano, en una sola línea: sin saltos de línea ni caracteres de control. El portal lo muestra escapado, nunca como HTML. Ver la advertencia de abajo. |
| `status` | Sí | `pending` (se ve en el inicio), `done` (hecha) o `cancelled` (cancelada). Solo `pending` se ve. |
| `due_at` | No | Fecha límite, ISO 8601 **con zona horaria**. `null` o ausente: sin fecha. Una fecha sin zona responde `422`. |
| `url` | Sí | Enlace para ver el elemento en la app. Reglas en la sección 5. Máximo 2048 caracteres. |
| `completable_from_portal` | Sí | `true` si la persona puede completar la tarea desde el portal. **Siempre `false` en las aprobaciones**: `true` con `approval` responde `422`. |
| `version` | Sí | Entero de 1 a 9007199254740991 que la app sube en cada cambio del elemento. Reglas en 3.3. |
| `assignee.tenant_id` | Sí | ID del inquilino de Microsoft de la cuenta de la persona (claim `tid`). GUID. |
| `assignee.oid` | Sí | ID de objeto de la cuenta en ese inquilino (claim `oid`). GUID. |

- Los GUID se aceptan en mayúsculas o minúsculas; el portal los guarda en minúsculas.
- `assignee` no puede cambiar para un mismo `external_id`. Para otra persona, use otro `external_id`.
- Los campos que no están en esta tabla se ignoran y no se guardan.
- El cuerpo no puede pasar de 8 KB.

> **El título se ve en el inicio del portal**, en pantallas que otras personas pueden ver, y puede quedar en la copia sin conexión del navegador. No ponga en `title` contenido sensible: salarios, datos de salud, números de documento, datos de clientes, montos de pagos ni nada que no se pueda leer por encima del hombro. Escriba un título corto y lleve el detalle a la app.

#### Respuesta

`201` si el elemento es nuevo; `200` en los demás casos:

```json
{
  "data": {
    "external_id": "8812:0b7d5e3a-0000-4000-8000-000000000002",
    "result": "created",
    "version": 3
  }
}
```

| `result` | Código | Cuándo |
|---|---|---|
| `created` | `201` | El portal no tenía el elemento. |
| `updated` | `200` | La `version` enviada es mayor que la guardada. Se reemplazan todos los campos. |
| `unchanged` | `200` | La `version` enviada es igual a la guardada. No se cambia nada, aunque el contenido sea distinto (salvo `kind` o `assignee`: ver 3.3, punto 3). |
| `ignored_stale` | `200` | La `version` enviada es menor que la guardada. No se cambia nada. `version` trae la que el portal tiene. |

### 3.3 Cómo funciona `version`

La app es la fuente de verdad, pero sus llamadas pueden llegar desordenadas o repetidas (reintentos, varios procesos). `version` resuelve el orden:

1. La app guarda una `version` por elemento y la **sube en cada cambio** (por ejemplo, un contador o un número que solo crece).
2. El portal aplica un `PUT` solo si su `version` es **mayor** que la que tiene.
3. Con la **misma** `version`, el portal responde `unchanged` y no compara el contenido, salvo `kind` y `assignee`: si alguno de los dos es distinto, responde `422`, igual que con una versión mayor, porque esos dos campos nunca cambian (3.7). Para cambiar algo más, suba la versión. Para pasar una tarea a otra persona o cambiar su tipo, bórrela y créela con otro `external_id`.
4. Con una `version` **menor**, el portal responde `ignored_stale`. No es un error: no reintente.
5. Cuando el portal completa una tarea (sección 6), la marca como hecha **sin cambiar** su `version`. El siguiente `PUT` de la app con una versión mayor manda, aunque traiga `pending`: la app es la fuente de verdad.
6. Al borrar (3.4), el portal recuerda la última `version` durante 30 días. Un `PUT` con una versión igual o menor en ese tiempo responde `ignored_stale` y no revive el elemento. Uno con versión mayor lo crea de nuevo.

### 3.4 Borrar: `DELETE /api/v1/work-items/{external_id}`

Permiso `work:write`. Responde `204` sin cuerpo, exista o no el elemento. Repetirlo no cambia nada.

```bash
curl -s -X DELETE https://apps.segurexlatam.net/api/v1/work-items/8812:0b7d5e3a-0000-4000-8000-000000000002 \
  -H "Authorization: Bearer $TOKEN"
```

Use `DELETE` cuando el elemento deja de existir en la app. Para una tarea hecha o cancelada basta un `PUT` con `done` o `cancelled`.

### 3.5 Personas que aún no han entrado al portal

El portal acepta elementos para cuentas que nunca han entrado. Los guarda por `tenant_id` y `oid` y los muestra cuando esa cuenta entra por primera vez.

- `assignee.tenant_id` debe ser el inquilino de una empresa registrada en el portal. Si no, responde `422`.
- La app no recibe ninguna señal de si la persona ya entró al portal.

### 3.6 Elementos hechos, cancelados y borrados

- `done` y `cancelled` no se ven en el inicio. El portal los borra 30 días después de su último cambio.
- Un elemento borrado deja solo su rastro de versión (3.3, punto 6) durante 30 días.

### 3.7 Validación: ejemplo de `422`

```json
{
  "message": "The title field must not be greater than 140 characters. (and 1 more error)",
  "errors": {
    "title": ["The title field must not be greater than 140 characters."],
    "url": ["The url host must be the host of the app registered in the portal."]
  }
}
```

Motivos de `422` propios de este endpoint:

| Campo | Motivo |
|---|---|
| `kind` | No es `task` ni `approval`, o cambió para un `external_id` que ya existe. |
| `title` | Vacío, de más de 140 caracteres, con saltos de línea o caracteres de control. |
| `status` | No es `pending`, `done` ni `cancelled`. |
| `due_at` | No es una fecha ISO 8601 con zona horaria. |
| `url` | No es `https`, su host no es el de la app del catálogo, trae usuario o contraseña, o pasa de 2048 caracteres. También si la integración no tiene una app del catálogo con `url`. |
| `completable_from_portal` | No es booleano, o es `true` en una aprobación. |
| `version` | No es un entero entre 1 y 9007199254740991. |
| `assignee` | Falta, sus GUID no son válidos, su inquilino no es de una empresa del portal, cambió para un `external_id` que ya existe, o la persona ya tiene 500 elementos pendientes de esta integración. |

---

## 4. Visibilidad

1. El inicio muestra **solo los elementos de la cuenta activa**: aquellos cuyo `{tenant_id, oid}` es el de la cuenta con la que la persona entró.
2. Cada cuenta pertenece a una empresa. En Segurex la persona ve solo los elementos de su cuenta de Segurex; en Derivados, solo los de su cuenta de Derivados. Para ver los otros, cambia de empresa.
3. **Los elementos de las cuentas vinculadas nunca se mezclan.** El portal no lee `person_id` para mostrar ni para completar nada: `person_id` nunca autoriza (`odd/tasks/cuentas-vinculadas.md`).
4. Solo se ven los elementos `pending` de integraciones no revocadas.
5. La cuenta de emergencia no tiene `oid`, así que no ve elementos.
6. Orden en el inicio:
   - primero **Por aprobar**, después **Tareas**;
   - en cada grupo, por `due_at` (las vencidas primero) y al final las que no tienen fecha;
   - hasta 20 elementos por grupo, con un enlace «Ver todas» a la `url` de la app del catálogo.
7. Una tarea con `due_at` pasada se marca «Vencida». El portal no cambia su `status`.

---

## 5. Enlaces «Ver tarea» y «Ver aprobación»

El botón abre `url` en una pestaña nueva (`rel="noopener noreferrer"`).

Reglas que el portal valida en cada `PUT` (si no se cumplen, `422`):

- El esquema es `https`.
- El host (y el puerto, si lo trae) es **igual** al de la `url` de la app del catálogo de la integración (2.1). Se compara sin distinguir mayúsculas. Un subdominio distinto no sirve.
- No trae usuario ni contraseña (`https://usuario@...`).
- No pasa de 2048 caracteres.

Lo que debe hacer la app al abrir el enlace:

1. **Pedir inicio de sesión con Microsoft (SSO)** con los mismos inquilinos de Entra que usa el portal.
2. **Comprobar que esa persona puede ver el elemento.** Un enlace que viene del portal **nunca es una autorización**: cualquiera puede copiarlo o escribirlo a mano.
3. No poner en el enlace tokens, códigos de acceso ni datos personales.

---

## 6. Portal → App: completar una tarea

Solo para `kind: task` con `completable_from_portal: true` y `status: pending`.

### 6.1 La llamada

Cuando la persona pulsa «Completar», el servidor del portal comprueba:

- que la cuenta activa es la del `assignee`;
- que el elemento es una tarea, completable y pendiente;
- que la integración no está revocada y tiene URL base y secreto.

Después hace, en la misma petición de la persona, esta llamada de servidor a servidor:

```text
POST {app_base_url}/api/portal/v1/tasks/{external_id}/complete
```

Ejemplo completo, con `app_base_url` igual a `https://tareas.segurexlatam.net`:

```http
POST /api/portal/v1/tasks/8812%3A0b7d5e3a-0000-4000-8000-000000000002/complete HTTP/1.1
Host: tareas.segurexlatam.net
Content-Type: application/json
X-Portal-Timestamp: 1793300400
X-Portal-Signature: sha256=2cedea4d9a6e9ded7595db836c02ea855879bc48276263574a9a6ddd7b8d50f0

{"assignee":{"tenant_id":"6f1c2a9e-0000-4000-8000-000000000001","oid":"0b7d5e3a-0000-4000-8000-000000000002"},"completed_at":"2026-10-29T14:00:00-05:00","request_id":"9a1f3c6e-2b4d-4e8f-9c0a-7d5b3e1f2a6c"}
```

| Parte | Descripción |
|---|---|
| `{external_id}` | El mismo del `PUT`, codificado para URL con `rawurlencode` (RFC 3986, hexadecimal en mayúsculas: `:` va como `%3A`). |
| `assignee` | `{tenant_id, oid}` de la cuenta activa, en minúsculas. |
| `completed_at` | Momento en que la persona pulsó «Completar», ISO 8601 con la hora de Bogotá. |
| `request_id` | UUID nuevo en cada pulsación. |
| `X-Portal-Timestamp` | Segundos desde 1970-01-01 UTC (Unix), como entero en texto. |
| `X-Portal-Signature` | `sha256=` + HMAC-SHA256 en hexadecimal minúsculo (sección 6.2). |

Condiciones de la llamada:

- **Síncrona.** El portal no tiene trabajador de colas, solo un cron cada minuto, así que la persona espera la respuesta.
- **Tiempo máximo: 5 segundos** en total. Después cuenta como falla.
- El portal **no sigue redirecciones** (un `3xx` cuenta como falla) y siempre verifica el certificado TLS.
- El portal **no reintenta solo.** Si falla, la persona puede volver a pulsar; eso genera un `request_id` nuevo.
- Mientras la llamada está en curso, el portal no acepta otra pulsación sobre el mismo elemento.

### 6.2 La firma

Cada integración tiene su propio secreto. El portal lo genera con 32 bytes aleatorios y lo muestra como `ryrsig_` seguido de 43 caracteres. La clave HMAC es **la cadena completa tal como se muestra**, en UTF-8, con el prefijo incluido.

Receta exacta:

1. `timestamp` = el valor de `X-Portal-Timestamp`, como texto.
2. `method` = `POST`, en mayúsculas.
3. `path` = la ruta exacta de la petición, **codificada tal como llegó**: sin esquema, sin host y sin `?` ni consulta. Si `app_base_url` tiene una ruta (por ejemplo `https://servidor/tareas`), esa ruta va incluida. No la decodifique ni la normalice. Ejemplo: `/api/portal/v1/tasks/8812%3A0b7d5e3a-0000-4000-8000-000000000002/complete`.
4. `raw_body` = los bytes exactos del cuerpo recibido, **antes** de interpretar el JSON. No vuelva a serializarlo: cualquier espacio de diferencia cambia la firma.
5. `mensaje` = `timestamp` + `"\n"` + `method` + `"\n"` + `path` + `"\n"` + `raw_body`. `"\n"` es un salto de línea (byte `0x0A`), sin `\r`.
6. `firma` = `"sha256=" + hex_minúsculas(HMAC-SHA256(clave = secreto, datos = mensaje))`.
7. Compare `firma` con `X-Portal-Signature` en **tiempo constante**.
8. Rechace si `|ahora − timestamp| > 300` segundos (5 minutos), antes o después.

Como la firma cubre el método y la ruta, un cuerpo firmado para una tarea no sirve para otra. Si un proxy delante de la app decodifica o reescribe la ruta, la firma no coincidirá: la app debe leer la ruta original de la petición.

Verificación en PHP. En Laravel, `$rawBody` es `$request->getContent()` y `$path` es `strtok($request->getRequestUri(), '?')`, que conserva la codificación original:

```php
function portalRequestIsValid(string $method, string $path, string $rawBody, ?string $timestamp, ?string $signature, string $secret): bool
{
    if ($timestamp === null || ! ctype_digit($timestamp)) {
        return false;
    }

    if (abs(time() - (int) $timestamp) > 300) {
        return false;
    }

    if ($signature === null || ! str_starts_with($signature, 'sha256=')) {
        return false;
    }

    $message = $timestamp."\n".strtoupper($method)."\n".$path."\n".$rawBody;
    $expected = 'sha256='.hash_hmac('sha256', $message, $secret);

    return hash_equals($expected, $signature);
}
```

Para rotar el secreto sin perder llamadas, pruebe con el secreto nuevo y, si no coincide, con el anterior, solo durante la transición.

Cómo firma el portal (referencia para quien lo implemente):

```php
$body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$url = rtrim($baseUrl, '/').'/api/portal/v1/tasks/'.rawurlencode($externalId).'/complete';
$path = parse_url($url, PHP_URL_PATH);
$timestamp = (string) time();
$signature = 'sha256='.hash_hmac('sha256', $timestamp."\nPOST\n".$path."\n".$body, $secret);

Http::timeout(5)
    ->connectTimeout(5)
    ->withoutRedirecting()
    ->withHeaders(['X-Portal-Timestamp' => $timestamp, 'X-Portal-Signature' => $signature])
    ->withBody($body, 'application/json')
    ->post($url);
```

El portal firma la misma ruta que envía, sin volver a codificarla.

**Dato de prueba.** Con estos valores:

- secreto: `ryrsig_EJEMPLO_no_usar_en_produccion_0123456789`;
- timestamp: `1793300400`;
- método: `POST`;
- ruta: `/api/portal/v1/tasks/8812%3A0b7d5e3a-0000-4000-8000-000000000002/complete`;
- cuerpo: el del ejemplo de 6.1, exacto, en una sola línea y sin salto de línea al final;

la firma es:

```text
sha256=2cedea4d9a6e9ded7595db836c02ea855879bc48276263574a9a6ddd7b8d50f0
```

Use este dato en una prueba automática de la app. El secreto es de ejemplo: nunca lo use fuera de pruebas.

### 6.3 Lo que debe comprobar la app

En este orden:

1. La firma y el tiempo (6.2). Si fallan: `401`.
2. Idempotencia por `request_id`: si ya procesó ese `request_id`, devuelva la misma respuesta de la primera vez sin repetir el efecto. Guarde los `request_id` al menos 10 minutos, más que la ventana de 5 minutos de la firma. No hace falta ligar el `request_id` al `external_id`: la firma ya cubre la ruta, y solo el portal, que tiene el secreto, puede generar un `request_id` válido.
3. Que la tarea existe. Si no: `404`.
4. Que `assignee` es la persona asignada a esa tarea, comparando `tenant_id` y `oid`. Si no: `403`.
5. Que la tarea es completable desde el portal y no está cancelada. Una **aprobación** nunca lo es. Si no: `403`.
6. Si ya estaba hecha: `409` con `already_done`.
7. Si todo está bien: márquela como hecha con `completed_at` y responda `200`.

Después, la app **también envía su propio `PUT`** con `status: done` y una `version` mayor (sección 3), para que la copia del portal quede igual a la app.

Cuerpo de las respuestas de la app (el portal lee solo `code`):

```json
{ "code": "completed" }
```

| `code` | Código |
|---|---|
| `completed` | `200` |
| `already_done` | `409` |
| `not_assignee` | `403` |
| `not_completable` | `403` |
| `not_found` | `404` |
| `invalid_signature` | `401` |

### 6.4 Qué hace el portal con la respuesta

| Respuesta de la app | Qué hace el portal | Mensaje a la persona |
|---|---|---|
| `200` | Marca el elemento como hecho y lo quita del inicio. | «Tarea completada.» |
| `409` (`already_done`) | Igual que `200`: lo da por hecho. | «Tarea completada.» |
| `403` | El elemento sigue pendiente. | «No puedes completar esta tarea desde el portal. Ábrela en la app.» |
| `404` | Borra el elemento del portal. | «Esta tarea ya no existe en la app.» |
| `5xx` o sin respuesta en 5 s | El elemento sigue pendiente. | «No se pudo completar. Inténtalo de nuevo o ábrela en la app.» |
| Cualquier otra (`3xx`, `400`, `401`, `422`, `409` sin `already_done`...) | El elemento sigue pendiente. Indica un error de configuración (secreto, URL base). | «No se pudo completar. Inténtalo de nuevo o ábrela en la app.» |

El portal muestra siempre sus propios mensajes, nunca el texto que devuelve la app.

### 6.5 Auditoría

El portal registra cada intento en **Administración › Auditoría**, tipo **API**, con la persona que pulsó y el nombre de la integración:

| Evento | Cuándo | Datos |
|---|---|---|
| `work_item.completed` | Respuesta `200` o `409` con `already_done`. | `external_id`, `request_id`, código HTTP. |
| `work_item.complete_failed` | Cualquier otro resultado. | `external_id`, `request_id`, código HTTP o `timeout`, y el motivo (`forbidden`, `not_found`, `timeout`, `server_error`, `unexpected_response`). |

Auditoría no guarda el título, el secreto ni la firma. Los `PUT` y `DELETE` no crean una entrada cada uno; quedan en el «Último uso» de la integración.

---

## 7. Aprobaciones

- **Una aprobación nunca se aprueba ni se rechaza en el portal.** El portal solo muestra «Ver aprobación», que abre `url`.
- Esto se hace cumplir en el servidor:
  - el portal no tiene ninguna ruta para aprobar;
  - `completable_from_portal: true` con `kind: approval` responde `422`;
  - la ruta de «Completar» del portal rechaza las aprobaciones antes de llamar a la app.
- **La app no debe exponer al portal ninguna llamada para aprobar.** El secreto de firma solo sirve para `complete` de tareas, y la app debe responder `403` (`not_completable`) si recibe `complete` para una aprobación.
- Cuando se aprueba o se rechaza en la app, la app envía un `PUT` con `done` o `cancelled` (o un `DELETE`), y la aprobación desaparece del inicio.

---

## 8. Seguridad y privacidad

1. **Identidad:** solo `{tenant_id, oid}`. Nunca el correo, que puede cambiar o repetirse.
2. **`person_id` nunca autoriza nada.** Ni en el portal ni en la app.
3. **Sin tokens de Microsoft en el portal.** El portal no llama a la app en nombre de la persona: firma con el secreto de la integración y dice quién pulsó.
4. **Un enlace del portal nunca es autorización.** La app pide SSO y comprueba permisos en cada apertura.
5. **Títulos sin contenido sensible** (ver la advertencia de 3.2).
6. **Datos mínimos:** el portal guarda solo los campos de la tabla de 3.2. No hay descripción, comentarios, adjuntos ni montos.
7. **Secretos:** el token y el secreto viven en el gestor de secretos de la app, nunca en el código, en un ticket ni en un chat. Si se filtran, rótelos.
8. **Transporte:** todo va por HTTPS. El portal no sigue redirecciones al completar.
9. **Lista de IP:** limite el token a las IP del servidor de la app. La app puede limitar a la IP del portal la ruta `/api/portal/v1/...`.
10. **Aislamiento entre integraciones:** cada integración ve y cambia solo sus propios `external_id`.

---

## 9. Errores y límites

### 9.1 Errores del portal

Son los mismos de [`docs/api.md`](api.md), sección 4. Todas las respuestas de error son JSON.

| Código | Cuándo |
|---|---|
| `401` | Falta el token, no existe, fue revocado o fue reemplazado al rotarlo. |
| `403` | El token no tiene el permiso `work:write`, o la llamada viene de una IP que no está en su lista. |
| `404` | La dirección no existe, o el `external_id` tiene caracteres no permitidos. |
| `413` | El cuerpo pasa de 8 KB. Propio de este contrato. |
| `422` | Datos inválidos (sección 3.7). `errors` dice qué campo falló. |
| `429` | Demasiadas llamadas. Espere los segundos de `Retry-After`. |
| `5xx` | Error del portal. Reintente más tarde, con espera creciente. Repetir el mismo `PUT` es seguro. |

### 9.2 Límites

| Límite | Valor |
|---|---|
| Llamadas por token | 120 por minuto, el mismo límite de hoy (`X-RateLimit-Limit`, `X-RateLimit-Remaining`). |
| Llamadas sin token válido | 30 por minuto por IP. |
| Cuerpo del `PUT` | 8 KB. |
| `external_id` | 190 caracteres. |
| `title` | 140 caracteres. |
| `url` | 2048 caracteres. |
| Elementos pendientes por persona y por integración | 500. |
| Elementos visibles en el inicio | 20 por grupo. |
| Rastro de un elemento borrado | 30 días. |
| Elementos hechos o cancelados | Se borran a los 30 días. |
| Llamada «completar» | 5 segundos, sin reintento automático. |
| Diferencia de reloj aceptada por la app | 5 minutos. |

---

## 10. Lista de chequeo para el equipo de la app

Registro:

- [ ] La app está en **Administración › Aplicaciones** con su `url` `https`.
- [ ] Hay una integración con `work:write`, las IP del servidor, la app del catálogo y la URL base.
- [ ] El token y el secreto están en el gestor de secretos, no en el código.

App → Portal:

- [ ] Cada elemento tiene un `external_id` fijo y válido, uno por persona.
- [ ] La persona se identifica por `tid` y `oid` de Microsoft, nunca por el correo.
- [ ] Cada cambio sube `version`; los reintentos repiten la misma.
- [ ] `ignored_stale` y `unchanged` no se tratan como errores.
- [ ] Los títulos son cortos y sin contenido sensible.
- [ ] Las aprobaciones van siempre con `completable_from_portal: false`.
- [ ] Al terminar, cancelar o borrar en la app, se envía `PUT` (`done`/`cancelled`) o `DELETE`.
- [ ] Ante `429` y `5xx`, reintenta con espera creciente.

Enlaces:

- [ ] Las `url` son `https` y del mismo host que la app del catálogo.
- [ ] Al abrir un enlace, la app pide SSO con los inquilinos de Entra del grupo y comprueba que la persona puede ver el elemento.

Portal → App:

- [ ] Existe `POST /api/portal/v1/tasks/{external_id}/complete`.
- [ ] Verifica la firma sobre el método, la ruta tal como llegó y el cuerpo crudo, en tiempo constante, y rechaza más de 5 minutos de diferencia.
- [ ] Pasa la prueba con el dato de 6.2.
- [ ] Es idempotente por `request_id`.
- [ ] Comprueba que `assignee` es la persona asignada y que la tarea es completable.
- [ ] Responde `200`, `409`, `403`, `404` o `401` con su `code`, en menos de 5 segundos.
- [ ] Rechaza `complete` para las aprobaciones.
- [ ] No expone ninguna llamada para aprobar.
- [ ] Después de completar, envía su propio `PUT` con `status: done`.

---

## 11. Preguntas abiertas

1. **Carga inicial y conciliación.** Con 120 llamadas por minuto, cargar miles de elementos tarda. Además no hay una lectura para saber qué tiene el portal. ¿Hace falta un endpoint por lotes, un límite mayor para `work:write`, o un `GET /api/v1/work-items` para conciliar?
2. **Cuentas de invitado (B2B).** El portal guarda a un invitado con el inquilino y el `oid` de su organización de origen. Si la app tiene un registro de un solo inquilino, verá a esa persona con otro `oid`, y sus elementos no coincidirán. ¿Se excluyen los invitados como responsables, o cómo obtiene la app el `tid` y el `oid` de origen?

---

## 12. Registro de cambios

| Versión | Fecha | Cambio |
|---|---|---|
| 0.1 | 2026-10-29 | Primera versión, con las decisiones de Jhon: la app es la fuente de verdad, un elemento por persona, identidad por `{tenant_id, oid}`, enlaces solo al host de la app, «Completar» firmado y solo para tareas, aprobaciones solo con «Ver». |
| 0.1 | 2026-10-29 | La firma de «Completar» cubre también el método y la ruta: `timestamp` + `"\n"` + `"POST"` + `"\n"` + ruta + `"\n"` + cuerpo. Nuevo dato de prueba. Se quita la regla que ligaba `request_id` a `external_id`, porque ya no hace falta. Se cierra la pregunta abierta sobre la ruta sin firmar. |
| 0.1.1 | 2026-10-30 | Decisión de Jhon: un `PUT` con la misma `version` pero otro `kind` u otro `assignee` responde `422`, como con una versión mayor (3.3, punto 3). |

Los cambios a este contrato los decide Jhon, con un PR en el repositorio del portal (GrupoRyR/Portal-RYR).
