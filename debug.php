<?php

require_once __DIR__.'/bootstrap.php';

Swoole\Runtime::enableCoroutine(true);

$channel = $argv[1] ?? 'debug';

\Co\run(function () use ($channel) {
    $redis = new Redis();

    try {
        $redis->pconnect('127.0.0.1', 9501);
        TerminalLogger::success("Subscribed to $channel; waiting for messages...");

        $redis->subscribe([$channel], function (Redis $redis, string $channel, string $message): void {
            TerminalLogger::warning("channel=$channel message=$message");
        });
    } catch (RedisException $exception) {
        TerminalLogger::error("Redis error: {$exception->getMessage()}");
    }
});
