<?php
namespace KrothiumAPI\Database\Drivers;

use Predis\Client as PredisClient;
use RuntimeException;

class RedisDriver {
    protected PredisClient $connection;
    protected string $envName;

    public function __construct(string $envName = 'DEFAULT') {
        $this->envName = strtoupper(string: $envName);
        $this->connect();
    }

    protected function connect(): void {
        $host = $_ENV["{$this->envName}_REDIS_HOST"] ?? $_ENV['REDIS_HOST'] ?? '127.0.0.1';
        $port = (int) ($_ENV["{$this->envName}_REDIS_PORT"] ?? $_ENV['REDIS_PORT'] ?? 6379);
        $password = $_ENV["{$this->envName}_REDIS_PASSWORD"] ?? $_ENV['REDIS_PASSWORD'] ?? null;
        $database = isset($_ENV["{$this->envName}_REDIS_DATABASE"]) ? (int) $_ENV["{$this->envName}_REDIS_DATABASE"] : (isset($_ENV['REDIS_DATABASE']) ? (int) $_ENV['REDIS_DATABASE'] : 0);

        // Usa Predis
        try {
            $parameters = [
                'scheme' => 'tcp',
                'host' => $host,
                'port' => $port,
                'database' => $database,
            ];
            if ($password) $parameters['password'] = $password;

            $this->connection = new PredisClient(parameters: $parameters);
            // testa a conexão (Predis conecta sob demanda, connect() força handshake)
            $this->connection->connect();
        } catch (\Throwable $e) {
            throw new RuntimeException(message: "Error connecting to Redis: {$e->getMessage()}");
        }
    }

    /** Retorna o cliente interno (Predis ou Redis) */
    public function getClient(): PredisClient {
        return $this->connection;
    }

    /** Define um valor (string). Se $ttl for informado, adiciona expire em segundos. */
    public function set(string $key, mixed $value, ?int $ttl = null): bool {
        // Predis: set or setex
        if ($ttl !== null && $ttl > 0) {
            $resp = $this->connection->setex($key, $ttl, $value);
            return (bool) $resp;
        }
        $resp = $this->connection->set($key, $value);
        return (bool) $resp;
    }

    /** Recupera um valor por chave */
    public function get(string $key): mixed {
        return $this->connection->get($key);
    }

    /** Remove chaves, retorna número de chaves removidas */
    public function del(string ...$keys): int {
        if (empty($keys)) return 0;
        return (int) $this->connection->del(...$keys);
    }

    /** Verifica se uma chave existe */
    public function exists(string $key): bool {
        return (bool) $this->connection->exists($key);
    }

    /** Define TTL (segundos) para uma chave */
    public function expire(string $key, int $seconds): bool {
        return (bool) $this->connection->expire($key, $seconds);
    }

    /** Proxy para comandos não-explicitados */
    public function __call(string $name, array $arguments) {
        if (method_exists(object_or_class: $this->connection, method: $name)) {
            return $this->connection->{$name}(...$arguments);
        }
        throw new RuntimeException(message: "Redis command or method '{$name}' not supported");
    }
}