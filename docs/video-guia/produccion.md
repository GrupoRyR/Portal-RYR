# Video guía del Portal RYR: plan de producción

Este documento dice cómo producir el video guía descrito en [guion.md](guion.md) y [storyboard.md](storyboard.md).

**Estado:** plan. Nada está instalado ni comprado. Cada compra o instalación espera la aprobación de Jhon.

**Fuentes:** las licencias y los precios se leyeron en la fuente primaria el 2026-11-05. Revísalos antes de comprar: cambian.

---

## 1. Resumen

| Tema | Decisión propuesta |
|---|---|
| Herramienta | **Remotion** (React + TypeScript), con una licencia de empresa de un puesto |
| Pantallas del portal | Capturas de un entorno local con datos de prueba, nunca de producción |
| Voz | Azure AI Speech, voz neuronal `es-CO-SalomeNeural` o `es-CO-GonzaloNeural` |
| Música | Pixabay Music, sin atribución obligatoria |
| Subtítulos | Archivo `.srt` por capítulo, generado con los tiempos de la voz |
| Formatos | 1920×1080 (escritorio) y 1080×1920 (celular) |
| Marca | Una empresa por pantalla; skills `liquid-glass-ryr` y `frontend-marca-sgx-sydc-v2` |
| Costo estimado | Unos US$25–50 en total (uno o dos meses de licencia de Remotion) |
| Tiempo estimado | De 9 a 11 días hábiles de una persona |

---

## 2. Herramienta

### Comparación

| | Remotion | Motion Canvas | Canva (sin código) | CapCut (sin código) |
|---|---|---|---|---|
| Lenguaje | React + TypeScript | TypeScript (canvas 2D) | Editor web | Editor de escritorio |
| Licencia | Gratis solo para empresas de hasta 3 empleados. Si son más, licencia de empresa | MIT, gratis | Cuenta gratis o Pro | Gratis |
| Costo para Segurex y SDC | US$25 al mes por puesto («Remotion for Creators») | US$0 | Pro: unos US$144 al año (sin confirmar) | US$0 |
| Exporta MP4 | Sí, incluido | Solo con el plugin `@motion-canvas/ffmpeg` | Sí | Sí |
| Vidrio (`backdrop-filter`, tokens CSS) | Sí: es HTML y CSS real | Difícil: dibuja en canvas, no en CSS | Aproximado, a mano | Aproximado, a mano |
| Dos formatos desde una fuente | Sí: dos composiciones con el mismo código | Sí, con código | A mano, por cada formato | A mano, por cada formato |
| Mantenimiento | Activo | Sin versión estable desde diciembre de 2024 | Activo | Activo |
| Riesgo principal | Pago mensual | Proyecto casi detenido | Marca inexacta y trabajo manual | La música de su biblioteca no sirve para la intranet |

### Recomendación: Remotion

- **El portal ya es HTML y CSS.** Remotion anima componentes React, así que el vidrio, los tokens de marca y el modo oscuro se reproducen con el mismo CSS del portal, sin redibujar nada.
- **Un solo código, dos formatos.** Cada capítulo es una composición de 1920×1080 y otra de 1080×1920 que comparten escenas.
- **Cambios baratos.** Si un botón del portal cambia de nombre, se corrige un texto y se vuelve a exportar.
- **Costo bajo y acotado.** Un puesto a US$25 al mes. Se activa solo mientras dura la producción y se cancela al terminar. Según la FAQ de Remotion, el puesto cubre a la persona que escribe el código, también cuando usa herramientas con IA.

**Por qué no las otras:**

- **Motion Canvas** es gratis (MIT), pero no publica una versión estable desde el 2024-12-14 y dibuja en canvas, así que el vidrio se tendría que imitar.
- **Canva** sirve como plan B sin código. Su música «Popular Music» permite videos de capacitación interna. Pero cada pantalla y cada formato se arman a mano, y la marca queda aproximada.
- **CapCut** queda descartado: su música comercial solo se puede publicar en CapCut, TikTok y TikTok for Business. Para la intranet hace falta un permiso escrito.

