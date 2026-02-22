<?php
namespace KrothiumAPI\Database;

use PDO;
use Dotenv\Dotenv;
use RuntimeException;
use KrothiumAPI\Database\Drivers\MySQLDriver;
use KrothiumAPI\Database\Drivers\PostgreSQLDriver;

class DBManager {
    private static array $connections = [];
    private static bool $envLoaded = false;

    private static function loadEnv(): void {
        // Garante que ROOT_SYSTEM_PATH esteja definido
        if (!defined('ROOT_SYSTEM_PATH')) {
            define('ROOT_SYSTEM_PATH', dirname(dirname(__DIR__)));
        }
        if (!self::$envLoaded) {
            $envFile = ROOT_SYSTEM_PATH . '/.env';
            if ((ROOT_SYSTEM_PATH !== null) && file_exists(filename: $envFile)) {
                $dotenv = Dotenv::createImmutable(paths: ROOT_SYSTEM_PATH);
                $dotenv->load();
            }
            self::$envLoaded = true;
        }
    }

    /**
     * Retorna conexão por nome + schema
     */
    public static function getConnection(string $connectionName = 'DEFAULT', ?string $schema = null): mixed {
        self::loadEnv();
        $connectionName = strtoupper(string: $connectionName);
        $key = $connectionName . ($schema ? "_$schema" : '');
        if (!isset(self::$connections[$key])) {
            $driver = $_ENV["{$connectionName}_DB_DRIVER"];
            switch (strtolower(string: $driver)) {
                case 'mysql':
                    $conn = new MySQLDriver(envName: $connectionName);
                    break;
                case 'pgsql':
                case 'postgresql':
                    $conn = new PostgreSQLDriver(envName: $connectionName, schema: $schema);
                    break;
                default:
                    throw new RuntimeException(message: "Unknown driver: {$driver}");
            }
            self::$connections[$key] = $conn;
        }
        return self::$connections[$key];
    }

    public static function disconnect(string $connectionName = 'DEFAULT', ?string $schema = null): void {
        $connectionName = strtoupper(string: $connectionName);
        $key = $connectionName . ($schema ? "_$schema" : '');
        self::$connections[$key] = null;
    }

    /** Métodos auxiliares (execute, fetchAll, fetchOne, etc.) */
    public static function execute(string $sql, array $params = [], string $connectionName = 'DEFAULT', ?string $schema = null): bool {
        $conn = self::getConnection(connectionName: $connectionName, schema: $schema);
        if (method_exists(object_or_class: $conn, method: 'execute')) {
            return $conn->execute($sql, $params);
        }
        throw new RuntimeException(message: "The database connection '{$connectionName}' does not support execute()");
    }

    public static function fetchAll(string $sql, array $params = [], string $connectionName = 'DEFAULT', ?string $schema = null): array {
        $conn = self::getConnection(connectionName: $connectionName, schema: $schema);
        if (method_exists(object_or_class: $conn, method: 'fetchAll')) {
            return $conn->fetchAll($sql, $params);
        }
        throw new RuntimeException(message: "The database connection '{$connectionName}' does not support fetchAll()");
    }

    public static function fetchOne(string $sql, array $params = [], string $connectionName = 'DEFAULT', ?string $schema = null): ?array {
        $conn = self::getConnection(connectionName: $connectionName, schema: $schema);
        if (method_exists(object_or_class: $conn, method: 'fetchOne')) {
            return $conn->fetchOne($sql, $params);
        }
        throw new RuntimeException(message: "The database connection '{$connectionName}' does not support fetchOne()");
    }

    // Transações
    public static function beginTransaction(string $connectionName = 'DEFAULT', ?string $schema = null): void {
        $conn = self::getConnection(connectionName: $connectionName, schema: $schema);
        if (method_exists(object_or_class: $conn, method: 'beginTransaction')) $conn->beginTransaction();
    }

    public static function commit(string $connectionName = 'DEFAULT', ?string $schema = null): void {
        $conn = self::getConnection(connectionName: $connectionName, schema: $schema);
        if ($conn instanceof PDO && $conn->inTransaction()) {
            $conn->commit();
        } elseif (method_exists(object_or_class: $conn, method: 'getPDO')) {
            $pdo = $conn->getPDO();
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->commit();
            }
        }
    }

    public static function rollback(string $connectionName = 'DEFAULT', ?string $schema = null): void {
        $conn = self::getConnection(connectionName: $connectionName, schema: $schema);
        if ($conn instanceof PDO && $conn->inTransaction()) {
            $conn->rollback();
        } elseif (method_exists(object_or_class: $conn, method: 'getPDO')) {
            $pdo = $conn->getPDO();
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollback();
            }
        }
    }

    public static function lastInsertId(string $connectionName = 'DEFAULT', ?string $schema = null): string {
        $conn = self::getConnection(connectionName: $connectionName, schema: $schema);
        if (method_exists(object_or_class: $conn, method: 'getPDO')) return $conn->getPDO()->lastInsertId();
        throw new RuntimeException(message: "The database connection '{$connectionName}' does not support lastInsertId()");
    }
}