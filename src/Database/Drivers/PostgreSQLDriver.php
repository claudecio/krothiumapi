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
        $host   = $this->envValue(key: 'DB_HOST', envName: $envName);
        $port   = $this->envValue(key: 'DB_PORT', default: 5432, envName: $envName);
        $dbname = $this->envValue(key: 'DB_NAME', envName: $envName);
        $user   = $this->envValue(key: 'DB_USERNAME', envName: $envName);
        $pass   = $this->envValue(key: 'DB_PASSWORD', envName: $envName);
        $schema ??= $this->envValue(key: 'DB_SCHEMA', default: 'public', envName: $envName);
        $systemTimeZone = $this->envValue(key: 'DB_TIMEZONE', default: 'UTC', envName: $envName);
        $sslMode = $this->envValue(key: 'DB_SSLMODE', default: 'disable', envName: $envName); // disable, require, verify-ca, verify-full

        try {
            $this->connection = new PDO(
                dsn: "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslMode}",
                username: $user,
                password: $pass,
                options: [
                    PDO::ATTR_PERSISTENT => $this->envBool(key: 'DB_PERSISTENT', default: true, envName: $envName),
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