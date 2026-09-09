<?php
require_once __DIR__ . '/../vendor/autoload.php';

use KrothiumAPI\KrothiumAPI;
use KrothiumAPI\Http\Router;
use KrothiumAPI\Utils\HttpUtil;
use KrothiumAPI\Services\LoggerService;
use KrothiumAPI\Helpers\ConstHelper;
use KrothiumAPI\Database\RedisManager;
use KrothiumAPI\Database\Drivers\RedisDriver;

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
echo " Executando Testes do Framework KrothiumAPI\n";
echo "====================================================\n\n";

// 1. Testes de Inicialização e Configuração do KrothiumAPI
echo "1. Testes de KrothiumAPI Core e Config:\n";
$tempDir = sys_get_temp_dir() . '/krothium_test_' . uniqid();
@mkdir($tempDir . '/storage/Logs', 0777, true);

KrothiumAPI::init([
    'app' => [
        'mode' => 'DEV',
    ],
    'paths' => [
        'root' => $tempDir,
        'storage' => $tempDir . '/storage',
    ],
    'router' => [
        'base_path' => '/api/v1',
        'allowed_origins' => ['https://example.com'],
    ],
    'system' => [
        'enable_session' => false,
        'default_timezone' => 'America/Fortaleza',
    ],
    'logger' => [
        'driver' => 'FILE',
        'logDir' => $tempDir . '/storage/Logs',
    ]
]);

assertTest("KrothiumAPI::config() lê chave com dot notation", KrothiumAPI::config('app.mode') === 'DEV');
assertTest("KrothiumAPI::config() lê chave aninhada profunda", KrothiumAPI::config('router.base_path') === '/api/v1');
assertTest("KrothiumAPI::config() retorna default para chave inexistente", KrothiumAPI::config('inexistente.chave', 'default_val') === 'default_val');
assertTest("ConstHelper recupera constante definida no init", ConstHelper::get('APP_SYS_MODE') === 'DEV');

// 2. Testes de HttpUtil (Leitura de Inputs, Headers e IP)
echo "\n2. Testes de HttpUtil:\n";
HttpUtil::clearInputCache();

$_GET['page'] = '2';
$_GET['filter'] = 'active';
$_POST['username'] = 'claudecio';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer token_secreto_xyz';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.195';
$_SERVER['HTTP_X_CUSTOM_HEADER'] = 'KrothiumHeader';

assertTest("HttpUtil::query() recupera parâmetro GET", HttpUtil::query('page') === '2');
assertTest("HttpUtil::post() recupera parâmetro POST", HttpUtil::post('username') === 'claudecio');
assertTest("HttpUtil::input() resolve parâmetros unificados", HttpUtil::input('page') === '2' && HttpUtil::input('username') === 'claudecio');
assertTest("HttpUtil::header() lê cabeçalhos case-insensitively", HttpUtil::header('X-Custom-Header') === 'KrothiumHeader');
assertTest("HttpUtil::getBearerToken() extrai token Bearer", HttpUtil::getBearerToken() === 'token_secreto_xyz');
assertTest("HttpUtil::ip() extrai IP do Cloudflare (CF-Connecting-IP)", HttpUtil::ip() === '203.0.113.195');

// 3. Testes do Roteador (Router)
echo "\n3. Testes do Roteador (Router):\n";
Router::clearRoutes();
Router::init();

// Registro de rotas com Closure
Router::get('/status', function () {
    return ['status' => 'online', 'uptime' => 100];
});

Router::post('/users/{id}', function ($id) {
    return ['user_id' => $id, 'created' => true];
});

// Registro de rotas com Grupo
Router::group('/admin', function () {
    Router::get('/dashboard', function () {
        return ['section' => 'admin_dashboard'];
    });
    Router::delete('/user/{id}', function ($id) {
        return ['deleted_user' => $id];
    });
});

$routes = Router::getRoutes();
assertTest("Router registrou rota GET /status com Closure", isset($routes['GET']));
assertTest("Router registrou rota POST /users/{id}", count($routes['POST']) === 1);
assertTest("Router registrou rota dentro de grupo /admin/dashboard", $routes['GET'][1]['path'] === '/admin/dashboard');

// Teste de Matching e extração de parâmetros
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/api/v1/users/42';

// Simulação de execução interna de matching
$matchedParams = [];
foreach ($routes['POST'] as $route) {
    // Usando reflexão para testar matchPath
    $reflection = new ReflectionClass(Router::class);
    $method = $reflection->getMethod('matchPath');
    $method->setAccessible(true);
    if ($method->invoke(null, $route['path'], '/users/42')) {
        $getParamsMethod = $reflection->getMethod('getParams');
        $getParamsMethod->setAccessible(true);
        $matchedParams = $getParamsMethod->invoke(null);
        
        $getNamedMethod = $reflection->getMethod('getNamedParams');
        $getNamedMethod->setAccessible(true);
        $namedParams = $getNamedMethod->invoke(null);
    }
}
assertTest("Router extrai parâmetros posicionais corretamente", $matchedParams === ['42']);
assertTest("Router extrai parâmetros nomeados {id} corretamente", isset($namedParams['id']) && $namedParams['id'] === '42');

