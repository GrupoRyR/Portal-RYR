# Guion del video guía: Portal RYR (One)

Video de animación (motion graphics) sobre capturas reales del portal, en 8 capítulos cortos. Enseña a entrar, cambiar de empresa y usar el inicio, «Mi día», los avisos, el asistente y el celular. El capítulo 8 es opcional, para quien publica o administra.

## Ficha

| Tema | Decisión |
|---|---|
| Público | Colaboradores de Segurex y de Soluciones y Derivados (SDC). El capítulo 8, solo editores de anuncios y administradores. |
| Duración total | 610 s (10 min 10 s). Cada capítulo dura entre 60 y 90 s y se puede publicar suelto. |
| Una empresa por pantalla | Cada cuadro muestra una sola marca. Los capítulos alternan Segurex y SDC (tabla de abajo). La única excepción es el selector de empresa (escenas 2.6 y 2.7), donde el portal muestra el nombre de las dos empresas como texto. |
| Sin sesión | El inicio de sesión («Vitrina») es neutro: sin color de empresa. |
| Datos | **Todo lo que se ve es de muestra**: «Ana Prueba», `ana.prueba@ejemplo.test`, «Luis Demo», tasas como «4.123,45». Ninguna persona, correo ni cifra es real. Cada pantalla lleva el rótulo «Datos de muestra». |
| Sin voz | El video no tiene locución: se lee una barra de texto y suena música de fondo. Decisión de Jhon del 2026-11-05. |
| Ritmo de lectura | Máximo 84 caracteres por barra, en 2 líneas de 42, y máximo 15 caracteres por segundo. Hoy todas las barras caben en 2 líneas de 42 sin cortar palabras, y el promedio es de unos 8 caracteres por segundo. |
| Primero el texto | La barra de texto aparece primero. La acción en pantalla (clic, acercamiento) empieza cerca de 1 s después, para que se lea antes de mirar. |
| Estilo | Español neutro, frases cortas, tuteo («tú»). Texto para leer, no para oír. |
| Textos del portal | Los botones y títulos entre «» son los reales del código. La fuente de cada uno está en [Fuentes de los textos](#fuentes-de-los-textos). |

| Capítulo | Marca | Tema | Duración |
|---|---|---|---|
| 1. Qué es el portal y cómo entrar | Neutra; Segurex al final | Claro | 60 s |
| 2. Vincular tu otra cuenta y cambiar de empresa | Segurex; SDC al final | Claro | 80 s |
| 3. El inicio | SDC | Claro | 85 s |
| 4. «Mi día» | Segurex | Oscuro | 80 s |
| 5. Avisos, anuncios y eventos | SDC | Claro | 75 s |
| 6. El asistente | Segurex | Claro | 70 s |
| 7. Tema, el portal en el celular y los avisos | SDC | Claro y oscuro | 70 s |
| 8. Opcional: anuncios con correo y Mercados | Segurex | Claro | 90 s |

Cada escena tiene seis campos: número, duración, qué se ve, texto en pantalla, barra de texto y transición. El «Texto en pantalla» es el rótulo corto sobre la captura. La «Barra de texto» es la frase que se lee en la barra inferior. El cuadro de cada escena está en `docs/video-guia/storyboard.md`, con el mismo número.

---

## 1. Qué es el portal y cómo entrar

- **Duración:** 60 s.
- **Objetivo:** que la persona reconozca One y entre con su cuenta de Microsoft.

| N.º | Duración | Qué se ve | Texto en pantalla | Barra de texto | Transición |
|---|---|---|---|---|---|
| 1.1 | 6 s | Animación: el logo One se arma sobre un fondo neutro. | «One · Portal RYR» | Este es One, el portal de Segurex y Soluciones y Derivados. | Fundido desde negro |
| 1.2 | 9 s | Animación: íconos de apps, anuncios y tareas se juntan en una tarjeta de vidrio. | «Tus apps, anuncios y tareas, en un solo lugar» | Tus aplicaciones, los anuncios de tu empresa y tus tareas, en un solo lugar. | La tarjeta se convierte en la de la Vitrina |
| 1.3 | 9 s | Portal, `/login`: la Vitrina, con fotos que se funden detrás de la tarjeta, el logo One y el título «Bienvenido a One». | «Bienvenido a One» | Abre el portal en tu navegador. Verás la pantalla de inicio de sesión. | Acercamiento al botón |
| 1.4 | 10 s | Portal, `/login`: el cursor pulsa «Iniciar sesión con Microsoft». | «Iniciar sesión con Microsoft» | Pulsa «Iniciar sesión con Microsoft» y usa tu cuenta de trabajo, la de tu correo. | Barrido a la derecha |
| 1.5 | 9 s | Animación: un selector de cuentas genérico (no la pantalla real de Microsoft) con `ana.prueba@ejemplo.test`. | «Tu cuenta de trabajo de Segurex o de SDC» | ¿Tienes cuenta en las dos? Entra con cualquiera y luego cambia de empresa. | Barrido de regreso a la Vitrina |
| 1.6 | 8 s | Portal, `/login`: se resalta la línea de ayuda «Escribe a soporte de TI». | «¿Problemas para entrar? Escribe a soporte de TI» | Si no puedes entrar, usa el enlace de soporte de TI que está debajo. | Fundido a blanco |
| 1.7 | 9 s | Portal, `/`: el inicio de Segurex con el saludo «Buenos días, Ana». | «Buenos días, Ana» · «Datos de muestra» | Al entrar llegas al inicio, con los colores de tu empresa. | Corte a la tarjeta de cierre |

Suma: 6 + 9 + 9 + 10 + 9 + 8 + 9 = **60 s**.

## 2. Vincular tu cuenta de la otra empresa y cambiar de empresa

- **Duración:** 80 s.
- **Objetivo:** vincular la cuenta de la otra empresa desde `/cuenta` y cambiar de empresa en el encabezado.

| N.º | Duración | Qué se ve | Texto en pantalla | Barra de texto | Transición |
|---|---|---|---|---|---|
| 2.1 | 8 s | Portal, `/` (Segurex): se abre el menú del avatar «AP» y se resalta «Cuentas vinculadas». | «Tu avatar › Cuentas vinculadas» | ¿Trabajas en las dos empresas? Abre tu avatar y elige «Cuentas vinculadas». | Clic que lleva a la página |
| 2.2 | 9 s | Portal, `/cuenta`: título «Cuentas vinculadas», tarjeta «Tus cuentas» con «Aún no tienes cuentas vinculadas.». | «Cuentas vinculadas» | Cada cuenta conserva sus propios permisos, aplicaciones y favoritos. | Acercamiento al botón |
| 2.3 | 9 s | Portal, `/cuenta`: el cursor pulsa «Vincular otra empresa». Aparece una ilustración de la confirmación de Microsoft. | «Vincular otra empresa» | Pulsa «Vincular otra empresa» y confirma tu cuenta con tu contraseña. | Corte a la página siguiente |
| 2.4 | 11 s | Portal, `/cuenta/vincular`: título «Vincular otra cuenta», lista «Cómo funciona» y botón «Continuar con Microsoft». | «Continuar con Microsoft» | Pulsa «Continuar con Microsoft» y entra con la cuenta de la otra empresa. | Barrido al inicio |
| 2.5 | 9 s | Portal, `/`: aviso «Vinculaste tu cuenta de Soluciones y Derivados.» sobre el inicio de Segurex. | «Vinculaste tu cuenta de …» | Vuelves al inicio con un mensaje de confirmación. Sigues en la misma empresa. | Acercamiento al encabezado |
| 2.6 | 10 s | Portal, encabezado de `/`: el selector «Cambiar de empresa» con «Segurex» marcado y «Soluciones y Derivados» como botón. | «Cambiar de empresa» | El encabezado ahora muestra el selector de empresa. La actual aparece marcada. | Pulso sobre la otra empresa |
| 2.7 | 10 s | Portal, `/`: el cursor pulsa «Soluciones y Derivados». Microsoft entra con esa cuenta y el inicio carga en azul con «Ahora estás en tu cuenta de Soluciones y Derivados.». | «Ahora estás en tu cuenta de …» | Toca la otra empresa. Microsoft entra con esa cuenta, casi sin preguntar. | Barrido de color hacia el azul de SDC |
| 2.8 | 8 s | Portal, `/` (SDC): se resaltan el logo, los colores y las apps, que cambiaron. | «Otra empresa: otros colores y otras apps» | Cambian los colores, las apps y los anuncios: son de la empresa activa. | Corte al celular |
| 2.9 | 6 s | Portal en celular, `/`: menú del avatar con «Tus empresas». | «En el celular: menú › Tus empresas» | En el celular, cambias de empresa desde «Tus empresas», en el menú. | Fundido a la tarjeta de cierre |

Suma: 8 + 9 + 9 + 11 + 9 + 10 + 10 + 8 + 6 = **80 s**.

## 3. El inicio: aplicaciones, buscador, accesos y Mercados

- **Duración:** 85 s.
- **Objetivo:** ubicar «Mis aplicaciones», «Favoritos», «Recientes», el buscador, «Accesos rápidos» y la banda de Mercados.

| N.º | Duración | Qué se ve | Texto en pantalla | Barra de texto | Transición |
|---|---|---|---|---|---|
| 3.1 | 8 s | Portal, `/` (SDC): vista completa con el saludo, las tarjetas y la columna «Mi día». | «El inicio» | Este es el inicio. A la izquierda, tus aplicaciones; a la derecha, «Mi día». | Acercamiento a la tarjeta de apps |
| 3.2 | 10 s | Portal, `/`: tarjeta «Mis aplicaciones» con la nota «Tienes 12 aplicaciones disponibles en …» y sus categorías. | «Mis aplicaciones» | «Mis aplicaciones» muestra solo las que puedes usar, por categoría. | Seguimiento del cursor |
| 3.3 | 10 s | Portal, `/`: el cursor pulsa la estrella de la app de muestra «Compras demo»; la tarjeta salta a «Favoritos». | «Toca la estrella para fijarla en Favoritos» | Toca la estrella de una aplicación para fijarla en «Favoritos», siempre arriba. | Desplazamiento corto hacia abajo |
| 3.4 | 7 s | Portal, `/`: se resalta la lista «Recientes». | «Recientes» | En «Recientes» aparecen las últimas aplicaciones que abriste. | Corte al buscador |
| 3.5 | 11 s | Portal, `/`: se pulsa Ctrl K y se abre «Buscar en el portal»; al escribir «vaca» salen los grupos «Aplicaciones», «Anuncios», «Fechas» y «Personas». | «Ctrl K (⌘ K en Mac)» | Pulsa Ctrl K para buscar aplicaciones, anuncios, fechas y personas a la vez. | El diálogo se cierra con Esc |
| 3.6 | 8 s | Portal, `/`: tarjeta «Accesos rápidos» con Outlook, Teams y OneDrive. | «Accesos rápidos» | «Accesos rápidos» tiene Outlook, Teams, OneDrive y los enlaces de tu empresa. | Subida al encabezado |
| 3.7 | 12 s | Portal, `/`: la banda «Mercados» corre bajo el encabezado con «Dólar (TRM) 4.123,45 ▲ 0,32 %» y «Euro 4.456,78 ▼ 0,15 %». | «Mercados» · «Datos de muestra» | Bajo el encabezado corre «Mercados»: dólar, euro y otros indicadores. | Acercamiento a las flechas |
| 3.8 | 10 s | Portal, `/`: acercamiento a ▲ verde, ▼ rojo y ● neutro. | «Verde: subió · Rojo: bajó» | La flecha verde indica que subió y la roja que bajó, frente al dato anterior. | Paneo al control de pausa |
| 3.9 | 9 s | Portal, `/`: el cursor pulsa «Pausar»; la banda se detiene y el control dice «Reanudar». | «Pausar · Reanudar» | Pulsa «Pausar» para leer con calma. Al pasar el mouse, también se detiene. | Fundido a la tarjeta de cierre |

Suma: 8 + 10 + 10 + 7 + 11 + 8 + 12 + 10 + 9 = **85 s**.

## 4. «Mi día»: lo que necesita tu atención hoy

- **Duración:** 80 s.
- **Objetivo:** recorrer los bloques de «Mi día»: «Tareas pendientes», «Por confirmar», «Cumpleaños y aniversarios», «Próximas fechas», «Personas» y «Estado de TI».

| N.º | Duración | Qué se ve | Texto en pantalla | Barra de texto | Transición |
|---|---|---|---|---|---|
| 4.1 | 7 s | Portal, `/#mi-dia` (Segurex, tema oscuro): acercamiento a la tarjeta «Mi día». | «Mi día» | «Mi día» reúne lo que necesita tu atención hoy. | Desplazamiento al primer bloque |
| 4.2 | 12 s | Portal, `/#tareas`: «Tareas pendientes» con «Por aprobar» y «Tareas»; la fila «Revisar orden de compra 0001» lleva «Vencida». | «Tareas pendientes · Por aprobar · Tareas» | Primero lo que debes aprobar y luego tus tareas. Las vencidas van arriba. | Acercamiento a los botones |
| 4.3 | 10 s | Portal, `/#tareas`: se resaltan «Ver tarea» y «Completar»; al pulsar «Completar» aparece «Sí, completar». | «Ver tarea · Completar» | «Ver tarea» abre la app. Algunas se completan aquí con «Completar». | Desplazamiento hacia abajo |
| 4.4 | 9 s | Portal, `/#por-confirmar`: «Por confirmar» con un anuncio de muestra y «Confirma su lectura · 3 de noviembre». | «Por confirmar» | En «Por confirmar» están los anuncios obligatorios que aún no confirmas. | Desplazamiento hacia abajo |
| 4.5 | 10 s | Portal, `/#celebraciones`: «Cumpleaños y aniversarios» con «Luis Demo · Hoy · Cumpleaños» y el botón «Felicitar». | «Cumpleaños y aniversarios» | Ves las celebraciones de la semana. «Felicitar» abre un chat de Teams. | Desplazamiento hacia abajo |
| 4.6 | 8 s | Portal, `/#fechas`: «Próximas fechas» con un festivo y un evento de muestra, y el enlace «Ver eventos». | «Próximas fechas» | «Próximas fechas» muestra los festivos y eventos que se acercan. | Desplazamiento hacia abajo |
| 4.7 | 9 s | Portal, `/#personas`: «Personas»; se escribe «compras» en el campo «Nombre, cargo o área». Se ve «Ver directorio». | «Personas · Nombre, cargo o área» | En «Personas» buscas a un compañero por nombre, cargo o área. | Desplazamiento hacia abajo |
| 4.8 | 10 s | Portal, `/#estado-ti`: «Estado de TI» con «Todo funciona con normalidad» y el botón «Reportar un problema». | «Estado de TI» | Si algo falla, lo ves en «Estado de TI». Avísalo con «Reportar un problema». | Alejamiento a toda la columna |
| 4.9 | 5 s | Portal, `/`: la columna «Mi día» completa, con un rótulo encima. | «Solo lo de tu cuenta activa» | Todo lo que ves aquí es de tu cuenta activa. | Fundido a la tarjeta de cierre |

Suma: 7 + 12 + 10 + 9 + 10 + 8 + 9 + 10 + 5 = **80 s**.

## 5. Avisos, anuncios y eventos

- **Duración:** 75 s.
- **Objetivo:** usar la campana «Avisos», confirmar la lectura de un anuncio y guardar un evento en el calendario.

| N.º | Duración | Qué se ve | Texto en pantalla | Barra de texto | Transición |
|---|---|---|---|---|---|
| 5.1 | 8 s | Portal, encabezado de `/` (SDC): la campana con el número «3». | «Avisos» | La campana muestra cuántos avisos tienes sin leer. | Clic en la campana |
| 5.2 | 10 s | Portal, `/`: menú «Avisos» abierto con un anuncio, una aprobación, una tarea y un evento de muestra. | «Anuncios, aprobaciones, tareas y eventos» | Ves anuncios, aprobaciones, tareas y eventos. Toca uno para abrirlo. | Acercamiento al pie del menú |
| 5.3 | 7 s | Portal, `/`: el cursor pulsa «Ver todo» y la página baja a «Mi día». | «Ver todo» | «Ver todo» te lleva a «Mi día», en el inicio. | Desplazamiento a «Anuncios» |
| 5.4 | 10 s | Portal, `/#anuncios`: tarjeta «Anuncios»; uno lleva «Fijado» y otro «Lectura obligatoria». | «Anuncios» | En «Anuncios» están las noticias de tu empresa. Los fijados van primero. | Acercamiento al anuncio obligatorio |
| 5.5 | 11 s | Portal, `/#anuncios`: el cursor pulsa «Confirmar lectura»; aparece «Leído el 5 de noviembre». | «Confirmar lectura» | Si es de «Lectura obligatoria», léelo y pulsa «Confirmar lectura». | Paneo a «Ver todos» |
| 5.6 | 7 s | Portal, `/anuncios`: la lista completa, título «Anuncios». | «Ver todos» | «Ver todos» abre la lista completa de anuncios. | Corte a «Próximas fechas» |
| 5.7 | 9 s | Portal, `/#fechas` y luego `/eventos`: el cursor pulsa «Ver eventos» y abre la página «Eventos». | «Ver eventos» | Para ver los eventos, pulsa «Ver eventos» en «Próximas fechas». | Acercamiento a un evento |
| 5.8 | 13 s | Portal, `/eventos`: el evento de muestra «Jornada de bienestar» con fecha y lugar; el cursor pulsa «Agregar a mi calendario» y un ícono de calendario recibe el archivo. | «Agregar a mi calendario» | Cada evento trae fecha y lugar. Guárdalo con «Agregar a mi calendario». | Fundido a la tarjeta de cierre |

Suma: 8 + 10 + 7 + 10 + 11 + 7 + 9 + 13 = **75 s**.

## 6. El asistente

- **Duración:** 70 s.
- **Objetivo:** abrir el «Asistente», saber qué puede responder y conocer sus límites reales.

| N.º | Duración | Qué se ve | Texto en pantalla | Barra de texto | Transición |
|---|---|---|---|---|---|
| 6.1 | 8 s | Portal, `/` (Segurex): el botón flotante «Asistente», abajo a la derecha, con un pulso. | «Asistente» | Abajo a la derecha está «Asistente». Púlsalo para abrir el chat. | El panel se abre desde el botón |
| 6.2 | 9 s | Portal, `/`: panel «Asistente» con «Hola, Ana.», las sugerencias y el campo «Pregunta o pide algo…». | «Pregunta o pide algo…» | Empieza con una sugerencia o escribe tu propia pregunta. | Clic en una sugerencia |
| 6.3 | 11 s | Portal, `/`: se pulsa «¿Qué tareas y aprobaciones tengo pendientes?» y llega una respuesta de muestra. | «¿Qué tareas y aprobaciones tengo pendientes?» | Pregunta por apps, anuncios, fechas, personas, tareas o el estado de TI. | Corte a la página del asistente |
| 6.4 | 10 s | Portal, `/asistente`: se resalta la nota del pie: «El asistente solo lee lo que tu cuenta ya ve en el portal y no puede cambiar nada.». | «Solo lee. No cambia nada.» | Solo lee lo que tu cuenta ya ve. No publica, no aprueba y no completa nada. | Resaltado que baja a la frase siguiente |
| 6.5 | 9 s | Portal, `/asistente`: se resalta «Las respuestas las genera Claude, de Anthropic, y pueden tener errores». | «Revisa los datos importantes en el portal» | Las respuestas las genera Claude, de Anthropic, y pueden tener errores. | Paneo al contador |
| 6.6 | 9 s | Portal, `/asistente`: bajo «Tu pregunta», el contador «Te quedan 38 preguntas de 40 por hoy.». | «Te quedan 38 preguntas de 40 por hoy.» · «Datos de muestra» | Tienes un número de preguntas por día. Bajo el campo ves cuántas te quedan. | Paneo al botón |
| 6.7 | 8 s | Portal, `/asistente`: botón «Nueva conversación» y la frase «La conversación no se guarda». | «Nueva conversación» | No se guarda: se borra al cerrar sesión o al cambiar de empresa. | Corte al panel |
| 6.8 | 6 s | Portal, `/`: en el panel, una pregunta ajena al trabajo y una respuesta amable de muestra. | «Solo temas del portal y del trabajo» | Si preguntas algo ajeno al portal, te lo dirá con amabilidad. | Fundido a la tarjeta de cierre |

Suma: 8 + 9 + 11 + 10 + 9 + 9 + 8 + 6 = **70 s**.

## 7. Tema claro y oscuro, el portal en el celular y los avisos

- **Duración:** 70 s.
- **Objetivo:** elegir el tema, instalar el portal en el celular y activar «Avisos en el celular».

| N.º | Duración | Qué se ve | Texto en pantalla | Barra de texto | Transición |
|---|---|---|---|---|---|
| 7.1 | 9 s | Portal, `/` (SDC, claro): menú del avatar abierto; se resalta «Tema» con «Claro», «Oscuro» y «Sistema». | «Tema: Claro · Oscuro · Sistema» | Para cambiar el tema, abre el menú de tu avatar y busca «Tema». | Clic en «Oscuro» |
| 7.2 | 10 s | Portal, `/`: al pulsar «Oscuro», el inicio pasa a tema oscuro. | «Oscuro» | «Sistema» sigue el ajuste de tu equipo. El portal recuerda tu elección. | Corte a un celular Android |
| 7.3 | 10 s | Animación de un celular Android con Chrome: menú ⋮ y la opción «Instalar aplicación» (texto del navegador, no del portal). | «Android: menú ⋮ › Instalar aplicación» | Instala el portal. En Android, elige «Instalar aplicación» en Chrome. | Barrido a un iPhone |
| 7.4 | 9 s | Animación de un iPhone con Safari: «Compartir» y «Agregar a inicio» (texto del navegador). | «iPhone: Compartir › Agregar a inicio» | En iPhone, abre Safari, toca «Compartir» y luego «Agregar a inicio». | Acercamiento a la pantalla de inicio |
| 7.5 | 7 s | Animación: el ícono de SDC en la pantalla de inicio del celular; al tocarlo, el portal abre sin barra del navegador. | «Se abre como una app» | Queda un ícono con el logo de tu empresa y se abre como una aplicación. | Corte a `/cuenta` |
| 7.6 | 10 s | Portal en celular, `/cuenta#avisos`: tarjeta «Avisos en el celular»; el dedo pulsa «Activar en este dispositivo» y aparece el permiso del navegador. | «Avisos en el celular» | En «Cuentas vinculadas», pulsa «Activar en este dispositivo». | Bloqueo de pantalla |
| 7.7 | 9 s | Animación: la pantalla bloqueada recibe la notificación del anuncio de muestra «Simulacro de evacuación». | «Solo anuncios importantes» | Solo llegan los anuncios importantes que tu empresa decida enviar al celular. | Rótulo sobre la notificación |
| 7.8 | 6 s | Animación: rótulo sobre un iPhone y otro celular. | «En iPhone: primero instala el portal» | En iPhone, primero instala el portal. Actívalo en cada dispositivo. | Fundido a la tarjeta de cierre |

Suma: 9 + 10 + 10 + 9 + 7 + 10 + 9 + 6 = **70 s**.

## 8. Opcional, para editores y administradores: anuncio con correo y Mercados

- **Duración:** 90 s.
- **Objetivo:** publicar un anuncio que también sale por correo a la lista de cada empresa, y administrar la banda de Mercados.

| N.º | Duración | Qué se ve | Texto en pantalla | Barra de texto | Transición |
|---|---|---|---|---|---|
| 8.1 | 7 s | Animación: tarjeta de vidrio con el título del capítulo. | «Opcional: editores y administradores» | Este capítulo es para quienes publican anuncios o administran el portal. | Fundido a Administración |
| 8.2 | 8 s | Portal, menú del avatar › «Administración» y luego `/admin/anuncios`: el cursor pulsa «Nuevo anuncio». | «Administración › Anuncios › Nuevo anuncio» | En «Administración», abre «Anuncios» y pulsa «Nuevo anuncio». | Corte al formulario |
| 8.3 | 10 s | Portal, `/admin/anuncios/nuevo`: sección «Contenido» con «Título», «Texto» e «Imagen (opcional)», llenos con datos de muestra. | «Contenido: Título · Texto · Imagen» | En «Contenido» escribe el título y el texto. La imagen es opcional. | Desplazamiento a «Publicación» |
| 8.4 | 10 s | Portal, `/admin/anuncios/nuevo`: sección «Publicación» con «Para quién», «Desde», «Hasta (opcional)», «Fijar arriba» y «Lectura obligatoria». | «Para quién · Desde · Hasta» | En «Publicación» eliges para quién es, desde cuándo y si es obligatoria. | Acercamiento a la casilla de correo |
| 8.5 | 12 s | Portal, `/admin/anuncios/nuevo`: se resalta «Enviar también por correo a la lista de cada empresa», ya marcada, con «Lo reciben: Segurex (todos@segurex.ejemplo.test)». | «Enviar también por correo a la lista de cada empresa» · «Datos de muestra» | El correo viene marcado. Debajo ves qué listas lo reciben. Sale una vez. | Paneo a la vista previa |
| 8.6 | 8 s | Portal, `/admin/anuncios/nuevo`: «Vista previa» y el cursor pulsa «Guardar». | «Vista previa · Guardar» | Revisa la vista previa y pulsa «Guardar». El correo sale al publicarse. | Corte a Mercados |
| 8.7 | 9 s | Portal, `/admin/mercados`: título «Mercados» y la lista «Mercados de» con Segurex elegida. | «Administración › Mercados» | En «Mercados» decides qué ve cada empresa. Primero elígela en «Mercados de». | Desplazamiento a los insumos |
| 8.8 | 9 s | Portal, `/admin/mercados`: «Insumos que ve esta empresa» con «Zinc (US$/t)» y «Cobre (US$/t)» marcados; el cursor pulsa «Guardar insumos». | «Insumos que ve esta empresa» | Marca los insumos automáticos de esa empresa y pulsa «Guardar insumos». | Desplazamiento a los precios |
| 8.9 | 11 s | Portal, `/admin/mercados`: «Nuevo precio» con «Insumo» «Soda cáustica», «Valor» «4.123,45», «Unidad», «Fecha del precio» y «Fuente (opcional)»; el cursor pulsa «Guardar». | «Valor: coma para los decimales, por ejemplo 4.123,45» | Para un precio a mano, usa «Nuevo precio» y escribe los decimales con coma. | Corte al inicio |
| 8.10 | 6 s | Portal, `/` (Segurex): la banda muestra «Soda cáustica $4.123,45/kg ▲». | «Aparece en la banda» · «Datos de muestra» | El precio aparece en la banda en la siguiente carga. | Fundido a negro y logo One |

Suma: 7 + 8 + 10 + 10 + 12 + 8 + 9 + 9 + 11 + 6 = **90 s**.

---

## Fuentes de los textos

Rutas relativas a la raíz del repositorio. «js» indica un texto que pone el JavaScript del portal.

| Texto del portal | Fuente |
|---|---|
| «Bienvenido a One» | `resources/views/auth/login.blade.php:30` |
| «Iniciar sesión con Microsoft» | `resources/views/auth/login.blade.php:41` |
| Logo One (alt «One, portal de Segurex y Soluciones y Derivados») | `resources/views/auth/login.blade.php:24-27` |
| «Usa tu cuenta de trabajo de Segurex o de SDC…» | `resources/views/auth/login.blade.php:44` |
| Fila de logos «Empresas» en la Vitrina | `resources/views/auth/login.blade.php:46-55` |
| «Escribe a soporte de TI» | `resources/views/auth/login.blade.php:60-62` |
| «Buenos días» / «Buenas tardes» | `app/View/Home/Greeting.php:27-28` |
| «Portal RYR» (encabezado) | `resources/views/components/portal-header.blade.php:20` |
| «Tus empresas», «Actual» | `resources/views/components/portal-header.blade.php:98,102` |
| «Cuentas vinculadas» (menú) | `resources/views/components/portal-header.blade.php:120` |
| «Administración» (menú) | `resources/views/components/portal-header.blade.php:127` |
| «Tema», «Claro», «Oscuro», «Sistema» | `resources/views/components/portal-header.blade.php:133-137` |
| «Cerrar sesión» | `resources/views/components/portal-header.blade.php:144` |
| «Buscar aplicaciones» (campo del encabezado) | `resources/views/components/portal-header.blade.php:49,52` |
| «Ctrl K» | `resources/views/components/portal-header.blade.php:56` |
| «⌘ K» en Mac; Ctrl K y ⌘ K abren el buscador | `resources/js/home.js:260,272` (js) |
| «Cambiar de empresa» (selector) | `resources/views/components/account-switcher.blade.php:15,35` |
| «Cuentas vinculadas», «Tus cuentas», «Aún no tienes cuentas vinculadas.» | `resources/views/account/show.blade.php:24,34,39` |
| «Cada una conserva sus propios permisos, aplicaciones y favoritos.» | `resources/views/account/show.blade.php:43` |
| «Vincular otra empresa» | `resources/views/account/show.blade.php:63` |
| «Vincular otra cuenta», «Cómo funciona», «Continuar con Microsoft» | `resources/views/account/link.blade.php:18,25,41` |
| «Vinculaste tu cuenta de …» | `app/Http/Controllers/Auth/MicrosoftLoginController.php:405` |
| «Ahora estás en tu cuenta de …» | `app/Http/Controllers/Auth/MicrosoftLoginController.php:332` |
| «Mis aplicaciones», «Tienes N aplicaciones disponibles en …» | `resources/views/home.blade.php:75`; `app/View/Home/HomePage.php:211-212` |
| «Favoritos», «Toca la estrella de una aplicación para fijarla aquí.» | `resources/views/home.blade.php:102-103` |
| «Recientes» | `resources/views/home.blade.php:113` |
| «Accesos rápidos» | `resources/views/home.blade.php:160` |
| «Anuncios», «Ver todos» | `resources/views/home.blade.php:182-183` |
| «Mi día» | `resources/views/home.blade.php:210` |
| «Por confirmar» | `resources/views/home.blade.php:220` |
| «Cumpleaños y aniversarios», «Felicitar» | `resources/views/home.blade.php:239,257` |
| «Próximas fechas», «Ver eventos» | `resources/views/home.blade.php:268,270` |
| «Personas», «Ver directorio», «Nombre, cargo o área» | `resources/views/home.blade.php:321-326` |
| «Buscar en el portal»; grupos «Aplicaciones», «Anuncios», «Fechas», «Personas» | `resources/views/components/home/search-dialog.blade.php:18-21,28` |
| «Mercados», «Pausar», «Reanudar» | `resources/views/components/home/markets.blade.php:31,62-63` |
| «Dólar (TRM)», «Euro», «Petróleo Brent», «Tasa de política (BanRep)», «COLCAP», «Zinc (US$/t)», «Cobre (US$/t)» | `app/Markets/MarketSeries.php:109-117` |
| ▲ verde (`good`), ▼ rojo (`crit`) | `app/View/Home/MarketFigure.php:113-114,127-128` |
| «Tareas pendientes», «Por aprobar», «Tareas» | `resources/views/components/home/work-list.blade.php:17,27-28` |
| «Completar», «Sí, completar» | `resources/views/components/home/work-list.blade.php:60,64` |
| «Ver tarea» / «Ver aprobación» | `app/View/Home/WorkRow.php:74` |
| «Confirma su lectura · …» | `app/View/Notifications/Notification.php:49` |
| «Estado de TI», «Reportar un problema» | `resources/views/components/home/it-status.blade.php:24,52` |
| «Todo funciona con normalidad» | `app/ItStatus/ItStatusLevel.php:58` |
| «Avisos», «No tienes avisos nuevos», «Ver todo» | `resources/views/components/notification-bell.blade.php:37,41,62` |
| «Avisos, 3 sin leer» (nombre accesible de la campana) | `app/View/Notifications/NotificationList.php:147-149` |
| «Fijado», «Lectura obligatoria», «Confirmar lectura», «Leído el …» | `resources/views/components/announcements/card.blade.php:24,28,48,52` |
| «Anuncios» (página `/anuncios`) | `resources/views/announcements/index.blade.php:18` |
| «Eventos», «Agregar a mi calendario» | `resources/views/events/index.blade.php:20,50` |
| «Asistente» (botón flotante y menú) | `resources/views/components/assistant-fab.blade.php:2`; `resources/views/components/assistant-link.blade.php:3` |
| «Hola, …», «Pregunta o pide algo…», «Nueva conversación» (panel) | `resources/js/assistant-panel.js:145,84,74` (js) |
| Sugerencias, por ejemplo «¿Qué tareas y aprobaciones tengo pendientes?» | `resources/js/assistant-panel.js:27-31`; `resources/views/assistant/show.blade.php:23-27` |
| «Pregúntale por lo que ya ves en el portal…» | `resources/views/assistant/show.blade.php:44` |
| «Tu pregunta», «Te quedan N preguntas de N por hoy.», «Enviar», «Nueva conversación» | `resources/views/assistant/show.blade.php:149,155,158,165` |
| «El asistente solo lee lo que tu cuenta ya ve…», «La conversación no se guarda…» | `resources/views/assistant/show.blade.php:170-171` |
| Límites: 40 preguntas por día y 10 por conversación (valores por defecto), 1000 caracteres por pregunta | `config/assistant.php:108,112,119` |
| Límite del mes («alcanzó el límite de uso de este mes») | `resources/views/assistant/show.blade.php:142` |
| Reglas: solo lee, no inventa, no publica ni aprueba, responde temas del portal | `app/Assistant/SystemPrompt.php:29-36` |
| «Avisos en el celular», «Activar en este dispositivo» | `resources/views/account/partials/push-optin.blade.php:16,27` |
| iPhone: «Agregar a inicio», iOS 16.4 o posterior | `resources/views/account/partials/push-optin.blade.php:21` |
| «Instalar aplicación» (texto de Chrome, no del portal) | `docs/manual-administracion.md:419` |
| «Anuncios» (menú de Administración) | `app/View/Components/Layouts/Admin.php:52` |
| «Nuevo anuncio» | `resources/views/admin/announcements/index.blade.php:19`; `resources/views/livewire/admin/announcement-editor.blade.php:24` |
| «Contenido», «Título», «Texto», «Imagen (opcional)» | `resources/views/livewire/admin/announcement-editor.blade.php:68,70,75,86` |
| «Publicación», «Para quién», «Desde», «Hasta (opcional)» | `resources/views/livewire/admin/announcement-editor.blade.php:115,117,152,157` |
| «Fijar arriba», «Lectura obligatoria» | `resources/views/livewire/admin/announcement-editor.blade.php:165,173` |
| «Enviar también por correo a la lista de cada empresa», «Lo reciben: …» | `resources/views/livewire/admin/announcement-editor.blade.php:183-184` |
| La casilla de correo viene marcada en un anuncio nuevo | `app/Livewire/Admin/AnnouncementEditor.php:91` |
| «Enviar al celular» | `resources/views/livewire/admin/announcement-editor.blade.php:201` |
| «Guardar», «Vista previa» | `resources/views/livewire/admin/announcement-editor.blade.php:50,214` |
| «Mercados» (menú de Administración y página) | `app/View/Components/Layouts/Admin.php:55`; `resources/views/admin/markets/index.blade.php:4` |
| «Mercados de», «Insumos que ve esta empresa», «Guardar insumos» | `resources/views/livewire/admin/market-manager.blade.php:22,35,53` |
| «Nuevo precio», «Insumo», «Valor», «Unidad», «Fecha del precio», «Fuente (opcional)», «Guardar» | `resources/views/livewire/admin/market-manager.blade.php:73,76,83,88,98,103,109,120` |
| Valor con coma decimal («4.200,50») | `docs/manual-administracion.md` §16.3 |

## Diferencias con el pedido

Lo que el pedido nombra de otra forma, o que en el portal funciona distinto. El guion sigue el código.

| Pedido | Lo que hay en el portal | Cómo lo resuelve el guion |
|---|---|---|
| «Nunca las dos marcas en un cuadro» | La Vitrina muestra los logos de las dos empresas en la fila «Empresas» (`login.blade.php:46-55`). | Los cuadros 1.3, 1.4 y 1.6 recortan esa fila. Si se quiere la tarjeta completa, hay que aceptar esa segunda excepción. |
| Página `/cuenta` | Se titula «Cuentas vinculadas». El botón dice «Vincular otra empresa», pero la página siguiente se titula «Vincular otra cuenta». | Se usan los tres textos tal como están. |
| Selector de empresa en el encabezado | Solo aparece con una cuenta vinculada y desde tablet. En el celular está en el menú, como «Tus empresas». Cambiar de empresa es un inicio de sesión real de Microsoft. | Escena 2.9 para el celular; la 2.7 lo dice. |
| «Tareas y aprobaciones» | El bloque se llama «Tareas pendientes», con los grupos «Por aprobar» y «Tareas». | Escena 4.2. |
| «Cumpleaños» | El bloque se llama «Cumpleaños y aniversarios». | Escena 4.5. |
| «Campanita» | El menú se titula «Avisos». Los eventos aparecen en la lista, pero no suman al número. | Escenas 5.1 y 5.2. |
| Ctrl K | En Mac dice «⌘ K», y «/» también abre el buscador. En el celular no hay atajo: se usa «Buscar» en la barra inferior. | Escena 3.5; el corte vertical usa «Buscar». |
| «Pausar» en Mercados | Solo aparece cuando la banda se mueve. Con «Reducir movimiento» la banda queda quieta y sin control. La banda no aparece antes de la primera sincronización. | Grabar con el movimiento activo. |
| Instalar el portal (PWA) | El portal no tiene un botón propio para instalarse: se instala desde el menú del navegador. Esos textos cambian según la versión del navegador. | Escenas 7.3 y 7.4 como animación, con el rótulo «texto del navegador». |
| Avisos (push) | Se activan en `/cuenta`, en «Avisos en el celular», solo si TI configuró las llaves. Solo envían anuncios marcados con «Enviar al celular». | Escenas 7.6 a 7.8. |
| Asistente: límites | La nota «solo lee… pueden tener errores» y el botón «Enviar» están en la página `/asistente`, no en el panel flotante. El botón flotante solo aparece si TI encendió el asistente. El límite diario (40 por defecto) lo puede cambiar TI. | Escenas 6.4 a 6.6 en `/asistente`; la barra de texto no dice el número. |
| «Admin › Mercados» | El menú dice «Administración». La sección es «Mercados». Un editor de anuncios solo ve su empresa. | Escenas 8.2 y 8.7. |
| «Correo a la lista» | La casilla se llama «Enviar también por correo a la lista de cada empresa». Solo aparece si la empresa tiene lista y buzón remitente. En un anuncio nuevo viene marcada. | Escena 8.5. |
| Manual de administración | El §7.1 aún describe el inicio de sesión anterior («cometas», «Empresas del grupo»). El código actual es la Vitrina. | El guion sigue el código. Conviene actualizar el manual (fuera de este encargo). |
| Tema | El selector «Tema» del menú aparece solo cuando carga el JavaScript del portal. | Sin cambio para el video. |
