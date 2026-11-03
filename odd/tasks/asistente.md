# Asistente del portal

Ola 3 («el Asistente»), aprobada por Jhon (líder de IA). Rama `feat/asistente` desde `origin/main` (`e9f48e4`). Sin push ni PR: los decide Jhon.

## Objetivo

Que cada persona pueda preguntarle al portal, en lenguaje natural, por lo que ya ve en él (sus apps, fechas, cumpleaños, directorio, anuncios, tareas, aprobaciones y estado de TI), y que quien hoy puede crear anuncios reciba un borrador que solo se publica si la persona lo confirma.

## Problema y por qué

- La información está repartida en varias pantallas del portal; preguntar es más rápido que buscar.
- El conector de Claude (MCP) ya resuelve la lectura con las reglas del portal, pero solo desde Claude. El asistente reutiliza esas mismas consultas dentro del portal.

## Alcance

- Página `/asistente` (ruta `assistant.show`), Blade sin Livewire ni Alpine, formulario POST que funciona sin JavaScript.
- SDK oficial `anthropic-ai/sdk`, modelo `claude-opus-5-5`, esfuerzo `low` configurable, segundo modelo configurable cuando la respuesta termina en `refusal`.
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

- [ ] A1. **Plan.** Este documento. Ruta: en línea (un archivo).
- [ ] A2. **SDK, configuración e interruptor.** `composer require anthropic-ai/sdk`, `config/assistant.php`, `services.anthropic.key`, `App\Assistant\AssistantSwitch` (despliegue + Admin, guardado en `portal_settings`), interruptor «Asistente» en Configuración (Livewire, solo súper administrador) y su línea de Auditoría. Ruta: delegada (escritor único de la ola).
- [ ] A3. **Herramientas.** `PortalTool::readFor()`, `App\Assistant\AssistantTools` con las herramientas del conector para la cuenta activa, `estado_de_ti` (ItStatusBoard), `anuncios_recientes` y `borrador_de_anuncio` (solo editores). Ruta: delegada.
- [ ] A4. **Conversación y modelo.** `AssistantModel` (SDK y falso), `Assistant` (bucle de herramientas, respaldo por `refusal`, tope de rondas), historial en sesión, límite diario, Auditoría `assistant.asked` y su texto en Auditoría. Ruta: delegada.
- [ ] A5. **Página.** `/asistente` (GET, POST, borrar), Blade con la marca de la empresa activa, accesible y mobile-first, entrada desde la barra de marca y el menú del inicio (una línea). Ruta: delegada.
- [ ] A6. **Publicar el borrador.** POST con CSRF, casilla de confirmación, política `create` + `publishTo`, `AnnouncementPublisher`. Ruta: delegada.
- [ ] A7. **Documentación.** `docs/despliegue.md` §1.23, `docs/manual-administracion.md` §15, `docs/estado-del-proyecto.md`. Ruta: delegada.

## Criterios de aceptación

- Con `PORTAL_ASSISTANT_ENABLED` apagado o el interruptor apagado, `/asistente` responde 404 y no hay enlace.
- Sin `ANTHROPIC_API_KEY`, la página dice que el asistente no está disponible y no llama al modelo.
- Cada herramienta devuelve solo lo que la cuenta activa ve en el portal (mismas consultas del conector).
- Ninguna herramienta escribe; un borrador no crea ningún anuncio hasta el POST confirmado de la persona, que pasa por la política.
- Cada pregunta deja una línea en Auditoría sin el texto.
- Al llegar al límite diario, la pregunta se rechaza sin llamar al modelo.
- Las pruebas no usan la red.

## Comprobaciones

- `php artisan test` completo en verde (main tenía 2.934 pruebas), con `APP_KEY` y `OPENSSL_CONF` en el entorno.
- `vendor/bin/pint --test` con salida 0.

## Avance

- (se completa por tarea)
