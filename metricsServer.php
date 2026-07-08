<?php

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

$host = getenv('METRICS_HOST') ?: '0.0.0.0';
$port = (int) (getenv('METRICS_PORT') ?: 9502);

$server = new Server($host, $port);
$server->set([
    'worker_num' => 1,
    'log_file' => '/dev/null',
    'log_level' => SWOOLE_LOG_ERROR,
]);

require_once __DIR__ . '/metrics.php';

$server->on('request', function (Request $request, Response $response): void {
    if (($request->server['request_uri'] ?? '/') !== '/metrics') {
        $response->status(404);
        $response->end("Not Found\n");

        return;
    }

    $response->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
    $response->end(renderMetrics());
});

$date = date('Y-m-d H:i:s');
echo "start on : ".$date.PHP_EOL;
echo "Metrics server running on {$host}:{$port} ". PHP_EOL;

$server->start();
