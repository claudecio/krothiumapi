<?php
require_once __DIR__ . '/../vendor/autoload.php';

use KrothiumAPI\Database\DBManager;
use KrothiumAPI\Database\Contracts\DriverInterface;
use KrothiumAPI\Database\Drivers\SQLiteDriver;
use KrothiumAPI\Database\Drivers\PostgreSQLDriver;
use KrothiumAPI\Database\Drivers\MySQLDriver;

$passed = 0;
$failed = 0;

function assertTest(string $description, bool $condition): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}\n";
        $failed++;
    }
}

echo "====================================================\n";
echo " Executando Testes da Camada de Banco de Dados\n";
echo "====================================================\n\n";

// 1. Teste de conexão SQLite em memória via DBManager
$_ENV['TEST_DB_DRIVER'] = 'sqlite';
$_ENV['TEST_DB_NAME'] = ':memory:';

echo "1. Testes de Ciclo de Vida e DBManager:\n";
$db = DBManager::getConnection('TEST');
assertTest("DBManager::getConnection retorna instância de DriverInterface", $db instanceof DriverInterface);
assertTest("DBManager::hasConnection identifica conexão ativa", DBManager::hasConnection('TEST'));
assertTest("DBManager::pdo retorna instância de PDO", DBManager::pdo('TEST') instanceof PDO);
assertTest("DBManager::ping retorna true", $db->ping());

// 2. Teste de criação de schema e CRUD básico
echo "\n2. Testes de CRUD e Prepared Statements:\n";
$db->execute("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT, active INTEGER, balance REAL)");

$insertId = $db->insert('users', [
    'name' => 'Claudecio',
    'email' => 'contato@claudecio.is-a.dev',
    'active' => 1,
    'balance' => 150.50
]);
assertTest("insert() retorna o ID gerado", $insertId == 1);

$user = $db->fetchOne("SELECT * FROM users WHERE id = :id", ['id' => 1]);
assertTest("fetchOne() retorna registro correto", $user !== null && $user['name'] === 'Claudecio');

$all = $db->fetchAll("SELECT * FROM users");
assertTest("fetchAll() retorna array com registros", count($all) === 1);

$count = $db->fetchColumn("SELECT count(*) FROM users");
assertTest("fetchColumn() retorna valor escalar", (int)$count === 1);

$updated = $db->update('users', ['name' => 'Claudecio Martins'], 'id = :where_id', ['where_id' => 1]);
assertTest("update() atualiza dados e retorna linhas afetadas", $updated === 1);

$userUpdated = $db->fetchOne("SELECT * FROM users WHERE id = 1");
assertTest("fetchOne() confirma alteração", $userUpdated['name'] === 'Claudecio Martins');

$deleted = $db->delete('users', 'id = :id', ['id' => 1]);
assertTest("delete() remove registro e retorna linhas afetadas", $deleted === 1);

$userDeleted = $db->fetchOne("SELECT * FROM users WHERE id = 1");
assertTest("fetchOne() retorna null após exclusão", $userDeleted === null);

// 3. Teste de Transações
echo "\n3. Testes de Transação:\n";
$db->insert('users', ['name' => 'User 1', 'email' => 'u1@test.com', 'active' => 1, 'balance' => 0]);

// Sucesso da transação
$result = $db->transaction(function ($driver) {
    $driver->insert('users', ['name' => 'User 2', 'email' => 'u2@test.com', 'active' => 1, 'balance' => 0]);
    return 'transacao_ok';
});
assertTest("transaction() commita com sucesso e retorna valor do callback", $result === 'transacao_ok');
assertTest("Registros inseridos na transação bem sucedida persistem", (int)$db->fetchColumn("SELECT count(*) FROM users") === 2);

// Rollback da transação em caso de exceção
try {
    $db->transaction(function ($driver) {
        $driver->insert('users', ['name' => 'User 3', 'email' => 'u3@test.com', 'active' => 1, 'balance' => 0]);
        throw new RuntimeException("Erro proposital para testar rollback");
    });
} catch (RuntimeException $e) {
    // Exceção esperada
}
assertTest("transaction() faz rollback automático em caso de exceção", (int)$db->fetchColumn("SELECT count(*) FROM users") === 2);

// 4. Testes do PostgreSQL Driver (Métodos específicos & segurança)
echo "\n4. Testes de Utilitários e Segurança do PostgreSQLDriver:\n";
// Criar uma classe anônima de teste estendendo PostgreSQLDriver para testar métodos protegidos sem tentar conexão com porta remota
$pgDriverReflection = new class('TEST_PG') extends PostgreSQLDriver {
    public function __construct(string $envName) {
        $this->envName = $envName;
        // Não conecta para teste de formatação
    }

    public function testFormatSearchPath(string $schema): string {
        return $this->formatSearchPath($schema);
    }
};

