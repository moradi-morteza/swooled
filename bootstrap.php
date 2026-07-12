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