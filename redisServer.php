<?php

use Swoole\Redis\Server;
use Swoole\Server as SwooleServer;

error_reporting(E_ALL & ~E_DEPRECATED);

$ip = getenv('APP_HOST') ?: '0.0.0.0';
$port = (int) (getenv('APP_PORT') ?: 9501);
$pidFile = getenv('APP_PID_FILE') ?: '/tmp/swoole-redis.pid';
$maxConn = (int) (getenv('MAX_CONNECTIONS') ?: 1024);
$backlog = (int) (getenv('SERVER_BACKLOG') ?: 4096);
$redisPassword = getenv('REDIS_PASSWORD') ?: '';
$authenticatedConnections = [];

$server = new Server($ip, $port);


$server->set([
    // Number of worker processes
    'worker_num' => 1,

    // Enable async signal handling
    'enable_coroutine' => true,

    // Max connections
    'max_conn' => $maxConn,
    'backlog' => $backlog,

    // Debug logs
    'log_level' => SWOOLE_LOG_DEBUG,

    // Log file
    'log_file' => __DIR__ . '/swoole.log',

    // Error display
    'display_errors' => true,

    'max_request' => 10000,

    // Heartbeat detection
    'heartbeat_check_interval' => 5,
    'heartbeat_idle_time' => 60,

    // Better TCP handling
    'open_tcp_nodelay' => true,

    // Daemon mode (false for debugging)
    'daemonize' => false,
]);




// Worker start
$server->on(
    "workerStart",
    function (SwooleServer $server, int $workerId) use ($pidFile) {

        file_put_contents($pidFile, (string) posix_getpid());

        echo sprintf(
            "[%s] Worker #%d started PID=%d\n",
            date('H:i:s'),
            $workerId,
            posix_getpid()
        );

    }
);


// Worker stop
$server->on(
    "workerStop",
    function (SwooleServer $server, int $workerId) {

        echo sprintf(
            "[%s] Worker #%d stopped\n",
            date('H:i:s'),
            $workerId
        );

    }
);


// Client connect
$server->on(
    'connect',
    function ($server, $fd) {

        echo sprintf(
            "[%s] Client connected FD=%d\n",
            date('H:i:s'),
            $fd
        );

    }
);


// Client disconnect
$server->on(
    'close',
    function ($server, $fd) use (&$authenticatedConnections) {

        unset($authenticatedConnections[$fd]);

        echo sprintf(
            "[%s] Client closed FD=%d\n",
            date('H:i:s'),
            $fd
        );

    }
);

$server->setHandler("PING",function (int $fd, array $data) use ($server) {
    echo "PING $fd".PHP_EOL;
    if (count($data) === 0) {
        // PING without arguments returns "PONG"
        $server->send($fd, statusResponse("PONG"));
    } else {
        // PING with argument returns the argument as echo
        $server->send($fd, stringResponse($data[0]));
    }
});


// Worker error
$server->on(
    'workerError',
    function ($server, $workerId, $workerPid, $exitCode, $signal) {

        echo sprintf(
            "Worker error: ID=%d PID=%d CODE=%d SIGNAL=%d\n",
            $workerId,
            $workerPid,
            $exitCode,
            $signal
        );

    }
);

function statusResponse(string $status): string
{
    return Server::format(Server::STATUS, $status);
    # return "+OK\r\n"; pear RESP format
}
function nilResponse(): string
{
    return Server::format(Server::NIL);
}

/**
 * Returns a bulk string response (used for GET, etc.)
 */
function stringResponse(string $value): string
{
    return Server::format(Server::STRING, $value);
}

/**
 * Returns an integer response (used for commands like INCR)
 */
function integerResponse(int $number): string
{
    return Server::format(Server::INT, $number);
}



echo "Redis server running on {$ip}:{$port}\n";


$server->start();
