# Monitoreo de producción — Netdata

Dashboard de métricas y alertas por email para los **7 containers de producción** del VPS.

**Dashboard:** https://monitor.sicrmapp.com (basic auth)
**Alertas:** llegan a `contacto@atlanticosoft.dev`

---

## Qué se monitorea

Sólo **producción**. Staging (5 containers) y `erp-woo-sync` quedan completamente fuera: no se grafican, no se guardan sus métricas y no generan alertas. Siguen corriendo normalmente en el VPS — Netdata simplemente los ignora.

| Monitoreado | Ignorado |
|---|---|
| `crm-si-front-app-1` | `crm-si-front-staging-app-1` |
| `crm-si-back-app-1` | `crm-si-back-staging-app-1` |
| `crm-si-back-scheduler-1` | `crm-si-back-staging-reverb-1` |
| `crm-si-back-queue-worker-1` | `crm-si-back-staging-db-1` |
| `crm-si-back-reverb-1` | `crm-si-back-staging-redis-1` |
| `crm-si-back-redis-1` | `erp-woo-sync` |
| `crm-si-back-db-1` | |

Además se monitorea el **host**: CPU, RAM, disco, red y OOM kills.

> **Si un container excluido pasa a producción**, hay que sacarlo de los patrones en `netdata.conf` **y** en `health.d/docker.conf`. Si no, corre sin gráficos y sin alertas — que es justamente lo que no se quiere de algo en producción.

---

## Los dos caminos de exclusión (importante)

Netdata mira los containers por **dos vías independientes**, y cada una se filtra por separado. Esto no es evidente y costó descubrirlo:

| Vía | Qué aporta | Dónde se filtra |
|---|---|---|
| Plugin de **cgroups** | CPU, RAM, disco por container | `netdata.conf` → `[plugin:cgroups]` |
| Colector **go.d/docker** | Estado: running / exited / unhealthy | `health.d/docker.conf` → `chart labels` |

**El filtro de cgroups no afecta al colector de Docker.** Verificado en el VPS: con sólo el primero, igual saltaron alertas `docker_container_unhealthy` por `erp-woo-sync` y `crm-si-back-staging-reverb-1`. Por eso existen los dos.

### Cuidado con la directiva de cgroups

En `[plugin:cgroups]` hay tres directivas parecidas y **sólo una sirve**:

- `enable by default cgroups matching` — filtra por el nombre **crudo** del cgroup, que en Docker es `system.slice_docker-<hash>.scope`. El nombre del container **no aparece ahí**, así que `!*staging*` no matchea nada. Peor: su valor por defecto trae exclusiones necesarias (`!*.mount`, `!*.slice`, `!*.service`…) y pisarlo hace que Netdata intente monitorear cgroups del sistema que no son containers. **No tocar.**
- `enable by default cgroups names matching` — filtra por el nombre **ya resuelto** vía `docker.sock` (`crm-si-back-app-1`). **Es la correcta.**

Sintaxis de simple patterns: negaciones primero, `*` al final. Se evalúa en orden y gana la primera coincidencia; con el `*` adelante no excluiría nada.

---

## Instalación

Orden importa: el DNS tiene que existir antes de certbot.

### 1. DNS

Registro **A**: `monitor.sicrmapp.com` → `72.62.12.121`. Verificar antes de seguir:

```bash
dig +short monitor.sicrmapp.com     # debe devolver 72.62.12.121
```

### 2. Credenciales SMTP en el VPS

```bash
sudo cp msmtprc.example /etc/netdata-msmtprc
sudo nano /etc/netdata-msmtprc      # poner la password real de info@sicrmapp.com
                                    # (la misma que MAIL_PASSWORD en crm-si-back/.env)
sudo chown root:root /etc/netdata-msmtprc
sudo chmod 600 /etc/netdata-msmtprc # msmtp rechaza el archivo si es más permisivo
```

No hace falta instalar `msmtp` en el host: **la imagen de Netdata ya lo trae**, con `/usr/sbin/sendmail` apuntando a él.

### 3. Config de notificaciones

```bash
cp health_alarm_notify.conf.example health_alarm_notify.conf
```

No lleva secretos (las credenciales están en `/etc/netdata-msmtprc`), pero se copia para poder cambiar destinatarios sin tocar el repo. **No se commitea.**

### 4. Levantar Netdata

```bash
cd ops/monitoring
docker compose -f docker-compose.netdata.yml up -d
curl -fsS http://127.0.0.1:19999/api/v1/info    # debe responder
```

### 5. Basic auth

```bash
sudo apt-get install -y apache2-utils
sudo htpasswd -c /etc/nginx/.htpasswd-monitor <usuario>
```

