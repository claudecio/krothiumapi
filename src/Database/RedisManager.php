<?php
namespace KrothiumAPI\Database;

use KrothiumAPI\Database\Drivers\RedisDriver;

class RedisManager {
    /**
     * Armazena múltiplas conexões Redis nomeadas.
     * Exemplo: [
     *  'DEFAULT' => RedisDriver, 
     *  'CACHE' => RedisDriver,
     *  'QUEUE' => RedisDriver
     * ]
     */
    private static array $connections = [];

    /**
     * Retorna a conexão Redis para o ambiente informado.
     * Se não existir, cria uma nova.
     */
    private static function connect(string $envName = 'DEFAULT'): RedisDriver {
        if (!isset(self::$connections[$envName])) {
            self::$connections[$envName] = new RedisDriver(envName: $envName);
        }
        return self::$connections[$envName];
    }

    /**
     * Armazena um valor no Redis associado a uma chave, com uma TTL opcional.
     *
     * Este método garante que a conexão com o Redis seja estabelecida e então delega a operação SET para o driver.
     *
     * @param string $key A chave sob a qual o valor será armazenado.
     * @param mixed $value O valor a ser armazenado (pode ser string, int, array, etc.).
     * @param int|null $ttl O tempo de vida (Time-To-Live) em segundos para a chave (opcional).
     * @return bool Retorna `true` em caso de sucesso.
     */
    public static function set(string $key, mixed $value, ?int $ttl = null, string $envName = 'DEFAULT'): bool {
        $conn = self::connect(envName: $envName);
        return $conn->set(key: $key, value: $value, ttl: $ttl);
    }

    /**
     * Recupera o valor armazenado no Redis associado a uma chave.
     *
     * Este método delega a operação GET para o driver de conexão, recuperando o valor de uma chave específica.
     *
     * @param string $key A chave cujo valor deve ser recuperado.
     * @return mixed O valor da chave, ou `null` se a chave não existir.
     */
    public static function get(string $key, string $envName = 'DEFAULT'): mixed {
        $conn = self::connect(envName: $envName);
        return $conn->get(key: $key);
    }

    /**
     * Remove uma ou mais chaves do Redis.
     *
     * Este método recebe um array de chaves e remove cada uma delas individualmente do serviço Redis.
     *
     * @param array $keys Um array de strings contendo as chaves a serem removidas.
     * @return int O número total de chaves removidas.
     */
    public static function del(array $keys, string $envName = 'DEFAULT'): int {
        $conn = self::connect(envName: $envName);
        $deleted = 0;
        foreach ($keys as $key) {
            $deleted += $conn->del(keys: $key);
        }
        return $deleted;
    }

    /**
     * Verifica a existência de uma chave no Redis.
     *
     * @param string $key A chave a ser verificada.
     * @return bool Retorna `true` se a chave existir no Redis, `false` caso contrário.
     */
    public static function exists(string $key, string $envName = 'DEFAULT'): bool {
        $conn = self::connect(envName: $envName);
        return $conn->exists(key: $key);
    }

    /**
     * Define um tempo de vida (TTL) para uma chave existente no Redis.
     *
     * @param string $key A chave para a qual o TTL será definido.
     * @param int $seconds O tempo de vida em segundos.
     * @return bool Retorna `true` se o TTL foi definido com sucesso, `false` caso contrário (ex: a chave não existe).
     */
    public static function expire(string $key, int $seconds, string $envName = 'DEFAULT'): bool {
        $conn = self::connect(envName: $envName);
        return $conn->expire(key: $key, seconds: $seconds);
    }

    /**
     * Permite chamar qualquer método nativo do driver Redis (método mágico __call).
     *
     * Este método atua como um proxy para invocar comandos diretamente no driver subjacente do Redis, permitindo flexibilidade para comandos que não estão mapeados explicitamente nos outros métodos da classe.
     *
     * @param string $method O nome do método/comando Redis a ser chamado.
     * @param array $args Um array de argumentos a serem passados para o método.
     * @return mixed O resultado da execução do comando Redis.
     */
    public static function call(string $method, array $args = [], string $envName = 'DEFAULT'): mixed {
        $conn = self::connect(envName: $envName);
        return $conn->__call(name: $method, arguments: $args);
    }
}