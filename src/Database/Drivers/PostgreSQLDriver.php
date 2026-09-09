<?php
namespace KrothiumAPI\Database\Drivers;

use PDO;
use PDOException;
use RuntimeException;

class PostgreSQLDriver extends PDOAbstract {
    protected ?string $schema = null;

    public function __construct(string $envName = 'DEFAULT', ?string $schema = null) {
        $this->schema = $schema;
        $this->envName = strtoupper(trim($envName));
        parent::__construct(envName: $this->envName);
    }

    protected function connect(string $envName): void {
        $host = $this->envValue(key: 'DB_HOST', default: '127.0.0.1', envName: $envName);
        $port = $this->envInt(key: 'DB_PORT', default: 5432, envName: $envName);
        $dbname = $this->envValue(key: 'DB_NAME', envName: $envName);
        $user = $this->envValue(key: 'DB_USERNAME', default: 'postgres', envName: $envName);
        $pass = $this->envValue(key: 'DB_PASSWORD', default: '', envName: $envName);
        $schema = $this->schema ?? $this->envValue(key: 'DB_SCHEMA', default: 'public', envName: $envName);
        $systemTimeZone = $this->envValue(key: 'DB_TIMEZONE', default: 'UTC', envName: $envName);
        $sslMode = $this->envValue(key: 'DB_SSLMODE', default: 'prefer', envName: $envName);
        $sslRootCert = $this->envValue(key: 'DB_SSLROOTCERT', envName: $envName);
        $sslCert = $this->envValue(key: 'DB_SSLCERT', envName: $envName);
        $sslKey = $this->envValue(key: 'DB_SSLKEY', envName: $envName);
        $connectTimeout = $this->envInt(key: 'DB_CONNECT_TIMEOUT', default: 5, envName: $envName);
        $applicationName = $this->envValue(key: 'DB_APPLICATION_NAME', default: 'KrothiumAPI', envName: $envName);
        $charset = $this->envValue(key: 'DB_CHARSET', default: 'UTF8', envName: $envName);
        $persistent = $this->isPersistentConnection(envName: $envName, default: false);

        if (empty($dbname)) {
            throw new RuntimeException("Database name (DB_NAME) is required for PostgreSQL connection '{$envName}'.");
        }

        // Montagem do DSN PDO pgsql
        $dsnParts = [
            "host={$host}",
            "port={$port}",
            "dbname={$dbname}",
            "sslmode={$sslMode}",
            "connect_timeout={$connectTimeout}",
        ];

        if (!empty($sslRootCert)) {
            $dsnParts[] = "sslrootcert={$sslRootCert}";
        }
        if (!empty($sslCert)) {
            $dsnParts[] = "sslcert={$sslCert}";
        }
        if (!empty($sslKey)) {
            $dsnParts[] = "sslkey={$sslKey}";
        }

        $optionsFlags = [];
        if (!empty($charset)) {
            $optionsFlags[] = "--client_encoding=" . escapeshellarg($charset);
        }
        if (!empty($applicationName)) {
            $optionsFlags[] = "--application_name=" . escapeshellarg($applicationName);
        }
        if (!empty($optionsFlags)) {
            $dsnParts[] = "options='" . implode(' ', $optionsFlags) . "'";
        }

        $dsn = 'pgsql:' . implode(';', $dsnParts);

        $pdoOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_PERSISTENT => $persistent,
            PDO::ATTR_TIMEOUT => $connectTimeout,
        ];

        try {
            $this->connection = new PDO(
                dsn: $dsn,
                username: $user,
                password: $pass,
                options: $pdoOptions
            );

            // Define o fuso horário da sessão de forma segura
            $quotedTimeZone = $this->connection->quote($systemTimeZone);
            $this->connection->exec("SET TIME ZONE {$quotedTimeZone}");

            // Define o schema padrão com escape seguro de identificadores
            $searchPath = $this->formatSearchPath($schema);
            $this->connection->exec("SET search_path TO {$searchPath}");
            $this->schema = $schema;
        } catch (PDOException $e) {
            throw new RuntimeException(
                "Error connecting to PostgreSQL ({$envName} at {$host}:{$port}/{$dbname}): {$e->getMessage()}",
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Define dinamicamente o search_path/schema para a conexão atual.
     */
    public function setSchema(string $schema): void {
        $searchPath = $this->formatSearchPath($schema);
        $this->getPDO()->exec("SET search_path TO {$searchPath}");
        $this->schema = $schema;
    }

    /**
     * Retorna o schema ativo no driver.
     */
    public function getSchema(): ?string {
        return $this->schema;
    }

    /**
     * Verifica se uma tabela existe no schema especificado (ou no search_path atual).
     */
    public function tableExists(string $tableName, ?string $schema = null): bool {
        $targetSchema = $schema ?? $this->schema ?? 'public';
        $sql = "SELECT 1 FROM information_schema.tables WHERE table_schema = :schema AND table_name = :table LIMIT 1";
        $result = $this->fetchOne($sql, [
            'schema' => $targetSchema,
            'table' => $tableName
        ]);
        return $result !== null;
    }

    /**
     * Formata e escapa os nomes dos schemas para o search_path com segurança.
     */
    protected function formatSearchPath(string $schema): string {
        $schemas = array_filter(array_map('trim', explode(',', $schema)));
        if (!in_array('public', $schemas, true)) {
            $schemas[] = 'public';
        }

        $escaped = array_map(function ($s) {
            $cleaned = str_replace('"', '""', $s);
            return "\"{$cleaned}\"";
        }, $schemas);

        return implode(', ', $escaped);
    }
}