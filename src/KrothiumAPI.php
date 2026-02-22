<?php
namespace KrothiumAPI;

use KrothiumAPI\Http\Router;
use KrothiumAPI\Helpers\ConstHelper;
use KrothiumAPI\Services\LoggerService;

class KrothiumAPI {
    private static array $config;

    /**
     * Inicializa e configura os componentes essenciais da aplicação.
     *
     * Este método estático é o ponto de partida para a inicialização do sistema. Ele carrega as configurações, define o tratamento de erros, gerencia a sessão, configura o fuso horário e inicializa o sistema de logs e o roteador.
     *
     * #### Fluxo de Operação:
     * 1.  **Carregamento de Configurações:** Armazena o array de configurações (`$config`) na propriedade estática da classe.
     * 2.  **Setup Essencial:** Chama métodos privados para configurar:
     * * `setupErrors()`: Configuração de exibição de erros.
     * * `setupSession()`: Inicialização da sessão PHP.
     * * `setupTimezone()`: Definição do fuso horário da aplicação.
     * * `setupConstants()`: Definição de constantes globais (se houver).
     * * `setupLogger()`: Inicialização do sistema de logs.
     * * `setupErrorHandlers()`: Definição de manipuladores de exceção e erros customizados.
     * 3.  **Inicialização do Roteador:** Chama `Router::init()` para inicializar o sistema de roteamento, preparando-o para receber e despachar requisições.
     *
     * @param array $config Um array de configurações iniciais a serem aplicadas na aplicação. Padrão: `[]`.
     * @return void
     */
    public static function init(array $config = []) {
        self::$config = $config;
        self::setupConstants();
        // Inicia o router
        Router::init();
        
        self::setupErrors();
        self::setupSession();
        self::setupTimezone();
        self::setupLogger();
        self::setupErrorHandlers();
    }

    /**
     * Configura erros
     */
    private static function setupErrors(): void {
        $errors = self::$config['errors'];
        if(ConstHelper::get(constant_name: 'APP_SYS_MODE') === 'DEV') {
            ini_set(option: 'display_errors', value: 1);
            ini_set(option: 'display_startup_errors', value: 1);
            ini_set(option: 'log_errors', value: E_ALL);
            error_reporting(error_level: 1);
            if(isset($errors['error_log'])) {
                ini_set(option: 'error_log', value: $errors['error_log']);
            }
        }
    }

    /**
     * Configura constantes
     */
    private static function setupConstants() {
        $constants = self::$config['constants'];
        if(!empty($constants)) {
            foreach ($constants as $name => $value) {
                if (!defined(constant_name: $name)) {
                    define(constant_name: $name, value: $value);
                }
            }
        }
    }

    /**
     * Configura timezone
     */
    private static function setupTimezone(): void {
        $timezone = self::$config['system']['default_timezone'];
        if(!empty($timezone)) {
            date_default_timezone_set(timezoneId: $timezone);
        }
    }

    /**
     * Configura sessão
     */
    private static function setupSession(): void {
        $startSession = self::$config['system']['enable_session'];
        if (($startSession === true) && session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Configura logger
     */
    private static function setupLogger(): void {
        $logConfig = self::$config['logger'];
        if(!empty($logConfig)) {
            LoggerService::init(
                driver: $logConfig['driver'],
                logDir: $logConfig['logDir']
            );
        }
    }

    /**
     * Configura handlers de erro para modo JSON
     */
    private static function setupErrorHandlers(): void {
        // Captura warnings / notices
        set_error_handler(callback: function ($errno, $errstr, $errfile, $errline) {
            self::jsonErrorResponse(
                message: "Erro PHP: {$errstr}", 
                code: $errno, 
                extra: [
                    'file' => $errfile,
                    'line' => $errline
                ]
            );
        });
        // Captura exceptions não tratadas
        set_exception_handler(callback: function ($exception) {
            self::jsonErrorResponse(
                message: $exception->getMessage(),
                code: $exception->getCode(),
                extra: [
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'trace' => $exception->getTrace()
                ]
            );
        });
        // Captura fatal errors (shutdown)
        register_shutdown_function(callback: function () {
            $error = error_get_last();
            if ($error !== null) {
                self::jsonErrorResponse(
                    message: $error['message'],
                    code: $error['type'],
                    extra: [
                        'file' => $error['file'],
                        'line' => $error['line']
                    ]
                );
            }
        });
    }

    /**
     * Retorna resposta JSON de erro e encerra execução
     */
    private static function jsonErrorResponse(string $message, int $code = 500, array $extra = []): void {
        // Evita headers duplicados
        if (!headers_sent()) {
            http_response_code(response_code: 500);
            header(header: 'Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(
            value: [
                'status'  => 'error',
                'message' => $message,
                'code'    => $code,
                'extra'   => $extra,
            ], 
            flags: JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
        exit;
    }

    /**
     * Dispara roteador
     */
    public static function routerDispatch() {
        if (php_sapi_name() !== 'cli') {
            Router::dispatch();
        }
    }
}