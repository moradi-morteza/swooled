<?php

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Client;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

if (!extension_loaded('swoole')) {
    fwrite(STDERR, "The swoole extension is required. Run this inside the app container.\n");
    exit(1);
}

if (extension_loaded('xdebug')) {
    fwrite(STDERR, "Warning: Xdebug is loaded. For high concurrency, run inside Docker or disable Xdebug to avoid unstable Swoole client runs.\n");
}

$options = getopt('', [
    'host::',
    'port::',
    'total::',
    'concurrency::',
    'mode::',
    'password::',
    'message::',
    'timeout::',
    'hold-ms::',
    'duration::',
    'batch-size::',
    'interval-ms::',
    'help',
]);

if (isset($options['help'])) {
    echo usage();
    exit(0);
}

$config = [
    'host' => (string) ($options['host'] ?? '127.0.0.1'),
    'port' => (int) ($options['port'] ?? 9501),
    'total' => max(1, (int) ($options['total'] ?? 1000)),
    'concurrency' => max(1, (int) ($options['concurrency'] ?? 100)),
    'mode' => strtolower((string) ($options['mode'] ?? 'ping')),
    'password' => (string) ($options['password'] ?? ''),
    'message' => (string) ($options['message'] ?? 'hello'),
    'timeout' => max(0.1, (float) ($options['timeout'] ?? 2.0)),
    'hold_ms' => max(0, (int) ($options['hold-ms'] ?? 0)),
    'duration' => max(1, (int) ($options['duration'] ?? 30)),
    'batch_size' => max(1, (int) ($options['batch-size'] ?? 100)),
    'interval_ms' => max(1, (int) ($options['interval-ms'] ?? 1000)),
];

$allowedModes = ['connect', 'ping', 'echo', 'auth-ping', 'pressure'];
if (!in_array($config['mode'], $allowedModes, true)) {
    fwrite(STDERR, "Invalid mode: {$config['mode']}\n\n" . usage());
    exit(1);
}

if ($config['mode'] === 'auth-ping' && $config['password'] === '') {
    fwrite(STDERR, "Mode auth-ping requires --password.\n");
    exit(1);
}

$config['concurrency'] = min($config['concurrency'], $config['total']);

$stats = [
    'ok' => 0,
    'failed' => 0,
    'latency_total_ms' => 0.0,
    'latency_min_ms' => null,
    'latency_max_ms' => 0.0,
    'errors' => [],
];

$startedAt = microtime(true);

if ($config['mode'] === 'pressure') {
    Coroutine\run(function () use (&$config, &$stats): void {
        runPressure($config, $stats);
    });
} else {
    Coroutine\run(function () use ($config, &$stats): void {
        $jobs = new Channel($config['total']);

        for ($i = 0; $i < $config['total']; $i++) {
            $jobs->push($i);
        }
        $jobs->close();

        for ($i = 0; $i < $config['concurrency']; $i++) {
            Coroutine::create(function () use ($jobs, $config, &$stats): void {
                while ($jobs->pop() !== false) {
                    runMeasured($config, $stats);
                }
            });
        }
    });
}

$duration = microtime(true) - $startedAt;
$avgLatency = $stats['ok'] > 0 ? $stats['latency_total_ms'] / $stats['ok'] : 0.0;
$rps = $duration > 0 ? $config['total'] / $duration : 0.0;

echo "Stress test complete\n";
echo "Mode: {$config['mode']}\n";
echo "Target: {$config['host']}:{$config['port']}\n";
echo "Total: {$config['total']}\n";
echo "Hold: {$config['hold_ms']} ms\n";
if ($config['mode'] === 'pressure') {
    echo "Duration target: {$config['duration']}s\n";
    echo "Batch size: {$config['batch_size']}\n";
    echo "Interval: {$config['interval_ms']} ms\n";
    echo 'Estimated peak active: ' . estimatedPressurePeak($config) . "\n";
} else {
    echo "Concurrency: {$config['concurrency']}\n";
}
echo 'OK: ' . $stats['ok'] . "\n";
echo 'Failed: ' . $stats['failed'] . "\n";
echo 'Duration: ' . number_format($duration, 3) . "s\n";
echo 'Rate: ' . number_format($rps, 2) . " req/s\n";
echo 'Latency avg: ' . number_format($avgLatency, 2) . " ms\n";
echo 'Latency min: ' . number_format((float) $stats['latency_min_ms'], 2) . " ms\n";
echo 'Latency max: ' . number_format($stats['latency_max_ms'], 2) . " ms\n";

