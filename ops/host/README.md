# Configuración del host (VPS)

Archivos que viven **fuera de Docker**, en el sistema del VPS. Se versionan acá porque si hay que rehacer el servidor, sin esto se pierden.

> Estos archivos **no se despliegan solos**: `deploy.yml` no los toca. Si cambiás alguno, hay que copiarlo a mano al VPS (ver abajo).

## Archivos

| Repo | En el VPS | Qué hace |
|---|---|---|
| `daemon.json` | `/etc/docker/daemon.json` | Rotación de logs + techo de build cache |
| `docker-prune.sh` | `/usr/local/bin/docker-prune.sh` | Limpieza diaria del build cache |
| `cron.d-docker-builder-prune` | `/etc/cron.d/docker-builder-prune` | Dispara el script a las 05:38 |

## Build cache: por qué esta configuración

El cache llegó a 18 GB creciendo ~2 GB/día. Ya había un cron semanal que corría bien pero **casi no limpiaba**: quedaba caché de hasta 7 meses.

**La trampa:** `docker builder prune --filter until=168h` filtra por *último uso*, y las capas base se tocan en cada build. Docker las ve "usadas hoy" aunque el contenido tenga meses. Medido en prod: sobre 18 GB liberó **168 MB**.

**Lo que funciona:** `--reserved-space`, un techo duro que conserva los N GB más recientes sin mirar cuándo se usaron. Con 6 GB liberó **9,72 GB** (17,9 → 8,2 GB de caché; disco 24% → 19%).

Quedan dos capas, para que no dependa de una sola cosa:

1. **Cron diario** — limpia activamente y deja rastro en `/var/log/docker-prune.log`
2. **`builder.gc` del daemon** — techo de 10 GB que Docker respeta solo, aunque el cron falle

### Nombres de flags (importante)

Este VPS corre **Docker 29**. Los nombres cambiaron y los viejos fallan **en silencio**:

| Contexto | Correcto | Obsoleto |
|---|---|---|
| CLI | `--reserved-space` | ~~`--keep-storage`~~ (removido) |
| `daemon.json` | `defaultReservedSpace`, `maxUsedSpace` | ~~`defaultKeepStorage`~~ |

Con el nombre viejo en `daemon.json`, el daemon **ignora la config sin avisar**.

## Aplicar cambios en el VPS

```bash
# daemon.json — OJO: reinicia Docker, corta TODOS los containers ~30s
scp ops/host/daemon.json root@72.62.12.121:/etc/docker/daemon.json
ssh root@72.62.12.121 'systemctl restart docker'

# script y cron — sin impacto, no reinician nada
scp ops/host/docker-prune.sh root@72.62.12.121:/usr/local/bin/docker-prune.sh
ssh root@72.62.12.121 'chmod 755 /usr/local/bin/docker-prune.sh'
scp ops/host/cron.d-docker-builder-prune root@72.62.12.121:/etc/cron.d/docker-builder-prune
ssh root@72.62.12.121 'chmod 644 /etc/cron.d/docker-builder-prune'
```

Hacer backup antes de pisar `daemon.json` (hay copias en `/root/daemon.json.bak-*`).

## Verificar

```bash
# el cron corrió y cuánto liberó
ssh root@72.62.12.121 'tail -20 /var/log/docker-prune.log'

# estado actual del cache
ssh root@72.62.12.121 'docker system df'
```

## Otro cron que ya existía

`/etc/cron.d/docker-image-prune` borra imágenes sin usar de más de 24 h. **Está bien como está**, no se tocó. El filtro `until=24h` es lo que evita que borre imágenes que servirían para un rollback.
