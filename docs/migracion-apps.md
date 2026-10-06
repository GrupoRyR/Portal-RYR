# Guía de migración de las aplicaciones a «Entrar con Microsoft»

Esta guía explica cómo pasar cada aplicación de la empresa a **«Entrar con Microsoft»**, para que quien la abra desde el Portal RYR entre sin volver a escribir su contraseña. Está dirigida al área de TI; no es material para usuarios finales.

Decisiones ya tomadas (no se reabren en esta guía):

- **Cada aplicación pertenece a una sola empresa**, así que cada una tiene **su propio registro en Microsoft Entra, de un solo inquilino**: Toma Pedidos, Cuentas por pagar y Cartera en el inquilino de Segurex; Portafolio en el de Derivados. Ninguna aplicación es multiinquilino.
- **Cada aplicación sigue decidiendo quién entra** con su propia tabla `usuarios` y sus roles. El portal no expone una API de accesos y las aplicaciones no dependen de que el portal esté en línea.
- Esta guía no modifica los repositorios de las aplicaciones; describe los cambios que se harán en cada uno.

En los comandos y valores, reemplace estos marcadores:

| Marcador | Significado |
|---|---|
| `<id-del-inquilino>` | ID de inquilino (Directory/tenant ID) de la empresa dueña de la app |
| `<id-de-la-aplicación>` | Application (client) ID del registro de la app en Entra |
| `<secreto-de-la-aplicación>` | Valor del secreto de cliente (se copia una sola vez) |
| `<proyecto>` | Referencia del proyecto de Supabase (la parte inicial de `https://<proyecto>.supabase.co`) |
| `<dominio-app>` | Dominio público de la aplicación, por ejemplo el de Vercel |

Las rutas de archivo de las secciones 4 y 5 son relativas a la carpeta de cada repositorio. Los ID de inquilino y los secretos **nunca** se escriben en el código ni en esta guía: van en `.env`, en las variables de Vercel o en el panel de Supabase.

---

## 1. Qué cambia para el usuario y cómo funciona el inicio de sesión único

**Para el usuario.** La pantalla de ingreso de la aplicación muestra el botón **«Entrar con Microsoft»** en lugar del formulario de correo y contraseña. Si ya entró al portal ese día, al pulsar el botón (o al abrir la app desde la tarjeta) vuelve a la aplicación en uno o dos segundos, sin escribir nada.

**Cómo funciona.** El inicio de sesión único no lo hace el portal sino Microsoft:

1. Al entrar al portal, Microsoft deja abierta una sesión en el navegador (en `login.microsoftonline.com`).
2. Cuando la aplicación envía al usuario a Microsoft para identificarse, Microsoft reconoce esa sesión y, si el usuario pertenece al inquilino del registro de la app, lo devuelve de inmediato con su identidad.
3. La aplicación recibe la identidad (correo e ID de objeto de Entra) y decide con su tabla `usuarios` si la persona entra y con qué rol.

Consecuencias prácticas:

- El inicio de sesión único funciona **dentro del mismo inquilino**. Una persona de Segurex que ya entró al portal pasa directo a Cuentas por pagar; una de Derivados pasa directo a Portafolio.
- Si el navegador tiene **varias cuentas de Microsoft** abiertas (por ejemplo, una personal y una de trabajo), Microsoft puede mostrar el selector de cuenta. Es normal: el usuario elige su cuenta de la empresa y continúa.
- Microsoft documenta los parámetros opcionales `login_hint` (precarga la cuenta) y `domain_hint` (salta el paso de descubrimiento del dominio). Socialite los admite con `->with([...])` y Supabase con `options.queryParams`. **No se recomiendan por ahora**: el registro de un solo inquilino ya dirige al usuario a la organización correcta, y Microsoft advierte que `domain_hint` impide el ingreso de invitados. Úselos solo si las pruebas muestran que el selector de cuenta molesta a muchos usuarios.
- La primera vez puede aparecer la pantalla de permisos de Microsoft. Para evitarla, conceda el **consentimiento de administrador** al crear el registro (sección 3, paso 7).

---

## 2. Resumen por aplicación

| Orden | App | Empresa / inquilino | Stack y hosting | Ingreso hoy | Método recomendado | Esfuerzo |
|---|---|---|---|---|---|---|
| 1 | Toma Pedidos SAP | Segurex | Laravel + MySQL, GoDaddy | Microsoft (Socialite) | Ya migrada; solo endurecimiento opcional (sección 4) | Bajo |
| 2 | Cuentas por pagar | Segurex | Next.js 16 + Supabase, Vercel | Correo y contraseña (Supabase) | Proveedor Azure de Supabase Auth (sección 5) | Medio |
| 3 | Cartera | Segurex | Next.js 16 + Supabase, Vercel | Correo y contraseña (Supabase) | Igual que Cuentas por pagar (sección 5) | Bajo, reutiliza el trabajo de la anterior |
| 4 | Portafolio de productos | Derivados | PHP sin framework + MySQL, GoDaddy | Correo y contraseña propios | Librería `thenetworg/oauth2-azure` (sección 6) | Medio–alto |

