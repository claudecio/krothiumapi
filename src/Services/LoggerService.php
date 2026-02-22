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
    public static function init(string $driver = self::DRIVER_FILE,?string $logDir = null): void {
        self::$driver = strtoupper(string: $driver);
        if (!defined(constant_name: 'STORAGE_FOLDER_PATH')) {
            throw new Exception(message: "Constant 'STORAGE_FOLDER_PATH' is not defined.");
        }
        self::$logDir = $logDir ?? ConstHelper::get(constant_name: 'STORAGE_FOLDER_PATH') . '/logs';
        if (self::$driver === self::DRIVER_FILE && !is_dir(filename: self::$logDir)) {
            if (!mkdir(directory: self::$logDir, permissions: 0775, recursive: true) && !is_dir(filename: self::$logDir)) {
                throw new Exception(message: "Failed to create log directory: " . self::$logDir);
            }
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
                self::logToFile(message: $message, level: $level);
            break;
        }
    }

    // Métodos auxiliares por nível
    public static function info(string $message, array $context = []): void { self::log(message: $message, level: 'INFO', context: $context); }

    public static function warning(string $message, array $context = []): void { self::log(message: $message, level: 'WARNING', context: $context); }

    public static function error(string $message, array $context = []): void { self::log(message: $message, level: 'ERROR', context: $context); }

    public static function debug(string $message, array $context = []): void { self::log(message: $message, level: 'DEBUG', context: $context); }

    /**
     * Log para arquivo
     */
    private static function logToFile(string $message, string $level): void {
        $date = (new DateTime())->format(format: 'Y-m-d');
        $now = (new DateTime())->format(format: 'Y-m-d H:i:s');
        $filename = self::$logDir . "/app-{$date}.log";
        $logMessage = "[$now][$level] $message" . PHP_EOL;
        file_put_contents(filename: $filename, data: $logMessage, flags: FILE_APPEND);
    }
}