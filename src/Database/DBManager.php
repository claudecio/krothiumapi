<?php
namespace KrothiumAPI\Database;

use PDO;
use Dotenv\Dotenv;
use RuntimeException;
use KrothiumAPI\Database\Contracts\DriverInterface;
use KrothiumAPI\Database\Drivers\MySQLDriver;
use KrothiumAPI\Database\Drivers\PostgreSQLDriver;
use KrothiumAPI\Database\Drivers\SQLiteDriver;

class DBManager {
    /**
     * @var array<string, DriverInterface>
     */
    private static array $connections = [];
    private static bool $envLoaded = false;

    /**
     * @var array<string, class-string<DriverInterface>>
     */
    private static array $customDrivers = [];

    /**
     * Carrega as variáveis de ambiente a partir do arquivo .env
     */
    public static function loadEnv(): void {
        if (!defined('ROOT_SYSTEM_PATH')) {
            define('ROOT_SYSTEM_PATH', dirname(__DIR__, 2));
        }

        if (!self::$envLoaded) {
            $envFile = ROOT_SYSTEM_PATH . '/.env';
            if (file_exists($envFile)) {
                $dotenv = Dotenv::createImmutable(paths: ROOT_SYSTEM_PATH);
                $dotenv->load();
            }
            self::$envLoaded = true;
        }
    }

    /**
     * Registra uma classe customizada de driver para um identificador.
     *
     * @param string $name Nome do driver (ex: 'oracle', 'sqlsrv', etc.)
     * @param class-string<DriverInterface> $driverClass Classe que implementa DriverInterface
     */
    public static function registerDriver(string $name, string $driverClass): void {
        if (!is_subclass_of($driverClass, DriverInterface::class)) {
            throw new RuntimeException("Class '{$driverClass}' must implement DriverInterface.");
        }
        self::$customDrivers[strtolower($name)] = $driverClass;
    }

    /**
     * Retorna a conexão com base no nome e schema opcional.
     */
    public static function getConnection(string $connectionName = 'DEFAULT', ?string $schema = null): DriverInterface {
        self::loadEnv();
        $connectionName = strtoupper(trim($connectionName));
        $key = self::buildKey($connectionName, $schema);

        if (!isset(self::$connections[$key])) {
            $driverKey = "{$connectionName}_DB_DRIVER";
            $driver = $_ENV[$driverKey] ?? $_SERVER[$driverKey] ?? getenv($driverKey)
                ?: ($_ENV['DB_DRIVER'] ?? $_SERVER['DB_DRIVER'] ?? getenv('DB_DRIVER') ?: null);

            if ($driver === null || $driver === false || $driver === '') {
                throw new RuntimeException("Database driver not defined for connection '{$connectionName}'.");
            }

            $driverLower = strtolower(trim((string)$driver));

            if (isset(self::$customDrivers[$driverLower])) {
                $class = self::$customDrivers[$driverLower];
                $conn = new $class(envName: $connectionName);
            } else {
                $conn = match ($driverLower) {
                    'mysql' => new MySQLDriver(envName: $connectionName),
                    'pgsql', 'postgresql', 'postgres' => new PostgreSQLDriver(envName: $connectionName, schema: $schema),
                    'sqlite', 'sqlite3' => new SQLiteDriver(envName: $connectionName),
                    default => throw new RuntimeException("Unsupported database driver '{$driver}' for connection '{$connectionName}'."),
                };
            }

            self::$connections[$key] = $conn;
        }

        return self::$connections[$key];
    }

    /**
     * Injeta ou sobrescreve uma conexão específica (útil para testes unitários ou mocks).
     */
    public static function setConnection(string $connectionName, DriverInterface $connection, ?string $schema = null): void {
        $connectionName = strtoupper(trim($connectionName));
        $key = self::buildKey($connectionName, $schema);
        self::$connections[$key] = $connection;
    }

    /**
     * Verifica se já existe uma conexão instanciada no pool.
     */
    public static function hasConnection(string $connectionName = 'DEFAULT', ?string $schema = null): bool {
        $connectionName = strtoupper(trim($connectionName));
        $key = self::buildKey($connectionName, $schema);
        return isset(self::$connections[$key]) && self::$connections[$key] !== null;
    }

    /**
     * Reconecta uma conexão existente.
     */
    public static function reconnect(string $connectionName = 'DEFAULT', ?string $schema = null): DriverInterface {
        $conn = self::getConnection(connectionName: $connectionName, schema: $schema);
        $conn->reconnect();
        return $conn;
    }

    /**
     * Encerra e remove uma conexão específica do pool.
     */
    public static function disconnect(string $connectionName = 'DEFAULT', ?string $schema = null): void {
        $connectionName = strtoupper(trim($connectionName));
        $key = self::buildKey($connectionName, $schema);

        if (isset(self::$connections[$key])) {
            self::$connections[$key]->disconnect();
            unset(self::$connections[$key]);
        }
    }

    /**
     * Encerra todas as conexões ativas.
     */
    public static function disconnectAll(): void {
        foreach (self::$connections as $key => $conn) {
            $conn->disconnect();
        }
        self::$connections = [];
    }

