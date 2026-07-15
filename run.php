<?php

require_once __DIR__.'/bootstrap.php';

use Swoole\Constant;
use Swoole\Redis\Server;
use Swoole\Server as SwooleServer;
use Swoole\Timer;

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

$channelFds = [];
$fdChannels = [];

$server->setHandler("AUTH", function (int $fd, array $data) use ($server) {
    TerminalLogger::info("client auth fd : $fd");
    $password   = $data[0] ?? null;
    $server->send($fd, statusResponse("OK"));

});

$server->setHandler("PING",function (int $fd, array $data) use ($server) {
    TerminalLogger::info("client ping fd : $fd");
    if (count($data) === 0) {
        // PING without arguments returns "PONG"
        $server->send($fd, statusResponse("PONG"));
    } else {
        // PING with argument returns the argument as echo
        $server->send($fd, stringResponse($data[0]));
    }
});

$server->setHandler("SUBSCRIBE", function (int $fd, array $data) use ($server, &$channelFds, &$fdChannels) {
    $channel = $data[0] ?? null;

    if ($channel === null) {
        $server->send($fd, errorResponse("ERR wrong number of arguments for 'subscribe' command"));
        return;
    }

    if ($server->protect($fd) === false) {
        $server->send($fd, errorResponse("ERR failed to protect subscriber connection"));
        return;
    }

    if (isset($fdChannels[$fd])) {
        $previousChannel = $fdChannels[$fd];
        unset($channelFds[$previousChannel][$fd]);

        if (empty($channelFds[$previousChannel])) {
            unset($channelFds[$previousChannel]);
        }
    }

    $channelFds[$channel][$fd] = $fd;
    $fdChannels[$fd] = $channel;
    $server->send($fd, subscribeResponse($channel, 1));

    TerminalLogger::success("subscribed fd $fd to channel $channel");
});















// ------------------------------------------------------------------------------------------
// Worker start
$server->on("workerStart", function (SwooleServer $server, int $workerId) use (&$channelFds) {
    Timer::tick(10_000, function () use ($server, &$channelFds) {
        foreach ($channelFds as $channel => $fds) {
            foreach ($fds as $fd) {
                if ($server->exist($fd)) {
                    $server->send($fd, pubSubMessageResponse($channel, "PING"));
                }
            }
        }
    });
});
// Worker stop
$server->on("workerStop", function (SwooleServer $server, int $workerId) {


});
// Client connect
$server->on('connect', function ($server, $fd) {
    TerminalLogger::success("client connect fd : $fd");

});
// Client disconnect
$server->on('close', function ($server, $fd) use (&$channelFds, &$fdChannels) {
    if (isset($fdChannels[$fd])) {
        $channel = $fdChannels[$fd];
        unset($channelFds[$channel][$fd], $fdChannels[$fd]);

        if (empty($channelFds[$channel])) {
            unset($channelFds[$channel]);
        }
    }

    TerminalLogger::error("client close fd : $fd");
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
function errorResponse(string $message): string
{
    return Server::format(Server::ERROR, $message);
}
function subscribeResponse(string $channel, int $subscriptionCount): string
{
    return "*3\r\n"
        . "$9\r\nsubscribe\r\n"
        . '$' . strlen($channel) . "\r\n{$channel}\r\n"
        . ":{$subscriptionCount}\r\n";
}
function pubSubMessageResponse(string $channel, string $message): string
{
    return "*3\r\n"
        . "$7\r\nmessage\r\n"
        . '$' . strlen($channel) . "\r\n{$channel}\r\n"
        . '$' . strlen($message) . "\r\n{$message}\r\n";
}

TerminalLogger::success("Redis server running on {$ip}:{$port}");

$server->start();