### Fuentes de licencias

- Remotion, licencia: <https://github.com/remotion-dev/remotion/blob/main/LICENSE.md> («a for-profit organization with up to 3 employees»).
- Remotion, precios: <https://www.remotion.pro/license> y <https://www.remotion.dev/docs/license/faq>.
- Motion Canvas, licencia MIT: <https://github.com/motion-canvas/motion-canvas/blob/main/LICENSE>. Versiones: <https://github.com/motion-canvas/motion-canvas/releases>. Exportar video: <https://motioncanvas.io/docs/rendering/video>.
- Canva, «Popular Music»: <https://canva.com/policies/popular-music-license>. La página no se pudo abrir directamente (403), así que este punto queda **sin confirmar**.
- CapCut, licencia de materiales: <https://www.capcut.com/material-license-agreement>.

---

## 3. Capturar el portal sin datos reales

**Regla:** las capturas y grabaciones salen de un entorno local (`http://localhost` o `*.test`) con datos de prueba.

**Nunca:**

- de producción;
- con nombres, correos, fotos, cumpleaños o cifras de personas reales;
- con el tablero de Microsoft o un correo real visible.

### Lo que falta hoy

- `DatabaseSeeder` carga empresas, roles, categorías y el catálogo de aplicaciones, pero **no carga usuarios ni contenido**. Lo hace a propósito, porque también corre en producción.
- La única forma de entrar sin Microsoft es el acceso de emergencia (`portal:emergency-account`). Ese acceso crea un `super_admin`, que ve el menú de administración, así que no muestra lo que ve un colaborador.
- Para el capítulo 2 hacen falta dos cuentas vinculadas, una en cada empresa.

### Propuesta (es un PR aparte, pendiente de aprobación)

1. **`VideoDemoSeeder`, solo para entorno local.** Usa las factories que ya existen (`UserFactory`, `CompanyFactory`, `AppFactory`, `AnnouncementFactory`, `EventFactory`, `WorkItemFactory`, `DirectoryEntryFactory`, `MarketRateFactory`, `ItServiceFactory`). Crea:
   - una colaboradora ficticia («Ana Prueba») con una cuenta en Segurex y otra en SDC, ya vinculadas;
   - un editor de anuncios ficticio;
   - anuncios, eventos, tareas, aprobaciones, cumpleaños y estados de TI de ejemplo;
   - tasas de mercado de ejemplo, unas que suben y otras que bajan.
   Si `app()->isProduction()`, el seeder no corre.
2. **Entrada local para grabar.** Una ruta que solo existe cuando `app()->isLocal()` y que inicia sesión como una cuenta de prueba. Así no hace falta Microsoft.
3. **Correos de prueba.** Todos terminan en `@ejemplo.test`. El correo local va al log (`MAIL_MAILER=log`), nunca a un buzón real.

### Cómo grabar

- **Ventana del navegador:** 1920×1080 con zoom al 100 %. Para los acercamientos, captura a 2× (por ejemplo, con la emulación de dispositivo del navegador) y así el zoom no se pixela.
- **Una toma por empresa.** Graba cada escena con la empresa que pide el guion (`data-brand="segurex"` o `"sdc"`) y con el tema que pide el storyboard.
- **Grabador.** En Windows 10, la Barra de juegos (Win + Alt + R) graba la ventana sin instalar nada. OBS Studio (GPL) es más preciso, pero hay que instalarlo, así que primero se pregunta.
- **Banda de Mercados.** Grábala con la animación en marcha y también en pausa. El guion muestra el botón «Pausar».
- **Revisión.** Antes de usar cada captura, revisa cuadro por cuadro que no haya un dato real, una URL de producción ni una notificación del sistema operativo.

---

## 4. Voz en español de Colombia

