# Storyboard del video guía: Portal RYR (One)

Un cuadro clave por escena, con el mismo número que `docs/video-guia/guion.md` (68 cuadros). Cada cuadro dice qué se compone, con qué marca, cómo se mueve y qué cambia en el corte vertical. El video no tiene voz: cada cuadro lleva además la barra de texto de su escena (regla de abajo).

## Reglas para todos los cuadros

| Tema | Regla |
|---|---|
| Herramienta | Motion Canvas (ver `produccion.md`). Cada cuadro es una captura PNG real del portal local, con capas animadas encima: rótulos, resaltados, cursor y acercamientos. El vidrio lo pone la captura; las capas no imitan `backdrop-filter`. Un cuadro que cambia de estado (menú abierto, banda en pausa) es otra captura. |
| Formato maestro | 1920×1080 (16:9), capturas del portal en escritorio a 1440 px de ancho, a 2× para que los acercamientos no se pixelen. |
| Corte vertical | 1080×1920 (9:16). Se graba aparte, en el modo celular del navegador (390 px de ancho), salvo que el cuadro diga otra cosa. Zona segura: 90 px a cada lado, 220 px arriba y 320 px abajo libres para la interfaz de la red social. Los rótulos van en el tercio medio. |
| Marca | Una empresa por cuadro, con `data-brand` en `<html>`: `segurex` (naranja), `derivados` (azul) o `neutral` (sin sesión). Colores solo con tokens: `--brand-button`, `--brand-tint`, `--brand-ink`, `--brand-dark` y los `--g-*` del vidrio. Nunca un hex nuevo. |
| Vidrio | El texto va siempre sobre vidrio (`g-card`, `g-sheet`, `g-banner`, `g-topbar`, `g-pop`, `g-foot`), nunca sobre la foto de `g-canvas`. Los rótulos del video usan el mismo vidrio, opaco entre 84 y 92 %. |
| Rótulos animados | Un rótulo es una capa sólida con el color de `--g-solid` (desde `brand.ts`), sin desenfoque, como hace el portal. Solo se animan opacidad y posición. |
| Barra de texto | El video no tiene voz: la frase de cada escena («Barra de texto» en el guion) se lee en una barra sólida, con el criterio de `--g-solid` (opaca entre 84 y 92 %, sin desenfoque). Texto en Helvetica o Arial, máximo 2 líneas. **Horizontal 1920×1080:** a lo largo del borde inferior, dentro de un margen de 60 px. **Vertical 1080×1920:** encima de los 320 px libres de abajo, en la parte baja del tercio medio, 2 líneas de unos 28 caracteres con letra más grande; si la frase no cabe, se permiten 3 líneas, solo en vertical. Hoy 61 de las 68 frases ocupan 3 líneas en vertical y ninguna pasa de 3. La barra aparece primero y la acción empieza cerca de 1 s después. Nunca tapa el elemento resaltado: si lo taparía, en esa escena la barra sube al borde superior. |
| Resaltado | Anillo de 2 px en `--g-ink` (el mismo del foco del portal) y pulso suave de 600 ms, dos veces. El cursor es el del sistema, a 150 %. |
| Flechas de mercado | ▲ en `--g-good`, ▼ en `--g-crit`, ● en `--g-ink-faint`. |
| Rótulo «Datos de muestra» | Píldora `g-pill` abajo a la izquierda en cada captura del portal, justo encima de la barra de texto; en el corte vertical, también encima de la barra de texto. |
| Logos | Los reales: Segurex blanco sobre su placa `--brand-dark`; SDC a color sobre su placa blanca. One en color (claro) o cristal (oscuro). |

## 1. Qué es el portal y cómo entrar