if ($stats['errors'] !== []) {
    echo "Errors:\n";
    arsort($stats['errors']);
    foreach (array_slice($stats['errors'], 0, 10, true) as $error => $count) {
        echo "  {$count}x {$error}\n";
    }
}

exit($stats['failed'] > 0 ? 1 : 0);

function runPressure(array &$config, array &$stats): void
{
    $startedAt = microtime(true);
    $deadline = $startedAt + $config['duration'];
    $intervalSeconds = $config['interval_ms'] / 1000;
    $nextBatchAt = $startedAt;
    $created = 0;

    while (microtime(true) < $deadline) {
        for ($i = 0; $i < $config['batch_size']; $i++) {
            $created++;
            Coroutine::create(function () use ($config, &$stats): void {
                runMeasured($config, $stats);
            });
        }

        $nextBatchAt += $intervalSeconds;
        $sleepFor = min($nextBatchAt, $deadline) - microtime(true);
        if ($sleepFor > 0) {
            Coroutine::sleep($sleepFor);
        }
    }

    $config['total'] = $created;
}

function estimatedPressurePeak(array $config): int
{
    if ($config['hold_ms'] <= 0) {
        return $config['batch_size'];
    }

    return $config['batch_size'] * max(1, (int) ceil($config['hold_ms'] / $config['interval_ms']));
}

function runMeasured(array $config, array &$stats): void
{
    $start = microtime(true);
    [$ok, $error] = runOne($config);
    $latencyMs = (microtime(true) - $start) * 1000;

    if ($ok) {
        $stats['ok']++;
        $stats['latency_total_ms'] += $latencyMs;
        $stats['latency_min_ms'] = $stats['latency_min_ms'] === null
            ? $latencyMs
            : min($stats['latency_min_ms'], $latencyMs);
        $stats['latency_max_ms'] = max($stats['latency_max_ms'], $latencyMs);

        return;
    }

    $stats['failed']++;
    $stats['errors'][$error] = ($stats['errors'][$error] ?? 0) + 1;
}

function runOne(array $config): array
{
    $client = new Client(SWOOLE_SOCK_TCP);

    if (!$client->connect($config['host'], $config['port'], $config['timeout'])) {
        return [false, 'connect failed: ' . socket_strerror($client->errCode)];
    }

    try {
        if ($config['mode'] === 'connect' || $config['mode'] === 'pressure') {
            holdConnection($config['hold_ms']);

            return [true, null];
        }

        if ($config['mode'] === 'auth-ping') {
            $buffer = '';
            [$ok, $error] = sendCommand($client, ['AUTH', $config['password']], $config['timeout'], 'OK', $buffer);
            if (!$ok) {
                return [$ok, $error];
            }

            [$ok, $error] = sendCommand($client, ['PING'], $config['timeout'], 'PONG', $buffer);
            holdConnection($config['hold_ms']);

            return [$ok, $error];
        }

        $buffer = '';
        if ($config['mode'] === 'echo') {
            [$ok, $error] = sendCommand($client, ['ECHO', $config['message']], $config['timeout'], $config['message'], $buffer);
            holdConnection($config['hold_ms']);

            return [$ok, $error];
        }

        [$ok, $error] = sendCommand($client, ['PING'], $config['timeout'], 'PONG', $buffer);
        holdConnection($config['hold_ms']);

        return [$ok, $error];
    } finally {
        $client->close();
    }
}

function holdConnection(int $holdMs): void
{
    if ($holdMs <= 0) {
        return;
    }

    Coroutine::sleep($holdMs / 1000);
}

