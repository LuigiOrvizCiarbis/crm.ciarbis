# crm

SI CRM — CRM Omnicanal para ventas con IA

📌 Regla general

main → intocable salvo merges aprobados desde test.
test → solo se mergea desde dev.
dev → todo lo nuevo va acá.
dev-luigi → rama exclusiva Luigi.

📌 Flujo de un dev normal

Crea su rama: feature/chat-improvements
Trabaja ahí.
Hace MR a dev.
Dev se testea → se mergea a test → si funciona → main.

## Probar una rama sin mergear

`ops/worktree/wt.sh` levanta un worktree (back + front) como stack Docker aislado, en paralelo al principal:

```bash
ops/worktree/wt.sh up lbugio/mi-rama   # o la carpeta del worktree; sin argumento, el worktree actual
ops/worktree/wt.sh ls                  # stacks levantados y sus URLs
ops/worktree/wt.sh down lbugio/mi-rama # borra contenedores, volúmenes e imágenes del stack
```

- Cada stack usa un bloque de puertos propio: front en `https://localhost:90N0` y API en `http://localhost:90N1` (`ls` muestra los valores exactos).
- La primera vez copia la DB del stack principal si está corriendo; si no, arranca vacía con seeders. Después aplica las migraciones de la rama.
- El scheduler no corre por defecto, para no duplicar automatizaciones sobre los datos copiados; `up --scheduler` lo suma.
- El código se ve en vivo (Next recarga solo y PHP toma los cambios en cada request). Volvé a correr `up` (~20 s, no recopia la DB) si la rama trae migraciones, cambia `composer.json`/`package.json`, o tocaste código que corren los jobs en cola: los workers no recargan solos.
- La sesión del navegador es distinta por puerto: hay que loguearse de nuevo en el front del stack, con los mismos usuarios de la DB local.
- Logs del back: `docker compose -p wt-<carpeta> logs -f app`. Para empezar con una copia fresca de la DB: `down` y después `up`.
- Necesita los `.env` del worktree, que Orca copia según `.worktreeinclude`.
- Al borrar el worktree desde Orca, el archive hook de `orca.yaml` corre `down` solo (por CLI: `orca worktree rm --run-hooks`). Si el worktree se borró por otro lado, `ls` lo muestra como "(worktree borrado)" y `down <carpeta>` lo limpia igual.


## Agenda de turnos con IA

La IA puede agendar reuniones directamente o dejarlas pendientes de aprobación del vendedor. La aprobación valida que el responsable y el horario no hayan cambiado durante la consulta de disponibilidad; si cambiaron, hay que volver a aceptar con los datos actuales.

- Los trabajos de sincronización atrasados no publican reuniones canceladas, rechazadas ni pendientes de aprobación.
- Los eventos de día completo bloquean los horarios según la zona del calendario de Google, aunque la agenda del CRM use otra zona.
- Cada llamada a una herramienta del bot comprueba el presupuesto de tiempo disponible.
- En la interfaz, un error de guardado permite reintentar sin perder los cambios, y un refresco atrasado no restaura propuestas ya resueltas.

### Bloquear mi agenda

Desde **Tareas → Calendario → Bloquear mi agenda**, o desde las acciones del encabezado de un chat (menú **Más acciones** en móvil), cada vendedor puede bloquear únicamente su calendario principal conectado en el workspace activo:

- **Por hoy:** desde ahora hasta la próxima medianoche del calendario.
- **Franja horaria:** inicio y fin en la zona horaria de Google, indicada junto al email.
- **Días completos:** una fecha o un rango, con la última fecha incluida.

El bloqueo se guarda directamente en Google como evento privado **No disponible**, sin invitados ni Meet y sin crear una tarea. La IA deja de ofrecer/reservar ese intervalo; tampoco se pueden aprobar propuestas superpuestas mientras siga bloqueado. Si ya hay eventos o propuestas, se pide confirmar **Bloquear igualmente** y se conservan intactos, sin cancelar ni avisar automáticamente a los clientes.

En **Mis bloqueos** se puede consultar otro período (hasta 93 días por consulta), abrir el evento en Google o **Desbloquear** con confirmación. No modifica el horario semanal del asistente ni los calendarios del resto del equipo. Si Google no confirma una escritura, se muestra un error recuperable; reintentar la misma intención no duplica el evento.

La API autenticada expone `GET/POST /api/google-calendar/blocks` y `DELETE /api/google-calendar/blocks/{eventId}`. Los eventos llevan marcadores privados de usuario/workspace; el backend verifica esos marcadores antes de devolverlos o eliminarlos. Bloqueos, reservas y aprobaciones comparten un lock por vendedor de 60 segundos y un presupuesto HTTP acumulado de 45 segundos.
