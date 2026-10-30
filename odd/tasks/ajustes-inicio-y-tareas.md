# Ajustes decididos por Jhon el 2026-10-30

Cambios pequeños y medianos que Jhon decidió al revisar las preguntas del inicio (`odd/tasks/inicio-fase-1.md`, `odd/tasks/inicio-fase-2.md`) y de la API de tareas (`odd/tasks/tareas-aprobaciones.md`).

## Objetivo

Aplicar cinco decisiones de Jhon:

1. La API de tareas rechaza la misma versión con otro tipo o persona.
2. «Puente festivo» en los festivos.
3. Fechas propias de cada empresa que se repiten cada año.
4. La lectura obligatoria se cuenta por persona.
5. Recordatorios a quien no ha leído.

## Problema y por qué

- **T1.** Hoy la misma `version` con otro `kind` o `assignee` responde `unchanged` y lo ignora. Jhon prefiere un error, para que la app note su fallo.
  - `kind` y `assignee` ya no pueden cambiar con una versión mayor (`422`), así que se usa el mismo `422`. La app que quiera mover una tarea a otra persona la borra y la crea con otro `external_id`.
- **T2.** Un festivo en lunes o viernes da un fin de semana de 3 días, que en Colombia se llama «puente».
- **T3.** Las fechas de cada empresa (aniversario, cierre contable, entrega de dotación) se repiten cada año.
  - Hoy un evento es de una sola vez.
  - Se reutilizan los eventos (#73): mismo permiso (Jhon: «un permiso» para eventos y anuncios), mismas empresas destinatarias y misma Auditoría. Se agrega «Se repite cada año», solo para eventos de todo el día. Así no hace falta otra pantalla de Admin.
- **T4.** Jhon decidió contar por persona: quien tiene cuenta en dos empresas confirma una sola vez.
- **T5.** Jhon quiere recordatorios. Son correos individuales desde el buzón que el portal ya usa para sus avisos (`MAIL_FROM_ADDRESS` por Graph), no a las listas, así que no dependen de la pregunta del buzón de las listas.

## Alcance

- **T1:** en `feat/tareas-1-api` (#74): misma versión con otro `kind` o `assignee` responde `422`. El contrato §3.3 se actualiza.
- **T2:** rama `feat/inicio-6-puentes` sobre #76: «Puente festivo» en «Próximas fechas» y en el buscador.
- **T3:** rama `feat/inicio-7-fechas-anuales` sobre T2: `events.repeats_yearly`; la próxima ocurrencia en el inicio, en el buscador y en `/eventos`; `RRULE:FREQ=YEARLY` en el `.ics`.
- **T4:** rama `feat/inicio-8-lectura-por-persona` sobre T3: estado «Leído» y reporte «X de Y personas».
- **T5:** rama `feat/inicio-9-recordatorios` sobre T4: botón «Enviar recordatorio» en el reporte. El cron envía en lotes.

## Fuera de alcance

- El correo a las listas de cada empresa y el resumen de los lunes (sigue pendiente qué buzón usar).
- Recordatorios automáticos sin que el editor los pida.
- Recurrencias que no sean anuales.

## Restricciones

- `person_id` no autoriza nada. T4 lo usa solo para agrupar, dentro de un ayudante de `App\Auth\LinkedAccounts`, y con una entrada nueva y razonada en `tests/Unit/Auth/PersonNeverAuthorizesTest.php`. Ver un anuncio sigue siendo por cuenta (`Announcement::scopeVisibleTo`).
- Inicio: sin Livewire ni Alpine y ≤ 12 consultas (`HomeScreenTest`; hoy ya está en 12). Nada de esto puede sumar consultas a `/`.
- Correos: patrón de los avisos actuales (`afterCommit`, `sendNow`, try/catch, `MailBrand`). Un fallo de correo nunca rompe la pantalla. Solo cron, sin colas.
- Pruebas con `APP_KEY` en el entorno y `Http::fake`/`Mail::fake`, nunca red real. `public/build` va en el repositorio.
- Commits convencionales sin atribución de IA.

## Tareas

- [x] T1. **API de tareas: `422` con la misma versión y otro tipo o persona** (#74). Ruta: delegada (escritor), pocas líneas pero código, prueba y contrato. Hecho: `633629f` y `b31b06c` en `feat/tareas-1-api`, empujados a #74; contrato v0.1.1. RED 3 de 97, GREEN 97/97; suite 1.359/1.359; Pint limpio. Revisión: el coordinador leyó el diff (pequeño) y repitió las 97 pruebas.
- [ ] T2. **«Puente festivo».** Ruta: delegada (escritor).
- [ ] T3. **Fechas que se repiten cada año.** Ruta: delegada (escritor).
- [ ] T4. **Lectura obligatoria por persona.** Ruta: delegada (escritor).
- [ ] T5. **Recordatorios.** Ruta: delegada (escritor).
- [ ] Revisión independiente y arreglos de cada PR.

## Preguntas para Jhon

- Ninguna por ahora.
- Las decisiones de diseño del coordinador se anotan en cada registro: `422` y no `409`; fechas anuales dentro de eventos; un correo por persona.

## Criterios de aceptación

- **T1:** la misma versión con otro `kind` o `assignee` responde `422`, con el mismo error que una versión mayor. La misma versión sin cambios sigue en `unchanged`.
- **T2:** un festivo en lunes o viernes muestra «Puente festivo». En martes, miércoles o jueves sigue diciendo «Festivo».
- **T3:** un evento anual aparece cada año en su fecha, en el inicio, en el buscador y en `/eventos`. El `.ics` lo repite.
- **T4:** confirmar en una cuenta marca «Leído» en todas las cuentas vinculadas de esa persona que ven el anuncio. El reporte cuenta personas.
- **T5:** el editor envía un recordatorio como máximo una vez cada 24 horas por anuncio. Le llega un correo a cada persona que falta. El reporte muestra cuándo y a cuántas personas.

## Chequeos

- RED antes de GREEN, la suite completa y Pint en cada rama.
- Capturas cuando cambia la interfaz.
- Una revisión independiente por PR.

## Entrega

- PR apilados, sin fusionar ni desplegar.
- T1 se suma a #74, con commits nuevos y sin reescribir la historia.

## Progreso

- 2026-10-30: documento creado.
- 2026-10-30: T1 hecho y empujado a #74.

## Siguiente paso

T2 en `feat/inicio-6-puentes`.
