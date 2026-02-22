<?php
namespace KrothiumAPI\Database\Drivers;

use PDO;

abstract class PDOAbstract {
    protected PDO $connection;

    public function __construct(string $envName) {
        $this->connect(envName: $envName);
    }

    abstract protected function connect(string $envName): void;

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