| N.º | Composición | Marca | Movimiento | Corte vertical 1080×1920 |
|---|---|---|---|---|
| 1.1 | Fondo neutro liso; el logo One al centro, a un tercio del ancho. | Neutra (`neutral`), claro. One en color. | Las piezas del logo entran por opacidad y escala de 0,96 a 1 en 1,2 s. | Logo al centro, a 60 % del ancho. Sin recortes. |
| 1.2 | Tres íconos (cuadrícula, megáfono, check) a la izquierda; una tarjeta `g-card` vacía a la derecha. | Neutra, claro. Íconos en `--g-ink-soft`. | Los íconos viajan a la tarjeta y se funden en ella; el rótulo aparece al final. | Íconos arriba y tarjeta abajo, en columna. Rótulo en dos líneas. |
| 1.3 | Captura de `/login`: tarjeta de la Vitrina centrada con One y «Bienvenido a One»; fotos de la Vitrina detrás del velo `--g-veil`. Encuadre del logo al botón, sin la fila de logos «Empresas». | Neutra, claro. Tarjeta `g-card` sobre `g-canvas` con `vitrina-slides`. | Fundido real de las fotos de la Vitrina; acercamiento lento de 100 % a 108 %. | Captura de celular: tarjeta a todo el ancho. La fila «Empresas» queda fuera del encuadre. |
| 1.4 | Acercamiento al botón «Iniciar sesión con Microsoft» (`g-btn--ink`, con los cuatro cuadros de Microsoft). Cursor sobre el botón. | Neutra, claro. Botón tinta, nunca `--brand-button`. | Resaltado del botón, clic con onda breve; rótulo arriba. | Botón a 80 % del ancho, en el centro vertical. |
| 1.5 | Ilustración plana de un selector de cuentas, con `ana.prueba@ejemplo.test` resaltada. Rótulo «Ilustración». | Neutra. Sin logos ni colores de Microsoft más allá de los cuatro cuadros. | Entra por la derecha; el cursor elige la cuenta. | La ilustración ocupa el tercio medio; el rótulo, debajo. |
| 1.6 | Recorte de la parte baja de la tarjeta: la línea «Solo para colaboradores… Escribe a soporte de TI». Sin la fila de logos. | Neutra, claro. | Subrayado animado bajo «Escribe a soporte de TI». | Recorte del texto en tres líneas, centrado. |
| 1.7 | Captura de `/`: encabezado `g-topbar` con el logo de Segurex y el saludo `g-banner` «Buenos días, Ana». | Segurex (`segurex`), claro. | Barrido de neutro a la foto de Segurex; el saludo entra por opacidad. | Captura de celular: encabezado en dos filas, saludo debajo. |

## 2. Vincular tu cuenta de la otra empresa y cambiar de empresa

| N.º | Composición | Marca | Movimiento | Corte vertical 1080×1920 |
|---|---|---|---|---|
| 2.1 | Encabezado de `/` a la derecha; menú del avatar «AP» abierto (`g-pop`); cursor sobre «Cuentas vinculadas». | Segurex, claro. Iniciales sobre `--g-accent-tint`. | El menú cae; resaltado de la fila «Cuentas vinculadas». | El menú ocupa el ancho; la fila resaltada, en el tercio medio. |
| 2.2 | `/cuenta`: `g-banner` «Cuentas vinculadas» arriba y la tarjeta «Tus cuentas» al centro. | Segurex, claro. | Acercamiento suave a la tarjeta. | La tarjeta llena el ancho; el título queda arriba de la zona segura. |
| 2.3 | `/cuenta`: botón «Vincular otra empresa» (`g-btn--primary`, `--brand-button`) con cursor. Al lado, ilustración de la confirmación de Microsoft. | Segurex, claro. | Clic; la ilustración entra en `--g-solid` por la derecha. | Botón arriba e ilustración abajo, en columna. |
| 2.4 | `/cuenta/vincular`: «Vincular otra cuenta», tarjeta «Cómo funciona» con la lista y el botón «Continuar con Microsoft». | Segurex, claro. | Los puntos de la lista se resaltan uno a uno; clic en el botón. | Se ven tres puntos de la lista y el botón; el resto se recorta. |
| 2.5 | `/`: aviso `g-flash` «Vinculaste tu cuenta de Soluciones y Derivados.» sobre el saludo. | Segurex, claro. El nombre de SDC solo como texto. | El aviso entra por opacidad; pulso único. | El aviso a todo el ancho, en el tercio medio. |
| 2.6 | Acercamiento al encabezado: selector `g-seg` con «Segurex» marcado (`--g-accent-tint`) y «Soluciones y Derivados» como botón. | Excepción permitida: los dos nombres como texto; solo el color de Segurex. | Resaltado del selector; flecha que señala la empresa marcada. | El selector no existe en celular: se usa el menú «Tus empresas» con el mismo resaltado. |
| 2.7 | Mismo encuadre; cursor sobre «Soluciones y Derivados». Después, el inicio de SDC con el aviso «Ahora estás en tu cuenta de …». | Cambio de `segurex` a `derivados`, claro. | Clic; barrido de color del naranja al azul (sin desenfoque mientras se mueve); el aviso aparece al final. | Igual que 2.6, desde «Tus empresas». El barrido va de arriba abajo. |
| 2.8 | `/` de SDC: logo de SDC en su placa blanca, tarjetas y apps. Tres resaltados: logo, botón primario, primera app. | SDC (`derivados`), claro. | Tres pulsos en secuencia, 400 ms entre uno y otro. | Logo y primera fila de apps; el botón primario se omite. |
| 2.9 | Celular centrado sobre fondo neutro; menú del avatar con «Tus empresas» y «Actual». | SDC, claro. | El celular entra desde abajo; resaltado de «Tus empresas». | Es el formato natural: el celular ocupa el cuadro. |

