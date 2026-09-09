<?php
namespace KrothiumAPI\Database;

use KrothiumAPI\Database\Drivers\RedisDriver;

class RedisManager {
    /**
     * @var array<string, RedisDriver>
     */
    private static array $connections = [];

    /**
     * Retorna a conexão Redis para o ambiente informado (ou cria uma nova).
     */
    public static function getConnection(string $envName = 'DEFAULT'): RedisDriver {
        $envName = strtoupper(trim($envName));
        if (!isset(self::$connections[$envName])) {
            self::$connections[$envName] = new RedisDriver(envName: $envName);
        }
        return self::$connections[$envName];
    }

    /**
     * Injeta uma instância de driver para testes/mocks.
     */
    public static function setConnection(string $envName, RedisDriver $driver): void {
        $envName = strtoupper(trim($envName));
        self::$connections[$envName] = $driver;
    }

    /**
     * Armazena um valor no Redis com TTL opcional.
     */
    public static function set(string $key, mixed $value, ?int $ttl = null, string $envName = 'DEFAULT'): bool {
        return self::getConnection($envName)->set(key: $key, value: $value, ttl: $ttl);
    }

    /**
     * Recupera o valor de uma chave.
     */
    public static function get(string $key, string $envName = 'DEFAULT'): mixed {
        return self::getConnection($envName)->get(key: $key);
    }

    /**
     * Recupera e deserializa automaticamente um JSON.
     */
    public static function getJson(string $key, mixed $default = null, string $envName = 'DEFAULT'): mixed {
        return self::getConnection($envName)->getJson(key: $key, default: $default);
    }

    /**
     * Retorna o item em cache ou executa a closure e salva o resultado no Redis.
     */
    public static function remember(string $key, int $ttl, callable $callback, string $envName = 'DEFAULT'): mixed {
        return self::getConnection($envName)->remember(key: $key, ttl: $ttl, callback: $callback);
    }

    /**
     * Remove uma ou mais chaves do Redis.
     */
    public static function del(array|string $keys, string $envName = 'DEFAULT'): int {
        $driver = self::getConnection($envName);
        if (is_array($keys)) {
            return $driver->del(...$keys);
        }
        return $driver->del($keys);
    }

    /**
     * Verifica a existência de uma chave.
     */
    public static function exists(string $key, string $envName = 'DEFAULT'): bool {
        return self::getConnection($envName)->exists(key: $key);
    }

    /**
     * Define tempo de expiração (TTL em segundos).
     */
    public static function expire(string $key, int $seconds, string $envName = 'DEFAULT'): bool {
        return self::getConnection($envName)->expire(key: $key, seconds: $seconds);
    }

    /**
     * Incrementa o valor numérico de uma chave.
     */
    public static function increment(string $key, int $by = 1, string $envName = 'DEFAULT'): int {
        return self::getConnection($envName)->increment(key: $key, by: $by);
    }

    /**
     * Decrementa o valor numérico de uma chave.
     */
    public static function decrement(string $key, int $by = 1, string $envName = 'DEFAULT'): int {
        return self::getConnection($envName)->decrement(key: $key, by: $by);
    }

    /**
     * Executa PING no Redis.
     */
    public static function ping(string $envName = 'DEFAULT'): bool {
        return self::getConnection($envName)->ping();
    }

    /**
     * Limpa o banco selecionado.
     */
    public static function flushdb(string $envName = 'DEFAULT'): bool {
        return self::getConnection($envName)->flushdb();
    }

    /**
     * Encerra uma conexão específica do pool.
     */
    public static function disconnect(string $envName = 'DEFAULT'): void {
        $envName = strtoupper(trim($envName));
        if (isset(self::$connections[$envName])) {
            self::$connections[$envName]->disconnect();
            unset(self::$connections[$envName]);
        }
    }

    /**
     * Encerra todas as conexões Redis ativas.
     */
    public static function disconnectAll(): void {
        foreach (self::$connections as $conn) {
            $conn->disconnect();
        }
        self::$connections = [];
    }

    /**
     * Invoca dinamicamente métodos do Predis.
     */
    public static function call(string $method, array $args = [], string $envName = 'DEFAULT'): mixed {
        return self::getConnection($envName)->__call(name: $method, arguments: $args);
    }
}