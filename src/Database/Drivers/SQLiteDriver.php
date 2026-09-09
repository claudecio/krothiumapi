<?php
namespace KrothiumAPI\Database\Drivers;

use PDO;
use PDOException;
use RuntimeException;

class SQLiteDriver extends PDOAbstract {
    protected function connect(string $envName): void {
        $database = $this->envValue(key: 'DB_DATABASE', envName: $envName) 
            ?? $this->envValue(key: 'DB_NAME', default: ':memory:', envName: $envName);

        // Se for caminho relativo e não for :memory:, resolve com ROOT_SYSTEM_PATH
        if ($database !== ':memory:' && !str_starts_with($database, '/')) {
            $root = defined('ROOT_SYSTEM_PATH') ? ROOT_SYSTEM_PATH : dirname(__DIR__, 3);
            $database = $root . '/' . ltrim($database, '/');
            $dir = dirname($database);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }

        $dsn = "sqlite:{$database}";

        try {
            $this->connection = new PDO(
                dsn: $dsn,
                options: [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ]
            );

            // Habilita foreign keys por padrão no SQLite
            $enableForeignKeys = $this->envBool(key: 'DB_FOREIGN_KEYS', default: true, envName: $envName);
            if ($enableForeignKeys) {
                $this->connection->exec('PRAGMA foreign_keys = ON');
            }

            // Define busy timeout
            $busyTimeout = $this->envInt(key: 'DB_BUSY_TIMEOUT', default: 5000, envName: $envName);
            $this->connection->exec("PRAGMA busy_timeout = {$busyTimeout}");

            // Define journal mode para arquivos locais
            if ($database !== ':memory:') {
                $journalMode = $this->envValue(key: 'DB_JOURNAL_MODE', default: 'WAL', envName: $envName);
                $this->connection->exec("PRAGMA journal_mode = {$journalMode}");
            }
        } catch (PDOException $e) {
            throw new RuntimeException(
                "Error connecting to SQLite ({$envName} at {$database}): {$e->getMessage()}",
                (int)$e->getCode(),
                $e
            );
        }
    }

    public function tableExists(string $tableName, ?string $schema = null): bool {
        $sql = "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1";
        $result = $this->fetchOne($sql, ['table' => $tableName]);
        return $result !== null;
    }
}