Cámaras es una aplicación local (instalada en el equipo): no aplica.

---

## 3. Registro en Microsoft Entra (pasos comunes)

Se hace **un registro por aplicación**, en el inquilino de la empresa dueña. Así cada app tiene sus propias URL de redirección, su propio secreto (que vence por separado) y se puede desactivar sin afectar a las demás.

### 3.1 Crear el registro

1. Entre al [centro de administración de Microsoft Entra](https://entra.microsoft.com) con una cuenta administradora del inquilino correcto (Segurex o Derivados). Si su cuenta ve los dos inquilinos, cambie de directorio con el ícono de **Configuración** de la barra superior.
2. Vaya a **Entra ID → App registrations (Registros de aplicaciones) → New registration (Nuevo registro)**.
3. **Nombre:** use el nombre de la app (por ejemplo `Cuentas por pagar SEGUREX`). Los usuarios pueden verlo en la pantalla de Microsoft.
4. **Supported account types (Tipos de cuenta compatibles):** **Single tenant only / Solo cuentas de este directorio organizativo**.
5. **Redirect URI:** plataforma **Web** y la URL de la tabla 3.3. Pulse **Register**.
6. En **Overview**, anote el **Application (client) ID** y el **Directory (tenant) ID**.
7. En **API permissions**, deje los permisos delegados de Microsoft Graph `openid`, `profile`, `email` y `User.Read` (agregue los que falten) y pulse **Grant admin consent for <empresa>**. Lo hace un administrador de esa organización de Microsoft 365. Con esto ningún usuario ve la pantalla de permisos.

### 3.2 Secreto de cliente y su vencimiento

1. En **Certificates & secrets → Client secrets → New client secret**, escriba una descripción con la fecha (por ejemplo `produccion-2026-10`) y elija la vigencia. Microsoft limita la vigencia a 24 meses y recomienda menos de 12.
2. Copie el **Value (Valor)** del secreto en ese momento; Microsoft no lo vuelve a mostrar. No copie el «Secret ID».
3. Guárdelo en el gestor de contraseñas y póngalo donde corresponda (`.env`, Supabase o configuración del servidor).
4. **Cree un recordatorio en el calendario** un mes antes del vencimiento. Cuando venza, la app deja de dejar entrar a todos. Para renovarlo: cree un secreto nuevo, cámbielo en la app, pruebe el ingreso y solo entonces borre el secreto viejo.

### 3.3 Decisión por aplicación

| App | Inquilino | Tipo de cuenta | URI de redirección (producción) | Dónde va el secreto | Claims opcionales |
|---|---|---|---|---|---|
| Toma Pedidos | Segurex | Un solo inquilino | `https://<dominio-de-pedidos>/auth/callback` (la ruta `auth.callback` de la app) | `.env` de la app (`AZURE_CLIENT_SECRET`) | No necesita |
| Cuentas por pagar | Segurex | Un solo inquilino | `https://<proyecto>.supabase.co/auth/v1/callback` (del proyecto Supabase de esta app) | Panel de Supabase | `email` y `xms_edov` (sección 3.4) |
| Cartera | Segurex | Un solo inquilino | `https://<proyecto>.supabase.co/auth/v1/callback` (del proyecto Supabase de esta app) | Panel de Supabase | `email` y `xms_edov` (sección 3.4) |
| Portafolio | Derivados | Un solo inquilino | `https://portafolio.derivadoscol.com.co/<ruta-del-callback>` (se confirma con el repositorio) | Archivo de configuración fuera de `public_html` | `email` (recomendado) |

Notas:

- **Varios entornos.** Agregue en el mismo registro una URI por entorno (producción y `http://localhost:<puerto>/...` para desarrollo). Las URI deben coincidir exactamente, incluida la barra final. En Cuentas por pagar y Cartera, Microsoft solo ve la URL de Supabase: los entornos de la app (Vercel de producción, vistas previas y `localhost`) se configuran en Supabase (sección 5.2).
- **Toma Pedidos:** el `.env.example` del repositorio usa el dominio `pedidos.segurex.com`, pero el catálogo del portal (tabla 1.1 del documento de especificación) dice `pedidos.segurex.com.co`. Confirme cuál es el real; la URI registrada en Entra debe ser la del dominio que atiende la app.
- **Restringir quién puede iniciar sesión (opcional).** En **Enterprise apps → <la app> → Properties**, **Assignment required? = Yes** y luego asigne usuarios o grupos en **Users and groups**. Así Microsoft rechaza antes de llegar a la app a quien no esté asignado. Es una barrera adicional, no reemplaza a la tabla `usuarios`. Tenga en cuenta que un Administrador global entra igual, y que asignar grupos requiere licencia Entra ID P1 o P2.

### 3.4 Claims opcionales para Supabase (`email` y `xms_edov`)

Supabase Auth exige que Microsoft entregue un correo válido y recomienda el claim `xms_edov`, que indica si el dominio del correo está verificado por la organización. Sin él, el correo puede considerarse no verificado y el usuario de Microsoft **no se vincula** con la cuenta que la persona ya tiene en Supabase (sección 7).

1. En el registro de la app, abra **Manifest**.
2. En `optionalClaims`, deje esto (si ya existe la sección, agregue solo lo que falte):

   ```json
   "optionalClaims": {
       "idToken": [
           { "name": "xms_edov", "source": null, "essential": false, "additionalProperties": [] },
           { "name": "email", "source": null, "essential": false, "additionalProperties": [] }
       ],
       "accessToken": [
           { "name": "xms_edov", "source": null, "essential": false, "additionalProperties": [] }
       ],
       "saml2Token": []
   }
   ```

3. Pulse **Save**.

El claim `email` sale del atributo de correo del usuario en Microsoft 365. Una cuenta sin buzón ni correo asignado no trae `email` y Supabase la rechaza; revise en Entra que cada usuario de la app tenga correo.

---

## 4. Toma Pedidos (Laravel + Socialite)

Repositorio: `PEDIDOS_COTIZACIONES_SAP/web`. **Ya entra con Microsoft**, con registro de un solo inquilino en Segurex. Lo que sigue es endurecimiento opcional.

### 4.1 Lo que ya existe

| Archivo | Qué hace |
|---|---|
| `composer.json` | Usa `laravel/socialite` y `socialiteproviders/microsoft-azure` |
| `app/Providers/AppServiceProvider.php` | Registra el proveedor `azure` con `SocialiteWasCalled` |
| `config/services.php` | Bloque `azure` con `client_id`, `client_secret`, `redirect` y `tenant` (si falta el tenant, Socialite usaría `common`) |
| `config/auth.php` | `dominio_permitido` desde `AUTH_DOMINIO_PERMITIDO` (por defecto `segurex.com`) |
| `routes/web.php` | Rutas `entrar`, `auth.redirigir` (`/auth/redirigir`) y `auth.callback` (`/auth/callback`) |
| `app/Http/Controllers/AutenticacionController.php` | Pide `openid profile email User.Read`; en `volver()` toma el correo, exige el dominio permitido, busca en `usuarios` por `correo`, rechaza si no existe o está inactivo, guarda `entra_object_id` y `ultimo_acceso`, inicia sesión y regenera la sesión |
| `database/migrations/2026_09_24_000200_create_usuarios.php` | Tabla `usuarios` con `correo` único y `entra_object_id` único y opcional |
| `tests/Feature/AutenticacionTest.php` | Pruebas del ingreso con Socialite simulado |

Detalle útil: el proveedor de Socialite consulta `/me` en Microsoft Graph; `getId()` devuelve el ID de objeto del usuario y `getEmail()` devuelve el `userPrincipalName`.

Variables de `.env` (ver `.env.example`): `AZURE_CLIENT_ID`, `AZURE_CLIENT_SECRET`, `AZURE_TENANT_ID`, `AZURE_REDIRECT_URI` y `AUTH_DOMINIO_PERMITIDO`.

### 4.2 Lista de endurecimiento (opcional)

- [ ] Mantener `AZURE_TENANT_ID` con el ID del inquilino de Segurex (nunca `common` ni `organizations`).
- [ ] Mantener `AUTH_DOMINIO_PERMITIDO=segurex.com`.
- [ ] **Identificar por `entra_object_id`** una vez vinculado, y usar el correo solo para vincular filas que aún no tienen ID de objeto. Microsoft recomienda no usar el correo ni el UPN para decidir accesos, porque pueden cambiar o reutilizarse; el ID de objeto (`oid`) no cambia.
- [ ] Rechazar una fila cuyo correo coincide pero ya tiene **otro** ID de objeto (por ejemplo, un correo reasignado a otra persona).

Cambio propuesto en `AutenticacionController::volver()`, reemplazando la línea `$usuario = Usuario::where('correo', $correo)->first();`:

```php
$objectId = (string) $cuenta->getId();

// Primero por el ID de objeto de Entra, que no cambia aunque cambie el correo.
$usuario = Usuario::where('entra_object_id', $objectId)->first();

if (! $usuario) {
    // Vinculación inicial: solo filas que todavía no tienen ID de objeto.
    $porCorreo = Usuario::where('correo', $correo)->first();

    if ($porCorreo && $porCorreo->entra_object_id !== null) {
        // El correo ya pertenece a otra cuenta de Microsoft (correo reasignado).
        $this->bitacora->registrar('INGRESO_RECHAZADO', 'usuario', $porCorreo->id, [
            'correo' => $correo,
            'motivo' => 'correo vinculado a otra cuenta de Microsoft',
        ]);

        return redirect()->route('sin-acceso')->with('correo', $correo);
    }

    $usuario = $porCorreo;
}

if ($usuario && $usuario->correo !== $correo) {
    // Cambió el alias en Microsoft: entra igual, pero queda registrado para
    // que administración actualice el correo de la fila.
    Log::notice('El correo de Microsoft no coincide con la fila del usuario', [
        'usuario_id' => $usuario->id,
        'correo_microsoft' => $correo,
    ]);
}
```

El resto del método no cambia (la revisión de `activo`, el `forceFill` de `entra_object_id` y `ultimo_acceso`, `Auth::login` y `session()->regenerate()`). La revisión del dominio sigue antes de la búsqueda.

Pruebas a agregar en `tests/Feature/AutenticacionTest.php`. Primero, permita que el ayudante reciba el ID de objeto:

```php
private function respuestaDeMicrosoft(string $correo, string $nombre = 'Persona de prueba', ?string $objectId = null): void
{
    $cuenta = new UsuarioSocialite;
    $cuenta->map([
        'id' => $objectId ?? 'objeto-entra-'.md5($correo),
        'name' => $nombre,
        'email' => $correo,
    ]);
    // ...igual que hoy
}

public function test_reconoce_por_id_de_objeto_aunque_cambie_el_correo(): void
{
    $usuario = Usuario::create([
        'correo' => 'persona.prueba@segurex.com', 'nombre' => 'Persona de prueba',
        'rol' => 'ASESOR', 'activo' => true, 'entra_object_id' => 'objeto-persona',
    ]);

    $this->respuestaDeMicrosoft('persona.prueba.nueva@segurex.com', objectId: 'objeto-persona');

    $this->get(route('auth.callback'))->assertRedirect(route('inicio'));
    $this->assertAuthenticatedAs($usuario);
}

public function test_un_correo_vinculado_a_otra_cuenta_de_microsoft_no_entra(): void
{
    Usuario::create([
        'correo' => 'persona.prueba@segurex.com', 'nombre' => 'Persona de prueba',
        'rol' => 'ASESOR', 'activo' => true, 'entra_object_id' => 'objeto-persona',
    ]);

    $this->respuestaDeMicrosoft('persona.prueba@segurex.com', objectId: 'objeto-otra-persona');

    $this->get(route('auth.callback'))->assertRedirect(route('sin-acceso'));
    $this->assertGuest();
}
```

`entra_object_id` ya está en `$fillable` del modelo `App\Models\Usuario`, así que `Usuario::create` lo acepta. Ejecute `php artisan test` en el repositorio de Toma Pedidos: las pruebas actuales deben seguir pasando.

**Reversa:** revertir el commit del cambio. Los `entra_object_id` guardados no estorban.

---

## 5. Cuentas por pagar y Cartera (Next.js 16 + Supabase)

Repositorios: `CUENTAS_POR_PAGAR_WEB` y `PROYECTO_CARTERA_WEB` (la app web está en `web/`, el SQL en `supabase/`). Las dos apps tienen el mismo ingreso; los archivos de autenticación son idénticos. Haga primero Cuentas por pagar y repita en Cartera.

### 5.1 Lo que existe hoy

| Archivo | Qué hace |
|---|---|
| `web/src/componentes/FormularioLogin.tsx` | Formulario de correo y contraseña con `signInWithPassword` |
| `web/src/app/login/page.tsx` | Página de ingreso; recibe `volver` para regresar a la página pedida |
| `web/src/proxy.ts` y `web/src/lib/supabase/sesion.ts` | Refrescan la sesión; envían a `/login` a quien no la tenga. `/login` y `/auth` ya son rutas públicas |
| `web/src/lib/supabase/cliente.ts` y `servidor.ts` | Clientes de `@supabase/ssr` para navegador y servidor (usan PKCE por defecto) |
| `web/src/componentes/FormularioClave.tsx` | Cambio de contraseña en «Mi cuenta» |
| `web/src/app/(app)/layout.tsx` | Muestra «Tu cuenta aún no tiene acceso» si el usuario no tiene fila activa en `usuarios` |
| `supabase/02_perfiles_y_rls.sql` (Cuentas por pagar) | Tabla `usuarios` (correo único sin distinguir mayúsculas), `mi_rol()` y políticas RLS por `auth.email()` |
| `supabase/02_usuarios_y_permisos.sql` (Cartera) | Tabla `usuarios`, `es_admin()`, `mis_slp_codes()` y RLS por `auth.email()` |
| `scripts/crear_cuentas.py` | Crea las cuentas de Supabase Auth con el correo ya confirmado |

**El acceso no cambia:** RLS sigue comparando `auth.email()` con `usuarios.email`. Al entrar con Microsoft, `auth.email()` es el correo que entrega Microsoft, que para los usuarios de Segurex es el mismo con el que se crearon. No hay que tocar el SQL.

### 5.2 Configuración en Supabase (panel)

En el proyecto de Supabase de la app:

1. **Authentication → Sign In / Providers → Azure:** actívelo y llene:
   - **Application (client) ID:** `<id-de-la-aplicación>`.
   - **Secreto del cliente:** `<secreto-de-la-aplicación>` (el «Value» de la sección 3.2).
   - **Azure Tenant URL:** `https://login.microsoftonline.com/<id-del-inquilino>` (inquilino de Segurex). Así Supabase solo acepta cuentas de esa organización.
2. Copie la **Callback URL** que muestra Supabase (`https://<proyecto>.supabase.co/auth/v1/callback`) y regístrela en Entra como URI de redirección **Web** (sección 3.1, paso 5).
3. **Authentication → URL Configuration:**
   - **Site URL:** la URL de producción de la app (por ejemplo `https://sgx-cuentas-por-pagar.vercel.app`).
   - **Redirect URLs:** agregue `https://<dominio-app>/**` (producción), `http://localhost:3000/**` (desarrollo) y, si usa vistas previas de Vercel, el patrón `https://*-<equipo>.vercel.app/**`. El `redirectTo` que envía la app debe coincidir con esta lista o el ingreso falla.

### 5.3 Cambios en el código

**a) Botón «Entrar con Microsoft».** Nuevo archivo `web/src/componentes/BotonMicrosoft.tsx`:

