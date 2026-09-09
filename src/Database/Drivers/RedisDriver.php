<?php
namespace KrothiumAPI\Database\Drivers;

use Predis\Client as PredisClient;
use RuntimeException;
use Throwable;

class RedisDriver {
    protected ?PredisClient $connection = null;
    protected string $envName;

    public function __construct(string $envName = 'DEFAULT') {
        $this->envName = strtoupper(trim(string: $envName));
        $this->connect();
    }

    protected function connect(): void {
        $host = $this->envValue(key: 'REDIS_HOST', default: '127.0.0.1');
        $port = (int)$this->envValue(key: 'REDIS_PORT', default: 6379);
        $password = $this->envValue(key: 'REDIS_PASSWORD');
        $database = (int)$this->envValue(key: 'REDIS_DATABASE', default: 0);
        $timeout = (float)$this->envValue(key: 'REDIS_TIMEOUT', default: 5.0);

        try {
            $parameters = [
                'scheme'   => 'tcp',
                'host'     => $host,
                'port'     => $port,
                'database' => $database,
                'timeout'  => $timeout,
            ];

            if (!empty($password)) {
                $parameters['password'] = $password;
            }

            $this->connection = new PredisClient(parameters: $parameters);
            $this->connection->connect();
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Error connecting to Redis ({$this->envName} at {$host}:{$port}/db:{$database}): {$e->getMessage()}",
                (int)$e->getCode(),
                $e
            );
        }
    }

    public function envValue(string $key, mixed $default = null): mixed {
        $prefix = $this->envName;
        $scopedKey = "{$prefix}_{$key}";

        if (isset($_ENV[$scopedKey]) && $_ENV[$scopedKey] !== '') {
            return $_ENV[$scopedKey];
        }
        if (isset($_SERVER[$scopedKey]) && $_SERVER[$scopedKey] !== '') {
            return $_SERVER[$scopedKey];
        }
        $val = getenv($scopedKey);
        if ($val !== false && $val !== '') {
            return $val;
        }

        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }
        $val = getenv($key);
        if ($val !== false && $val !== '') {
            return $val;
        }

        return $default;
    }

    public function getClient(): PredisClient {
        if ($this->connection === null) {
            $this->connect();
        }
        return $this->connection;
    }

    /**
     * Armazena um valor. Serializa automaticamente arrays e objetos como JSON.
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool {
        $payload = (is_array($value) || is_object($value))
            ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string)$value;

        if ($ttl !== null && $ttl > 0) {
            $resp = $this->getClient()->setex($key, $ttl, $payload);
            return (bool)$resp;
        }

        $resp = $this->getClient()->set($key, $payload);
        return (bool)$resp;
    }

    /**
     * Recupera o valor de uma chave.
     */
    public function get(string $key): mixed {
        return $this->getClient()->get($key);
    }

    /**
     * Recupera e decodifica automaticamente um payload JSON.
     */
    public function getJson(string $key, mixed $default = null): mixed {
        $val = $this->get($key);
        if ($val === null || $val === false) {
            return $default;
        }

        $decoded = json_decode((string)$val, true);
        return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $val;
    }

    /**
     * Padrão Cache-Aside: Retorna o item do cache ou executa a closure e salva o resultado.
     */
    public function remember(string $key, int $ttl, callable $callback): mixed {
        $val = $this->get($key);
        if ($val !== null && $val !== false) {
            $decoded = json_decode((string)$val, true);
            return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $val;
        }

        $freshValue = $callback();
        $this->set($key, $freshValue, $ttl);
        return $freshValue;
    }

    /**
     * Remove uma ou mais chaves.
     */
    public function del(string ...$keys): int {
        if (empty($keys)) {
            return 0;
        }
        return (int)$this->getClient()->del(...$keys);
    }

    /**
     * Verifica a existência de uma chave.
     */
    public function exists(string $key): bool {
        return (bool)$this->getClient()->exists($key);
    }

    /**
     * Define o TTL de expiração para uma chave.
     */
    public function expire(string $key, int $seconds): bool {
        return (bool)$this->getClient()->expire($key, $seconds);
    }

    /**
     * Incrementa o valor numérico de uma chave.
     */
    public function increment(string $key, int $by = 1): int {
        return ($by === 1) ? (int)$this->getClient()->incr($key) : (int)$this->getClient()->incrby($key, $by);
    }

    /**
     * Decrementa o valor numérico de uma chave.
     */
    public function decrement(string $key, int $by = 1): int {
        return ($by === 1) ? (int)$this->getClient()->decr($key) : (int)$this->getClient()->decrby($key, $by);
    }

    /**
     * Verifica se o Redis está respondendo ao comando PING.
     */
    public function ping(): bool {
        try {
            return (string)$this->getClient()->ping() === 'PONG';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Limpa o banco de dados Redis selecionado.
     */
    public function flushdb(): bool {
        return (bool)$this->getClient()->flushdb();
    }

    public function disconnect(): void {
        if ($this->connection !== null) {
            try {
                $this->connection->disconnect();
            } catch (Throwable) {
            }
            $this->connection = null;
        }
    }

    /**
     * Proxy para métodos nativos do Predis.
     */
    public function __call(string $name, array $arguments): mixed {
        try {
            return $this->getClient()->{$name}(...$arguments);
        } catch (Throwable $e) {
            throw new RuntimeException("Redis command '{$name}' failed: {$e->getMessage()}", (int)$e->getCode(), $e);
        }
    }
}