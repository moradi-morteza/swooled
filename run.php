<?php
enum ANSIColor: string {
    // Text Colors
    case Black = '0;30';
    case Red = '0;31';
    case Green = '0;32';
    case Yellow = '0;33';
    case Blue = '0;34';
    case Magenta = '0;35';
    case Cyan = '0;36';
    case Default = '0;37';
    case Orange = '38;5;214';

    // Bold/Bright Text Colors
    case BrightBlack = '1;30';  // Gray
    case BrightRed = '1;31';
    case BrightGreen = '1;32';
    case BrightYellow = '1;33';
    case BrightBlue = '1;34';
    case BrightMagenta = '1;35';
    case BrightCyan = '1;36';
    case BrightWhite = '1;37';

    // Background Colors
    case BackgroundBlack = '40';
    case BackgroundRed = '41';
    case BackgroundGreen = '42';
    case BackgroundYellow = '43';
    case BackgroundBlue = '44';
    case BackgroundMagenta = '45';
    case BackgroundCyan = '46';
    case BackgroundWhite = '47';

    // Bold/Bright Background Colors
    case BackgroundBrightBlack = '100'; // Gray
    case BackgroundBrightRed = '101';
    case BackgroundBrightGreen = '102';
    case BackgroundBrightYellow = '103';
    case BackgroundBrightBlue = '104';
    case BackgroundBrightMagenta = '105';
    case BackgroundBrightCyan = '106';
    case BackgroundBrightWhite = '107';

    // Reset
    case Reset = '0';

    public function getCode(): string {
        return "\033[" . $this->value . "m";
    }

    public static function reset(): string {
        return "\033[0m";
    }
}
enum LogLevel: int
{
    case All = 0;
    case Info = 1;
    case Warning = 2;
    case Error = 3;
    case Success = 4;
    case Blue = 5;
    case Cyan = 6;
    case Magenta = 7;
    case Critical = 8; // will be logged even if user is not set as MTP_LOG_USERS
}
class TerminalLogger
{
    public static LogLevel $log_level = LogLevel::All;

    private static function getConsoleColor(LogLevel $level): ANSIColor
    {
        return match ($level) {
            LogLevel::Warning => ANSIColor::Orange,
            LogLevel::Error   => ANSIColor::Red,
            LogLevel::Success => ANSIColor::Green,
            LogLevel::Blue    => ANSIColor::Blue,
            LogLevel::Cyan    => ANSIColor::Cyan,
            LogLevel::Magenta => ANSIColor::Magenta,
            default           => ANSIColor::Default,
        };
    }

    public static function getLogWithColor(string $message, LogLevel $level = null, ANSIColor $color = null): string
    {
        $colorCode = $color?->getCode() ?? ($level ? self::getConsoleColor($level)->getCode() : ANSIColor::Default->getCode());
        $colorEnd  = ANSIColor::reset();

        $now = microtime(true);
        $ms  = sprintf('%03d', ($now - floor($now)) * 1000);

        $timestamp = date("H:i:s.$ms") . ' : ' ;
        return $colorCode . $timestamp . $message . $colorEnd . PHP_EOL;
    }

    private static function stringify(string|array|JsonSerializable $message): string
    {
        if (is_string($message)) {
            return $message;
        }
        return
            json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
                ?: '[json_encode error, maybe contain binary]';
    }

    public static function logWithColor(string|array|JsonSerializable $message, LogLevel $level): void
    {
        if (self::$log_level === LogLevel::All || self::$log_level === $level) {
            echo self::getLogWithColor(self::stringify($message), $level);
        }
    }

    public static function successError(string|array|JsonSerializable $message, bool $success): void
    {
        $success ? self::success($message) : self::error($message);
    }

    public static function info(string|array|JsonSerializable $message): void
    {
        self::logWithColor($message, LogLevel::Info);
    }

    public static function warning(string|array|JsonSerializable $message): void
    {
        self::logWithColor($message, LogLevel::Warning);
    }

    public static function error(string|array|JsonSerializable $message): void
    {
        self::logWithColor($message, LogLevel::Error);
    }