```tsx
"use client";

import { useState } from "react";
import { crearClienteNavegador } from "@/lib/supabase/cliente";

export function BotonMicrosoft({ volver }: { volver?: string }) {
  const [cargando, setCargando] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function entrar() {
    setCargando(true);
    setError(null);

    const retorno = new URL("/auth/callback", window.location.origin);
    if (volver && volver.startsWith("/") && !volver.startsWith("//")) {
      retorno.searchParams.set("volver", volver);
    }

    const supabase = crearClienteNavegador();
    const { error: fallo } = await supabase.auth.signInWithOAuth({
      provider: "azure",
      // Supabase exige el scope "email" para el proveedor de Microsoft.
      options: { scopes: "email", redirectTo: retorno.toString() },
    });

    if (fallo) {
      setError("No fue posible conectar con Microsoft. Intenta de nuevo.");
      setCargando(false);
    }
    // Si no hay error, el navegador ya va camino a Microsoft.
  }

  return (
    <div>
      <button
        type="button"
        onClick={entrar}
        disabled={cargando}
        className="w-full rounded-lg px-4 py-3 text-base font-semibold text-white disabled:opacity-60"
        style={{ background: "var(--color-marca)" }}
      >
        {cargando ? "Conectando…" : "Entrar con Microsoft"}
      </button>
      {error && (
        <p role="alert" className="mt-3 text-sm" style={{ color: "var(--color-critico)" }}>
          {error}
        </p>
      )}
    </div>
  );
}
```