// 4. Testes de LoggerService (Canais e Drivers)
echo "\n4. Testes do LoggerService:\n";
LoggerService::init(driver: LoggerService::DRIVER_FILE, logDir: $tempDir . '/storage/Logs');
LoggerService::info("Log de teste informativo", ['user_id' => 1]);
LoggerService::channel('audit')->warning("Alerta de auditoria", ['action' => 'delete']);

$today = (new DateTime())->format('Y-m-d');
$appLog = $tempDir . "/storage/Logs/app-{$today}.log";
$auditLog = $tempDir . "/storage/Logs/audit-{$today}.log";

assertTest("LoggerService cria arquivo de log diário para canal padrão", file_exists($appLog) && str_contains(file_get_contents($appLog), "Log de teste informativo"));
assertTest("LoggerService suporta múltiplos canais dedicados", file_exists($auditLog) && str_contains(file_get_contents($auditLog), "Alerta de auditoria"));

// Teste do Driver JSON
LoggerService::init(driver: LoggerService::DRIVER_JSON, logDir: $tempDir . '/storage/Logs');
LoggerService::channel('json_channel')->info("Log em formato JSON", ['event' => 'order_placed']);
$jsonLog = $tempDir . "/storage/Logs/json_channel-{$today}.log";
assertTest("LoggerService grava logs em linhas JSON estruturadas", file_exists($jsonLog) && str_contains(file_get_contents($jsonLog), '"event":"order_placed"'));

// 5. Testes de RedisDriver (Mock / In-Memory Logic)
echo "\n5. Testes de RedisDriver & RedisManager:\n";
// Criar mock anônimo de RedisDriver para validar lógica de serialização e remember sem depender de servidor Redis externo rodando
$mockRedisDriver = new class extends RedisDriver {
    private array $storage = [];
    public function __construct() {
        $this->envName = 'TEST';
    }
    public function connect(): void {}
    public function set(string $key, mixed $value, ?int $ttl = null): bool {
        $payload = (is_array($value) || is_object($value))
            ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string)$value;
        $this->storage[$key] = $payload;
        return true;
    }
    public function get(string $key): mixed {
        return $this->storage[$key] ?? null;
    }
    public function exists(string $key): bool {
        return isset($this->storage[$key]);
    }
    public function del(string ...$keys): int {
        $c = 0;
        foreach ($keys as $k) {
            if (isset($this->storage[$k])) {
                unset($this->storage[$k]);
                $c++;
            }
        }
        return $c;
    }
    public function increment(string $key, int $by = 1): int {
        $curr = isset($this->storage[$key]) ? (int)$this->storage[$key] : 0;
        $this->storage[$key] = (string)($curr + $by);
        return (int)$this->storage[$key];
    }
    public function ping(): bool {
        return true;
    }
};

RedisManager::setConnection('TEST_REDIS', $mockRedisDriver);

// Teste de gravação e recuperação com serialização JSON automática
$testArray = ['user' => 'Claudecio', 'roles' => ['admin', 'dev']];
RedisManager::set('user_profile', $testArray, 3600, 'TEST_REDIS');

$retrievedJson = RedisManager::getJson('user_profile', null, 'TEST_REDIS');
assertTest("RedisManager serializa e deserializa arrays JSON automaticamente", $retrievedJson === $testArray);

// Teste do padrão remember
$calculatedTimes = 0;
$rememberResult1 = RedisManager::remember('cache_key_calc', 300, function () use (&$calculatedTimes) {
    $calculatedTimes++;
    return ['calculated_val' => 42];
}, 'TEST_REDIS');

$rememberResult2 = RedisManager::remember('cache_key_calc', 300, function () use (&$calculatedTimes) {
    $calculatedTimes++;
    return ['calculated_val' => 42];
}, 'TEST_REDIS');

assertTest("RedisManager::remember() executa callback apenas na primeira chamada (Cache-Aside)", $calculatedTimes === 1 && $rememberResult2['calculated_val'] === 42);

// Teste de Increment
$counter = RedisManager::increment('counter_test', 5, 'TEST_REDIS');
assertTest("RedisManager::increment() incrementa valor numérico", $counter === 5);

// Limpeza de diretórios temporários de teste
@array_map('unlink', glob("{$tempDir}/storage/Logs/*.*"));
@rmdir("{$tempDir}/storage/Logs");
@rmdir("{$tempDir}/storage");
@rmdir($tempDir);

echo "\n====================================================\n";
echo " Resumo dos Testes do Framework: {$passed} passaram, {$failed} falharam.\n";
echo "====================================================\n";

if ($failed > 0) {
    exit(1);
}
