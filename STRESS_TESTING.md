# Stress Testing Notes

## Why Grafana Did Not Show Connections

Grafana reads data from Prometheus.
Prometheus does not watch every connection live. It checks the metrics endpoint every few seconds.

In this project the scrape interval is:

```yaml
scrape_interval: 5s
```

Your test finished in about `1.4s`:

```text
Duration: 1.419s
```

That means all connections opened and closed before Prometheus had time to collect a sample.
So Grafana showed nothing.

Use `--hold-ms` to keep connections open long enough:

```sh
docker compose exec app php /app/stress.php --mode=connect --total=500 --concurrency=500 --hold-ms=30000
```

This keeps each connection open for 30 seconds, so Prometheus and Grafana can see active connections.

## Why Run The Stress Test Inside Docker

You ran:

```sh
php stress.php --mode=connect --total=10000 --concurrency=500
```

That used your host PHP, not the PHP inside Docker.

Your host PHP has Xdebug enabled. Xdebug is useful for debugging, but it can make high-concurrency Swoole coroutine scripts unstable or slower.

The stress script creates many coroutine TCP clients at the same time. With high numbers like:

```text
concurrency=500
total=10000
```

host PHP plus Xdebug may crash after the script finishes. That crash is the:

```text
Segmentation fault
```

The server can still be fine. The crash can be from the stress test client process itself.

## Recommended Commands

Run stress tests inside the app container:

```sh
docker compose exec app php /app/stress.php --mode=connect --total=500 --concurrency=500 --hold-ms=30000
```

Ping test:

```sh
docker compose exec app php /app/stress.php --mode=ping --total=10000 --concurrency=200
```

Echo test:

```sh
docker compose exec app php /app/stress.php --mode=echo --message=test --total=5000 --concurrency=100
```

Auth ping test:

```sh
docker compose exec app php /app/stress.php --mode=auth-ping --password=secret --total=5000 --concurrency=100
```

Open/close pressure test:

```sh
docker compose exec app php /app/stress.php --mode=pressure --duration=30 --batch-size=100 --interval-ms=1000 --hold-ms=500
```

This means:

- run for 30 seconds
- every 1000ms create 100 new connections
- keep each connection open for 500ms
- then close those connections

## Pressure Timeout Errors

If you see:

```text
connect failed: Operation timed out
```

the client could not finish opening the TCP connection before `--timeout`.

For pressure tests, estimate active connections with:

```text
batch-size * ceil(hold-ms / interval-ms)
```

Example:

```sh
--batch-size=500 --interval-ms=1000 --hold-ms=5000
```

Estimated active connections:

```text
500 * ceil(5000 / 1000) = 2500
```

If the server is configured with `MAX_CONNECTIONS=1024`, this test is too high and some connections can time out.

Use a smaller test:

```sh
docker compose exec app php /app/stress.php --mode=pressure --duration=60 --batch-size=200 --interval-ms=1000 --hold-ms=5000
```

Or increase the server limit before starting the app:

```sh
MAX_CONNECTIONS=5000 SERVER_BACKLOG=8192 docker compose up -d app
docker compose exec app php /app/stress.php --mode=pressure --duration=60 --batch-size=500 --interval-ms=1000 --hold-ms=5000 --timeout=10
```

## If You Want To Run On Host

Disable Xdebug for the command:

```sh
XDEBUG_MODE=off php stress.php --mode=connect --total=10000 --concurrency=500 --hold-ms=30000
```

If it still crashes, lower concurrency:

```sh
XDEBUG_MODE=off php stress.php --mode=connect --total=5000 --concurrency=100 --hold-ms=30000
```

## Good Test For Grafana

Use this command first:

```sh
docker compose exec app php /app/stress.php --mode=connect --total=500 --concurrency=500 --hold-ms=30000
```

Then open Grafana:

```text
http://localhost:3000
```

Dashboard:

```text
Swooled Overview
```

You should see active connections while the command is still running.
