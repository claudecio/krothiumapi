<?php
namespace KrothiumAPI\Database\Drivers;

use PDO;

abstract class PDOAbstract {
    protected PDO $connection;
    protected string $envName;

    public function __construct(string $envName) {
        $this->envName = strtoupper($envName);
        $this->connect(envName: $envName);
    }

    abstract protected function connect(string $envName): void;

    public function isPersistentConnection(string $envName, bool $default = true): bool {
        $optionName = $this->envKey(envName: $envName, key: 'DB_PERSISTENT');

        if (!array_key_exists($optionName, $_ENV)) {
            return $default;
        }

        $value = filter_var($_ENV[$optionName], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        return $value ?? $default;
    }

    public function envValue(string $key, mixed $default = null, ?string $envName = null): mixed {
        $scopedKey = $this->envKey(envName: $envName ?? $this->envName, key: $key);

        if (array_key_exists($scopedKey, $_ENV)) {
            return $_ENV[$scopedKey];
        }

        if (array_key_exists($key, $_ENV)) {
            return $_ENV[$key];
        }

        return $default;
    }

    protected function envBool(string $key, bool $default = false, ?string $envName = null): bool {
        $value = $this->envValue(key: $key, default: null, envName: $envName);
        if ($value === null) {
            return $default;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        return $filtered ?? $default;
    }

    private function envKey(string $key, ?string $envName = null): string {
        return strtoupper(trim(string: ($envName ?? $this->envName) . "_{$key}"));
    }

    public function execute(string $sql, array $params = []): bool {
        $stmt = $this->connection->prepare(query: $sql);
        return $stmt->execute(params: $params);
    }

    public function fetchAll(string $sql, array $params = []): array {
        $stmt = $this->connection->prepare(query: $sql);
        $stmt->execute(params: $params);
        return $stmt->fetchAll(mode: PDO::FETCH_ASSOC);
    }

    public function fetchOne(string $sql, array $params = []): ?array {
        $stmt = $this->connection->prepare(query: $sql);
        $stmt->execute(params: $params);
        $result = $stmt->fetch(mode: PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function beginTransaction(): void {
        if (!$this->connection->inTransaction()) {
            $this->connection->beginTransaction();
        }
    }

    public function commit(): void {
        if ($this->connection->inTransaction()) {
            $this->connection->commit();
        }
    }

    public function rollback(): void {
        if ($this->connection->inTransaction()) {
            $this->connection->rollBack();
        }
    }
    
    public function getPDO(): PDO {
        return $this->connection;
    }
}