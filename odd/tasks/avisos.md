# Avisos en el celular (web push)

Línea «Avisos» del portal. Rama `feat/avisos`, desde `origin/main` 59f2209. Decisión de producto de Jhon del 2026-11-03.

## Objetivo

Que los anuncios más importantes lleguen al celular como notificación, y que lo decida quien publica: una casilla «Enviar al celular» en el editor del anuncio. Solo los anuncios publicados con la casilla marcada envían un aviso. Nada más envía avisos en esta línea (ni tareas ni estado de TI).

## Problema y por qué

- Un anuncio urgente hoy solo se ve al abrir el portal o, si se marca, por correo a la lista de la empresa; en el celular nadie se entera a tiempo.
- El portal ya es instalable (PWA, `docs/despliegue.md` §1.11) y tiene service worker: falta suscribir el dispositivo y mandar el aviso.
- No hay colas ni workers: solo el cron de `schedule:run` cada minuto, como los recordatorios y el correo a las listas.

## Alcance

- T1. Suscripciones: paquete `minishlink/web-push`, tabla `push_subscriptions`, `POST`/`DELETE /cuenta/avisos`, control «Avisos en el celular» en `/cuenta` (JavaScript sin Alpine), `config/push.php` con las llaves VAPID (sin llaves, todo apagado) y `portal:push-keys` para generarlas.
- T2. Envío: `announcements.push_phone`, casilla «Enviar al celular» en el editor de Admin (los mismos que publican; auditada), y el cron que envía por lotes, una entrega por suscripción, nunca dos veces; borra las suscripciones que responden 404/410.
- T3. Service worker: evento `push` (notificación con el ícono de la empresa) y `notificationclick` (enfoca o abre la URL, solo del mismo origen), sin tocar el caché.
- T4. Documentos: `docs/despliegue.md` §1.22 y `docs/manual-administracion.md` §14.

## Fuera de alcance

- Avisos de tareas, aprobaciones o estado de TI.
- Avisos por persona (recordatorios) o reintentos manuales de un aviso fallido.
- Lista de dispositivos suscritos en `/cuenta` (cada dispositivo se activa y desactiva desde sí mismo).

## Restricciones

- Producción: MariaDB 10.11; pruebas en SQLite. Migraciones con prefijo `2026_11_05_7xxxxx`; nombres de índices y llaves ≤ 64 caracteres, explícitos.
- Sin colas: el cron cada minuto, por lotes, con candado de solapamiento.
- Sin red real en pruebas: un emisor falso (`App\Push\PushSender`).
- Sin Livewire ni Alpine fuera de Admin. `public/build` no se toca: el control de `/cuenta` va en línea en la vista y el service worker sale de `resources/js/sw.js` sin compilar.
- `.env*` bloqueado: los nombres nuevos solo en la documentación. Pruebas con `APP_KEY` en el entorno.
- Commits convencionales sin atribución de IA. Sin rebase ni force-push.

## Tareas

- [x] T1. **Suscripciones.** Ruta: escritor único (delegado por el coordinador): paquete, migración, modelo, fábrica, controlador, petición, rutas, vista, comando y pruebas.
- [x] T2. **Envío desde el cron.** Ruta: el mismo escritor: migraciones, `Announcement`, editor y vista de Admin, servicio de envío, emisor, comando, horario, Auditoría y pruebas.
- [x] T3. **Service worker.** Ruta: el mismo escritor: `resources/js/sw.js` y sus pruebas.
- [x] T4. **Documentos.** Ruta: el mismo escritor: despliegue §1.22 y manual §14.
- [x] R1. **Revisión, medio 1: solo cuentas activas.** Ruta: el mismo escritor (servicio, comando, horario, configuración, pruebas y documentos). Los avisos van solo a empresas activas y a cuentas que entraron en los últimos `push.max_inactive_days` (90); poda diaria `portal:prune-push-subscriptions` a las 04:45. `Announcement::scopeVisibleTo` no cambia.
- [x] R2. **Revisión, medio 2 y bajos 1, 2 y 5: nunca perder dispositivos por una falla general.** El emisor y VAPID se validan antes de anotar entregas; corte si un bloque entero falla sin respuesta HTTP; terminar el anuncio sin deshacer una casilla marcada de nuevo; reabrir al cambiar el público; carga útil por anuncio antes de anotar; un anuncio roto no bloquea a los demás; pruebas de choque de anotaciones, presupuesto, opciones de Guzzle y tamaño de la carga útil.
- [ ] R3. **Revisión, bajos 3 y 4: cerrar sesión y llaves cambiadas.** Al cerrar sesión se borra la suscripción del navegador (servidor y `home.js`); el control de `/cuenta` se suscribe de nuevo si la llave VAPID cambió.
- [ ] R4. **Revisión, bajo 6: nota de dependencias.** Se deja el cambio de cuenta de un endpoint y las versiones; se anota la fecha de `web-token/jwt-library` 4.2.4.