**b) Ruta de regreso.** Nuevo archivo `web/src/app/auth/callback/route.ts`. Intercambia el código por la sesión (patrón de la documentación de Supabase para Next.js con `@supabase/ssr`); el cliente de servidor existente guarda las cookies:

```ts
import { NextResponse, type NextRequest } from "next/server";
import { crearClienteServidor } from "@/lib/supabase/servidor";

export async function GET(peticion: NextRequest) {
  const { searchParams, origin } = new URL(peticion.url);
  const codigo = searchParams.get("code");
  const volver = searchParams.get("volver");
  // Solo rutas internas: evita redirigir a otro sitio.
  const destino = volver && volver.startsWith("/") && !volver.startsWith("//") ? volver : "/";

  if (codigo) {
    const supabase = await crearClienteServidor();
    const { error } = await supabase.auth.exchangeCodeForSession(codigo);
    if (!error) {
      return NextResponse.redirect(`${origin}${destino}`);
    }
  }

  return NextResponse.redirect(`${origin}/login?error=microsoft`);
}
```

El código de Microsoft vale 5 minutos, se usa una sola vez y debe canjearse en el mismo navegador donde empezó el ingreso. Si la app se publica detrás de un dominio propio con proxy, la documentación de Supabase usa el encabezado `x-forwarded-host` para armar la URL de regreso; en `*.vercel.app` no hace falta.

