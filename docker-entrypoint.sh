#!/usr/bin/env sh
set -eu

php /app/redisServer.php &
app_pid="$!"

cleanup() {
  kill "$app_pid" "$metrics_pid" 2>/dev/null || true
  wait "$app_pid" "$metrics_pid" 2>/dev/null || true
}

trap cleanup INT TERM

php /app/metricsServer.php &
metrics_pid="$!"

wait "$app_pid" "$metrics_pid"
