<?php
namespace KrothiumAPI\Database\Drivers;

use PDO;
use PDOStatement;
use Throwable;
use KrothiumAPI\Database\Contracts\DriverInterface;

abstract class PDOAbstract implements DriverInterface {
    protected ?PDO $connection = null;
    protected string $envName = 'DEFAULT';

    public function __construct(string $envName = 'DEFAULT') {
        $this->envName = strtoupper(trim($envName));
        $this->connect(envName: $this->envName);
    }

    abstract protected function connect(string $envName): void;

    public function isPersistentConnection(string $envName, bool $default = false): bool {
        return $this->envBool(key: 'DB_PERSISTENT', default: $default, envName: $envName);
    }

    public function envValue(string $key, mixed $default = null, ?string $envName = null): mixed {
        $targetEnv = $envName ?? $this->envName;
        $scopedKey = $this->envKey(key: $key, envName: $targetEnv);

        if (isset($_ENV[$scopedKey]) && $_ENV[$scopedKey] !== '') {
            return $_ENV[$scopedKey];
        }

        if (isset($_SERVER[$scopedKey]) && $_SERVER[$scopedKey] !== '') {
            return $_SERVER[$scopedKey];
        }

        $envVal = getenv($scopedKey);
        if ($envVal !== false && $envVal !== '') {
            return $envVal;
        }

        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }

        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }

        $envVal = getenv($key);
        if ($envVal !== false && $envVal !== '') {
            return $envVal;
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

    protected function envInt(string $key, int $default = 0, ?string $envName = null): int {
        $value = $this->envValue(key: $key, default: null, envName: $envName);
        if ($value === null) {
            return $default;
        }

        return is_numeric($value) ? (int)$value : $default;
    }

    private function envKey(string $key, ?string $envName = null): string {
        $prefix = strtoupper(trim($envName ?? $this->envName));
        return $prefix === '' ? $key : "{$prefix}_{$key}";
    }

    /**
     * Faz o bind tipado de parâmetros para PDOStatement.
     */
    protected function bindValues(PDOStatement $stmt, array $params): void {
        foreach ($params as $key => $value) {
            $paramKey = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ":{$key}");
            $paramType = match (true) {
                is_null($value) => PDO::PARAM_NULL,
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                is_resource($value) => PDO::PARAM_LOB,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($paramKey, $value, $paramType);
        }
    }

    /**
     * Prepara e executa a query com tipagem de parâmetros.
     */
    protected function prepareAndExecute(string $sql, array $params = []): PDOStatement {
        $stmt = $this->getPDO()->prepare(query: $sql);
        if (!empty($params)) {
            $this->bindValues($stmt, $params);
            $stmt->execute();
        } else {
            $stmt->execute();
        }
        return $stmt;
    }

    public function execute(string $sql, array $params = []): bool {
        $stmt = $this->prepareAndExecute($sql, $params);
        return $stmt->rowCount() >= 0;
    }

    public function fetchAll(string $sql, array $params = [], int $fetchMode = PDO::FETCH_ASSOC): array {
        $stmt = $this->prepareAndExecute($sql, $params);
        return $stmt->fetchAll(mode: $fetchMode);
    }

    public function fetchOne(string $sql, array $params = [], int $fetchMode = PDO::FETCH_ASSOC): ?array {
        $stmt = $this->prepareAndExecute($sql, $params);
        $result = $stmt->fetch(mode: $fetchMode);
        return is_array($result) ? $result : null;
    }

    public function fetchColumn(string $sql, array $params = [], int $columnIndex = 0): mixed {
        $stmt = $this->prepareAndExecute($sql, $params);
        $result = $stmt->fetchColumn($columnIndex);
        return $result !== false ? $result : null;
    }

    public function rowCount(string $sql, array $params = []): int {
        $stmt = $this->prepareAndExecute($sql, $params);
        return $stmt->rowCount();
    }

    public function insert(string $table, array $data): string|int|bool {
        if (empty($data)) {
            return false;
        }

        $columns = array_keys($data);
        $escapedColumns = array_map(fn($col) => $this->escapeIdentifier($col), $columns);
        $placeholders = array_map(fn($col) => ":{$col}", $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->escapeIdentifier($table),
            implode(', ', $escapedColumns),
            implode(', ', $placeholders)
        );

        $this->prepareAndExecute($sql, $data);
        $lastId = $this->lastInsertId();
        return ($lastId !== false && $lastId !== '0' && $lastId !== '') ? $lastId : true;
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int {
        if (empty($data)) {
            return 0;
        }

        $setClauses = [];
        $params = [];
        foreach ($data as $col => $val) {
            $paramName = "set_{$col}";
            $setClauses[] = $this->escapeIdentifier($col) . " = :{$paramName}";
            $params[$paramName] = $val;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->escapeIdentifier($table),
            implode(', ', $setClauses),
            $where
        );

        $params = array_merge($params, $whereParams);
        $stmt = $this->prepareAndExecute($sql, $params);
        return $stmt->rowCount();
    }

    public function delete(string $table, string $where, array $params = []): int {
        $sql = sprintf('DELETE FROM %s WHERE %s', $this->escapeIdentifier($table), $where);
        $stmt = $this->prepareAndExecute($sql, $params);
        return $stmt->rowCount();
    }

    public function beginTransaction(): void {
        if ($this->connection !== null && !$this->connection->inTransaction()) {
            $this->connection->beginTransaction();
        }
    }

    public function commit(): void {
        if ($this->connection !== null && $this->connection->inTransaction()) {
            $this->connection->commit();
        }
    }

    public function rollback(): void {
        if ($this->connection !== null && $this->connection->inTransaction()) {
            $this->connection->rollBack();
        }
    }

    public function inTransaction(): bool {
        return $this->connection !== null && $this->connection->inTransaction();
    }

    public function transaction(callable $callback): mixed {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function lastInsertId(?string $name = null): string|false {
        return $this->getPDO()->lastInsertId($name);
    }

    public function getPDO(): PDO {
        if ($this->connection === null) {
            $this->connect(envName: $this->envName);
        }
        return $this->connection;
    }

    public function ping(): bool {
        if ($this->connection === null) {
            return false;
        }
        try {
            $this->connection->query('SELECT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function reconnect(): void {
        $this->disconnect();
        $this->connect(envName: $this->envName);
    }

    public function disconnect(): void {
        $this->connection = null;
    }

    /**
     * Escapa identificadores (tabelas e colunas). Sobrescrito em subclasses quando necessário.
     */
    public function escapeIdentifier(string $identifier): string {
        $parts = explode('.', $identifier);
        $escaped = array_map(function ($part) {
            $clean = str_replace('"', '""', trim($part));
            return "\"{$clean}\"";
        }, $parts);

        return implode('.', $escaped);
    }
}