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
- [ ] T2. **Envío desde el cron.** Ruta: el mismo escritor: migraciones, `Announcement`, editor y vista de Admin, servicio de envío, emisor, comando, horario, Auditoría y pruebas.
- [ ] T3. **Service worker.** Ruta: el mismo escritor: `resources/js/sw.js` y sus pruebas.
- [ ] T4. **Documentos.** Ruta: el mismo escritor: despliegue §1.22 y manual §14.

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
- 2026-11-03: T1 hecho. RED observado (16 pruebas nuevas, 0 en verde) y luego GREEN (16/16; `tests/Feature/Account` sigue en verde). Paquete `minishlink/web-push` v11.0.0. Pint en verde.

## Siguiente paso

T1.
