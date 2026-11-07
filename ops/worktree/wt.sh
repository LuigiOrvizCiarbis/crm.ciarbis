#!/usr/bin/env bash
# Levanta un worktree de si-crm (back + front) como stack Docker aislado, con
# puertos propios y una copia de la DB local, sin tocar el stack principal.
# Compatible con el bash 3.2 de macOS.
set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
GIT_COMMON_DIR=$(git -C "$SCRIPT_DIR" rev-parse --path-format=absolute --git-common-dir)
MAIN_CHECKOUT=$(dirname "$GIT_COMMON_DIR")
# Dentro de .git: compartido por todos los worktrees y nunca se commitea.
STATE_DIR="$GIT_COMMON_DIR/wt-stacks"
# Compose nombra el proyecto por la carpeta: el stack principal es "crm-si-back".
MAIN_PROJECT=crm-si-back
PHP_SERVICES="reverb queue-worker whatsapp-webhook-worker whatsapp-sync-worker"

usage() {
  cat <<'EOF'
Uso: ops/worktree/wt.sh <comando> [rama|carpeta|ruta]

Comandos:
  up [objetivo] [--scheduler]  Levanta el worktree (por defecto, el del directorio actual).
                               La primera vez copia la DB del stack principal si está corriendo;
                               si no, arranca vacía con seeders. --scheduler suma el scheduler.
  down <objetivo>              Baja el stack y borra sus contenedores, volúmenes e imágenes.
  ls                           Lista los stacks de worktrees y sus URLs.

El objetivo puede ser la rama (lbugio/fix-audio), la carpeta del worktree (fix-audio) o su ruta.
EOF
}

die() { printf 'wt: %s\n' "$*" >&2; exit 1; }
log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mwt:\033[0m %s\n' "$*" >&2; }

# Último valor de KEY en un archivo .env, sin comillas; $3 si no está definido.
env_value() {
  local value
  value=$(sed -n "s/^$1=//p" "$2" | tail -n 1 | tr -d "\"'")
  printf '%s' "${value:-$3}"
}

slugify() {
  printf '%s' "$1" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9]+/-/g; s/^-+//; s/-+$//' | cut -c 1-40
}