### 6. nginx + certificado

```bash
sudo cp nginx/monitor.sicrmapp.com.conf /etc/nginx/sites-available/monitor.sicrmapp.com
sudo ln -s /etc/nginx/sites-available/monitor.sicrmapp.com /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d monitor.sicrmapp.com
```

UFW no se toca: sigue permitiendo sólo 22/80/443. El 19999 escucha en `127.0.0.1` y nginx es la única entrada.

---

## Verificación

```bash
# 1. Exactamente 7 containers, todos de prod (ningún staging ni woo-sync)
curl -s http://127.0.0.1:19999/api/v1/charts | python3 -c "
import json,sys
d=json.load(sys.stdin); n=set()
for k,c in d.get('charts',{}).items():
    if c.get('context','').startswith('cgroup.'):
        l=c.get('chart_labels',{}) or {}
        v=l.get('container_name') or l.get('cgroup_name')
        if v: n.add(v[0] if isinstance(v,list) else v)
[print(' ',x) for x in sorted(n)]; print('TOTAL:',len(n))"

# 2. Las alertas de estado también filtradas (otras 7)
curl -s 'http://127.0.0.1:19999/api/v1/alarms?all' | python3 -c "
import json,sys
d=json.load(sys.stdin); n=set()
for k,v in d.get('alarms',{}).items():
    if v.get('name')=='docker_container_unhealthy':
        n.add(v.get('chart','').replace('docker_local.container_','').replace('_health_status',''))
[print(' ',x) for x in sorted(n)]; print('TOTAL:',len(n))"

# 3. No expuesto: desde afuera del VPS esto DEBE fallar
curl -I --max-time 5 http://72.62.12.121:19999

# 4. Mail de prueba real
docker exec netdata sh -c 'printf "To: contacto@atlanticosoft.dev\nFrom: info@sicrmapp.com\nSubject: prueba\n\ntest\n" | /usr/sbin/sendmail -t'
docker exec netdata tail -3 /var/log/netdata/msmtp.log   # buscar smtpstatus=250
```

El dashboard en `https://monitor.sicrmapp.com` debe pedir usuario/contraseña y mostrar los 7 containers por nombre.

> **El primer mail puede caer en spam:** sale de `sicrmapp.com` y llega a `atlanticosoft.dev`, dominios distintos. Revisar esa carpeta antes de dar la config por rota.

---

## Alertas configuradas

| Alerta | Umbral | Archivo |
|---|---|---|
| RAM de container | warn 75%, crit 85% del límite | `health.d/containers.conf` |
| CPU de container (10 min) | warn 85%, crit 95% | `health.d/containers.conf` |
| Container unhealthy | healthcheck fallando | `health.d/docker.conf` |
| Container caído (exited) | cualquiera | `health.d/docker.conf` |
| Disco del host | warn 75%, crit 85% | `health.d/host.conf` |
| CPU del host (10 min) | warn 75%, crit 85% | `health.d/host.conf` |
| OOM kill | cualquiera en 5 min | `health.d/host.conf` |

Todas con `delay` de varios minutos: un pico de 10 segundos no dispara mail.

**Las alarmas stock de containers vienen con `to: silent`** — existen pero no notifican a nadie. Los archivos de `health.d/` las redefinen con `to: sysadmin`; sin eso no llegaría ningún mail aunque el SMTP funcione.

---

## Notas operativas

### Build cache de Docker (resuelto 2026-09-19)

Llegó a **18 GB** creciendo ~2 GB/día con los deploys. Ya existía un cron semanal (`/etc/cron.d/docker-builder-prune`) que corría bien pero **no limpiaba casi nada**: había caché de hasta 7 meses.

Dos causas, y la segunda no es obvia:

1. **Frecuencia insuficiente.** Semanal contra ~2 GB/día acumula 14 GB entre corridas.
2. **`--filter until=` no sirve para esto.** Filtra por *último uso*, y las capas base se tocan en cada build: Docker las considera "usadas hoy" aunque el contenido tenga meses. Medido: con `until=168h` sobre 18 GB liberó **168 MB**.

Lo que sí funciona es **`--reserved-space`**, un techo duro que conserva los N GB más recientes sin importar cuándo se usaron. Con 6 GB liberó **9,72 GB** (caché 17,9 → 8,2 GB; disco 24% → 19%).

**Configuración actual, en dos capas:**