    /**
     * Retorna a instância nativa do PDO para a conexão.
     */
    public static function pdo(string $connectionName = 'DEFAULT', ?string $schema = null): PDO {
        return self::getConnection(connectionName: $connectionName, schema: $schema)->getPDO();
    }

    /**
     * Executa uma consulta SQL com parâmetros preparados.
     */
    public static function execute(string $sql, array $params = [], string $connectionName = 'DEFAULT', ?string $schema = null): bool {
        return self::getConnection(connectionName: $connectionName, schema: $schema)->execute($sql, $params);
    }

    /**
     * Executa uma consulta e retorna todas as linhas.
     */
    public static function fetchAll(
        string $sql,
        array $params = [],
        string $connectionName = 'DEFAULT',
        ?string $schema = null,
        int $fetchMode = PDO::FETCH_ASSOC
    ): array {
        return self::getConnection(connectionName: $connectionName, schema: $schema)->fetchAll($sql, $params, $fetchMode);
    }

    /**
     * Executa uma consulta e retorna a primeira linha ou null.
     */
    public static function fetchOne(
        string $sql,
        array $params = [],
        string $connectionName = 'DEFAULT',
        ?string $schema = null,
        int $fetchMode = PDO::FETCH_ASSOC
    ): ?array {
        return self::getConnection(connectionName: $connectionName, schema: $schema)->fetchOne($sql, $params, $fetchMode);
    }

    /**
     * Executa uma consulta e retorna uma única coluna.
     */
    public static function fetchColumn(
        string $sql,
        array $params = [],
        int $columnIndex = 0,
        string $connectionName = 'DEFAULT',
        ?string $schema = null
    ): mixed {
        return self::getConnection(connectionName: $connectionName, schema: $schema)->fetchColumn($sql, $params, $columnIndex);
    }

    /**
     * Executa uma consulta e retorna a contagem de linhas afetadas.
     */
    public static function rowCount(
        string $sql,
        array $params = [],
        string $connectionName = 'DEFAULT',
        ?string $schema = null
    ): int {
        return self::getConnection(connectionName: $connectionName, schema: $schema)->rowCount($sql, $params);
    }

    /**
     * Helper de inserção de dados em uma tabela.
     */
    public static function insert(
        string $table,
        array $data,
        string $connectionName = 'DEFAULT',
        ?string $schema = null
    ): string|int|bool {
        return self::getConnection(connectionName: $connectionName, schema: $schema)->insert($table, $data);
    }

    /**
     * Helper de atualização de dados em uma tabela.
     */
    public static function update(
        string $table,
        array $data,
        string $where,
        array $whereParams = [],
        string $connectionName = 'DEFAULT',
        ?string $schema = null
    ): int {
        return self::getConnection(connectionName: $connectionName, schema: $schema)->update($table, $data, $where, $whereParams);
    }

    /**
     * Helper de exclusão de registros em uma tabela.
     */
    public static function delete(
        string $table,
        string $where,
        array $params = [],
        string $connectionName = 'DEFAULT',
        ?string $schema = null
    ): int {
        return self::getConnection(connectionName: $connectionName, schema: $schema)->delete($table, $where, $params);
    }

    /**
     * Inicia uma transação.
     */
    public static function beginTransaction(string $connectionName = 'DEFAULT', ?string $schema = null): void {
        self::getConnection(connectionName: $connectionName, schema: $schema)->beginTransaction();
    }

    /**
     * Confirma a transação.
     */
    public static function commit(string $connectionName = 'DEFAULT', ?string $schema = null): void {
        self::getConnection(connectionName: $connectionName, schema: $schema)->commit();
    }

    /**
     * Reverte a transação.
     */
    public static function rollback(string $connectionName = 'DEFAULT', ?string $schema = null): void {
        self::getConnection(connectionName: $connectionName, schema: $schema)->rollback();
    }

    /**
     * Verifica se está em transação.
     */
    public static function inTransaction(string $connectionName = 'DEFAULT', ?string $schema = null): bool {
        return self::getConnection(connectionName: $connectionName, schema: $schema)->inTransaction();
    }

    /**
     * Executa um callback dentro de uma transação protegida por commit/rollback automático.
     */
    public static function transaction(callable $callback, string $connectionName = 'DEFAULT', ?string $schema = null): mixed {
        return self::getConnection(connectionName: $connectionName, schema: $schema)->transaction($callback);
    }

    /**
     * Retorna o último ID inserido ou valor de sequence (PostgreSQL).
     */
    public static function lastInsertId(
        string $connectionName = 'DEFAULT',
        ?string $schema = null,
        ?string $sequence = null
    ): string|false {
        return self::getConnection(connectionName: $connectionName, schema: $schema)->lastInsertId($sequence);
    }

    /**
     * Constrói a chave identificadora da conexão no cache estático.
     */
    private static function buildKey(string $connectionName, ?string $schema = null): string {
        return $connectionName . ($schema !== null && $schema !== '' ? "_{$schema}" : '');
    }
}