### Opción recomendada: Azure AI Speech

- **Por qué:** la empresa ya usa Microsoft 365 y Azure (Entra).
- **Voces neuronales `es-CO` disponibles:**
  - `es-CO-SalomeNeural`, voz femenina;
  - `es-CO-GonzaloNeural`, voz masculina.
  Ninguna de las dos tiene estilos ni roles. Fuente: <https://learn.microsoft.com/en-us/azure/ai-services/speech-service/language-support?tabs=tts>.
- **Costo:**
  - El nivel gratis (F0) incluye 0,5 millones de caracteres al mes.
  - El nivel S1 cuesta US$15 por millón de caracteres (East US).
  - La locución completa (8 capítulos, unos 10 minutos) tiene cerca de 6 000 caracteres, así que cabe en el nivel gratis aun con muchas regrabaciones.
  - Fuentes: <https://azure.microsoft.com/en-us/pricing/details/cognitive-services/speech-services/> y la API de precios de Azure.
- **Cómo:**
  - Escribe la locución de cada escena en SSML, con pausas (`<break>`) y la pronunciación de siglas como «SDC» o «TI».
  - Genera un archivo de audio por escena. Así, si cambia una escena, solo se regraba esa.
- **Requisito:** un recurso de Speech en la suscripción de Azure de la empresa. Lo crea Jhon. La clave nunca va al repositorio.

### Alternativa: voz propia

- Una persona del equipo graba la locución con un micrófono USB en una sala silenciosa.
- **Ventaja:** suena más cercana.
- **Costos:**
  - cada cambio obliga a regrabar con la misma voz y el mismo ambiente;
  - hace falta la autorización escrita de esa persona para usar su voz.

---

## 5. Música y subtítulos

### Música

- **Fuente:** Pixabay Music.
  - Permite el uso comercial y no exige atribución.
  - Sirve fuera de cualquier plataforma, así que vale para la intranet.
  - Prohíbe vender la pista sola y usarla como marca.
  - Fuente: <https://pixabay.com/service/license-summary/>.
- **Registro:** guarda en `docs/video-guia/creditos.md` el enlace y la fecha de cada pista, aunque no se exija atribución.
- **Volumen:** música de fondo entre −20 y −24 dB bajo la voz. Baja aún más mientras habla la locución.
- **Evita:**
  - la biblioteca de música de CapCut (solo vale para TikTok y CapCut);
  - la biblioteca de audio de YouTube, porque su uso fuera de YouTube no está claro.

### Subtítulos (.srt)

- **Un `.srt` por capítulo y por formato.** El texto es la locución exacta del guion.
- **Cómo generarlos:**
  - Azure no entrega `.srt` directamente. Su síntesis da los tiempos de cada palabra y cada frase (`WordBoundary`, o `sentenceBoundaryEnabled` en la síntesis por lotes), y con esos tiempos se arma el `.srt`.
  - Si la voz es propia, usa Whisper (MIT, corre en el equipo; hay que instalarlo, así que primero se pregunta) con `--output_format srt`, y después corrige los textos contra el guion.
- **Reglas:**
  - máximo 2 líneas de 42 caracteres;
  - entre 1 y 7 segundos en pantalla;
  - en el formato vertical, por encima del tercio inferior para no tapar los controles del reproductor.

---

## 6. Formatos y exportación

| Formato | Uso | Notas |
|---|---|---|
| 1920×1080, 30 fps, MP4 (H.264 + AAC) | Portal, Teams y correo | Formato principal |
| 1080×1920, 30 fps, MP4 | Celular | Reacomoda, no recorta a ciegas (ver la zona segura en el storyboard) |
| `.srt` | Ambos | Un archivo por capítulo y formato |
| Miniatura PNG, 1280×720 | Portada en el portal | Una por capítulo |

**Nombres de archivo:** `NN-tema-formato-empresa.mp4`, por ejemplo `02-vincular-cuenta-1080x1920-sdc.mp4`.

