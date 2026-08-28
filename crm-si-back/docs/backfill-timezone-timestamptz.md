# Backfill opcional: timestamps desfasados en difusiones y automatizaciones

**Estado: NO ejecutado. Probablemente no haga falta nunca.**

Este documento guarda el SQL de un backfill que se decidió *no* correr. Se
deja escrito por si algún día hace falta, no como tarea pendiente.

## Contexto

Antes del fix de `HasTimezoneAwareDates` (ver `app/Models/Concerns/`), las
escrituras con `now()` a columnas `timestamptz` perdían el offset: PHP corre en
`America/Argentina/Buenos_Aires`, la sesión de Postgres en UTC, y Eloquent
serializaba sin zona. Cada valor quedó **3 horas adelantado** respecto del
instante real.

El bug de cara al usuario (difusiones programadas disparándose 3 horas tarde) ya
está resuelto en el código. Lo que este backfill corregiría son solo los
**registros históricos** anteriores al fix.

## Por qué se decidió no correrlo

El volumen afectado es marginal:

| tabla | filas |
|---|---|
| `broadcast_campaigns` | 2 |
| `broadcast_recipients` | 3 |
| `automation_runs` | 53 |
| `automation_action_runs` | 53 |

Son 2 difusiones (una de prueba, una real) y automatizaciones ya terminadas. El
único efecto de corregirlas sería la coherencia del historial en el panel, que
nadie consulta. A cambio exige parar el scheduler y los workers, y tomar backup.

Si en el futuro alguien audita esos registros y las horas no cierran, es por
esto: los valores anteriores al fix están 3 horas adelantados.

## Qué NO tocar (lo importante)

Estas columnas **ya están correctas**. Aplicarles el backfill las rompería:

- **`automation_runs.scheduled_for`** — `DateAutomationScheduler.php` la escribe
  con `->utc()` explícito, nunca pasó por el bug.
- **`broadcast_campaigns.scheduled_at` cuando `launch=scheduled`** — viene del
  front como ISO con `Z` y `Carbon::parse` conserva la zona. Solo está mal
  cuando `launch=now`, caso en que se escribió con `now()`.

`launch` no se persiste, así que las filas `launch=now` se identifican porque
`scheduled_at = started_at`. **Ese match hay que capturarlo ANTES de tocar
`started_at`**, o deja de coincidir y esas filas quedan sin corregir.

## Procedimiento

Con **scheduler y workers detenidos** (si no, una fila escrita a mitad de camino
queda mal), y con backup tomado:

```sql
-- 1. Capturar la frontera. NO usar created_at: también está corrupto.
SELECT max(id) FROM broadcast_campaigns;      -- :campaigns_max
SELECT max(id) FROM broadcast_recipients;     -- :recipients_max
SELECT max(id) FROM automation_runs;          -- :runs_max
SELECT max(id) FROM automation_action_runs;   -- :action_runs_max

BEGIN;

-- 2. PRIMERO scheduled_at de las launch=now, mientras todavía iguala started_at.
UPDATE broadcast_campaigns
SET scheduled_at = (scheduled_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Argentina/Buenos_Aires'
WHERE id <= :campaigns_max
  AND scheduled_at = started_at;

-- 3. Recién ahora el resto de broadcast_campaigns.
UPDATE broadcast_campaigns
SET started_at   = (started_at   AT TIME ZONE 'UTC') AT TIME ZONE 'America/Argentina/Buenos_Aires',
    completed_at = (completed_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Argentina/Buenos_Aires',
    created_at   = (created_at   AT TIME ZONE 'UTC') AT TIME ZONE 'America/Argentina/Buenos_Aires',
    updated_at   = (updated_at   AT TIME ZONE 'UTC') AT TIME ZONE 'America/Argentina/Buenos_Aires'
WHERE id <= :campaigns_max;

UPDATE broadcast_recipients
SET queued_at  = (queued_at  AT TIME ZONE 'UTC') AT TIME ZONE 'America/Argentina/Buenos_Aires',
    sent_at    = (sent_at    AT TIME ZONE 'UTC') AT TIME ZONE 'America/Argentina/Buenos_Aires',
    created_at = (created_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Argentina/Buenos_Aires',
    updated_at = (updated_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Argentina/Buenos_Aires'
WHERE id <= :recipients_max;

-- automation_runs: todas MENOS scheduled_for.
UPDATE automation_runs
SET queued_at   = (queued_at   AT TIME ZONE 'UTC') AT TIME ZONE 'America/Argentina/Buenos_Aires',
    started_at  = (started_at  AT TIME ZONE 'UTC') AT TIME ZONE 'America/Argentina/Buenos_Aires',
    finished_at = (finished_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Argentina/Buenos_Aires',
    created_at  = (created_at  AT TIME ZONE 'UTC') AT TIME ZONE 'America/Argentina/Buenos_Aires',
    updated_at  = (updated_at  AT TIME ZONE 'UTC') AT TIME ZONE 'America/Argentina/Buenos_Aires'
WHERE id <= :runs_max;

UPDATE automation_action_runs
SET delivery_started_at   = (delivery_started_at   AT TIME ZONE 'UTC') AT TIME ZONE 'America/Argentina/Buenos_Aires',
    delivery_confirmed_at = (delivery_confirmed_at AT TIME ZONE 'UTC') AT TIME ZONE 'America/Argentina/Buenos_Aires',
    started_at            = (started_at            AT TIME ZONE 'UTC') AT TIME ZONE 'America/Argentina/Buenos_Aires',
    finished_at           = (finished_at           AT TIME ZONE 'UTC') AT TIME ZONE 'America/Argentina/Buenos_Aires',
    created_at            = (created_at            AT TIME ZONE 'UTC') AT TIME ZONE 'America/Argentina/Buenos_Aires',
    updated_at            = (updated_at            AT TIME ZONE 'UTC') AT TIME ZONE 'America/Argentina/Buenos_Aires'
WHERE id <= :action_runs_max;

COMMIT;
```

## Verificación

Referencia conocida: los 3 destinatarios de la campaña 2 (`test1`, 19/08) tienen
`sent_at = 12:55:31+00`. El envío real ocurrió **12:55 hora argentina**,
confirmado contra el `timestamp` epoch del webhook de WhatsApp
(`1787154999` = 12:56:39 ART, la respuesta del contacto un minuto después).

Post-backfill deberían quedar en `15:55:31+00`, que es 12:55 ART.

No es reversible automáticamente: si algo sale mal, restaurar del backup.
