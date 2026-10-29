# Nuevo inicio, fase 1: base del inicio

Fase 1 del nuevo inicio, después de las cuentas vinculadas (`odd/tasks/cuentas-vinculadas.md`). Plan: «Fases siguientes», punto 1, del plan del nuevo inicio aprobado por Jhon (prototipo aprobado por el gerente: https://claude.ai/artifact/V6aNj3e3ycJDz4fK4g2oFQ).

## Objetivo

Un inicio que responda «qué hay hoy» en un vistazo: festivos de Colombia, fechas de la empresa, anuncios y accesos a Microsoft 365, con un buscador `Ctrl K` sobre apps, anuncios y fechas.

## Problema y por qué

- Hoy el inicio solo muestra apps y anuncios; las fechas importantes (festivos, cierres, fechas de cada empresa) viven en correos y calendarios sueltos.
- El gerente aprobó un prototipo con una columna «Mi día» y una búsqueda rápida.
- Los festivos cambian cada año (Ley Emiliani y Semana Santa), así que se calculan en código y no se cargan a mano.

## Alcance

- WU1: festivos de Colombia calculados en código (sin interfaz ni migración).
- WU2: fechas por empresa en Admin.
- WU3: nueva distribución del inicio con la columna «Mi día» y el menú «Secciones» en el celular.
- WU4: búsqueda `Ctrl K` con un índice JSON incrustado.

## Fuera de alcance

- Etiquetas de «puente» (no se construyen hasta que Jhon decida).
- Búsqueda de personas (bloqueada, ver preguntas).
- «Ver como» en el inicio.

## Restricciones

- Sin Livewire ni Alpine en `/`.
- Lighthouse ≥ 90 en el celular.
- Solo cron; sin colas ni procesos largos.
- Las pruebas nunca usan la red (`Http::fake`).
- Zona horaria `America/Bogota`, tomada de `config('app.timezone')`.
- Presupuesto de consultas del inicio ≤ 12 (`HomeScreenTest`).
- `public/build` va en el repositorio: clases nuevas de Tailwind piden `npm run build`.
- Las pruebas corren con `APP_KEY` en el entorno, porque `.env*` está bloqueado.
- Commits convencionales sin atribución de IA.

## Tareas

- [ ] WU1. **PR1, festivos de Colombia.** Ruta: delegada (escritor), porque son 2 o más archivos no triviales: `Holiday`, `ColombianHolidays` y sus pruebas.
  - `App\Calendar\Holiday`: fecha (`CarbonImmutable`) y nombre.
  - `App\Calendar\ColombianHolidays`: `forYear()`, `isHoliday()`, `nextOnOrAfter()` (cruza el fin de año) y `between()`. Pura: sin base de datos ni red.
  - Reglas: Ley 51 de 1983 (fijos y trasladados al lunes), Pascua gregoriana (Meeus/Jones/Butcher) y las leyes nuevas que cambian la lista.
  - Pruebas con las listas completas de 2025, 2026 y 2027.
- [ ] WU2. **PR2, fechas por empresa en Admin.** Bloqueada: decisión de Jhon.
- [ ] WU3. **PR3, distribución del inicio.** Ruta: por decidir al empezar.
  - Columna «Mi día»: festivos, tarjeta de anuncios y enlaces de Microsoft 365.
  - Menú «Secciones» (`<details>`) en el celular.
  - Sin «Ver como». Consultas del inicio ≤ 12.
- [ ] WU4. **PR4, búsqueda `Ctrl K`.** Ruta: por decidir al empezar.
  - Índice JSON incrustado que reutiliza `App\Catalog\AppSearch::normalize`.
  - Apps, anuncios visibles (`Announcement::scopeVisibleTo`) y fechas.
  - Búsqueda de personas: bloqueada, decisión de Jhon.

## Preguntas para Jhon

1. Etiquetas de «puente»: ¿se muestran? (no se construyen todavía).
2. WU2: ¿quién administra las fechas de cada empresa?, ¿cada empresa tiene las suyas?, ¿fechas que se repiten cada año o de una sola vez?
3. Búsqueda de personas: Ley 1581 de 2012 (datos personales) y hoy solo existen en el portal las personas que ya iniciaron sesión.
4. ¿La barra inferior del celular se queda al lado de «Secciones»?

## Criterios de aceptación

- WU1: las listas de 2025, 2026 y 2027 coinciden con las fuentes; `nextOnOrAfter` cruza del 31 de diciembre al 1 de enero; años lejanos (2000, 2100) no fallan.
- WU3: el inicio no hace más de 12 consultas y no carga Livewire ni Alpine.
- WU4: la búsqueda encuentra apps, anuncios visibles para quien busca y fechas, sin tildes ni mayúsculas.

## Chequeos

- `APP_KEY=base64:$(php -r 'echo base64_encode(random_bytes(32));') php artisan test --compact`, con la suite completa en verde.
- `vendor/bin/pint --test`.
- `/code-review` en cada PR.

## Entrega

- **Estrategia:** `ask-on-risk`, con cadena `stacked-to-main` (ya elegida por el coordinador): un PR por WU, encadenados encima de #68 (`feat/cuentas-vinculadas-4-interfaz`).
- **Ramas:** `feat/inicio-1-festivos` (WU1), desde `feat/cuentas-vinculadas-4-interfaz`.
- **Pronóstico:** WU1 ~400 líneas (más de la mitad, pruebas).
- **Revisión nativa (RDD):** apagada (global: off). Se usan los chequeos ordinarios.

## Progreso

- 2026-10-29: plan creado. Rama `feat/inicio-1-festivos` desde `feat/cuentas-vinculadas-4-interfaz`.

## Siguiente paso

WU1: pruebas primero (RED), luego `ColombianHolidays` (GREEN).