**Peso:** apunta a menos de 25 MB por capítulo horizontal, para que se pueda adjuntar o subir sin problemas.

---

## 7. Marca

- **Una empresa por pantalla.** La marca sale de la empresa activa (`data-brand`). Nunca van el naranja de Segurex y el azul de SDC en el mismo cuadro. La única excepción es el selector de empresa del capítulo 2 (escenas 2.6 y 2.7), que muestra los nombres de las dos empresas como texto. La Vitrina (`/login`) muestra los logos de las dos empresas en una fila: el storyboard la deja fuera de cuadro en las escenas 1.3, 1.4 y 1.6.
- **Logos reales como imagen**, nunca el nombre escrito como texto. El logo de Segurex en blanco va solo sobre fondo oscuro.
- **Tipografía:** Helvetica o Arial, la misma del portal.
- **Colores:** solo los tokens de la skill (claro y oscuro, con contraste AA). Ningún hex suelto en una escena. Nunca texto blanco sobre `#F26522`.
- **Vidrio (`liquid-glass-ryr`):**
  - El texto va siempre sobre un panel de vidrio (`g-card`, `g-sheet`, `g-pop`…), nunca directo sobre el fondo.
  - **Mientras algo se mueve detrás de un panel, ese panel deja de usar `backdrop-filter` y usa `--g-solid`.** Es una regla de la skill, y en video también evita parpadeos y exportaciones lentas.
- **Skills:** `liquid-glass-ryr` y `frontend-marca-sgx-sydc-v2`. La v2 figura «en evaluación» frente a la v1 desde el 2026-10-29. Hay que confirmar con Jhon que la v2 es la vigente antes de producir.

---

## 8. Pasos, tiempos y costos

| # | Paso | Responsable | Días hábiles |
|---|---|---|---|
| 1 | Aprobar el guion, la herramienta y la voz | Jhon | 1 |
| 2 | PR con `VideoDemoSeeder` y la entrada local | Desarrollo | 1 |
| 3 | Grabar las capturas de los 8 capítulos (Segurex y SDC, claro y oscuro) | Desarrollo | 1–2 |
| 4 | Generar la voz (SSML) y revisar la pronunciación | Desarrollo + Jhon | 1 |
| 5 | Animar en Remotion: plantilla común (títulos, resaltados, flechas, transiciones) y luego cada capítulo | Desarrollo | 3–4 |
| 6 | Música, mezcla y `.srt` | Desarrollo | 0,5 |
| 7 | Revisar con 2 o 3 colaboradores (uno de cada empresa) y corregir | Jhon + usuarios | 1 |
| 8 | Exportar los dos formatos y publicar en el portal | Desarrollo + Jhon | 0,5 |
| | **Total** | | **De 9 a 11 días** (unas 2 semanas) |

### Costos

| Concepto | Costo |
|---|---|
| Remotion, 1 puesto «Creators», 1 o 2 meses | US$25–50 |
| Azure AI Speech, nivel F0 | US$0 (el guion cabe en el cupo gratis) |
| Música de Pixabay | US$0 |
| Grabador (Barra de juegos de Windows) | US$0 |
| **Total en efectivo** | **US$25–50** |

El costo real es el tiempo del equipo: unas 2 semanas de una persona.

**Plan B sin código (Canva Pro):** unos US$144 al año (precio sin confirmar), con más horas de trabajo manual por formato.

---

## 9. Decisiones pendientes de Jhon

Se responden una por una, en este orden:

1. ¿Aprobamos Remotion con un puesto de US$25 al mes durante la producción?
2. ¿Voz de Azure (Salomé o Gonzalo) o voz propia?
3. ¿Aprobamos el PR del `VideoDemoSeeder` con la entrada solo local?
4. ¿La marca vigente es la v2 (`frontend-marca-sgx-sydc-v2`) o la v1?