$searchPath1 = $pgDriverReflection->testFormatSearchPath('tenant_abc');
assertTest("formatSearchPath escapa identificadores e inclui public", $searchPath1 === '"tenant_abc", "public"');

$searchPath2 = $pgDriverReflection->testFormatSearchPath('schema1, schema2, public');
assertTest("formatSearchPath formata múltiplos schemas com segurança", $searchPath2 === '"schema1", "schema2", "public"');

$searchPathInject = $pgDriverReflection->testFormatSearchPath('tenant"; DROP TABLE users; --');
assertTest("formatSearchPath neutraliza tentativas de SQL injection", $searchPathInject === '"tenant""; DROP TABLE users; --", "public"');

// 5. Testes de Escape de Identificadores (PostgreSQL e MySQL)
echo "\n5. Testes de Escape de Identificadores:\n";
$sqliteDriver = new SQLiteDriver('TEST');
assertTest("Escape de identificadores padrão (aspas duplas)", $sqliteDriver->escapeIdentifier('users.name') === '"users"."name"');

$mysqlDriverReflection = new class('TEST_MYSQL') extends MySQLDriver {
    public function __construct(string $envName) {
        $this->envName = $envName;
    }
};
assertTest("Escape de identificadores MySQL (crases)", $mysqlDriverReflection->escapeIdentifier('users.name') === '`users`.`name`');

// 7. Testes de Compatibilidade com o Uso Legado (API antiga 100% preservada)
echo "\n7. Testes de Compatibilidade Retroativa (Uso Antigo):\n";
$_ENV['LEGACY_DB_DRIVER'] = 'sqlite';
$_ENV['LEGACY_DB_NAME'] = ':memory:';

// Chamadas estáticas antigas em DBManager
DBManager::execute("CREATE TABLE legacy_test (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT)", [], 'LEGACY');
DBManager::execute("INSERT INTO legacy_test (title) VALUES (:t)", ['t' => 'Legado 1'], 'LEGACY');
DBManager::execute("INSERT INTO legacy_test (title) VALUES (:t)", ['t' => 'Legado 2'], 'LEGACY');

// fetchAll legado: ($sql, $params, $connectionName)
$legacyAll = DBManager::fetchAll("SELECT * FROM legacy_test", [], 'LEGACY');
assertTest("Uso legado: DBManager::fetchAll(\$sql, \$params, \$connectionName)", count($legacyAll) === 2 && $legacyAll[0]['title'] === 'Legado 1');

// fetchOne legado: ($sql, $params, $connectionName)
$legacyOne = DBManager::fetchOne("SELECT * FROM legacy_test WHERE id = :id", ['id' => 2], 'LEGACY');
assertTest("Uso legado: DBManager::fetchOne(\$sql, \$params, \$connectionName)", $legacyOne !== null && $legacyOne['title'] === 'Legado 2');

// lastInsertId legado: ($connectionName)
$legacyId = DBManager::lastInsertId('LEGACY');
assertTest("Uso legado: DBManager::lastInsertId(\$connectionName)", $legacyId == 2);

// Transações legadas: beginTransaction, commit, rollback manuais
DBManager::beginTransaction('LEGACY');
DBManager::execute("INSERT INTO legacy_test (title) VALUES (:t)", ['t' => 'Legado 3'], 'LEGACY');
assertTest("Uso legado: DBManager::inTransaction(\$connectionName)", DBManager::inTransaction('LEGACY'));
DBManager::commit('LEGACY');
assertTest("Uso legado: DBManager::commit(\$connectionName)", (int)DBManager::fetchColumn("SELECT count(*) FROM legacy_test", [], 0, 'LEGACY') === 3);

// Acesso direto ao PDO legado: $driver->getPDO()
$conn = DBManager::getConnection('LEGACY');
assertTest("Uso legado: \$conn->getPDO() retorna PDO nativo", $conn->getPDO() instanceof PDO);
assertTest("Uso legado: \$conn->fetchAll() e \$conn->fetchOne()", count($conn->fetchAll("SELECT * FROM legacy_test")) === 3);


echo "\n====================================================\n";
echo " Resumo dos Testes: {$passed} passaram, {$failed} falharam.\n";
echo "====================================================\n";

if ($failed > 0) {
    exit(1);
}
