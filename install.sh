#!/bin/sh
# AISSeaStats installer (Docker). Run from the repository folder:
#   ./install.sh           download the ready-made image (about 1 minute), or build it here if that fails
#   ./install.sh --build   build the image on this machine (5 to 10 minutes on a Raspberry Pi)
# It creates .env with random passwords (once) and starts the three containers (db, app, worker).
set -eu

cd "$(dirname "$0")"

say() { printf '%s\n' "$*"; }
die() { printf 'Error: %s\n' "$*" >&2; exit 1; }

mode=pull
for arg in "$@"; do
  case "$arg" in
    --build) mode=build ;;
    -h|--help) sed -n '2,5p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) die "unknown option: $arg (use --build to build the image here)" ;;
  esac
done

command -v docker >/dev/null 2>&1 || die "Docker is not installed. See https://docs.docker.com/engine/install/debian/"
docker compose version >/dev/null 2>&1 || die "Docker Compose v2 is missing (docker compose ...). Install the docker-compose-plugin package."
docker info >/dev/null 2>&1 || die "Cannot talk to Docker. Run with sudo, or add your user to the docker group and log in again."

case "$(uname -m)" in
  aarch64|arm64|x86_64|amd64) ;;
  armv7l|armv6l|armhf)
    die "32-bit system detected ($(uname -m)). The MariaDB image needs a 64-bit OS: install Raspberry Pi OS (64-bit)." ;;
  *) say "Warning: untested architecture $(uname -m)." ;;
esac

# Memory: MariaDB + PHP need about 300 MB; warn below 512 MB available.
if [ -r /proc/meminfo ]; then
  avail_mb=$(awk '/^MemAvailable:/ {print int($2 / 1024)}' /proc/meminfo)
  if [ -n "$avail_mb" ] && [ "$avail_mb" -lt 512 ]; then
    say "Warning: only ${avail_mb} MB of memory available (512 MB recommended). AISSeaStats may be slow or be stopped by the system."
  else
    say "Memory available: ${avail_mb:-?} MB. OK."
  fi
fi

# Disk: Docker images (~400 MB) plus data; require 1 GB, recommend 2 GB.
free_mb=$(df -Pm . | awk 'NR == 2 {print $4}')
if [ -n "$free_mb" ]; then
  if [ "$free_mb" -lt 1024 ]; then
    die "only ${free_mb} MB of free disk space here; at least 1 GB is needed (2 GB recommended)."
  elif [ "$free_mb" -lt 2048 ]; then
    say "Warning: ${free_mb} MB of free disk space (2 GB recommended)."
  else
    say "Free disk space: ${free_mb} MB. OK."
  fi
fi

rand() { od -An -tx1 -N24 /dev/urandom | tr -d ' \n'; }

if [ ! -f .env ]; then
  [ -f .env.example ] || die ".env.example not found"
  umask 077
  sed -e "s/^DB_PASSWORD=.*/DB_PASSWORD=$(rand)/" \
      -e "s/^DB_ROOT_PASSWORD=.*/DB_ROOT_PASSWORD=$(rand)/" .env.example > .env
  if [ -f /etc/timezone ]; then
    tz=$(cat /etc/timezone)
    [ -n "$tz" ] && sed -i "s#^TZ=.*#TZ=${tz}#" .env
  fi
  say "Created .env with random database passwords."
else
  say "Keeping existing .env."
fi

build_here() {
  say "Building and starting AISSeaStats."
  say "First build: about 5 to 10 minutes on a Raspberry Pi (compiling PHP extensions), 1 to 2 minutes on a PC."
  docker compose up -d --build
}
if [ "$mode" = pull ]; then
  say "Downloading the ready-made AISSeaStats image..."
  if docker compose pull; then
    docker compose up -d
  else
    say "Could not download the ready-made image (no access to ghcr.io?). Building it on this machine instead."
    build_here
  fi
else
  build_here
fi

say "Waiting for the application to be ready..."
i=0
while [ $i -lt 60 ]; do
  cid=$(docker compose ps -q app || true)
  if [ -n "$cid" ]; then
    status=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$cid" 2>/dev/null || echo unknown)
    [ "$status" = "healthy" ] && break
  fi
  i=$((i + 1))
  sleep 5
done

port=$(grep -E '^AISSEASTATS_PORT=' .env | cut -d= -f2)
port=${port:-8095}
ip=$(hostname -I 2>/dev/null | awk '{print $1}')
ip=${ip:-localhost}

if [ "${status:-}" = "healthy" ]; then
  say ""
  say "AISSeaStats is running: http://${ip}:${port}"
  say "Open it in a browser to finish the setup (station position, admin password)."
else
  say "The app is not healthy yet. Check the logs with: docker compose logs app db"
  exit 1
fi
