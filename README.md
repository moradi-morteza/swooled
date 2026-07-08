# Swooled Redis Server

Dockerized Swoole Redis server with Prometheus metrics and a Grafana dashboard.

## Run

```sh
docker compose up --build
```

Set a Redis password when needed:

```sh
REDIS_PASSWORD=secret docker compose up -d app
```

Services:

- Swoole Redis server: `localhost:9501`
- Metrics endpoint: `http://localhost:9502/metrics`
- Prometheus: `http://localhost:9090`
- Grafana: `http://localhost:3000`

Grafana login:

- Username: `admin`
- Password: `admin`

Open the `Swooled Overview` dashboard to see active connections and RAM usage.

## Metrics

- `swoole_active_connections`
- `swoole_process_memory_bytes`
- `swoole_php_memory_usage_bytes`
- `container_memory_usage_bytes`

## Stress Test

Run from the app container:

```sh
docker compose exec app php /app/stress.php --mode=ping --total=10000 --concurrency=200
```

To make active connections visible in Grafana, keep them open longer than the Prometheus scrape interval:

```sh
docker compose exec app php /app/stress.php --mode=connect --total=500 --concurrency=500 --hold-ms=30000
```

Prefer running the stress script inside the app container. Host PHP with Xdebug can be unstable under high Swoole coroutine concurrency.

Useful modes:

- `connect`: open and close TCP connections
- `ping`: open, send `PING`, close
- `echo`: open, send `ECHO`, close
- `auth-ping`: open, send `AUTH`, send `PING`, close
- `pressure`: every interval, create a batch of connections, hold them, close them

Use `--hold-ms=30000` to keep each connection open for 30 seconds.

Open/close pressure test:

```sh
docker compose exec app php /app/stress.php --mode=pressure --duration=30 --batch-size=100 --interval-ms=1000 --hold-ms=500
```

For large pressure tests, keep estimated active connections under `MAX_CONNECTIONS`:

```text
batch-size * ceil(hold-ms / interval-ms)
```

More details: [STRESS_TESTING.md](./STRESS_TESTING.md)
