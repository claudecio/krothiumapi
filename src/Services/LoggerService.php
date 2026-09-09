<?php
namespace KrothiumAPI\Services;

use DateTime;
use Exception;
use KrothiumAPI\Helpers\ConstHelper;

class LoggerService {
    public const DRIVER_FILE = 'FILE';
    public const DRIVER_CONSOLE = 'CONSOLE';
    public const DRIVER_STDOUT = 'STDOUT';
    public const DRIVER_JSON = 'JSON';

    private static string $logDir = '';
    private static bool $initialized = false;
    private static string $driver = self::DRIVER_FILE;
    private static string $activeChannel = 'app';

    /**
     * Inicializa o LoggerService.
     */
    public static function init(string $driver = self::DRIVER_FILE, ?string $logDir = null): void {
        self::$driver = strtoupper(string: $driver);

        $storagePath = defined('STORAGE_FOLDER_PATH') ? constant('STORAGE_FOLDER_PATH') : ConstHelper::get('STORAGE_FOLDER_PATH');
        if ($storagePath === null && (self::$driver === self::DRIVER_FILE || self::$driver === self::DRIVER_JSON)) {
            $root = defined('ROOT_SYSTEM_PATH') ? constant('ROOT_SYSTEM_PATH') : dirname(__DIR__, 2);
            $storagePath = $root . '/storage';
        }

        self::$logDir = $logDir ?? ($storagePath ? $storagePath . '/Logs' : sys_get_temp_dir() . '/krothium_logs');

        if (self::$driver === self::DRIVER_FILE || self::$driver === self::DRIVER_JSON) {
            self::ensureLogDirectory();
        }

        self::$initialized = true;
    }

    /**
     * Define temporariamente o canal ativo para o log.
     */
    public static function channel(string $channel): self {
        self::$activeChannel = preg_replace('/[^a-zA-Z0-9_\-]/', '', $channel);
        return new self();
    }

    /**
     * Gravação genérica de log.
     */
    public static function log(string $message, string $level = 'INFO', array $context = [], ?string $channel = null): void {
        if (!self::$initialized) {
            self::init();
        }

        $targetChannel = $channel ?? self::$activeChannel;

        switch (self::$driver) {
            case self::DRIVER_CONSOLE:
            case self::DRIVER_STDOUT:
                self::logToStdout(message: $message, level: $level, context: $context, channel: $targetChannel);
                break;
            case self::DRIVER_JSON:
                self::logToJsonFile(message: $message, level: $level, context: $context, channel: $targetChannel);
                break;
            case self::DRIVER_FILE:
            default:
                self::logToFile(message: $message, level: $level, context: $context, channel: $targetChannel);
                break;
        }

        // Reseta canal de volta para 'app'
        self::$activeChannel = 'app';
    }

    public static function info(string $message, array $context = [], ?string $channel = null): void {
        self::log(message: $message, level: 'INFO', context: $context, channel: $channel);
    }

    public static function warning(string $message, array $context = [], ?string $channel = null): void {
        self::log(message: $message, level: 'WARNING', context: $context, channel: $channel);
    }

    public static function error(string $message, array $context = [], ?string $channel = null): void {
        self::log(message: $message, level: 'ERROR', context: $context, channel: $channel);
    }

    public static function debug(mixed $data, array $context = [], ?string $channel = null): void {
        if (is_string($data)) {
            self::log(message: $data, level: 'DEBUG', context: $context, channel: $channel);
            return;
        }

        self::debugDetailed(data: $data, channel: $channel);
    }

    public static function debugDetailed(mixed $data, ?string $channel = null): void {
        if (!self::$initialized) {
            self::init();
        }

        $trace = debug_backtrace(options: DEBUG_BACKTRACE_IGNORE_ARGS, limit: 2);
        $caller = $trace[1] ?? [];

        $arquivo = isset($caller['file']) ? basename($caller['file']) : 'desconhecido';
        $linha = $caller['line'] ?? '-';
        $funcao = $caller['function'] ?? '-';

        $memoria = round(memory_get_usage(real_usage: true) / 1024 / 1024, 2);
        $picoMemoria = round(memory_get_peak_usage(real_usage: true) / 1024 / 1024, 2);

        $header = self::lineHeader();
        $log = implode(PHP_EOL, [
            self::lineSeparator(),
            $header,
            "Arquivo : {$arquivo}",
            "Linha   : {$linha}",
            "Função  : {$funcao}",
            "Memória : {$memoria} MB (Pico: {$picoMemoria} MB)",
            self::lineSeparator('-', 101),
            self::stringify($data),
            self::lineSeparator(),
            '',
        ]);

        self::appendToFile(content: $log, channel: $channel ?? self::$activeChannel);
    }

    public static function raw(mixed $data, string $level = 'DEBUG', ?string $channel = null): void {
        self::log(message: self::stringify($data), level: $level, channel: $channel);
    }

    public static function isInitialized(): bool {
        return self::$initialized;
    }

    public static function getLogDir(): string {
        if (!self::$initialized) {
            self::init();
        }
        return self::$logDir;
    }

    private static function ensureLogDirectory(): void {
        if (!is_dir(filename: self::$logDir)) {
            if (!@mkdir(directory: self::$logDir, permissions: 0775, recursive: true) && !is_dir(filename: self::$logDir)) {
                throw new Exception(message: "Failed to create log directory: " . self::$logDir);
            }
        }
    }

    private static function lineHeader(): string {
        return str_pad(' [' . (new DateTime())->format(format: 'Y-m-d H:i:s') . '] ', 101, '=', STR_PAD_BOTH);
    }

    private static function lineSeparator(string $char = '=', int $length = 101): string {
        return str_repeat($char, $length);
    }

    private static function stringify(mixed $data): string {
        if (is_string($data)) {
            return $data;
        }
        if (is_array($data) || is_object($data)) {
            return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }
        return print_r($data, true);
    }

    private static function appendToFile(string $content, string $channel = 'app'): void {
        self::ensureLogDirectory();
        $date = (new DateTime())->format(format: 'Y-m-d');
        $filename = self::$logDir . "/{$channel}-{$date}.log";
        @file_put_contents(filename: $filename, data: $content, flags: FILE_APPEND);
    }

    private static function logToFile(string $message, string $level, array $context = [], string $channel = 'app'): void {
        $header = self::lineHeader();
        $log = implode(PHP_EOL, [
            $header,
            "Level   : {$level}",
            "Message : {$message}",
        ]);

        if (!empty($context)) {
            $log .= PHP_EOL . "Context : " . self::stringify($context);
        }

        $log .= PHP_EOL . self::lineSeparator() . PHP_EOL . '';
        self::appendToFile(content: $log, channel: $channel);
    }

    private static function logToJsonFile(string $message, string $level, array $context = [], string $channel = 'app'): void {
        $record = [
            'timestamp' => (new DateTime())->format('Y-m-d\TH:i:s.uP'),
            'level'     => $level,
            'channel'   => $channel,
            'message'   => $message,
            'context'   => $context,
        ];
        self::appendToFile(content: json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, channel: $channel);
    }

    private static function logToStdout(string $message, string $level, array $context = [], string $channel = 'app'): void {
        $timestamp = (new DateTime())->format('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $line = "[{$timestamp}] [{$channel}] [{$level}] {$message}{$contextStr}" . PHP_EOL;

        $stream = ($level === 'ERROR') ? STDERR : STDOUT;
        if (is_resource($stream)) {
            fwrite($stream, $line);
        } else {
            echo $line;
        }
    }
}