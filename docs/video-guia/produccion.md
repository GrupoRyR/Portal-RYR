# Video guía del Portal RYR: plan de producción

Este documento dice cómo producir el video guía descrito en [guion.md](guion.md) y [storyboard.md](storyboard.md).

**Estado:** Jhon eligió la herramienta, aprobó la preparación (sección 9) y confirmó la marca v2 el 2026-11-05. Todavía no hay nada instalado ni construido.

**Fuentes:** las licencias y los precios se leyeron en la fuente primaria el 2026-11-05.

---

## 1. Resumen

| Tema | Decisión |
|---|---|
| Herramienta | **Motion Canvas** (MIT, TypeScript), elegida por Jhon el 2026-11-05 |
| Exportar a MP4 | Plugin `@motion-canvas/ffmpeg` |
| Vidrio | Capturas reales del portal como imágenes, con capas animadas encima. No se reproduce el CSS. |
| Pantallas del portal | Capturas de un entorno local con datos de prueba, nunca de producción |
| Voz | Azure AI Speech, voz neuronal `es-CO-SalomeNeural` o `es-CO-GonzaloNeural` |
| Música | Pixabay Music, sin atribución obligatoria |
| Subtítulos | Archivo `.srt` por capítulo, generado con los tiempos de la voz |
| Formatos | 1920×1080 (escritorio) y 1080×1920 (celular) |
| Marca | Una empresa por pantalla; skills `liquid-glass-ryr` y `frontend-marca-sgx-sydc-v2` (v2 confirmada por Jhon) |
| Costo de herramientas | **US$0** |
| Tiempo estimado | De 10 a 12 días hábiles de una persona |

---

## 2. Herramienta: Motion Canvas

### Qué es

- Una librería MIT para animar con código TypeScript. Licencia: <https://github.com/motion-canvas/motion-canvas/blob/main/LICENSE>.
- Cada escena es una función que mueve nodos (`Img`, `Rect`, `Txt`, `Layout`) sobre un canvas 2D.
- El editor corre en el navegador sobre Vite. Muestra la línea de tiempo y permite ajustar los tiempos.

### Exportar a MP4

- Motion Canvas exporta cuadros sueltos por defecto. Para MP4 hace falta el plugin `@motion-canvas/ffmpeg` («Video (FFmpeg)»). Fuente: <https://motioncanvas.io/docs/rendering/video>.
- El plugin trae FFmpeg como dependencia de npm, así que no hay que instalar FFmpeg aparte.
- Cada proyecto lleva **una sola pista de audio**. Por eso la voz y la música se mezclan antes en un archivo por capítulo (sección 5).

### Cómo se resuelve el vidrio

El vidrio del portal es CSS (`backdrop-filter`), y Motion Canvas dibuja en canvas: no puede aplicarlo. La solución es no reproducir el CSS:

1. **El portal real pone el vidrio.** Cada pantalla es una captura PNG del portal local, con el tema y la empresa que pide el storyboard. El vidrio, las sombras y los colores salen exactos porque son los del portal.
2. **Las capas van encima de la captura.** Todo lo que se anima son nodos encima de la imagen:
   - rótulos: `Rect` con relleno sólido opaco entre 84 y 92 % y texto `Txt`, el mismo criterio de `--g-solid`;
   - resaltados: `Rect` sin relleno, con borde de 2 px en el color de `--g-ink` y un pulso de opacidad;
   - cursor y clic: un `Img` del cursor y un círculo que se expande;
   - acercamientos: `scale` y `position` sobre el nodo de la captura.
3. **Sin desenfoque animado.** Ninguna capa intenta imitar `backdrop-filter`. Esto también respeta la regla de `liquid-glass-ryr`: nada se mueve detrás de un vidrio con desenfoque.
4. **Capturas al doble de resolución.** Se capturan a 2× para que los acercamientos de hasta 200 % no se pixelen.
5. **Una pantalla que cambia es otra captura.** Por ejemplo: el menú abierto, la banda en pausa, o antes y después de «Confirmar lectura». Se pasa de una a otra con un fundido corto o un barrido.