## 3. El inicio: aplicaciones, buscador, accesos y Mercados

| N.º | Composición | Marca | Movimiento | Corte vertical 1080×1920 |
|---|---|---|---|---|
| 3.1 | `/` completo: banda «Mercados», columna principal y `g-rail` «Mi día» a la derecha. | SDC, claro. | Alejamiento de 110 % a 100 %; etiquetas «Aplicaciones» y «Mi día» sobre cada columna. | En celular, «Mi día» va debajo: se muestra la columna principal y una flecha hacia abajo con «Mi día». |
| 3.2 | Tarjeta «Mis aplicaciones» con `g-mark`, la nota de disponibilidad y dos categorías (`g-apps`). | SDC, claro. | Los logos de las apps entran uno tras otro, como en el portal. | Se ven dos filas de apps. |
| 3.3 | Tarjeta de la app de muestra «Compras demo»; cursor sobre la estrella. | SDC, claro. Estrella activa en `--brand-ink`. | Clic; la tarjeta vuela a «Favoritos» (trayecto curvo de 500 ms). | La tarjeta sube a «Favoritos» en vertical. |
| 3.4 | Lista «Recientes» con tres apps de muestra. | SDC, claro. | Resaltado de la sección; subrayado de cada nombre. | Lista en una columna. |
| 3.5 | Diálogo «Buscar en el portal» al centro con «vaca» escrito y los cuatro grupos de resultados; fondo oscurecido. Tecla «Ctrl K» dibujada arriba. | SDC, claro. Ícono de cada grupo en `--brand-tint` con `--brand-ink`. | La tecla se presiona; el diálogo se abre; los grupos aparecen en orden. | Sin atajo de teclado: el dedo toca «Buscar» en la barra inferior (`g-dock`) y se abre el mismo diálogo a todo el ancho. |
| 3.6 | Tarjeta «Accesos rápidos» con chips `g-chip` de Outlook, Teams y OneDrive. | SDC, claro. | Los chips se resaltan de izquierda a derecha. | Los chips pasan a dos filas. |
| 3.7 | Banda `g-ticker` bajo el `g-topbar`, a todo el ancho, con el dólar y el euro de muestra. | SDC, claro. Etiqueta «Mercados» en `g-ticker-tag`. | Movimiento real de la banda; acercamiento a 125 %. | Captura de celular: la banda bajo el encabezado de dos filas; se ven dos cifras a la vez. |
| 3.8 | Acercamiento a 200 % sobre ▲ 0,32 % y ▼ 0,15 %; al lado, la leyenda con ●. | SDC, claro. Flechas en `--g-good`, `--g-crit` y `--g-ink-faint`. | Pulso sobre cada flecha; la leyenda entra en `--g-solid`. | Las dos cifras una sobre otra y la leyenda debajo. |
| 3.9 | Control `g-ticker-pause` a la derecha de la banda; cursor encima. | SDC, claro. | Clic; la banda se detiene y el texto cambia a «Reanudar». | El control queda en el borde derecho, dentro de la zona segura. |

## 4. «Mi día»: lo que necesita tu atención hoy

