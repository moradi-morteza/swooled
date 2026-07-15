<?php
use Swoole\Constant;
use Swoole\Redis\Server;
use Swoole\Server as SwooleServer;

$ip = '0.0.0.0';
$port = 9501;

$server = new Server($ip, $port);
$server->set([

    # Basic
    Constant::OPTION_WORKER_NUM               => 1,
    Constant::OPTION_REACTOR_NUM              => 1,
    Constant::OPTION_HOOK_FLAGS               => SWOOLE_HOOK_ALL,
    Constant::OPTION_ENABLE_COROUTINE         => true,

    # Heartbeat
    Constant::OPTION_HEARTBEAT_IDLE_TIME      => 60,
    Constant::OPTION_HEARTBEAT_CHECK_INTERVAL => 30,

]);

$server->setHandler("AUTH", function (int $fd, array $data) use ($server) {
    echo "client auth fd : $fd".PHP_EOL;
    $password   = $data[0] ?? null;
    $server->send($fd, statusResponse("OK"));

});

$server->setHandler("PING",function (int $fd, array $data) use ($server) {
    echo "client ping fd : $fd".PHP_EOL;
    if (count($data) === 0) {
        // PING without arguments returns "PONG"
        $server->send($fd, statusResponse("PONG"));
    } else {
        // PING with argument returns the argument as echo
        $server->send($fd, stringResponse($data[0]));
    }
});















// ------------------------------------------------------------------------------------------
// Worker start
$server->on("workerStart", function (SwooleServer $server, int $workerId) {

});
// Worker stop
$server->on("workerStop", function (SwooleServer $server, int $workerId) {


});
// Client connect
$server->on('connect', function ($server, $fd) {
    echo "client connect fd : $fd".PHP_EOL;

});
// Client disconnect
$server->on('close', function ($server, $fd) {
    echo "client close fd : $fd".PHP_EOL;
});
// Worker error
$server->on(
    'workerError',
    function ($server, $workerId, $workerPid, $exitCode, $signal) {

    }
);
function statusResponse(string $status): string
{
    return Server::format(Server::STATUS, $status);
}
function stringResponse(string $value): string
{
    return Server::format(Server::STRING, $value);
}

echo "Redis server running on {$ip}:{$port}\n";

$server->start();
