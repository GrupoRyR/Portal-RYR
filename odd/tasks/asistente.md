# Asistente del portal

Ola 3 («el Asistente»), aprobada por Jhon (líder de IA). Rama `feat/asistente` desde `origin/main` (`e9f48e4`). Sin push ni PR: los decide Jhon.

## Objetivo

Que cada persona pueda preguntarle al portal, en lenguaje natural, por lo que ya ve en él (sus apps, fechas, cumpleaños, directorio, anuncios, tareas, aprobaciones y estado de TI), y que quien hoy puede crear anuncios reciba un borrador que solo se publica si la persona lo confirma.

## Problema y por qué

- La información está repartida en varias pantallas del portal; preguntar es más rápido que buscar.
- El conector de Claude (MCP) ya resuelve la lectura con las reglas del portal, pero solo desde Claude. El asistente reutiliza esas mismas consultas dentro del portal.

## Alcance

- Página `/asistente` (ruta `assistant.show`), Blade sin Livewire ni Alpine, formulario POST que funciona sin JavaScript.
- SDK oficial `anthropic-ai/sdk`; Haiku 5.5 para quien pregunta, Sonnet 5.5 para editores, Opus 5.5 solo como respaldo por `refusal` (decisión de Jhon); esfuerzo `low` configurable.
- Herramientas de solo lectura con la cuenta **activa** (la de la sesión) y su empresa, reutilizando las herramientas del conector (`app/Mcp/Tools/*`) y `App\ItStatus\ItStatusBoard`.
- `borrador_de_anuncio` solo para quien puede crear anuncios (`AnnouncementPolicy::create`): propone, nunca escribe. «Publicar» es un POST con CSRF de la persona, con casilla de confirmación, por `AnnouncementPublisher` y la política (`create` + `publishTo`).
- Auditoría: una línea por pregunta (herramientas, modelo, tokens, rechazo y respaldo), nunca el texto.
- Apagado por defecto: `PORTAL_ASSISTANT_ENABLED` y el interruptor «Asistente» de Configuración. Sin `ANTHROPIC_API_KEY` la página dice que no está disponible.
- Límite diario de preguntas por cuenta y largo máximo del historial; la conversación vive solo en la sesión.

## Fuera de alcance