| N.º | Composición | Marca | Movimiento | Corte vertical 1080×1920 |
|---|---|---|---|---|
| 4.1 | `g-rail` «Mi día» ocupando dos tercios del cuadro; la columna principal, borrosa al fondo. | Segurex (`segurex`), oscuro. One cristal donde aparezca. | Acercamiento desde la vista completa. | «Mi día» a todo el ancho, después de la columna principal. |
| 4.2 | Bloque «Tareas pendientes»: encabezados de grupo `g-label` «Por aprobar» y «Tareas»; la fila de muestra con la pastilla «Vencida». | Segurex, oscuro. «Vencida» en el color de falla del portal. | Resaltado de cada grupo; pulso en «Vencida». | Dos filas por grupo; el resto se recorta. |
| 4.3 | Botones «Ver tarea» (`g-btn`) y «Completar» (`g-btn--primary`); se abre la confirmación «Sí, completar». | Segurex, oscuro. Botón primario en `--brand-button`. | Clic en «Completar»; la confirmación se despliega; no se confirma en el video. | Botones a todo el ancho, uno debajo del otro. |
| 4.4 | Bloque «Por confirmar» con el título del anuncio de muestra y «Confirma su lectura · 3 de noviembre». | Segurex, oscuro. | Resaltado del título. | Bloque centrado en el tercio medio. |
| 4.5 | Bloque «Cumpleaños y aniversarios»: casilla de fecha `g-day`, «Luis Demo» y el botón «Felicitar». | Segurex, oscuro. | Pulso en «Felicitar»; ícono de chat que aparece al lado. | La fila completa; «Felicitar» debajo del nombre. |
| 4.6 | Bloque «Próximas fechas»: un festivo (`g-day--accent`) y un evento; enlace «Ver eventos». | Segurex, oscuro. | Los dos elementos entran uno tras otro. | Lista en una columna. |
| 4.7 | Bloque «Personas» con el campo `g-input` «Nombre, cargo o área» y «compras» escrito. | Segurex, oscuro. | Escritura letra a letra; resaltado de «Ver directorio». | Campo a todo el ancho; el teclado del celular se omite. |
| 4.8 | Bloque «Estado de TI» con la pastilla «Todo funciona con normalidad» y el botón «Reportar un problema». | Segurex, oscuro. Pastilla en el color de servicio activo del portal. | Pulso del punto de estado; resaltado del botón. | Pastilla y botón apilados. |
| 4.9 | `g-rail` completa, en miniatura, con el rótulo encima en `g-banner`. | Segurex, oscuro. | Alejamiento; el rótulo entra en `--g-solid`. | Miniatura vertical de la columna; rótulo en el tercio medio. |

## 5. Avisos, anuncios y eventos

| N.º | Composición | Marca | Movimiento | Corte vertical 1080×1920 |
|---|---|---|---|---|
| 5.1 | Acercamiento al encabezado: la campana con el número «3» sobre `--g-button`. | SDC (`derivados`), claro. | Pulso del número dos veces. | Encabezado de celular; la campana queda dentro de la zona segura superior. |
| 5.2 | Menú «Avisos» (`g-pop`) abierto con cuatro elementos de muestra, cada uno con su ícono en `--g-accent-ink`. | SDC, claro. | El menú cae; los elementos aparecen uno a uno. | En celular el menú ocupa el ancho del encabezado. Se ven los cuatro elementos. |
| 5.3 | Pie del menú con «Ver todo»; cursor encima. | SDC, claro. | Clic; desplazamiento rápido a «Mi día». | El desplazamiento es vertical, natural en este formato. |
| 5.4 | Tarjeta «Anuncios» con dos `g-tile`: uno «Fijado» (`--brand-tint`, `--brand-ink`) y uno con «Lectura obligatoria» (`g-ann--must`). | SDC, claro. | Resaltado de cada etiqueta. | En celular los anuncios van en una franja: se muestra el obligatorio. |
| 5.5 | Acercamiento al anuncio obligatorio; cursor sobre «Confirmar lectura» (`--brand-button`). | SDC, claro. | Clic; el botón se cambia por «Leído el 5 de noviembre» con check en el color de servicio activo. | Botón a todo el ancho en el tercio medio. |
| 5.6 | `/anuncios`: `g-banner` «Anuncios» y la lista. | SDC, claro. | Desplazamiento lento de la lista. | Lista en una columna. |
| 5.7 | Partido en dos: a la izquierda «Próximas fechas» con «Ver eventos»; a la derecha `/eventos` con el título «Eventos». | SDC, claro. | Clic a la izquierda; la página de la derecha se desliza. | Uno arriba del otro: primero el enlace, después la página. |
| 5.8 | Tarjeta del evento «Jornada de bienestar» con fecha, lugar y el botón «Agregar a mi calendario»; a la derecha, un ícono genérico de calendario. | SDC, claro. | Clic; un archivo vuela al ícono y el ícono marca el día. | La tarjeta arriba y el ícono abajo; el vuelo es vertical. |