| Capa | Dónde | Qué hace |
|---|---|---|
| Cron diario 05:38 | `/etc/cron.d/docker-builder-prune` → `/usr/local/bin/docker-prune.sh` | `docker builder prune -af --reserved-space 6GB`, loguea a `/var/log/docker-prune.log` |
| Techo del daemon | `/etc/docker/daemon.json` → `builder.gc` | `defaultReservedSpace: 6GB`, `maxUsedSpace: 10GB` — Docker se autorregula aunque el cron falle |

> **Nombres de flags según versión (Docker 29 aquí):** en CLI es `--reserved-space`, **no** `--keep-storage` (removido). En `daemon.json` son `defaultReservedSpace` / `maxUsedSpace`, **no** `defaultKeepStorage` (obsoleto). Con el nombre viejo el daemon ignora la config **sin avisar**.

El cron anterior mandaba todo a `/dev/null`; por eso nadie notó en meses que no alcanzaba. Ahora deja rastro en `/var/log/docker-prune.log`.

### Postgres: límite subido de 256 MB a 1 GB (2026-09-19)

Monitoreando se detectó que `crm-si-back-db-1` vivía al **97% real** de sus 256 MB (`docker stats` mostraba 71% porque no cuenta el page cache) con **12.645 eventos de `memory.max` throttling** y cero OOM kills. Postgres tocaba el techo del cgroup constantemente y tiraba caché que necesitaba, releyéndola del disco: problema de rendimiento, no de estabilidad — por eso llevaba 11 días sin caerse.

Cambios en `crm-si-back/docker-compose.prod.yml`:

| Parámetro | Antes | Ahora | Por qué |
|---|---|---|---|
| `memory` | 256M | **1G** | El host tiene 15,9 GB y sólo 2,9 GB limitados |
| `max_connections` | 200 | **100** | Se midieron 8 conexiones reales; PHP no usa pool persistente |
| `effective_cache_size` | 4GB (default) | **768M** | No reserva memoria: le dice al planner cuánta caché suponer. Sobreestimaba 16× |
| `shared_buffers` | 128MB (implícito) | **128MB** (explícito) | Ya era correcto; se fija para que no dependa del default |

**Resultado medido:** throttling de 12.645 → **0**. Uso estabilizado en ~6% de 1 GB.

> Cuidado al editar el `command:` de ese servicio: con `command: >` (escalar plegado) los flags `-c` se colapsan y Postgres arranca con la config por defecto **sin avisar**. Va como lista YAML explícita.

### CPU del scheduler y del backend (2026-09-19)

Netdata y `cpu.stat` mostraron que dos containers estaban **CPU-throttled**: el kernel los frenaba contra su cuota.

| Container | Antes | Ahora | Throttling |
|---|---|---|---|
| `scheduler` | 0.25 CPU / 128M | **1.0 CPU / 256M** | 51,8% → **3,0%** |
| `app` (backend) | 1.0 CPU / 512M | **1.5 CPU** / 512M | 15,3% → **0,0%** |

**Por qué el scheduler gastaba tanto:** `schedule:list` muestra **3 tareas por minuto** (`automations:dispatch-due`, `broadcasts:dispatch-due`, `mail:sync-channels`). Cada una levanta un proceso PHP nuevo que carga el framework entero. Con 0.25 CPU no alcanzaba, y las tareas llegaban tarde.

Su patrón es **de ráfaga**: 0,13% de CPU en reposo, pico de ~200% durante un segundo al disparar. La cuota nueva absorbe el pico sin frenarlo.

**Efecto colateral:** el load del host **bajó** de 1,67 a 1,03. Al no frenarlos, terminan antes y liberan CPU.

> **Sobre el overcommit:** hay 5,75 CPUs asignadas sobre 4 físicas, más staging sin límite. Es intencional — ninguno usa su cuota de forma sostenida. Por eso `scheduler` quedó en 1.0 y no 2.0, y `app` en 1.5: dar margen al pico sin competir de forma sostenida.

### Redis: techo de memoria (2026-09-19)

Venía con **`maxmemory 0`** (sin techo) y **`noeviction`**: crecía hasta chocar con el límite del container y ahí Docker lo mataba. No es sólo caché — Redis guarda **sesiones y la cola de jobs**, así que un OOM se lleva los jobs encolados.

Ahora: `--maxmemory 96mb --maxmemory-policy volatile-lru` (uso real: 1,85 MB; pico 6 h: 9 MB, así que el techo es holgado). 96 MB = 75% del límite de 128 MB, para que Redis libere **antes** de que el cgroup lo mate.

> **`volatile-lru`, no `allkeys-lru`.** Laravel usa db0 para colas/sesiones y db1 para caché (`config/database.php`: `REDIS_DB=0`, `REDIS_CACHE_DB=1`), pero `maxmemory-policy` es **global, no por base**. `volatile-lru` desaloja sólo claves con TTL: el caché lo tiene, los jobs encolados no. Con `allkeys-lru`, Redis borraría jobs pendientes al llenarse.