**c) Página de ingreso.** En `web/src/app/login/page.tsx`: lea también `error` de `searchParams`, muestre `<BotonMicrosoft volver={volver} />` como opción principal y, si `error === "microsoft"`, un aviso «No fue posible ingresar con Microsoft. Intenta de nuevo o comunícate con el área de sistemas.». Durante la transición deje `FormularioLogin` debajo, bajo un enlace «Ingresar con correo y contraseña» (sección 5.5).

No hay que cambiar `proxy.ts` ni `sesion.ts`: `/auth` ya es pública y quien ya tiene sesión es enviado de `/login` a `/`.

### 5.4 Cómo siguen funcionando los usuarios actuales

- Supabase **vincula automáticamente** identidades con el mismo correo a un solo usuario, siempre que el correo esté verificado. Las cuentas actuales se crearon con el correo confirmado (`scripts/crear_cuentas.py`), y con el claim `xms_edov` (sección 3.4) el correo de Microsoft llega verificado. Resultado esperado: la persona conserva su mismo usuario de Supabase, ahora con dos identidades (`email` y `azure`).
- **Compruébelo en la primera prueba:** en **Authentication → Users**, el usuario de prueba debe aparecer una sola vez y con el proveedor `azure` agregado. Si aparece duplicado, revise los claims opcionales antes de seguir. Aunque quedara duplicado, RLS compara por correo, así que la persona vería sus datos; pero su historial de Supabase Auth quedaría partido.
- Una persona de Segurex **sin fila** en `usuarios` puede completar el ingreso con Microsoft (Supabase le crea un usuario de Auth), pero RLS no le muestra datos y la app le muestra «Tu cuenta aún no tiene acceso». El acceso sigue otorgándose desde el panel de usuarios de la app. Si quiere frenar esto antes, use **Assignment required** en Entra (sección 3.3).
- Lo que **no** está verificado: el efecto de desactivar «Allow new users to sign up» de Supabase sobre el ingreso con Microsoft de usuarios ya existentes. No lo cambie sin probarlo primero.