## 6. El asistente

| N.º | Composición | Marca | Movimiento | Corte vertical 1080×1920 |
|---|---|---|---|---|
| 6.1 | `/` completo; el botón `g-fab` «Asistente» (`g-btn--primary`) abajo a la derecha, resaltado. | Segurex, claro. | Pulso del botón dos veces; el cursor llega. | El botón queda por encima de la barra inferior (`g-fab--raised`) y dentro de los 320 px libres de abajo. |
| 6.2 | Panel `g-assist` abierto a la derecha: «Asistente», «Hola, Ana.», sugerencias en chips y el campo «Pregunta o pide algo…». | Segurex, claro. Burbuja del bot `g-msg--bot`. | El panel se abre desde el botón; las sugerencias aparecen en cascada. | Bajo 640 px el panel ocupa toda la pantalla: así se muestra. |
| 6.3 | Mismo panel: la pregunta en `g-msg--me` y la respuesta de muestra en `g-msg--bot`. | Segurex, claro. | Clic en la sugerencia; tres puntos de espera; la respuesta entra por opacidad. | Panel a pantalla completa; se ven la pregunta y el inicio de la respuesta. |
| 6.4 | `/asistente`: tarjeta inferior con la nota del pie; la frase «solo lee… no puede cambiar nada» resaltada. | Segurex, claro. Texto en `--g-ink-faint` sobre `g-card`. | Resaltado tipo marcador que avanza por la frase. | La nota en cuatro líneas, aumentada al 130 %. |
| 6.5 | Misma tarjeta; resaltada la frase de Claude y los errores. | Segurex, claro. | El marcador baja a la frase siguiente. | Igual que 6.4. |
| 6.6 | Campo «Tu pregunta» con el contador debajo y el botón «Enviar». | Segurex, claro. | Pulso del contador; el número baja de 39 a 38. | Campo y contador a todo el ancho. |
| 6.7 | Botón «Nueva conversación» y la frase «La conversación no se guarda» a la derecha. | Segurex, claro. | Resaltado del botón; ícono de papelera que se vacía junto a la frase. | Botón arriba, frase abajo. |
| 6.8 | Panel con una pregunta de muestra ajena al trabajo y la respuesta amable. | Segurex, claro. | La respuesta entra por opacidad; rótulo al final. | Panel a pantalla completa. |

## 7. Tema claro y oscuro, el portal en el celular y los avisos

| N.º | Composición | Marca | Movimiento | Corte vertical 1080×1920 |
|---|---|---|---|---|
| 7.1 | Menú del avatar abierto; sección «Tema» con el control `g-seg` «Claro · Oscuro · Sistema». | SDC, claro. | Resaltado del control. | Menú a todo el ancho; «Tema» en el tercio medio. |
| 7.2 | Mismo encuadre; al pulsar «Oscuro», todo el inicio cambia. | SDC, de claro a oscuro. Los `--brand-*` y `--g-*` pasan a sus valores oscuros; One a cristal. | Fundido cruzado de 800 ms entre las dos capturas; nunca un barrido animado detrás del vidrio. | Captura de celular en los dos temas, con el mismo fundido. |
| 7.3 | Celular Android dibujado al centro, con el menú ⋮ de Chrome abierto y «Instalar aplicación» resaltado. Rótulo «Texto del navegador». | SDC en la página de fondo, claro. | Toque en ⋮; el menú cae; resaltado de la opción. | Formato natural: el celular llena el cuadro. |
| 7.4 | iPhone dibujado al centro con Safari: botón «Compartir» y la hoja con «Agregar a inicio». Rótulo «Texto del navegador». | SDC en la página de fondo, claro. | La hoja sube; resaltado de la opción. | Formato natural. |
| 7.5 | Pantalla de inicio de un celular con el ícono de SDC; al tocarlo, el portal se abre a pantalla completa. | SDC, claro. Ícono de la empresa del manifiesto. | Toque; el ícono se expande hasta la pantalla del portal. | Formato natural. |
| 7.6 | Captura de celular de `/cuenta#avisos`: tarjeta «Avisos en el celular» y el botón «Activar en este dispositivo»; encima, el permiso del navegador. | SDC, claro. | Toque; el permiso aparece; el estado cambia a «Listo: los avisos están activados en este dispositivo.». | Formato natural. En el maestro 16:9, el celular va a la izquierda y el rótulo a la derecha. |
| 7.7 | Pantalla bloqueada con la notificación del anuncio de muestra y el ícono de SDC. | SDC. | La notificación baja desde arriba. | Formato natural; la notificación queda bajo los 220 px superiores. |
| 7.8 | Dos celulares lado a lado (iPhone y Android) con el rótulo encima. | SDC, claro. | El rótulo entra en `--g-solid`; los dos celulares hacen un pulso. | Celulares uno sobre otro, reducidos; rótulo en el centro. |

