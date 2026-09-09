<?php
namespace KrothiumAPI\Database\Drivers;

use PDO;
use PDOException;
use RuntimeException;

class MySQLDriver extends PDOAbstract {
    protected function connect(string $envName): void {
        $host = $this->envValue(key: 'DB_HOST', default: '127.0.0.1', envName: $envName);
        $port = $this->envInt(key: 'DB_PORT', default: 3306, envName: $envName);
        $dbname = $this->envValue(key: 'DB_NAME', envName: $envName);
        $user = $this->envValue(key: 'DB_USERNAME', default: 'root', envName: $envName);
        $password = $this->envValue(key: 'DB_PASSWORD', default: '', envName: $envName);
        $charset = $this->envValue(key: 'DB_CHARSET', default: 'utf8mb4', envName: $envName);
        $connectTimeout = $this->envInt(key: 'DB_CONNECT_TIMEOUT', default: 5, envName: $envName);
        $persistent = $this->isPersistentConnection(envName: $envName, default: false);

        if (empty($dbname)) {
            throw new RuntimeException("Database name (DB_NAME) is required for MySQL connection '{$envName}'.");
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        $pdoOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_PERSISTENT => $persistent,
            PDO::ATTR_TIMEOUT => $connectTimeout,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES '{$charset}'",
        ];

        try {
            $this->connection = new PDO(
                dsn: $dsn,
                username: $user,
                password: $password,
                options: $pdoOptions
            );

            $timezone = $this->envValue(key: 'DB_TIMEZONE', envName: $envName);
            if (!empty($timezone)) {
                $quotedTimezone = $this->connection->quote($timezone);
                $this->connection->exec("SET time_zone = {$quotedTimezone}");
            }
        } catch (PDOException $e) {
            throw new RuntimeException(
                "Error connecting to MySQL ({$envName} at {$host}:{$port}/{$dbname}): {$e->getMessage()}",
                (int)$e->getCode(),
                $e
            );
        }
    }

    public function escapeIdentifier(string $identifier): string {
        $parts = explode('.', $identifier);
        $escaped = array_map(function ($part) {
            $clean = str_replace('`', '``', trim($part));
            return "`{$clean}`";
        }, $parts);

        return implode('.', $escaped);
    }

    public function tableExists(string $tableName, ?string $database = null): bool {
        $db = $database ?? $this->envValue(key: 'DB_NAME', envName: $this->envName);
        $sql = "SELECT 1 FROM information_schema.tables WHERE table_schema = :db AND table_name = :table LIMIT 1";
        $result = $this->fetchOne($sql, [
            'db' => $db,
            'table' => $tableName
        ]);
        return $result !== null;
    }
}