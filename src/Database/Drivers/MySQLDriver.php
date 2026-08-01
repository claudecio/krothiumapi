<?php
namespace KrothiumAPI\Database\Drivers;

use PDO;
use PDOException;
use RuntimeException;

class MySQLDriver extends PDOAbstract {
    protected function connect(string $envName): void {
        $host = $this->envValue(key: 'DB_HOST', envName: $envName);
        $port = $this->envValue(key: 'DB_PORT', default: 3306, envName: $envName);
        $dbname = $this->envValue(key: 'DB_NAME', envName: $envName);
        $user = $this->envValue(key: 'DB_USERNAME', envName: $envName);
        $password = $this->envValue(key: 'DB_PASSWORD', envName: $envName);
        $charset = $this->envValue(key: 'DB_CHARSET', default: 'utf8mb4', envName: $envName);

        try {
            $this->connection = new PDO(
                dsn: "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}",
                username: $user,
                password: $password,
                options: [
                    PDO::ATTR_PERSISTENT => $this->envBool(key: 'DB_PERSISTENT', default: true, envName: $envName),
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        } catch (PDOException $e) {
            throw new RuntimeException(message: "Error connecting to MySQL: {$e->getMessage()}");
        }
    }
}