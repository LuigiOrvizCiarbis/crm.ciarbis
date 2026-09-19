#!/bin/sh
# Limpieza de build cache de Docker. Invocado por /etc/cron.d/docker-builder-prune.
#
# Por que "reserved-space" y no solo "until":
#   "until=168h" filtra por ULTIMO USO, y las capas base se tocan en cada
#   build: Docker las ve "usadas hoy" aunque su contenido tenga 5 meses.
#   Medido en prod: con until=168h sobre 18GB solo libero 168MB.
#   "reserved-space" es un techo duro: conserva los N GB mas recientes y
#   descarta el resto sin importar cuando se uso por ultima vez.
#
# 6GB deja lugar a las capas de los 5 builds del stack (~2GB c/u comparten
# base) para que los deploys sigan siendo rapidos, y corta el crecimiento
# indefinido (~2GB/dia).
LOG=/var/log/docker-prune.log
echo "=== $(date -Is) ===" >> "$LOG"
docker builder prune -af --reserved-space 6GB >> "$LOG" 2>&1
echo "post-prune: $(docker system df | grep "Build Cache")" >> "$LOG"
