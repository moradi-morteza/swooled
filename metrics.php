<?php

if (!function_exists('renderMetrics')) {
    function renderMetrics(): string
    {
        $appPort = (int) (getenv('APP_PORT') ?: 9501);
        $pidFile = getenv('APP_PID_FILE') ?: '/tmp/swoole-redis.pid';
        $pid = readPid($pidFile);

        return ''
            . "# HELP swoole_active_connections Active established TCP connections to the Swoole Redis port.\n"
            . "# TYPE swoole_active_connections gauge\n"
            . 'swoole_active_connections ' . activeConnections($appPort) . "\n"
            . "# HELP swoole_process_memory_bytes Resident memory used by the Swoole worker process.\n"
            . "# TYPE swoole_process_memory_bytes gauge\n"
            . 'swoole_process_memory_bytes ' . ($pid ? processRssBytes($pid) : 0) . "\n"
            . "# HELP swoole_php_memory_usage_bytes Memory used by this PHP metrics exporter process.\n"
            . "# TYPE swoole_php_memory_usage_bytes gauge\n"
            . 'swoole_php_memory_usage_bytes ' . memory_get_usage(true) . "\n"
            . "# HELP container_memory_usage_bytes Current container cgroup memory usage.\n"
            . "# TYPE container_memory_usage_bytes gauge\n"
            . 'container_memory_usage_bytes ' . containerMemoryBytes() . "\n";
    }
}

if (!function_exists('readPid')) {
function readPid(string $pidFile): ?int
{
    if (!is_readable($pidFile)) {
        return null;
    }

    $pid = (int) trim((string) file_get_contents($pidFile));

    return $pid > 0 ? $pid : null;
}
}

if (!function_exists('activeConnections')) {
function activeConnections(int $port): int
{
    $hexPort = strtoupper(str_pad(dechex($port), 4, '0', STR_PAD_LEFT));
    $connections = 0;

    foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $file) {
        if (!is_readable($file)) {
            continue;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach (array_slice($lines ?: [], 1) as $line) {
            $columns = preg_split('/\s+/', trim($line));
            if (count($columns) < 4) {
                continue;
            }

            if (str_ends_with($columns[1], ':' . $hexPort) && $columns[3] === '01') {
                $connections++;
            }
        }
    }

    return $connections;
}
}

if (!function_exists('processRssBytes')) {
function processRssBytes(int $pid): int
{
    $statusFile = "/proc/{$pid}/status";
    if (!is_readable($statusFile)) {
        return 0;
    }

    foreach (file($statusFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with($line, 'VmRSS:') && preg_match('/\d+/', $line, $matches)) {
            return ((int) $matches[0]) * 1024;
        }
    }

    return 0;
}
}

if (!function_exists('containerMemoryBytes')) {
function containerMemoryBytes(): int
{
    foreach (['/sys/fs/cgroup/memory.current', '/sys/fs/cgroup/memory/memory.usage_in_bytes'] as $file) {
        if (is_readable($file)) {
            return (int) trim((string) file_get_contents($file));
        }
    }

    return 0;
}
}

if (PHP_SAPI !== 'cli-server' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
    echo renderMetrics();
}
