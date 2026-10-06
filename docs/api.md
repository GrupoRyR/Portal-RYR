# API para sistemas externos — Portal RYR

Esta guía es para el equipo de TI que conecta un sistema (un monitor de disponibilidad, una herramienta de gestión) con el Portal RYR. Con la API usted puede:

- **leer el catálogo** de aplicaciones del portal;
- **reportar el estado** de una aplicación: si funciona, si está lenta o si está caída.

El portal no depende de estos sistemas: si ninguno usa la API, todo sigue funcionando igual.

- **Dirección base:** `https://apps.segurexlatam.net/api/v1`
- **Formato:** JSON, en UTF-8. Las fechas van en ISO 8601 con la hora de Bogotá (`2026-10-06T10:00:00-05:00`).

---

## 1. Token de acceso

Cada sistema tiene su propio token. Lo crea un súper administrador en **Administración → Integraciones → Nuevo token**, eligiendo:

- **Nombre del sistema**, por ejemplo «Monitor de disponibilidad». Es el nombre que aparece en Auditoría.
- **Permisos:**

  | Permiso | Qué permite |
  |---|---|
  | `catalog:read` | Leer el catálogo de apps, sin usuarios ni reglas de acceso |
  | `status:write` | Reportar si una app funciona, falla o está caída |

- **IP permitidas** (opcional): direcciones (`10.0.0.5`) o rangos (`192.168.1.0/24`) desde las que se acepta el token. Si la lista está vacía, se acepta desde cualquier IP. Se recomienda limitarla a la IP del servidor que hace las llamadas.

El token empieza por `ryr_` y **se muestra una sola vez**, al crearlo. Guárdelo en el gestor de secretos del sistema que lo va a usar; el portal solo conserva una huella del token y no puede volver a mostrarlo.

- **Rotar:** genera un token nuevo y el anterior deja de funcionar de inmediato. Úselo si el token se pudo haber filtrado o si se perdió.
- **Revocar:** el token deja de funcionar para siempre. El sistema queda en la lista como «Revocado».

> Nunca escriba el token en el código fuente, en un ticket ni en un chat. Si ocurre, rótelo.

### Cómo enviarlo

En cada llamada, envíe el token en el encabezado `Authorization`:

```http
Authorization: Bearer ryr_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

En los ejemplos de esta guía, `$TOKEN` es su token:

```bash
export TOKEN='ryr_...'
```

---

## 2. Leer el catálogo

`GET /api/v1/apps` — permiso `catalog:read`.

Devuelve las aplicaciones del portal, en el orden en que se ven en la pantalla principal. Las apps **ocultas** no aparecen. Nunca incluye usuarios ni reglas de acceso.

```bash
curl -s https://apps.segurexlatam.net/api/v1/apps \
  -H "Authorization: Bearer $TOKEN"
```

Respuesta (`200`):

```json
{
  "data": [
    {
      "slug": "cartera",
      "name": "Visualizador de cartera",
      "type": "web",
      "url": "https://cartera.segurex.com.co",
      "category": { "slug": "tesoreria", "name": "Tesorería" },
      "all_companies": false,
      "companies": [ { "slug": "segurex", "name": "Segurex" } ],
      "hosting": "vercel",
      "login_type": "microsoft",
      "status": {
        "current": "failing",
        "maintenance": null,
        "reported": {
          "status": "down",
          "message": "Estamos revisando",
          "reported_at": "2026-10-06T09:35:00-05:00"
        }
      },
      "updated_at": "2026-10-06T09:35:00-05:00"
    }
  ]
}
```

| Campo | Descripción |
|---|---|
| `slug` | Identificador fijo de la app. No cambia aunque cambie el nombre o la URL. Es el que se usa para reportar el estado. |
| `type` | `web` (se abre en el navegador) o `local` (instalada en el equipo; `url` es `null`). |
| `all_companies` | `true` si la app está disponible para todas las empresas activas; `companies` las lista. |
| `hosting` | `godaddy`, `vercel`, `local` u `other`. |
| `login_type` | `microsoft`, `own` (inicio de sesión propio) o `local`. |
| `status.current` | Lo que ve la gente en la tarjeta: `active`, `maintenance` o `failing` («Con fallas»). |
| `status.maintenance` | Mensaje y fecha estimada de regreso, si TI puso la app en mantenimiento. |
| `status.reported` | Último estado reportado por un sistema externo, mientras siga vigente (ver 3.3); si no, `null`. |
| `updated_at` | Última modificación de la app, incluidos los cambios de estado reportados. |

### Solo lo que cambió

Agregue `updated_since` con una fecha ISO 8601 para traer solo las apps modificadas desde ese momento (incluido):

```bash
curl -s "https://apps.segurexlatam.net/api/v1/apps?updated_since=2026-10-06T08:00:00-05:00" \
  -H "Authorization: Bearer $TOKEN"