- Guardar conversaciones en la base de datos.
- Escribir cualquier dato desde el modelo (anuncios, lecturas, tareas).
- Streaming y JavaScript obligatorio.
- Cambiar el inicio (`home.blade.php` lo reescribe el PR #99): como máximo una o dos líneas.

## Restricciones

- Las pruebas nunca usan la red: el modelo va detrás de `App\Assistant\AssistantModel`, con una implementación del SDK y otra falsa para pruebas.
- Nunca se lee ni se edita `.env*`; las variables se documentan en `docs/despliegue.md` §1.23.
- Migraciones solo si hacen falta, con prefijo `2026_11_05_8xxxxx` y nombres cortos (MariaDB, 64 caracteres).
- Código, nombres y comentarios en inglés; textos de interfaz y documentación en español.
- Commits convencionales sin atribución de IA; sin rebase, sin force-push, sin `git stash` a secas.

## Decisiones de diseño

- **Cuenta activa.** Las herramientas leen con la cuenta de la sesión (`auth()->user()`), nunca con las vinculadas: el asistente responde por la empresa activa. Cambiar de cuenta o salir invalida la sesión (`SwitchAccountController`, `LogoutController`), así que la conversación se borra sola; además se guarda con el id de la cuenta y se descarta si no coincide.
- **Reutilización.** `PortalTool` gana `readFor(Collection $accounts, array $arguments)`: corre la misma `handle()` de la herramienta del conector con esas cuentas en lugar de las de la conexión. Las reglas de visibilidad, los límites y la validación son los mismos.
- **Sin migración.** El interruptor se guarda en `portal_settings` (como el del conector) y el límite diario se cuenta con las líneas de Auditoría `assistant.asked` del día (hora de Bogotá).
- **Respaldo ante `refusal`.** Si el modelo principal termina en `refusal`, la misma petición se repite con el modelo de respaldo y la pregunta sigue con él. Se audita.
- **Historial.** En la sesión se guarda solo el texto de las preguntas y respuestas (sin bloques de pensamiento ni resultados de herramientas), con un máximo de turnos.

## Tareas

- [x] A1. **Plan.** Este documento. Ruta: en línea (un archivo). Commit `d0b2931`.
- [x] A2. **SDK, configuración e interruptor.** `anthropic-ai/sdk` ^1.4, `config/assistant.php`, `services.anthropic.key`, `AssistantSwitch` (despliegue + Admin en `portal_settings`, empieza apagado), tarjeta «Asistente del portal» en Configuración y su línea de Auditoría. Ruta: delegada (escritor único). RED 1/11 (Livewire y texto de Auditoría inexistentes), GREEN 11/11. Commit `c6e4614`.
- [x] A3. **Herramientas.** `PortalTool::readFor()` (la misma `handle()` del conector para la cuenta activa), `ConnectorTool`, `anuncios_recientes`, `estado_de_ti` y `borrador_de_anuncio`. Ruta: delegada. 11/11 y MCP 250/250 sin regresiones. Commit `56f2339`.
- [x] A4. **Conversación y modelo.** `AssistantModel` (SDK y falso), bucle de herramientas, respaldo por `refusal`, tope de rondas, límite diario, Auditoría `assistant.asked`. Ruta: delegada. Prueba del SDK contra un cliente HTTP simulado (Guzzle MockHandler). Commit `634962f`.
- [x] A5. **Página.** `/asistente` (GET, POST, nueva), Blade sin Livewire ni Alpine, enlaces en la barra de marca y una línea en el menú del inicio. Commits `e3a4d5f` y `a35af82` (solo clases que ya están en `public/build`, sin `npm run build`).
- [x] A6. **Publicar el borrador.** POST con CSRF, casilla de confirmación, `create` + `publishTo`, `AnnouncementPublisher`. Mutación comprobada: quitar la regla `accepted` hace fallar la prueba. Commit `e3a4d5f`.
- [x] A7. **Consumo de tokens, modelos por rol y presupuesto** (pedidos del coordinador y de Jhon). Prefijo estático en caché con el contexto en el mensaje de la persona, punto de caché móvil, historial que solo crece con los bloques de razonamiento y su firma, plan fijo por conversación (modelo, esfuerzo, herramientas), Haiku 5.5 para quien pregunta y Sonnet 5.5 para editores, respaldo por `refusal` como petición nueva a Opus 5.5, `max_tokens` por rol y manejo de `max_tokens`, esquema estricto del borrador, filas por herramienta, uso y costo en Auditoría, `portal:assistant-usage`, presupuesto `ASSISTANT_MONTHLY_BUDGET_USD` (20) con avisos al 80 % y 100 %. Ruta: delegada. Suite 3051/3051. Commit `afb3b03`.
- [x] A8. **Proveedores.** `ProviderRegistry` detrás de `AssistantModel`, *drivers* `anthropic` y `openai_compatible` (Http de Laravel), preset DeepSeek (por verificar), referencias «proveedor:modelo», precios por proveedor, aviso de Ley 1581 en Configuración. Ruta: delegada. Commit `8f4130c`.
- [x] A9. **Proveedor secundario.** Clasificación por tipo de error, circuito en la caché (30 min por saldo o llave, 2 min por 429/5xx/espera), conversación movida como texto, aviso por correo una vez por espera, Auditoría `assistant.failover`. Ruta: delegada. Suite 3076/3076. Commit `e5a6afa`.

Tamaño: unas 6.400 líneas sin `composer.lock` (más de la mitad, pruebas y documentación). Pasa mucho de las ~400 por ola porque los pedidos llegaron en serie; cada tarea es un commit separado y puede ir en su propio PR encadenado (A1 a A6, A7, A8, A9).

- [x] A10. **Correcciones de la revisión independiente** (0 altas, 2 medias, 5 bajas). Ruta: delegada (escritor). Pruebas escritas con cada corrección; RED comprobado por mutación (quitar el control del presupuesto y del plazo: 2/4 fallan; mover antes de tiempo: falla; sin la entrada original: falla).
  - M1: el presupuesto se revisa antes de cada llamada, con lo que la pregunta ya costó; se detiene con el mensaje del presupuesto, historial válido y Auditoría `assistant.budget_blocked`.
  - M2: `ASSISTANT_DEADLINE_SECONDS` (90) para toda la pregunta, cada llamada con el tiempo que queda; «La respuesta tardó demasiado; intenta de nuevo.»; la línea de Auditoría se escribe en un `finally`, con el uso y el costo aunque una llamada lance una excepción.
  - L1: un turno sin texto ni herramientas no se guarda; se guarda el texto de la página.
  - L2: la entrada de cada `tool_use` se guarda como el JSON que escribió el proveedor (`input_json`) y se reenvía tal cual (un `{}` anidado sigue siendo objeto).
  - L3: la conversación pasa al secundario solo cuando este responde; con el circuito abierto, va directo al secundario.
  - L4: carrera del límite diario (dos preguntas a la vez pueden pasar la última): aceptada, la acota el limitador `assistant` (10 por minuto por cuenta).
  - L5: los correos de presupuesto y de proveedor salen después de la respuesta (`defer`, sin cola en el servidor), tras el commit, con el mismo «una vez por ventana».
  - Prueba nueva: el paso al secundario en la segunda ronda del bucle de herramientas.

## Decisiones tomadas sin consultar (para Jhon)

1. El interruptor de Admin empieza **apagado** (el del conector empieza encendido): cada pregunta cuesta y envía datos a Anthropic.
2. Las herramientas leen solo con la cuenta activa, nunca con las vinculadas.
3. El borrador se publica para la empresa de la cuenta activa, desde ese minuto, sin fin, sin fijar, sin imagen, sin correo a la lista ni aviso al celular; el resto se cambia en Administración.
4. `max_tokens` usa `draft` en las conversaciones de editores y `chat` en las demás; el «uso» del reporte es `draft` solo cuando la pregunta propuso un borrador.
5. Los resultados de las herramientas conservan los campos del conector (fecha ISO y en texto): solo se limitan las filas, en la consulta.
6. Un modelo que no está en la tabla de precios se cobra con la tarifa más cara (`default`).
7. Precios de caché de Haiku y escritura de Sonnet/Opus derivados de los multiplicadores (marcados «verify»); precios y nombres de DeepSeek son provisionales.
8. Cómo responde Anthropic al límite de gasto del espacio de trabajo no está documentado en la guía: se asume 402 o 429 (los dos pasan al secundario).
9. `configured()` da el asistente por disponible si cada rol tiene llave en su principal o en su secundario.
## Criterios de aceptación

- Con `PORTAL_ASSISTANT_ENABLED` apagado o el interruptor apagado, `/asistente` responde 404 y no hay enlace.
- Sin `ANTHROPIC_API_KEY`, la página dice que el asistente no está disponible y no llama al modelo.
- Cada herramienta devuelve solo lo que la cuenta activa ve en el portal (mismas consultas del conector).
- Ninguna herramienta escribe; un borrador no crea ningún anuncio hasta el POST confirmado de la persona, que pasa por la política.
- Cada pregunta deja una línea en Auditoría sin el texto.
- Al llegar al límite diario, la pregunta se rechaza sin llamar al modelo.
- Las pruebas no usan la red.

## Comprobaciones

- `php artisan test` completo en verde (main tenía 2.934 pruebas), con `APP_KEY` y `OPENSSL_CONF` en el entorno: 3076/3076 al cerrar A9.
- `vendor/bin/pint --test` con salida 0.

## Avance

- Todas las tareas cerradas en la rama `feat/asistente`, sin push ni PR. Pendiente: revisión visual en el navegador (ambas marcas, claro/oscuro, 375 px), Lighthouse y la prueba real con la llave en el servidor. Espejo en Engram: pendiente.
