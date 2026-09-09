<?php
namespace KrothiumAPI\Database\Contracts;

use PDO;

interface DriverInterface {
    /**
     * Retorna a instância nativa do PDO.
     */
    public function getPDO(): PDO;

    /**
     * Executa uma consulta SQL com parâmetros preparados e retorna se teve sucesso.
     */
    public function execute(string $sql, array $params = []): bool;

    /**
     * Executa uma consulta SQL e retorna todos os registros.
     */
    public function fetchAll(string $sql, array $params = [], int $fetchMode = PDO::FETCH_ASSOC): array;

    /**
     * Executa uma consulta SQL e retorna um único registro ou null caso não encontrado.
     */
    public function fetchOne(string $sql, array $params = [], int $fetchMode = PDO::FETCH_ASSOC): ?array;

    /**
     * Executa uma consulta SQL e retorna o valor de uma única coluna do primeiro registro.
     */
    public function fetchColumn(string $sql, array $params = [], int $columnIndex = 0): mixed;

    /**
     * Executa uma consulta SQL e retorna o número de linhas afetadas.
     */
    public function rowCount(string $sql, array $params = []): int;

    /**
     * Helper para inserção de dados em uma tabela.
     * Retorna o ID gerado (lastInsertId) ou true/false.
     */
    public function insert(string $table, array $data): string|int|bool;

    /**
     * Helper para atualização de dados em uma tabela.
     * Retorna a quantidade de linhas afetadas.
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int;

    /**
     * Helper para exclusão de registros em uma tabela.
     * Retorna a quantidade de linhas afetadas.
     */
    public function delete(string $table, string $where, array $params = []): int;

    /**
     * Inicia uma transação se não houver uma ativa.
     */
    public function beginTransaction(): void;

    /**
     * Confirma a transação atual.
     */
    public function commit(): void;

    /**
     * Cancela a transação atual.
     */
    public function rollback(): void;

    /**
     * Verifica se existe uma transação em andamento.
     */
    public function inTransaction(): bool;

    /**
     * Executa um callback dentro de uma transação.
     * Realiza commit em caso de sucesso ou rollback automático em caso de exceção.
     */
    public function transaction(callable $callback): mixed;

    /**
     * Retorna o último ID inserido ou o valor da sequência (PostgreSQL).
     */
    public function lastInsertId(?string $name = null): string|false;

    /**
     * Verifica se a conexão com o banco ainda está ativa (ping).
     */
    public function ping(): bool;

    /**
     * Reconecta ao banco de dados.
     */
    public function reconnect(): void;

    /**
     * Encerra a conexão atual com o banco.
     */
    public function disconnect(): void;
}