### Volúmenes huérfanos (2026-09-19)

Había 41 volúmenes sin container asociado. Se borraron **39** (`node_modules` y `vendor` de builds viejos): **4,3 GB liberados**, disco 19% → 16%.

> **No usar `docker volume prune` a ciegas acá.** Entre los huérfanos había dos volúmenes con **datos reales** — `crm-si-back_postgres-data-staging` y `crm-si-back_redis-data-staging` — que figuran como huérfanos porque los containers de staging corriendo usan otros. Un prune genérico los habría borrado. Conviene revisar el contenido antes y excluir lo que tenga datos.

### Límites que quedaron holgados a propósito

`queue-worker` (27% de 256M), `front-app` (16% de 512M) y `reverb` (50% de 128M) están sobredimensionados. **Se dejaron así**: el host tiene 13 GB libres, bajarlos no gana nada y un límite ajustado sólo agrega riesgo de OOM en un pico.

### Por qué los umbrales de RAM están en 75/85

Historia útil para no re-tocarlos a ciegas.

La primera medición mostró el scheduler al **95,64%** de sus 128 MB. Veinte minutos después daba **12,5 MiB (~10%)**, y se concluyó que había sido un pico del deploy. Con más datos resultó que **ninguna de las dos lecturas era la historia completa**: su patrón es de ráfaga — casi nada en reposo, picos de hasta 126 MB al disparar tareas. Un muestreo corto lo pinta relajado o al borde según cuándo mire.

Dos lecciones que valen para cualquier container de este stack:

1. **Una medición puntual no define un límite.** Hay que mirar el pico sobre horas (`group=max`), no el valor instantáneo.
2. **`docker stats` no alcanza para diagnosticar.** No cuenta el page cache ni muestra throttling. Los datos que importan están en `/sys/fs/cgroup/`: `memory.events` (campo `max`) y `cpu.stat` (`nr_throttled` vs `nr_periods`).

Los umbrales 75/85 avisan con tiempo de reacción. **Si un container empieza a vivir cerca de su límite, la respuesta correcta es subirle el límite en su compose, no subir el umbral de la alerta** — que es lo que se hizo con Postgres y el scheduler.

### Métricas de red por container

Desactivadas a propósito (`script to get cgroup network interfaces =` vacío en `netdata.conf`). Desde un container sin `--network=host`, el helper no puede entrar al netns de los otros y falla con `Cannot find a cgroup PID` por cada container en cada scan — ~14 errores por ciclo que tapan los logs útiles. Se pierden sólo las métricas de red **por container**; las del host siguen.

### Los 2 containers unhealthy

`erp-woo-sync` y `crm-si-back-staging-reverb-1` figuran `unhealthy` desde hace semanas. **No están caídos: sus healthchecks están mal escritos.** Como están excluidos del monitoreo, no generan ruido. Si algún día se retoman, los endpoints correctos ya están verificados:

- **`erp-woo-sync`**: cura contra `/api/sync/running`, que está detrás del `authMiddleware` (`/opt/erp-woo-sync/src/index.js:27`) y devuelve 401. `/login.html` (línea 19) es público y responde 200.
- **`crm-si-back-staging-reverb-1`**: cura contra el puerto 80, pero Reverb escucha en **8080**. `curl -fsS http://localhost:8080/up` devuelve 200 (es lo que ya usa el healthcheck de prod).

---

## Qué NO va a git

Estos archivos viven sólo en el VPS:

| Archivo | Por qué |
|---|---|
| `/etc/netdata-msmtprc` | Password SMTP de `info@sicrmapp.com` |
| `/etc/nginx/.htpasswd-monitor` | Hash de la contraseña del dashboard |
| `ops/monitoring/health_alarm_notify.conf` | Copia local (el repo trae el `.example`) |

El `.gitignore` del directorio los cubre.

---

## Seguridad

- **El puerto 19999 nunca se expone.** Bindea a `127.0.0.1` y UFW sólo permite 22/80/443. La única entrada es nginx con basic auth sobre HTTPS.
- **`docker.sock` va montado `:ro`.** Netdata lo necesita para resolver nombres de containers (sin él, el dashboard muestra hashes de cgroup ilegibles). Importante: **el `:ro` protege de escritura en el filesystem, pero no limita la API de Docker** — acceso al socket es equivalente a root en el host. Ésa es exactamente la razón por la que el dashboard no se publica sin autenticación.
