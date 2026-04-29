#!/bin/sh
set -e

PORT_TO_USE=${PORT:-8080}

MESAHUB_URL="${MESAHUB_URL:-}"
if [ -z "$MESAHUB_URL" ]; then
  echo "✗ MESAHUB_URL is required (e.g. mh://token@host/db or mh://local/db)"
  exit 1
fi

# ── [0] Mesahub: embedded or external ─────────────────────────────────────────
# MESAHUB_URL format: mh://[token@]host[:port]/dbname
# Use mh://local/dbname to start a bundled mesahub-server in this container.
# Use mh://token@yourhost.railway.app/dbname to point to an external instance.

_INNER="${MESAHUB_URL#mh://}"
_DBNAME="${_INNER##*/}"
_HOSTPART="${_INNER%%/*}"
if echo "$_HOSTPART" | grep -q "@"; then
  _HOST="${_HOSTPART##*@}"
else
  _HOST="$_HOSTPART"
fi

if [ "$_HOST" = "local" ]; then
  # ── Embedded mode ───────────────────────────────────────────────────────────
  export MESAHUB_CORE_PORT="${MESAHUB_CORE_PORT:-3001}"
  _ADMIN_TOKEN="${MESAHUB_ADMIN_TOKEN:-$(openssl rand -hex 32)}"

  echo "[0] Starting bundled mesahub-server on :$MESAHUB_CORE_PORT (db: $_DBNAME)..."
  DATA_PATH="${DATA_PATH:-/data}" \
  ADMIN_TOKEN="$_ADMIN_TOKEN" \
  SESSION_SECRET="$(openssl rand -hex 32)" \
  FILE_TOKEN_SIGNING_SECRET="$(openssl rand -hex 32)" \
  PORT="$MESAHUB_CORE_PORT" \
    mesahub-server &
  MESAHUB_PID=$!

  max_attempts=30
  attempt=0
  until curl -sf "http://localhost:${MESAHUB_CORE_PORT}/api/health" > /dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ $attempt -eq $max_attempts ]; then
      echo "✗ mesahub-server failed to start after ${max_attempts}s"
      kill $MESAHUB_PID 2>/dev/null || true
      exit 1
    fi
    sleep 1
  done
  echo "✓ mesahub-server ready on :$MESAHUB_CORE_PORT"

  # Create the application DB (idempotent — 409 just means it already exists)
  curl -sf -X POST "http://localhost:${MESAHUB_CORE_PORT}/api/db" \
    -H "Authorization: Bearer $_ADMIN_TOKEN" \
    -H "Content-Type: application/json" \
    -d "{\"name\":\"${_DBNAME}\",\"slug\":\"${_DBNAME}\",\"owner\":\"system\"}" > /dev/null 2>&1 || true
  echo "✓ Database '${_DBNAME}' ready"

  # Rewrite MESAHUB_URL with the resolved token so PHP-FPM and cron can parse it
  export MESAHUB_URL="mh://${_ADMIN_TOKEN}@localhost:${MESAHUB_CORE_PORT}/${_DBNAME}"
else
  echo "[0] External mesahub at $_HOST (db: $_DBNAME) — skipping bundled server"
fi

# Patch nginx to listen on the Railway-assigned port
sed -i "s/listen 80;/listen ${PORT_TO_USE};/" /etc/nginx/http.d/default.conf

# Start the cron daemon (busybox crond, log level 2 = notice+, log to stdout)
crond -l 2 -L /dev/stdout

# Start PHP-FPM in the background
php-fpm -D

# Start Nginx in the foreground (keeps the container alive)
exec nginx -g 'daemon off;'
