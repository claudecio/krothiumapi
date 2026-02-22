<?php
namespace KrothiumAPI\Database\Drivers;

use PDO;
use PDOException;
use RuntimeException;

class MySQLDriver extends PDOAbstract {
    protected function connect(string $envName): void {
        $host = $_ENV["{$envName}_DB_HOST"];
        $port = $_ENV["{$envName}_DB_PORT"] ?? 3306;
        $dbname = $_ENV["{$envName}_DB_NAME"];
        $user = $_ENV["{$envName}_DB_USERNAME"];
        $password = $_ENV["{$envName}_DB_PASSWORD"];
        $charset = $_ENV["{$envName}_DB_CHARSET"] ?? 'utf8mb4';

        try {
            $this->connection = new PDO(
                dsn: "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}",
                username: $user,
                password: $password,
                options: [
                    PDO::ATTR_PERSISTENT => true,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        } catch (PDOException $e) {
            throw new RuntimeException(message: "Error connecting to MySQL: {$e->getMessage()}");
        }
    }
}