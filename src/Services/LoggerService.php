<?php
namespace KrothiumAPI\Services;

use DateTime;
use Exception;
use KrothiumAPI\Helpers\ConstHelper;

class LoggerService {
    private static string $logDir;
    public const DRIVER_FILE = 'FILE';
    private static bool $initialized = false;
    private static string $driver = self::DRIVER_FILE;

    /**
     * Inicializa o Logger
     */
    public static function init(string $driver = self::DRIVER_FILE, ?string $logDir = null): void {
        self::$driver = strtoupper(string: $driver);
        if (!defined(constant_name: 'STORAGE_FOLDER_PATH')) {
            throw new Exception(message: "Constant 'STORAGE_FOLDER_PATH' is not defined.");
        }
        self::$logDir = $logDir ?? ConstHelper::get(constant_name: 'STORAGE_FOLDER_PATH') . '/Logs';
        if (self::$driver === self::DRIVER_FILE) {
            self::ensureLogDirectory();
        }
        self::$initialized = true;
    }

    /**
     * Log genérico
     */
    public static function log(string $message, string $level = 'INFO', array $context = []): void {
        if (!self::$initialized) {
            throw new Exception(message: "LoggerService is not initialized. Call LoggerService::init() first.");
        }
        switch (self::$driver) {
            case self::DRIVER_FILE:
                self::logToFile(message: $message, level: $level, context: $context);
            break;
        }
    }

    // Métodos auxiliares por nível
    public static function info(string $message, array $context = []): void { self::log(message: $message, level: 'INFO', context: $context); }

    public static function warning(string $message, array $context = []): void { self::log(message: $message, level: 'WARNING', context: $context); }

    public static function error(string $message, array $context = []): void { self::log(message: $message, level: 'ERROR', context: $context); }

    public static function debug(mixed $data, array $context = []): void {
        if (is_string($data)) {
            self::log(message: $data, level: 'DEBUG', context: $context);
            return;
        }

        self::debugDetailed(data: $data);
    }

    public static function debugDetailed(mixed $data): void {
        if (!self::$initialized) {
            throw new Exception(message: "LoggerService is not initialized. Call LoggerService::init() first.");
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

        self::appendToFile($log);
    }

    public static function raw(mixed $data, string $level = 'DEBUG'): void {
        self::log(message: self::stringify($data), level: $level);
    }

    public static function isInitialized(): bool {
        return self::$initialized;
    }

    public static function getLogDir(): string {
        if (!self::$initialized) {
            throw new Exception(message: "LoggerService is not initialized. Call LoggerService::init() first.");
        }

        return self::$logDir;
    }

    private static function ensureLogDirectory(): void {
        if (!is_dir(filename: self::$logDir)) {
            if (!mkdir(directory: self::$logDir, permissions: 0775, recursive: true) && !is_dir(filename: self::$logDir)) {
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

        return print_r($data, true);
    }

    private static function appendToFile(string $content): void {
        self::ensureLogDirectory();
        $date = (new DateTime())->format(format: 'Y-m-d');
        $filename = self::$logDir . "/app-{$date}.log";
        file_put_contents(filename: $filename, data: $content, flags: FILE_APPEND);
    }

    /**
     * Log para arquivo
     */
    private static function logToFile(string $message, string $level, array $context = []): void {
        $now = (new DateTime())->format(format: 'Y-m-d H:i:s');
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
        self::appendToFile($log);
    }
}