## Criterios de aceptación

- Sin las tres llaves VAPID: `/cuenta` no muestra el control, los endpoints responden 404, el editor no muestra la casilla y el cron no envía nada. Nada falla.
- Suscribirse: solo con sesión, CSRF, límite de peticiones, endpoint `https` de un servicio de push conocido; el endpoint es único (hash de 64 caracteres); suscribir de nuevo el mismo endpoint lo pasa a la cuenta que lo envía.
- El cron envía solo anuncios publicados con «Enviar al celular», a las suscripciones de las cuentas que ven el anuncio en el inicio (`Announcement::scopeVisibleTo`: empresa de la cuenta, sin cuentas de emergencia), por lotes, una entrega por suscripción y anuncio, nunca dos; borra las que responden 404/410.
- Carga útil: título, texto corto, URL del anuncio, ícono de la empresa y una etiqueta; sin datos personales.
- El service worker muestra la notificación y al tocarla enfoca o abre la URL solo si es del mismo origen. El caché sigue igual (pruebas de PWA en verde).

## Verificación

- `vendor/bin/pint --test`
- `APP_KEY="base64:…" php artisan test --compact` (suite completa; en `main` 2,723 en verde).

## Progreso

- 2026-11-03: documento creado.
- 2026-11-03: T1 hecho. RED observado (16 pruebas nuevas, 0 en verde) y luego GREEN (16/16; `tests/Feature/Account` sigue en verde). Paquete `minishlink/web-push` v11.0.0. Pint en verde. Commit `806a47c`.
- 2026-11-03: T2 hecho. RED observado (19 pruebas nuevas, 0 en verde) y luego GREEN (19/19, más los editores de anuncios y Auditoría: 75/75). El emisor real se prueba contra un cliente HTTP simulado (sin red); en Windows esas pruebas necesitan `OPENSSL_CONF` y, sin él, se saltan.
  - Decisiones: quién recibe es la misma regla de `Announcement::scopeVisibleTo` (por cuenta; invitados y empresas inactivas incluidos, como en el inicio); al no quedar nadie, el anuncio queda terminado (`push_finished_at`) y un dispositivo nuevo no recibe avisos viejos, salvo que alguien desmarque y vuelva a marcar la casilla; una entrega fallida o que murió a medias no se reintenta (nunca dos veces).
- 2026-11-03: T2 en el commit `1312efd`. T3 hecho: RED observado (2 de 3 pruebas nuevas en rojo; la tercera vigila que el caché no cambie) y luego GREEN; `tests/Feature/Pwa` 32/32. Prueba de humo manual con node (fuera del repositorio): muestra la notificación, ignora ícono y enlace de otro origen, tolera una carga útil rota, enfoca la ventana abierta o abre una nueva.
- 2026-11-03: T3 en el commit `b608b8a`. T4 hecho (documentación pasiva: revisión estructural, sin RED): `docs/despliegue.md` §1.22 y `docs/manual-administracion.md` §14. Verificación final: Pint en verde; suite completa 2,773/2,773 en verde con `OPENSSL_CONF`; sin él, las 3 pruebas del emisor real y de `portal:push-keys` se saltan y el resto pasa.
  - Pendiente, no verificado aquí: las migraciones en MariaDB 10.11 (las pruebas usan SQLite) y un envío real a un celular (necesita las llaves en el servidor).
- 2026-11-03: T4 en el commit `a6fa93e`. Revisión independiente: sin altos, 2 medios y varios bajos; se arreglan como R1 a R4.
- 2026-11-03: R1 hecho (commit `c3b21a9`). RED: el oráculo corregido (la empresa inactiva no recibe) y la cuenta sin entrar hace 31 días fallaron; GREEN después. La poda nocturna usa primero los ids y luego borra, porque MariaDB no acepta un DELETE con subconsulta sobre la misma tabla.
- 2026-11-03: R2 hecho. RED: 11 de 16 pruebas nuevas o cambiadas fallaron; GREEN después. El emisor arranca (y valida VAPID con `VAPID::validate`, con el registro de Laravel como logger) antes de anotar nada; si un bloque entero queda sin respuesta HTTP se borran sus anotaciones y la vuelta se detiene; terminar exige `push_finished_at` nulo y el mismo `updated_at` visto al empezar; cambiar las empresas de un anuncio marcado lo reabre; carga útil e íconos antes de anotar; si algo se rompe después de anotar, esas filas quedan `failed` (pudo salir) y la vuelta sigue con el siguiente anuncio. `Str::limit` cuenta ancho de pantalla: un emoji vale dos.

## Siguiente paso

Revisión independiente y PR de `feat/avisos` (decisión del coordinador). Después del despliegue: llaves VAPID en el servidor (§1.22) y prueba en un Android y un iPhone instalado.
