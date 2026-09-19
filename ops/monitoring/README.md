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

### Build cache de Docker

Al momento de instalar esto: **16 GB de build cache**, 12,7 reclamables, sobre un disco de 193 GB al 23%. Crece en cada deploy a `preprod` y nadie lo estaba mirando — es parte de por qué existe la alerta de disco.

```bash
docker system df                    # ver cuánto ocupa
docker builder prune                # liberar el reclamable
```

### El scheduler y su límite de 128 MB

La primera medición mostró `crm-si-back-scheduler-1` al **95,64%** de sus 128 MB, lo que parecía un container al borde del OOM. Midiendo 20 minutos con Netdata dio **12,5–13 MiB estables (~10%)**: aquel 95% fue un pico puntual del deploy (el container tenía 54 minutos de vida), no su estado normal.

Por eso los umbrales de RAM quedaron en 75/85 y no más arriba. **Si algún container empieza a vivir cerca de su límite, la respuesta correcta es subirle el límite en su compose, no subir el umbral de la alerta.**

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