## 8. Opcional, para editores y administradores: anuncio con correo y Mercados

| N.º | Composición | Marca | Movimiento | Corte vertical 1080×1920 |
|---|---|---|---|---|
| 8.1 | Tarjeta `g-card` centrada con el título del capítulo sobre la foto de Segurex. | Segurex, claro. | Entrada por opacidad y escala. | Tarjeta al centro, título en tres líneas. |
| 8.2 | Menú del avatar con «Administración»; después, `/admin/anuncios` en el marco `g-admin-chrome` (barra lateral `g-admin-side` de vidrio, panel `g-admin-main` opaco) con «Nuevo anuncio». | Segurex, claro. | Clic en el menú; corte; resaltado de «Nuevo anuncio». | Bajo 1024 px la barra lateral se oculta: se muestra el botón del menú y el panel. |
| 8.3 | Formulario en `g-admin-main`: sección «Contenido» con los campos llenos con datos de muestra. | Segurex, claro. | Escritura del título de muestra; resaltado de «Imagen (opcional)». | Campos apilados; la vista previa se omite. |
| 8.4 | Sección «Publicación» con «Para quién», «Desde», «Hasta (opcional)» y los interruptores «Fijar arriba» y «Lectura obligatoria». | Segurex, claro. | Resaltado en orden de arriba abajo; se enciende «Lectura obligatoria». | Una columna; los interruptores a la derecha de cada fila. |
| 8.5 | Acercamiento al interruptor «Enviar también por correo a la lista de cada empresa», encendido, con la línea «Lo reciben: …». | Segurex, claro. | Pulso del interruptor; subrayado de «Lo reciben». | El texto de ayuda se recorta a su primera línea. |
| 8.6 | Panel «Vista previa» a la derecha y el botón «Guardar» (`--brand-button`) arriba. | Segurex, claro. | Cambio de escritorio a celular en la vista previa; clic en «Guardar». | Vista previa y botón apilados. |
| 8.7 | `/admin/mercados`: título «Mercados» y la lista «Mercados de» con Segurex. | Segurex, claro. | Se abre la lista y se elige Segurex. | Una columna. |
| 8.8 | Tarjeta «Insumos que ve esta empresa» con casillas; «Zinc (US$/t)» y «Cobre (US$/t)» marcadas; botón «Guardar insumos». | Segurex, claro. | Las dos casillas se marcan; clic en el botón. | Se ven cuatro casillas y el botón. |
| 8.9 | Formulario «Nuevo precio» con «Soda cáustica», «4.123,45», la unidad, la fecha y la fuente. Rótulo sobre el campo «Valor». | Segurex, claro. | Escritura del valor; el rótulo señala la coma. | Campos apilados; rótulo junto a «Valor». |
| 8.10 | `/`: banda «Mercados» con «Soda cáustica $4.123,45/kg ▲» resaltada. Cierre con el logo One. | Segurex, claro; cierre neutro. | Pulso sobre la cifra; fundido a negro y logo One. | Banda de celular con la cifra resaltada; One al centro en el cierre. |
