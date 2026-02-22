<?php
namespace KrothiumAPI\Database\Drivers;

use PDO;
use PDOException;
use RuntimeException;

class PostgreSQLDriver extends PDOAbstract {
    public function __construct(string $envName, ?string $schema = null) {
        $this->connect(envName: $envName, schema: $schema);
    }

    protected function connect(string $envName, ?string $schema = null): void {
        $host   = $_ENV["{$envName}_DB_HOST"];
        $port   = $_ENV["{$envName}_DB_PORT"] ?? 5432;
        $dbname = $_ENV["{$envName}_DB_NAME"];
        $user   = $_ENV["{$envName}_DB_USERNAME"];
        $pass   = $_ENV["{$envName}_DB_PASSWORD"];
        $schema ??= $_ENV["{$envName}_DB_SCHEMA"] ?? 'public';
        $systemTimeZone = $_ENV["{$envName}_DB_TIMEZONE"] ?? 'UTC';
        $sslMode = $_ENV["{$envName}_DB_SSLMODE"] ?? 'disable'; // disable, require, verify-ca, verify-full

        try {
            $this->connection = new PDO(
                dsn: "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslMode}",
                username: $user,
                password: $pass,
                options: [
                    PDO::ATTR_PERSISTENT => false,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
            // Define o fuso horário da sessão
            $this->connection->exec(statement: "SET TIME ZONE '{$systemTimeZone}'");
            
            // Define o schema padrão
            $this->connection->exec(statement: "SET search_path TO {$schema}, public");
        } catch (PDOException $e) {
            throw new RuntimeException(
                message: "Error connecting to PostgreSQL: {$e->getMessage()}"
            );
        }
    }

    // Método auxiliar para alterar schema dinamicamente
    public function setSchema(string $schema): void {
        $this->connection->exec(statement: "SET search_path TO {$schema}, public");
    }
}