### 5.5 Contraseñas después del cambio

1. **Transición (dos semanas sugeridas):** el botón de Microsoft es la opción principal y el formulario de contraseña queda como alternativa. Así hay plan B si algo falla.
2. **Cierre:** quite `FormularioLogin` de la página de ingreso y el cambio de contraseña de «Mi cuenta» (`FormularioClave.tsx`). Después, en **Authentication → Sign In / Providers**, desactive el proveedor **Email** y confirme con una prueba que el ingreso con contraseña queda rechazado.
3. Deje una cuenta administradora con contraseña solo si TI la necesita como acceso de emergencia; en ese caso mantenga el proveedor Email activo y documente quién la custodia.

### 5.6 Reversa

- **Código:** en Vercel, promueva el despliegue anterior a producción (o revierta el commit y vuelva a desplegar).
- **Supabase:** mientras el proveedor Email siga activo, las contraseñas funcionan; no hace falta desactivar Azure. Las identidades `azure` vinculadas no estorban.
- **Portal:** vuelva a poner «Inicio de sesión» en «Propio» (sección 9).

---

## 6. Portafolio de productos (PHP sin framework, Derivados)

> **El repositorio de Portafolio no está disponible en este equipo.** Los nombres de archivo, la tabla de usuarios y la forma de la sesión que aparecen aquí son una propuesta; confírmelos contra el repositorio real antes de aplicar.

### 6.1 Librería recomendada