### Colores en las capas

- Los tokens de la skill (`--brand-button`, `--brand-ink`, `--g-ink`, `--g-good`, `--g-crit`…) se copian una sola vez a un archivo `brand.ts`, con su valor de modo claro y de modo oscuro, por empresa.
- Ese es el único lugar con valores hex. Las escenas usan los nombres, nunca un hex suelto.
- Si cambia un token del portal, se actualiza `brand.ts` y se vuelve a exportar.

### Dos formatos

- **Un proyecto por formato:** `horizontal` (1920×1080) y `vertical` (1080×1920). El plugin de Vite acepta varios proyectos y el editor deja elegir cuál abrir.
- **Lo que comparten:** los componentes (rótulo, resaltado, cursor, logo, `brand.ts`) y la locución.
- **Lo que cambia:** el encuadre. El corte vertical usa las capturas de celular (390 px de ancho) y las reglas de zona segura del storyboard.

### Riesgo y cómo se cubre

**El riesgo:** Motion Canvas casi no se mantiene. Su última versión estable es la v3.17.2, del 2024-12-14 (<https://github.com/motion-canvas/motion-canvas/releases>).

**Cómo se cubre:**

- **Versiones fijas.** Se fijan las versiones exactas en `package.json` y se sube `package-lock.json`, sin `^`.
- **El resultado no depende de la herramienta.** Es un MP4 normal: si la herramienta deja de funcionar, los videos ya hechos siguen sirviendo.
- **Se puede mantener la propia.** La licencia MIT permite copiar y mantener una versión propia si hiciera falta.

### Alternativas descartadas

| Herramienta | Motivo |
|---|---|
| Remotion | Su licencia gratis cubre solo empresas de hasta 3 empleados (<https://github.com/remotion-dev/remotion/blob/main/LICENSE.md>). Segurex y SDC necesitarían una licencia de empresa: US$25 al mes por puesto (<https://www.remotion.pro/license>). Jhon eligió una herramienta gratis. |
| Canva | Sin código, pero cada pantalla y cada formato se arman a mano, y la marca queda aproximada. |
| CapCut | Su música comercial solo se puede publicar en CapCut, TikTok y TikTok for Business (<https://www.capcut.com/material-license-agreement>). |

---

## 3. Capturar el portal sin datos reales

**Regla:** las capturas salen de un entorno local (`http://localhost` o `*.test`) con datos de prueba.

**Nunca:**

- de producción;
- con nombres, correos, fotos, cumpleaños o cifras de personas reales;
- con el tablero de Microsoft o un correo real visible.

### Lo que falta hoy

- `DatabaseSeeder` carga empresas, roles, categorías y el catálogo de aplicaciones, pero **no carga usuarios ni contenido**. Lo hace a propósito, porque también corre en producción.
- La única forma de entrar sin Microsoft es el acceso de emergencia (`portal:emergency-account`). Ese acceso crea un `super_admin`, que ve el menú «Administración», así que no muestra lo que ve un colaborador.
- Para el capítulo 2 hacen falta dos cuentas vinculadas, una en cada empresa.

La sección 9 propone cómo resolverlo sin que nada llegue a producción.

### Cómo capturar

- **Capturas fijas en PNG.** Motion Canvas anima imágenes, así que la mayoría de las escenas son capturas fijas, no grabaciones de video.
- **Escritorio:** ventana de 1440 px de ancho, a 2× (en el navegador, con la emulación de dispositivo y factor de escala 2).
- **Celular:** 390 px de ancho, a 2× o 3×, para el corte vertical.
- **Empresa y tema.** Haz una captura por empresa y por tema, según el storyboard (`data-brand="segurex"` o `"derivados"`).
- **Animaciones reales del portal.** Para las pocas escenas que necesitan el movimiento real (el fundido de la Vitrina, la banda de Mercados en marcha), graba un clip corto con la Barra de juegos de Windows (Win + Alt + R) y ponlo en la escena con el nodo `Video`.
- **Nombres de archivo:** cada captura se guarda con el número de su escena, por ejemplo `3.8-sdc-claro-escritorio.png`.
- **Revisión.** Antes de usar una captura, revisa que no haya un dato real, una URL de producción ni una notificación del sistema operativo.

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
  - Genera un audio por escena. Así, si cambia una escena, solo se regraba esa.
- **Requisito:** un recurso de Speech en la suscripción de Azure de la empresa. Lo crea Jhon. La clave nunca va al repositorio.

### Alternativa: voz propia

- Una persona del equipo graba la locución con un micrófono USB en una sala silenciosa.
- **Ventaja:** suena más cercana.
- **Costos:**
  - cada cambio obliga a regrabar con la misma voz y el mismo ambiente;
  - hace falta la autorización escrita de esa persona para usar su voz.

---

## 5. Música, mezcla y subtítulos

### Música

- **Fuente:** Pixabay Music.
  - Permite el uso comercial y no exige atribución.
  - Sirve fuera de cualquier plataforma, así que vale para la intranet.
  - Prohíbe vender la pista sola y usarla como marca.
  - Fuente: <https://pixabay.com/service/license-summary/>.
- **Registro:** guarda en `creditos.md` el enlace y la fecha de cada pista, aunque no se exija atribución.
- **Evita:**
  - la biblioteca de música de CapCut (solo vale para TikTok y CapCut);
  - la biblioteca de audio de YouTube, porque su uso fuera de YouTube no está claro.

### Mezcla

- Motion Canvas acepta una sola pista de audio por proyecto. Por cada capítulo:
  1. une los audios de las escenas en orden;
  2. pon la música debajo, entre −20 y −24 dB bajo la voz;
  3. exporta un solo archivo (`NN-tema.wav`).
- Los tiempos de cada escena en Motion Canvas se ajustan a ese audio, con `waitFor` y las marcas de tiempo del editor.

### Subtítulos (.srt)

- **Un `.srt` por capítulo.** El texto es la locución exacta del guion. Los mismos tiempos sirven para los dos formatos.
- **Cómo generarlos:**
  - Azure no entrega `.srt` directamente. Su síntesis da los tiempos de cada palabra y cada frase (`WordBoundary`, o `sentenceBoundaryEnabled` en la síntesis por lotes), y con esos tiempos se arma el `.srt`.
  - Si la voz es propia, usa Whisper (MIT, corre en el equipo; hay que instalarlo, así que primero se pregunta) con `--output_format srt`, y después corrige los textos contra el guion.
- **Reglas:**
  - máximo 2 líneas de 42 caracteres;
  - entre 1 y 7 segundos en pantalla;
  - en el formato vertical, por encima del tercio inferior.

---

## 6. Formatos y exportación

| Formato | Proyecto | Uso | Notas |
|---|---|---|---|
| 1920×1080, 30 fps, MP4 (H.264 + AAC) | `horizontal` | Portal, Teams y correo | Formato principal |
| 1080×1920, 30 fps, MP4 | `vertical` | Celular | Reacomoda, no recorta a ciegas (ver la zona segura en el storyboard) |
| `.srt` | Ambos | Subtítulos | Un archivo por capítulo |
| Miniatura PNG, 1280×720 | `horizontal` | Portada en el portal | Un cuadro exportado por capítulo |

**Nombres de archivo:** `NN-tema-formato-empresa.mp4`, por ejemplo `02-vincular-cuenta-1080x1920-derivados.mp4`.

**Peso:** apunta a menos de 25 MB por capítulo horizontal.

---

## 7. Marca

- **Una empresa por pantalla.** La marca sale de la empresa activa (`data-brand="segurex"` o `"derivados"`). Nunca van el naranja de Segurex y el azul de SDC en el mismo cuadro.
  - La única excepción es el selector de empresa del capítulo 2 (escenas 2.6 y 2.7), que muestra los nombres de las dos empresas como texto.
  - La Vitrina (`/login`) muestra los logos de las dos empresas en una fila. El storyboard la deja fuera de cuadro en las escenas 1.3, 1.4 y 1.6.
- **Logos reales como imagen**, nunca el nombre escrito como texto. El logo de Segurex en blanco va solo sobre fondo oscuro.
- **Tipografía de las capas:** Helvetica o Arial, la misma del portal.
- **Colores de las capas:** solo los de `brand.ts`, copiados de los tokens de la skill (con contraste AA). Nunca texto blanco sobre `#F26522`.
- **Vidrio:** lo pone la captura real. Las capas de texto son sólidas y opacas entre 84 y 92 %, nunca texto directo sobre la foto de fondo.
- **Skills:** `liquid-glass-ryr` y `frontend-marca-sgx-sydc-v2`. Jhon confirmó el 2026-11-05 que la v2 es la vigente para el video.

---

## 8. Pasos, tiempos y costos

| # | Paso | Responsable | Días hábiles |
|---|---|---|---|
| 1 | Aprobar el guion y la voz | Jhon | 1 |
| 2 | Datos de prueba y entrada local (sección 9) | Desarrollo | 1 |
| 3 | Instalar Motion Canvas y armar la plantilla común (rótulo, resaltado, cursor, logo, `brand.ts`) | Desarrollo | 1,5 |
| 4 | Tomar las capturas de los 8 capítulos (escritorio y celular, empresa y tema de cada escena) | Desarrollo | 1,5 |
| 5 | Generar la voz (SSML), revisar la pronunciación, mezclar con la música | Desarrollo + Jhon | 1 |
| 6 | Animar los 8 capítulos en los dos formatos | Desarrollo | 3–4 |
| 7 | Subtítulos `.srt` | Desarrollo | 0,5 |
| 8 | Revisar con 2 o 3 colaboradores (uno de cada empresa) y corregir | Jhon + usuarios | 1 |
| 9 | Exportar y publicar en el portal | Desarrollo + Jhon | 0,5 |
| | **Total** | | **De 10 a 12 días** |

### Costos

| Concepto | Costo |
|---|---|
| Motion Canvas y `@motion-canvas/ffmpeg` (MIT) | US$0 |
| Azure AI Speech, nivel F0 | US$0 (la locución cabe en el cupo gratis) |
| Música de Pixabay | US$0 |
| Grabador (Barra de juegos de Windows) | US$0 |
| **Total en efectivo** | **US$0** |

El costo real es el tiempo del equipo: unas 2 semanas y media de una persona. Elegir Motion Canvas suma más o menos un día frente a Remotion, por las capturas y las capas.

---

## 9. Siguiente paso: preparar la producción

Jhon aprobó esta sección el 2026-11-05. Todavía no hay nada hecho.

**Regla común:** nada de esto llega a producción.

- El servidor despliega `main` con `git pull`, así que **todo archivo que entre a `main` termina en el servidor**, aunque no se use.
- Por eso las piezas 1 y 2 viven en una rama local que **nunca se fusiona**: `video/captura-local`.
- Además, cada pieza se protege sola si alguna vez corre fuera de local.

### 9.1 Datos de demostración sin personas reales

**Archivos (en la rama `video/captura-local`):**

- `database/seeders/VideoDemoSeeder.php`. Usa las factories que ya existen y crea:
  - «Ana Prueba», con una cuenta en Segurex y otra en Soluciones y Derivados, ya vinculadas;
  - «Luis Demo», editor de anuncios;
  - anuncios (uno por confirmar), eventos, tareas y aprobaciones, cumpleaños, personas del directorio y servicios de TI;
  - tasas de mercado, unas que suben y otras que bajan.
- Si hace falta, estados nuevos en `database/factories/UserFactory.php` y `WorkItemFactory.php`.
- `tests/Feature/VideoDemoSeederTest.php`. Comprueba que los correos terminan en `@ejemplo.test` y que el seeder no corre en producción.

**Protecciones:**

- Si `app()->isProduction()`, el seeder se detiene con un error.
- Usa Faker, que en producción no existe (`composer install --no-dev`).
- Todos los correos son `@ejemplo.test`. El correo local va al log (`MAIL_MAILER=log`, lo pone Jhon en su `.env` local).

**Riesgos:**

| Riesgo | Mitigación |
|---|---|
| Correr el seeder en una base real | La guarda de producción, más una base local aparte (SQLite) solo para el video |
| Un nombre generado por Faker se parece al de una persona real | Los nombres visibles en pantalla son fijos y ficticios («Ana Prueba», «Luis Demo»); Faker solo llena lo que no sale en cuadro |
| La rama se queda atrás de `main` | Fusionar `main` en la rama antes de cada sesión de capturas |

### 9.2 Entrar al portal solo en local

**Archivos (en la misma rama):**

- `routes/local.php`, con una ruta `GET /_local/entrar/{user}` que inicia sesión como una cuenta de prueba y redirige al inicio.
- `bootstrap/app.php`, para cargar `routes/local.php` solo si `app()->environment('local')`.
- `tests/Feature/LocalLoginTest.php`, que comprueba que la ruta da 404 fuera de local y que solo acepta cuentas `@ejemplo.test`.

**Protecciones:**

- La ruta no se registra fuera de local.
- Solo acepta usuarios con correo `@ejemplo.test`.
- Vive en una rama que no se fusiona.

**Riesgos:**

| Riesgo | Mitigación |
|---|---|
| Es una puerta sin contraseña | Solo existe con `APP_ENV=local`, solo para cuentas `@ejemplo.test`, y nunca en `main` |
| La ruta se fusiona a `main` por error | La rama se llama `video/captura-local` y su PR, si se abre, queda en borrador con «NO FUSIONAR». Una prueba en `main` puede comprobar que `/_local/entrar` no existe |
| Cambiar de empresa normalmente pide Microsoft | La ruta entra directo a la cuenta de cada empresa. El capítulo 2 muestra la confirmación de Microsoft como ilustración, no como pantalla real |

### 9.3 Instalar Motion Canvas

**Dónde:** fuera de este repositorio. Opciones:

- **Recomendada:** un repositorio aparte, por ejemplo `GrupoRyR/portal-ryr-video`.
- **Alternativa:** una carpeta local sin Git.

Las capturas pesan cientos de MB y no deben inflar el repositorio del portal ni llegar al servidor.

**Archivos (en el repositorio nuevo):**

- `package.json` y `package-lock.json`, con versiones exactas de `@motion-canvas/core`, `@motion-canvas/2d`, `@motion-canvas/ui`, `@motion-canvas/vite-plugin` y `@motion-canvas/ffmpeg`;
- `vite.config.ts`, con los proyectos `horizontal` y `vertical`;
- `src/brand.ts`, con los tokens de la sección 7;
- `src/components/`, con el rótulo, el resaltado, el cursor y el logo;
- `src/scenes/NN-*.tsx`, una escena por capítulo y formato;
- `capturas/`, `audio/` y `subtitulos/`.

**Cómo:** con `npm init @motion-canvas@latest` y después `npm install @motion-canvas/ffmpeg`. Jhon aprobó la instalación. Crear el repositorio en GitHub es su decisión.

**Riesgos:**

| Riesgo | Mitigación |
|---|---|
| El proyecto casi no se mantiene (última versión estable: 2024-12-14) | Versiones fijas; el resultado es un MP4 normal; con la licencia MIT se puede mantener una versión propia |
| Se descargan paquetes de npm, incluido un binario de FFmpeg | Instalar solo los paquetes oficiales `@motion-canvas/*`, revisar `npm audit` y no ejecutar scripts de paquetes desconocidos |
| Capturas con datos reales en un repositorio | El repositorio es privado y solo guarda capturas del entorno local; se revisan antes de cada commit |

---

## 10. Decisiones de Jhon

| # | Decisión | Respuesta |
|---|---|---|
| 1 | Herramienta | Motion Canvas (gratis), 2026-11-05 |
| 2 | Preparación de la sección 9: rama `video/captura-local` sin fusionar y repositorio aparte para Motion Canvas | Aprobada, 2026-11-05 |
| 3 | Marca | v2 (`frontend-marca-sgx-sydc-v2`), 2026-11-05 |
| 4 | Voz: Azure (Salomé o Gonzalo) o voz propia | Pendiente |