```

Codifique el signo `+` como `%2B` si su zona horaria lo usa, o envíe la hora en UTC con `Z` (`2026-10-06T13:00:00Z`).

Una app que se oculta o se borra simplemente deja de aparecer. Si su sistema guarda una copia del catálogo, haga además una lectura completa (sin `updated_since`) de vez en cuando, por ejemplo una vez al día.

---

## 3. Reportar el estado de una app

`PUT /api/v1/apps/{slug}/status` — permiso `status:write`.

```bash
curl -s -X PUT https://apps.segurexlatam.net/api/v1/apps/cartera/status \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"status": "down", "message": "Estamos revisando", "source": "uptime-kuma", "reference": "INC-1042"}'
```

| Campo | Obligatorio | Descripción |
|---|---|---|
| `status` | Sí | `operational` (funciona), `degraded` (funciona con problemas) o `down` (caída). |
| `message` | No | Texto para las personas, máximo 140 caracteres. Si no se envía, la tarjeta dice «Estamos revisando». |
| `source` | No | Qué sistema o regla generó el reporte, máximo 100 caracteres. |
| `reference` | No | Identificador del incidente en su sistema, máximo 100 caracteres. |

Respuesta (`200`):

```json
{
  "data": {
    "slug": "cartera",
    "changed": true,
    "status": {
      "current": "failing",
      "maintenance": null,
      "reported": { "status": "down", "message": "Estamos revisando", "reported_at": "2026-10-06T10:00:00-05:00" }
    }
  }
}
```

### 3.1 Qué ve la gente

- `degraded` y `down` ponen la tarjeta en **«Con fallas»**, en rojo, con el mensaje. La app se puede seguir abriendo, porque la falla puede ser parcial.
- `operational` devuelve la tarjeta a normal.
- **El mantenimiento manda.** Si TI puso la app en mantenimiento, el reporte se guarda en el historial pero la tarjeta sigue mostrando el mantenimiento (`current` es `maintenance`). Cuando termina el mantenimiento, se muestra el último reporte si sigue vigente.
- Las apps ocultas también aceptan reportes; simplemente no se ven en el inicio.

### 3.2 Reportes repetidos

Puede enviar el mismo estado cada vez que su monitor revise la app. Si el estado y el mensaje son iguales al último reporte vigente:

- la respuesta trae `"changed": false`;
- no se crea un registro nuevo en el historial ni en Auditoría;
- se renueva la hora del reporte, para que la falla no venza mientras usted la siga reportando.

Un mensaje distinto, un estado distinto o un reporte después del vencimiento sí cuentan como cambio.

### 3.3 Vencimiento

Si una app está «Con fallas» y no llega un reporte nuevo en **2 horas**, la tarjeta vuelve a verse normal sola, para que nunca quede en rojo por un sistema que dejó de reportar. El tiempo se cambia en **Administración → Configuración → Estados reportados por la API** (1, 2 o 4 horas).

Recomendación: mientras la app falle, repita el reporte al menos cada 30 minutos; cuando se recupere, envíe `operational`.

### 3.4 Historial y auditoría

Cada cambio queda guardado con la app, el estado, el mensaje, `source`, `reference`, el sistema que lo reportó y la fecha. Se ve en:

- **Administración → Aplicaciones →** la app **→ Estado reportado** (últimos reportes);
- **Administración → Integraciones →** el sistema **→ Últimos cambios que hizo**;
- **Administración → Auditoría**, tipo **API**, con el nombre del sistema.

En **Estado reportado** un administrador también puede limpiar a mano un estado reportado.

---

## 4. Errores

Todas las respuestas de error son JSON, aunque la llamada no envíe `Accept: application/json`:

```json
{ "message": "Unauthenticated." }
```

| Código | Cuándo |
|---|---|
| `401` | Falta el token, no existe, fue revocado o fue reemplazado al rotarlo. |
| `403` | El token no tiene el permiso del endpoint, o la llamada viene de una IP que no está en su lista. |
| `404` | No existe una app con ese `slug`, o la dirección no existe. |
| `422` | Datos inválidos. `errors` dice qué campo falló, por ejemplo `{"message": "...", "errors": {"status": ["..."]}}`. |
| `429` | Demasiadas llamadas (ver Límites). Espere los segundos que indica el encabezado `Retry-After`. |
| `5xx` | Error del portal. Reintente más tarde, con espera creciente. |

---

## 5. Límites

- **120 llamadas por minuto por token.** Cada respuesta trae los encabezados `X-RateLimit-Limit` y `X-RateLimit-Remaining`.
- Las llamadas sin un token válido se limitan a 30 por minuto por IP.
- El catálogo se devuelve completo en una sola respuesta (no hay paginación).

---

## 6. Preguntas frecuentes

**¿El token sirve para entrar al portal?** No. Solo sirve para la API, y solo para lo que permiten sus permisos.

**¿Puedo usar un token para varios sistemas?** Es mejor uno por sistema: así Auditoría muestra quién hizo cada cambio y puede revocar uno sin afectar a los demás.

**¿Qué pasa si el token se filtra?** Rótelo en Integraciones. El token anterior deja de funcionar de inmediato; ponga el nuevo en su sistema.

**¿Cómo pruebo sin afectar a las personas?** Reporte el estado de una app oculta, o envíe `operational`, que no cambia lo que se ve si la app ya está normal.
