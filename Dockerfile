FROM phpswoole/swoole:php8.3-alpine

WORKDIR /app

COPY run.php metrics.php metricsServer.php docker-entrypoint.sh ./

RUN chmod +x /app/docker-entrypoint.sh

ENV APP_HOST=0.0.0.0 \
    APP_PORT=9501 \
    METRICS_PORT=9502 \
    APP_PID_FILE=/tmp/swoole-redis.pid \
    MAX_CONNECTIONS=1024

EXPOSE 9501 9502

CMD ["/app/docker-entrypoint.sh"]