find_worktree_by() {
  local field=$1 wanted=$2 line path=""
  while IFS= read -r line; do
    case $line in
      "worktree "*)
        path=${line#worktree }
        if [[ $field == folder && $(basename "$path") == "$wanted" ]]; then
          printf '%s' "$path"
          return 0
        fi
        ;;
      "branch refs/heads/"*)
        if [[ $field == branch && ${line#branch refs/heads/} == "$wanted" ]]; then
          printf '%s' "$path"
          return 0
        fi
        ;;
    esac
  done < <(git -C "$MAIN_CHECKOUT" worktree list --porcelain)
  return 1
}

resolve_worktree() {
  local target=$1
  if [[ -z $target ]]; then
    git rev-parse --show-toplevel 2>/dev/null || die "No estás dentro de un worktree; indicá la rama o la carpeta."
  elif [[ -d $target ]]; then
    git -C "$target" rev-parse --show-toplevel
  else
    find_worktree_by branch "$target" || find_worktree_by folder "$target" \
      || die "No hay un worktree para '$target'. Crealo desde Orca y volvé a correr el comando."
  fi
}

# Cada slot reserva un bloque de 10 puertos: 9010-9015 para el slot 1, 9020-9025 para el 2...
set_ports() {
  local base=$((9000 + $1 * 10))
  FRONT_PORT=$base
  API_PORT=$((base + 1))
  API_TLS_PORT=$((base + 2))
  REVERB_PORT=$((base + 3))
  DB_PORT=$((base + 4))
  REDIS_PORT=$((base + 5))
}

port_busy() { lsof -nP -iTCP:"$1" -sTCP:LISTEN -t >/dev/null 2>&1; }

allocate_slot() {
  local used slot port
  used=$(grep -h '^SLOT=' "$STATE_DIR"/*.env 2>/dev/null | cut -d = -f 2 || true)
  for slot in $(seq 1 99); do
    grep -qx "$slot" <<<"$used" && continue
    set_ports "$slot"
    for port in $FRONT_PORT $API_PORT $API_TLS_PORT $REVERB_PORT $DB_PORT $REDIS_PORT; do
      port_busy "$port" && continue 2
    done
    printf '%s' "$slot"
    return 0
  done
  die "No quedan slots libres (1-99). Bajá algún stack con 'wt.sh down'."
}

load_state() {
  local file="$STATE_DIR/$SLUG.env"
  [[ -f $file ]] || return 1
  SLOT=$(env_value SLOT "$file" "")
  WORKTREE=$(env_value WORKTREE "$file" "")
  set_ports "$SLOT"
}

write_overrides() {
  local dir="$STATE_DIR/$SLUG"
  mkdir -p "$dir"
  printf 'SLOT=%s\nWORKTREE=%s\n' "$SLOT" "$WORKTREE" >"$STATE_DIR/$SLUG.env"

  # environment: pisa al env_file (.env), así los .env copiados quedan intactos.
  cat >"$dir/back.yml" <<EOF
# Generado por ops/worktree/wt.sh: no editar.
x-wt-env: &wt-env
  APP_URL: http://localhost:$API_PORT
  FRONTEND_URL: https://localhost:$FRONT_PORT

services:
  app:
    ports: !override
      - "$API_PORT:80"
      - "$API_TLS_PORT:443"
    environment: *wt-env
  reverb:
    ports: !override
      - "$REVERB_PORT:8080"
    environment: *wt-env
  queue-worker:
    environment: *wt-env
  whatsapp-webhook-worker:
    environment: *wt-env
  whatsapp-sync-worker:
    environment: *wt-env
  # Con la DB copiada, un segundo scheduler re-dispararía automatizaciones y
  # sincronizaciones sobre los mismos datos: solo corre con --scheduler.
  scheduler:
    profiles: [scheduler]
    environment: *wt-env
  db:
    ports: !override
      - "127.0.0.1:$DB_PORT:5432"
  redis:
    ports: !override
      - "127.0.0.1:$REDIS_PORT:6379"
EOF

  cat >"$dir/front.yml" <<EOF
# Generado por ops/worktree/wt.sh: no editar.
services:
  app:
    ports: !override
      - "$FRONT_PORT:3000"
    environment:
      - NEXT_PUBLIC_API_URL=http://localhost:$API_PORT
      - API_INTERNAL_URL=http://host.docker.internal:$API_PORT
      - NEXT_PUBLIC_REVERB_PORT=$REVERB_PORT
EOF
}

back_compose() {
  docker compose -p "wt-$SLUG" --project-directory "$WORKTREE/crm-si-back" \
    -f "$WORKTREE/crm-si-back/docker-compose.yml" -f "$STATE_DIR/$SLUG/back.yml" "$@"
}

front_compose() {
  docker compose -p "wt-$SLUG-front" --project-directory "$WORKTREE/crm-si-front" \
    -f "$WORKTREE/crm-si-front/docker-compose.yaml" -f "$STATE_DIR/$SLUG/front.yml" "$@"
}

db_is_empty() {
  [[ $(back_compose exec -T db psql -U "$DB_USER" -d "$DB_NAME" -Atc \
    "select to_regclass('public.migrations') is null") == t ]]
}

clone_main_db() {
  local main_db source_user source_name
  main_db=$(docker ps -q --filter "label=com.docker.compose.project=$MAIN_PROJECT" \
    --filter "label=com.docker.compose.service=db")
  if [[ -z $main_db ]]; then
    warn "El stack principal está apagado: la DB arranca vacía y se cargan los seeders."
    return 1
  fi
  source_user=$(env_value DB_USERNAME "$MAIN_CHECKOUT/crm-si-back/.env" laravel)
  source_name=$(env_value DB_DATABASE "$MAIN_CHECKOUT/crm-si-back/.env" laravel)
  log "Copiando la DB local ($source_name) del stack principal"
  docker exec "$main_db" pg_dump -U "$source_user" -d "$source_name" --no-owner --no-acl \
    | back_compose exec -T db psql -q -v ON_ERROR_STOP=1 -U "$DB_USER" -d "$DB_NAME" >/dev/null \
    || die "Falló la copia de la DB. Reintentá con: $0 down $SLUG && $0 up $SLUG"
}

wait_for_api() {
  local attempt
  for attempt in $(seq 1 60); do
    curl -fsS -o /dev/null "http://localhost:$API_PORT/api/health" && return 0
    sleep 2
  done
  warn "La API no respondió en http://localhost:$API_PORT/api/health. Revisá: docker compose -p wt-$SLUG logs app"
}

# Un contenedor en loop de reinicio rechaza `exec`; se espera a que quede arriba.
wait_until_running() {
  local id attempt
  id=$(back_compose ps -q "$1")
  for attempt in $(seq 1 60); do
    [[ $(docker inspect -f '{{.State.Status}}' "$id") == running ]] && return 0
    sleep 2
  done
  die "El contenedor $1 no queda en ejecución. Revisá: docker compose -p wt-$SLUG logs $1"
}

# Orca copia .worktreeinclude solo al crear el worktree: los creados antes de
# agregar una entrada no la tienen. Copia lo que falte sin pisar nada existente.
sync_ignored_files() {
  local include="$MAIN_CHECKOUT/.worktreeinclude" path copied
  [[ -f $include ]] || return 0
  while IFS= read -r path || [[ -n $path ]]; do
    path=$(printf '%s' "$path" | tr -d '[:space:]')
    [[ -z $path || $path == \#* || ! -e $MAIN_CHECKOUT/$path ]] && continue
    mkdir -p "$(dirname "$WORKTREE/$path")"
    copied=$(rsync -a --ignore-existing --out-format='%n' "$MAIN_CHECKOUT/$path" "$(dirname "$WORKTREE/$path")/")
    [[ -z $copied ]] || log "Copiado desde el checkout principal: $path"
  done <"$include"
}

cmd_up() {
  local target="" profile_args="" seed_args="" restart_services=$PHP_SERVICES
  while (($#)); do
    case $1 in
      --scheduler) profile_args="--profile scheduler"; restart_services="$PHP_SERVICES scheduler" ;;
      -h | --help) usage; exit 0 ;;
      -*) die "Opción desconocida: $1" ;;
      *) target=$1 ;;
    esac
    shift
  done

  WORKTREE=$(resolve_worktree "$target")
  [[ $WORKTREE != "$MAIN_CHECKOUT" ]] || die "Ese es el checkout principal: levantalo con docker compose up en cada app."
  sync_ignored_files
  [[ -f $WORKTREE/crm-si-back/.env && -f $WORKTREE/crm-si-front/.env.local ]] \
    || die "Faltan crm-si-back/.env o crm-si-front/.env.local en $WORKTREE y tampoco están en $MAIN_CHECKOUT."
  # Apache del back carga estos certificados al arrancar; sin ellos el contenedor reinicia en loop.
  [[ -s $WORKTREE/crm-si-front/certificates/localhost.pem ]] \
    || die "Falta crm-si-front/certificates/localhost.pem en $WORKTREE y tampoco está en $MAIN_CHECKOUT."

  SLUG=$(slugify "$(basename "$WORKTREE")")
  if ! load_state; then
    SLOT=$(allocate_slot)
    set_ports "$SLOT"
  fi
  write_overrides

  DB_USER=$(env_value DB_USERNAME "$WORKTREE/crm-si-back/.env" laravel)
  DB_NAME=$(env_value DB_DATABASE "$WORKTREE/crm-si-back/.env" laravel)

  log "Back: db y redis (proyecto wt-$SLUG)"
  back_compose up -d --wait db redis
  if db_is_empty && ! clone_main_db; then
    seed_args="--seed"
  fi

  log "Back: app y workers"
  # shellcheck disable=SC2086 # profile_args es vacío o "--profile scheduler"
  back_compose $profile_args up -d --build
  wait_until_running app
  back_compose exec -T app composer install --no-interaction --prefer-dist
  # shellcheck disable=SC2086
  back_compose exec -T app php artisan migrate --force $seed_args
  # Los workers arrancan antes de que exista vendor/: se reinician para tomarlo.
  # shellcheck disable=SC2086
  back_compose $profile_args restart $restart_services

  log "Front (proyecto wt-$SLUG-front)"
  front_compose up -d --build

  wait_for_api
  printf '\n  Rama:  %s\n  Front: https://localhost:%s\n  API:   http://localhost:%s\n  DB:    127.0.0.1:%s (%s)\n\n' \
    "$(git -C "$WORKTREE" branch --show-current)" "$FRONT_PORT" "$API_PORT" "$DB_PORT" "$DB_NAME"
}

cmd_down() {
  [[ -n ${1:-} ]] || die "Indicá qué stack bajar (ver: $0 ls)."
  SLUG=$(slugify "$1")
  if ! load_state; then
    SLUG=$(slugify "$(basename "$(resolve_worktree "$1")")")
    load_state || die "No hay un stack levantado para '$1'."
  fi

  log "Bajando wt-$SLUG"
  if [[ -f $WORKTREE/crm-si-back/docker-compose.yml && -f $WORKTREE/crm-si-front/docker-compose.yaml ]]; then
    back_compose --profile scheduler down -v --remove-orphans --rmi local
    front_compose down -v --remove-orphans --rmi local
  else
    # El worktree ya se borró (o quedaron solo restos): Compose resuelve el
    # proyecto por sus labels.
    docker compose -p "wt-$SLUG" down -v --remove-orphans --rmi local
    docker compose -p "wt-$SLUG-front" down -v --remove-orphans --rmi local
  fi
  rm -rf "${STATE_DIR:?}/$SLUG" "$STATE_DIR/$SLUG.env"
}

cmd_ls() {
  local file running branch found=0
  printf '%-32s %-34s %-7s %-24s %s\n' STACK RAMA ESTADO FRONT API
  for file in "$STATE_DIR"/*.env; do
    [[ -f $file ]] || continue
    found=1
    SLUG=$(basename "$file" .env)
    load_state
    running=$(docker ps -q --filter "label=com.docker.compose.project=wt-$SLUG" | wc -l | tr -d ' ')
    branch=$(git -C "$WORKTREE" branch --show-current 2>/dev/null || printf '(worktree borrado)')
    printf '%-32s %-34s %-7s %-24s %s\n' "$SLUG" "$branch" "$([[ $running -gt 0 ]] && printf up || printf down)" \
      "https://localhost:$FRONT_PORT" "http://localhost:$API_PORT"
  done
  ((found)) || printf '(sin stacks)\n'
}

mkdir -p "$STATE_DIR"
case ${1:-} in
  up) shift; cmd_up "$@" ;;
  down) shift; cmd_down "$@" ;;
  ls) cmd_ls ;;
  -h | --help | help | "") usage ;;
  *) usage >&2; exit 1 ;;
esac
