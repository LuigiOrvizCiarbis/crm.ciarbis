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


## Agenda de turnos con IA

La IA puede agendar reuniones directamente o dejarlas pendientes de aprobación del vendedor. La aprobación valida que el responsable y el horario no hayan cambiado durante la consulta de disponibilidad; si cambiaron, hay que volver a aceptar con los datos actuales.

- Los trabajos de sincronización atrasados no publican reuniones canceladas, rechazadas ni pendientes de aprobación.
- Los eventos de día completo bloquean los horarios según la zona del calendario de Google, aunque la agenda del CRM use otra zona.
- Cada llamada a una herramienta del bot comprueba el presupuesto de tiempo disponible.
- En la interfaz, un error de guardado permite reintentar sin perder los cambios, y un refresco atrasado no restaura propuestas ya resueltas.
