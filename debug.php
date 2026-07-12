<?php
Swoole\Runtime::enableCoroutine(true);

\Co\run(function () {


    go(function () {
        $redis = new Redis();
        $redis->pconnect('127.0.0.1', 9501);
        var_dump($redis->ping());
    });


    Co::sleep(20000);
});