    public static function success(string|array|JsonSerializable $message): void
    {
        self::logWithColor($message, LogLevel::Success);
    }

    public static function custom(string|array|JsonSerializable $message, ANSIColor $color): void
    {
        echo self::getLogWithColor(self::stringify($message), color: $color);
    }

    public static function auto(string|array|JsonSerializable $message, $level = LogLevel::Info): void
    {
        switch ($level) {
            case LogLevel::Error:
                self::error($message);
                break;
            case LogLevel::Success:
                self::success($message);
                break;
            case LogLevel::Warning:
                self::warning($message);
                break;
            default:
                self::info($message);
                break;
        }
    }
}

use Swoole\Constant;
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
$clients = [];

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
    // Heartbeat detection
    'heartbeat_check_interval' => 5,
    'heartbeat_idle_time' => 60,
    Constant::OPTION_OPEN_TCP_KEEPALIVE => true,
]);
// Worker start
$server->on("workerStart", function (SwooleServer $server, int $workerId) use ($pidFile, &$clients) {

    file_put_contents($pidFile, (string)posix_getpid());

    TerminalLogger::success(
        "Worker #{$workerId} started PID=" . posix_getpid()
    );

    Swoole\Timer::tick(20000, function () use (&$clients) {
        TerminalLogger::warning(json_encode($clients));
    });

});
// Worker stop
$server->on("workerStop", function (SwooleServer $server, int $workerId) {

    TerminalLogger::warning("Worker #{$workerId} stopped");

});
// Client connect
$server->on('connect', function ($server, $fd) use (&$clients) {

    TerminalLogger::info("Client connected FD={$fd}");
    $clients[$fd] = $fd;

});
// Client disconnect
$server->on('close', function ($server, $fd) use (&$authenticatedConnections,&$clients) {

    unset($authenticatedConnections[$fd]);
    unset($clients[$fd]);

    TerminalLogger::warning("Client disconnected FD={$fd}");

});
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
$server->setHandler("ECHO", function (int $fd, array $data) use ($server) {

    TerminalLogger::info("ECHO fd={$fd}");

    $server->send(
        $fd,
        stringResponse($data[0] ?? '')
    );

});
$server->setHandler("SELECT", function (int $fd, array $data) use ($server) {

    TerminalLogger::info(
        "SELECT fd={$fd} db=" . ($data[0] ?? 0)
    );

    $server->send(
        $fd,
        statusResponse("OK")
    );

});
$server->setHandler("INFO", function (int $fd, array $data) use ($server, $port) {

    TerminalLogger::info("INFO fd={$fd}");

    $section = $data[0] ?? 'default';

    $info = "# Server\r\n";
    $info .= "redis_version:6.2.0\r\n";
    $info .= "redis_mode:standalone\r\n";
    $info .= "os:" . PHP_OS . "\r\n";
    $info .= "tcp_port:{$port}\r\n";
    $info .= "\r\n";

    $server->send(
        $fd,
        stringResponse($info)
    );

});
$server->setHandler("TIME", function (int $fd, array $data) use ($server) {

    TerminalLogger::info("TIME fd={$fd}");

    $time = explode(' ', microtime());

    $seconds = $time[1];
    $microseconds = (int)($time[0] * 1000000);

    $server->send(
        $fd,
        arrayResponse([
            $seconds,
            (string)$microseconds
        ])
    );

});
$server->setHandler("HELLO", function (int $fd, array $data) use ($server) {

    TerminalLogger::info("HELLO fd={$fd}");

    $response = [
        'server'  => 'redis',
        'version' => '6.2.0',
        'proto'   => (int)($data[0] ?? 3),
        'id'      => random_int(1, PHP_INT_MAX),
        'mode'    => 'standalone',
        'role'    => 'master',
        'modules' => [],
    ];

    $server->send(
        $fd,
        arrayResponse($response)
    );

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

function arrayResponse(array $values): string
{
    return Server::format(Server::SET, $values);
}

echo "Redis server running on {$ip}:{$port}\n";

$server->start();