function sendCommand(Client $client, array $parts, float $timeout, string $expected, string &$buffer): array
{
    $payload = encodeRespArray($parts);

    if ($client->send($payload) === false) {
        return [false, 'send failed'];
    }

    $response = readResp($client, $timeout, $buffer);
    if ($response['ok'] === false) {
        return [false, $response['error']];
    }

    if ($response['value'] !== $expected) {
        return [false, 'unexpected response: ' . var_export($response['value'], true)];
    }

    return [true, null];
}

function encodeRespArray(array $parts): string
{
    $payload = '*' . count($parts) . "\r\n";

    foreach ($parts as $part) {
        $part = (string) $part;
        $payload .= '$' . strlen($part) . "\r\n" . $part . "\r\n";
    }

    return $payload;
}

function readResp(Client $client, float $timeout, string &$buffer): array
{
    $line = stressReadLine($client, $timeout, $buffer);
    if ($line === null) {
        return ['ok' => false, 'error' => 'read timeout or closed connection'];
    }

    $type = $line[0] ?? '';
    $body = substr($line, 1);

    if ($type === '+') {
        return ['ok' => true, 'value' => $body];
    }

    if ($type === '-') {
        return ['ok' => false, 'error' => $body];
    }

    if ($type === '$') {
        $length = (int) $body;
        if ($length < 0) {
            return ['ok' => true, 'value' => null];
        }

        $data = stressReadBytes($client, $length + 2, $timeout, $buffer);
        if ($data === null || strlen($data) < $length + 2) {
            return ['ok' => false, 'error' => 'short bulk string response'];
        }

        return ['ok' => true, 'value' => substr($data, 0, $length)];
    }

    if ($type === ':') {
        return ['ok' => true, 'value' => (string) ((int) $body)];
    }

    return ['ok' => false, 'error' => 'invalid RESP response: ' . $line];
}

function stressReadLine(Client $client, float $timeout, string &$buffer): ?string
{
    $deadline = microtime(true) + $timeout;

    while (microtime(true) < $deadline) {
        $pos = strpos($buffer, "\r\n");
        if ($pos !== false) {
            $line = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 2);

            return $line;
        }

        $chunk = $client->recv(max(0.01, $deadline - microtime(true)));
        if ($chunk === false || $chunk === '') {
            return null;
        }

        $buffer .= $chunk;
    }

    return null;
}

function stressReadBytes(Client $client, int $length, float $timeout, string &$buffer): ?string
{
    $deadline = microtime(true) + $timeout;

    while (strlen($buffer) < $length && microtime(true) < $deadline) {
        $chunk = $client->recv(max(0.01, $deadline - microtime(true)));
        if ($chunk === false || $chunk === '') {
            return null;
        }

        $buffer .= $chunk;
    }

    if (strlen($buffer) < $length) {
        return null;
    }

    $data = substr($buffer, 0, $length);
    $buffer = substr($buffer, $length);

    return $data;
}

function usage(): string
{
    return <<<TXT
Usage:
  php stress.php [options]

Options:
  --host=127.0.0.1        Server host
  --port=9501             Server port
  --total=1000            Total operations
  --concurrency=100       Concurrent coroutines
  --mode=ping             connect, ping, echo, auth-ping
  --password=secret       Password for auth-ping mode
  --message=hello         Message for echo mode
  --timeout=2             Socket timeout in seconds
  --hold-ms=0             Keep each connection open after connect/command
  --duration=30           Pressure mode duration in seconds
  --batch-size=100        Pressure mode connections created per interval
  --interval-ms=1000      Pressure mode interval between batches
  --help                  Show this help

Examples:
  php stress.php --mode=connect --total=10000 --concurrency=500
  php stress.php --mode=connect --total=500 --concurrency=500 --hold-ms=30000
  php stress.php --mode=ping --total=10000 --concurrency=200
  php stress.php --mode=echo --message=test --total=5000 --concurrency=100
  php stress.php --mode=auth-ping --password=secret --total=5000 --concurrency=100
  php stress.php --mode=pressure --duration=30 --batch-size=100 --interval-ms=1000 --hold-ms=500

TXT;
}
