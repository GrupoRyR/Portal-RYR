# Observaciones de Jhon tras probar main (2026-11-04)

Rama `fix/observaciones` desde `origin/main` (`8eec5cb`). Commits por tarea; push, PR y fusión los decide Jhon.

## Objetivo

Corregir lo que Jhon encontró al probar el portal desplegado y completar lo que falta frente al prototipo aprobado (https://claude.ai/artifact/V6aNj3e3ycJDz4fK4g2oFQ). El cambio de diseño a Liquid Glass va en una segunda etapa, después de que Jhon apruebe los bocetos.

## Orden decidido

1. Correcciones que no dependen del diseño (esta rama): correos, eliminar, Noticias, catálogo y fuentes de Mercados. Jhon las prueba mientras tanto.
2. En paralelo: bocetos para aprobación. Son el inicio en Liquid Glass, con las ubicaciones de los bloques del prototipo, y el inicio de sesión Vitrina sin el texto «Grupo empresarial RYR».
3. Después de la aprobación: el front end en Liquid Glass, en otra rama. Incluye el botón flotante del asistente, la campanita, los accesos rápidos y la banda de Mercados en movimiento. Pasa por `/code-review` y `/security-review`.

## No tocar

- La creación de anuncios desde el asistente y las herramientas de Administración: Jhon las probó y funcionan.

## Tareas

- [x] O1. **Correos de anuncios y eventos a todos.** Hoy no se envía ningún correo. Hay que hallar la causa y probarlo con Segurex y con SDC. Los borradores del asistente también deben enviar a la lista; hoy no lo hacen, por la decisión 3 de la ola 3.
  - Causa: la casilla «Enviar también por correo a la lista de cada empresa» venía desmarcada en un anuncio nuevo (`AnnouncementEditor::$emailLists = false`); sin «Lista de todo el personal» y «Buzón remitente» en Admin › Empresas la casilla ni aparecía y el anuncio se guardaba sin correo, sin aviso; los eventos no tenían correo. Los borradores del asistente se publican con `email_lists => false` (`AssistantDraftController`).
  - Cambio: casilla marcada por defecto en anuncios y eventos nuevos; los eventos van a la lista con el mismo cron (`portal:send-announcement-list-emails`, `App\Calendar\EventListEmails`, migración `2026_11_06_100001`); aviso en el editor y línea en Auditoría (`*.list_email_skipped`) cuando una empresa no tiene lista o buzón.
  - Pendiente (decisión de Jhon): los borradores del asistente siguen sin correo; no se tocó el asistente.
  - Ruta: escritor delegado (más de dos archivos no triviales). Pruebas: `ListEmailsByDefaultTest`, `SendEventListEmailsTest` (Segurex y SDC, `Http::fake`).
- [x] O2. **Eliminar anuncios y eventos.** Hoy solo se pueden retirar. Eliminar pide confirmación, borra lo que cuelga del anuncio o evento (lecturas, confirmaciones y avisos al celular) y deja una línea en Auditoría con el título y quién lo eliminó.
  - Cambio: «Eliminar» en el editor de anuncios y de eventos, con `wire:confirm`; la política `delete` es la misma que `update`. `erase()` (rasgo `AddressedToCompanies`) borra en una transacción lecturas, recordatorios y sus entregas, correos a las listas, avisos al celular, empresas y el registro, y escribe una sola línea `*.deleted` con el título; la imagen se borra después del commit. «Retirar» sigue igual. Sin migraciones.
  - Ruta: escritor delegado. Pruebas: `DeleteAnnouncementsAndEventsTest`, `AnnouncementPolicyTest`.
- [x] O3. **Noticias.**
  - Una dirección que es una página web y no un canal RSS da el mensaje «El documento no es seguro (doctype)». Debe decir que es una página web.
  - Si la página anuncia su canal RSS (`<link rel="alternate">`), el portal lo encuentra.
  - Causa: `FeedParser::load()` rechaza todo `<!DOCTYPE` (protección XXE, se mantiene), así que una página HTML salía como `doctype`.
  - Cambio: código nuevo `html_page` («Esta dirección es una página web, no un canal RSS») para un cuerpo que empieza por `<!doctype html` o `<html`, o servido como `text/html` sin ser un canal; `FeedDiscovery` lee los `<link rel="alternate">` del `<head>` (expresión regular acotada a 256 KB, sin cargar el documento), exige https y dominio público, y `FeedReader` pide el canal con el mismo `FeedFetcher`. Admin › Noticias guarda el canal encontrado y lo dice, o rechaza la página sin canal; `portal:fetch-news` cambia la dirección de las fuentes ya guardadas y lo audita como «Sistema».
  - Ruta: escritor delegado. Pruebas: `HtmlPageTest`, `NewsAutodiscoveryTest`, `NewsAdminTest`, `FeedParserTest` (`Http::fake` y resolvedor DNS falso).
  - Pendiente (sin cambio de código): fuentes de Colombia, internacionales y de mercado por empresa; las carga Jhon en Admin › Noticias.
  - Hay que dejar fuentes de Colombia, internacionales y de mercado para cada empresa (Segurex: cerraduras, seguridad y control de acceso; SDC: químicos, hipoclorito y aseo).
- [x] O4. **Catálogo.** Una cerradura agregada en Admin no sale en el inicio.
  - Causa (por diseño, no es un error): todo producto nuevo se crea oculto (`ProductManager::saveProduct()`, `'published' => false`) y el inicio solo muestra los publicados de una empresa activa a las personas de esa empresa (`ProductBoard::forCompany()`, `HomePageBuilder`). La foto no es obligatoria. La lista solo decía «Oculto».
  - Cambio: debajo de cada producto que no sale en el inicio, Admin › Catálogos dice por qué («No se muestra en el inicio: está oculto. Pulsa «Publicar» para mostrarlo.» o «…: la empresa está inactiva.») y la lista aclara que cada persona ve solo el catálogo de su empresa.
  - Ruta: escritor delegado. Pruebas: `ProductHomeHintTest`.
- [ ] O5. **Mercados.** Hay que sumar el Brent desde una fuente oficial por confirmar, y los insumos de cada empresa ingresados por un administrador con fecha. La flecha y la cifra en verde, y la banda en movimiento, van con el front end.
- [ ] F1. **Bocetos para aprobación:** el inicio en Liquid Glass y el inicio de sesión Vitrina.
- [ ] F2. **Front end Liquid Glass**, después de la aprobación.

## Respuestas sin código

- **Panel gerencial:** hoy no lee datos de ninguna parte. Los recibe por `PUT /api/v1/kpis` desde una integración con `kpis:write`, que sería el robot nocturno de SAP. Ese robot sigue en pausa hasta que Jhon decida.

## Comprobaciones

- `php artisan test` completo con `APP_KEY` y `OPENSSL_CONF` en el entorno (main: 3.146).
- `vendor/bin/pint --test` con salida 0.
- Las pruebas nunca usan la red (`Http::fake`).

## Avance

- Documento creado. Espejo en Engram: `odd/observaciones/tasks`.
- O1 a O4 hechos por un escritor delegado, un commit por tarea: O1 `70f292b`, O2 `bb37fdd`, O3 `b4e4ccf`, O4 `5e796ab`.
- Comprobación al cerrar O4: `php artisan test` 3.204 pruebas pasan (main: 3.146); `vendor/bin/pint --test` sale en 0.
- Pendiente de Jhon: si los borradores del asistente deben ir a la lista (hoy no; no se tocó el asistente); llenar «Lista de todo el personal» y «Buzón remitente» de cada empresa en Admin › Empresas; correr la migración `2026_11_06_100001` al desplegar.
- Siguiente: O5, F1 y F2.