[`thenetworg/oauth2-azure`](https://github.com/TheNetworg/oauth2-azure), sobre `league/oauth2-client`. Está mantenida (versión 2.2.6, publicada en junio de 2026, sin avisos de seguridad en Packagist) y es la más usada para este caso. Al recibir el `id_token`, valida su firma con las llaves de Microsoft, la audiencia (`aud` = ID de la aplicación) y el emisor contra el inquilino configurado.

Instalación (en el equipo local si el servidor no tiene Composer; luego se sube la carpeta `vendor/`):

```bash
composer require thenetworg/oauth2-azure
```

### 6.2 Configuración

Guarde los valores en un archivo **fuera de `public_html`**, por ejemplo `/home/<usuario>/config/portafolio-microsoft.php`:

```php
<?php
return [
    'clientId'     => '<id-de-la-aplicación>',
    'clientSecret' => '<secreto-de-la-aplicación>',
    'redirectUri'  => 'https://portafolio.derivadoscol.com.co/auth/callback.php',
    'tenant'       => '<id-del-inquilino>',          // inquilino de Derivados, nunca 'common'
    'dominio'      => 'derivadoscol.com',
];
```

### 6.3 Archivos propuestos

| Archivo | Qué hace |
|---|---|
| `login.php` | Agrega el botón «Entrar con Microsoft» que apunta a `auth/microsoft.php` |
| `auth/microsoft.php` | Crea el `state`, lo guarda en la sesión y envía a Microsoft |
| `auth/callback.php` | Revisa el `state`, canjea el código, busca al usuario, inicia sesión |
| Script SQL | Agrega la columna `entra_object_id` a la tabla de usuarios |

Proveedor común (por ejemplo `auth/proveedor.php`):

```php
<?php
use TheNetworg\OAuth2\Client\Provider\Azure;

require __DIR__.'/../vendor/autoload.php';
$config = require '/home/<usuario>/config/portafolio-microsoft.php';

$proveedor = new Azure([
    'clientId'     => $config['clientId'],
    'clientSecret' => $config['clientSecret'],
    'redirectUri'  => $config['redirectUri'],
    'tenant'       => $config['tenant'],
    'defaultEndPointVersion' => Azure::ENDPOINT_VERSION_2_0,
]);
$proveedor->scope = 'openid profile email';
```

`auth/microsoft.php`:

```php
<?php
session_start();
require __DIR__.'/proveedor.php';

$url = $proveedor->getAuthorizationUrl(['scope' => $proveedor->scope]);
$_SESSION['oauth2_state'] = $proveedor->getState();
header('Location: '.$url);
exit;
```

`auth/callback.php`:

```php
<?php
session_start();
require __DIR__.'/proveedor.php';

$state = $_GET['state'] ?? '';
$esperado = $_SESSION['oauth2_state'] ?? '';
unset($_SESSION['oauth2_state']);

if ($esperado === '' || ! hash_equals($esperado, $state) || empty($_GET['code'])) {
    header('Location: /login.php?error=sesion');   // state inválido o vencido
    exit;
}

try {
    $token = $proveedor->getAccessToken('authorization_code', [
        'scope' => $proveedor->scope,
        'code'  => $_GET['code'],
    ]);
    $cuenta = $proveedor->getResourceOwner($token);   // claims del id_token ya validado
} catch (Throwable $e) {
    error_log('Fallo el ingreso con Microsoft: '.$e->getMessage());
    header('Location: /login.php?error=microsoft');
    exit;
}

$objectId = (string) $cuenta->getId();                              // claim oid
$correo = strtolower(trim((string) ($cuenta->getEmail() ?: $cuenta->getPreferredUsername())));

if ($correo === '' || ! str_ends_with($correo, '@'.$config['dominio'])) {
    header('Location: /login.php?error=acceso');
    exit;
}

// $db: la conexión PDO que ya usa la app (confirmar en el repositorio).
$st = $db->prepare('SELECT * FROM usuarios WHERE entra_object_id = ?');
$st->execute([$objectId]);
$usuario = $st->fetch();

if (! $usuario) {
    $st = $db->prepare('SELECT * FROM usuarios WHERE LOWER(email) = ? AND entra_object_id IS NULL');
    $st->execute([$correo]);
    $usuario = $st->fetch();

    if ($usuario) {
        $db->prepare('UPDATE usuarios SET entra_object_id = ? WHERE id = ?')
           ->execute([$objectId, $usuario['id']]);
    }
}

if (! $usuario || ! $usuario['activo']) {
    header('Location: /login.php?error=acceso');
    exit;
}

session_regenerate_id(true);           // evita la fijación de sesión
$_SESSION['usuario_id'] = $usuario['id'];   // usar las mismas llaves que hoy usa login.php
header('Location: /');
exit;
```

Script SQL (ajuste el nombre de la tabla y del campo de correo al real):

```sql
ALTER TABLE usuarios
    ADD COLUMN entra_object_id VARCHAR(100) NULL,
    ADD UNIQUE KEY ux_usuarios_entra_object_id (entra_object_id);
```

Puntos a confirmar en el repositorio:

- Nombre de la tabla de usuarios, del campo de correo, del campo «activo» (si existe) y de los permisos que el gerente asigna.
- Las variables de sesión que hoy crea `login.php` después de validar la contraseña: el callback debe crear exactamente las mismas.
- La conexión a la base de datos (`$db` arriba) y si la app ya usa Composer.
- Que la sesión de PHP use cookies `Secure` y `HttpOnly` en producción.

**Reversa:** restaure los archivos anteriores desde Git o desde la copia previa. La columna `entra_object_id` puede quedarse.

---

## 7. Vincular los usuarios existentes (reglas comunes)

1. **Primer ingreso con Microsoft:** se busca la fila por correo, en minúsculas. Si existe, está activa y no tiene ID de objeto, se guarda el ID de objeto de Entra (`oid`).
2. **Ingresos siguientes:** se busca por ID de objeto. El correo solo sirve para la vinculación inicial y para mostrarse. En Cuentas por pagar y Cartera esta regla la cumple Supabase (vincula por correo verificado y guarda la identidad); RLS sigue por correo.
3. **Correo de la app distinto del de Microsoft.** Antes de la migración, compare la lista de `usuarios` de cada app con los usuarios de Entra (en **Entra ID → Users**, columnas «User principal name» y «Mail»). Corrija en la app los correos que no coincidan; de lo contrario esa persona verá «sin acceso». Toma Pedidos compara con el `userPrincipalName`; Supabase usa el claim `email` (el correo del buzón). Normalmente son iguales; si no, ajuste la fila al valor que use esa app.
4. **Correo reasignado.** Si una fila ya tiene un ID de objeto y llega otra cuenta de Microsoft con el mismo correo, se rechaza y se revisa a mano.
5. **Invitados.** Con registros de un solo inquilino, un invitado entra con una identidad del inquilino anfitrión (su ID de objeto es otro y su UPN tiene la forma `usuario_dominio#EXT#@...`). Microsoft recomienda tratar al invitado como un usuario nuevo. Toma Pedidos ya los rechaza por dominio. Regla general: cada persona entra a la app de **su** empresa con **su** cuenta; si alguien de Derivados necesita una app de Segurex, se decide caso por caso y se le da una fila con el correo con el que Microsoft lo identifica.
6. **Bajas.** Desactivar a la persona en la tabla `usuarios` de la app sigue siendo la forma de quitarle el acceso. Deshabilitar su cuenta en Microsoft 365 también impide nuevos ingresos.

---

## 8. Puesta en producción y reversa

### 8.1 Antes del cambio

- [ ] Registro en Entra creado, con consentimiento de administrador y URI de redirección de producción (sección 3).
- [ ] Secreto guardado en el gestor de contraseñas y recordatorio de vencimiento en el calendario.
- [ ] Correos de la tabla `usuarios` revisados contra Entra (sección 7, punto 3).
- [ ] Prueba completa en desarrollo o en una vista previa con al menos dos usuarios reales de roles distintos.
- [ ] Aviso a los usuarios de la app (correo o Teams), por ejemplo: «Desde el <fecha>, para entrar a <app> use el botón “Entrar con Microsoft” con su cuenta de la empresa. Ya no necesitará la contraseña de la aplicación.»

### 8.2 Día del cambio

1. Publique el cambio en un horario de poco uso.
2. Pruebe el ingreso desde el portal (tarjeta de la app) con una cuenta que ya tenga sesión en el portal: debe entrar sin pedir contraseña.
3. Pruebe con una cuenta que **no** tenga fila en `usuarios`: debe ver la pantalla de «sin acceso».
4. Pruebe el cierre de sesión y un segundo ingreso.
5. En Supabase, revise **Authentication → Users** (sección 5.4).
6. Marque la app como «Microsoft» en el portal (sección 9).

### 8.3 Reversa por aplicación

| App | Cómo volver atrás | Tiempo aproximado |
|---|---|---|
| Toma Pedidos | Revertir el commit del endurecimiento y desplegar | Minutos |
| Cuentas por pagar / Cartera | Promover en Vercel el despliegue anterior; las contraseñas siguen sirviendo mientras el proveedor Email esté activo | Minutos |
| Portafolio | Restaurar los archivos anteriores; la columna nueva puede quedarse | Minutos |
| Portal | Editar la app y volver «Inicio de sesión» a «Propio» | Inmediato |

---

## 9. Al terminar cada aplicación: actualizar el portal

1. En el portal, abra **Administración → Aplicaciones**.
2. Edite la aplicación.
3. En **Inicio de sesión**, elija **Microsoft** (antes «Propio») y pulse **Guardar cambios**.
4. Confirme en la pantalla principal que la tarjeta ya no muestra «Inicio de sesión propio».

---

## 10. Referencias

Fuentes consultadas el 2026-10-06:

- Microsoft Entra — registrar una aplicación (tipos de cuenta, un solo inquilino): https://learn.microsoft.com/entra/identity-platform/quickstart-register-app
- Microsoft Graph — registrar una app y agregar credenciales (vigencia máxima de 24 meses, recomendación de menos de 12): https://learn.microsoft.com/graph/auth-register-app-v2
- Microsoft Entra — referencia de claims opcionales (`xms_edov`, advertencia sobre `email` y `upn`): https://learn.microsoft.com/entra/identity-platform/optional-claims-reference
- Microsoft Entra — referencia de claims del ID token (`oid`, `sub`, `tid`, invitados): https://learn.microsoft.com/entra/identity-platform/id-token-claims-reference
- Microsoft Entra — validar claims (no autorizar por `email`, `preferred_username` ni `upn`): https://learn.microsoft.com/entra/identity-platform/claims-validation
- Microsoft Entra — OpenID Connect (`login_hint`, `domain_hint`, `prompt`): https://learn.microsoft.com/entra/identity-platform/v2-protocols-oidc
- Microsoft Entra — flujo de código de autorización (`prompt=select_account`, `domain_hint`): https://learn.microsoft.com/entra/identity-platform/v2-oauth2-auth-code-flow
- Microsoft Entra — flujo implícito (nota: `domain_hint` impide el ingreso de invitados): https://learn.microsoft.com/entra/identity-platform/v2-oauth2-implicit-grant-flow
- Microsoft Entra — restringir una app a un grupo de usuarios («Assignment required»): https://learn.microsoft.com/entra/identity-platform/howto-restrict-your-app-to-a-set-of-users
- Microsoft Entra — propiedades de una aplicación empresarial: https://learn.microsoft.com/entra/identity/enterprise-apps/application-properties
- Supabase — Login with Azure (Microsoft): https://supabase.com/docs/guides/auth/social-login/auth-azure
- Supabase — vinculación de identidades: https://supabase.com/docs/guides/auth/auth-identity-linking
- Supabase — URL de redirección y comodines: https://supabase.com/docs/guides/auth/redirect-urls
- Supabase — flujo PKCE (vigencia y uso único del código): https://supabase.com/docs/guides/auth/sessions/pkce-flow
- Supabase — ruta de callback en Next.js con `exchangeCodeForSession` (ejemplo de la guía de Google, mismo patrón): https://supabase.com/docs/guides/auth/social-login/auth-google
- Laravel Socialite (scopes y parámetros opcionales con `with`): https://laravel.com/docs/12.x/socialite
- Socialite Providers — Microsoft Azure: https://socialiteproviders.com/Microsoft-Azure/
- `thenetworg/oauth2-azure` (README y código fuente): https://github.com/TheNetworg/oauth2-azure
- `thenetworg/oauth2-azure` en Packagist: https://packagist.org/packages/thenetworg/oauth2-azure

Comprobado en el código instalado (no en la documentación): `@supabase/ssr` 0.12.5 configura `flowType: "pkce"` en sus clientes, y `socialiteproviders/microsoft-azure` devuelve el `userPrincipalName` como correo.
