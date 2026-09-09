<?php
namespace KrothiumAPI;

use Throwable;
use KrothiumAPI\Http\Router;
use KrothiumAPI\Helpers\ConstHelper;
use KrothiumAPI\Services\LoggerService;

class KrothiumAPI {
    private static array $defaultConfig = [
        'app' => [
            'mode' => 'DEV',
        ],
        'paths' => [
            'root' => null,
            'src' => null,
            'module' => null,
            'storage' => null,
            'component' => null,
        ],
        'router' => [
            'base_path' => '',
            'allowed_origins' => ['*'],
            'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept', 'Origin', 'X-API-Key'],
        ],
        'system' => [
            'enable_session' => true,
            'default_timezone' => 'UTC',
        ],
        'errors' => [
            'error_log' => null,
        ],
        'logger' => [
            'driver' => null,
            'logDir' => null,
        ],
        'constants' => [],
    ];

    private static array $config = [];

    /**
     * Inicializa e configura os componentes essenciais da aplicação.
     *
     * @param array $config Um array de configurações iniciais a serem aplicadas na aplicação. Padrão: `[]`.
     * @return void
     */
    public static function init(array $config = []): void {
        self::$config = self::normalizeConfig($config);
        self::setupConstants();
        self::setupErrors();
        self::setupSession();
        self::setupTimezone();
        self::setupLogger();
        self::setupErrorHandlers();
    }

    /**
     * Recupera um item de configuração ou toda a árvore de configuração.
     * Suporta notação de ponto (ex: `KrothiumAPI::config('app.mode')`).
     */
    public static function config(?string $key = null, mixed $default = null): mixed {
        if ($key === null) {
            return self::$config;
        }

        $segments = explode('.', $key);
        $current = self::$config;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    private static function normalizeConfig(array $config): array {
        return array_replace_recursive(self::$defaultConfig, $config);
    }

    /**
     * Configura ambiente de exibição e relatório de erros.
     */
    private static function setupErrors(): void {
        $errors = self::$config['errors'];
        $isDev = ConstHelper::get(constant_name: 'APP_SYS_MODE') === 'DEV';

        if ($isDev) {
            ini_set(option: 'display_errors', value: '1');
            ini_set(option: 'display_startup_errors', value: '1');
            ini_set(option: 'log_errors', value: '1');
            error_reporting(error_level: E_ALL);
        } else {
            ini_set(option: 'display_errors', value: '0');
            ini_set(option: 'display_startup_errors', value: '0');
            ini_set(option: 'log_errors', value: '1');
            error_reporting(error_level: E_ALL & ~E_DEPRECATED & ~E_STRICT);
        }

        if (!empty($errors['error_log'])) {
            ini_set(option: 'error_log', value: $errors['error_log']);
        }
    }

    /**
     * Configura constantes essenciais
     */
    private static function setupConstants(): void {
        $constants = array_merge(
            [
                'APP_SYS_MODE' => self::$config['app']['mode'],
                'ROOT_SYSTEM_PATH' => self::$config['paths']['root'],
                'INI_SYSTEM_PATH' => self::$config['paths']['src'],
                'MODULE_PATH' => self::$config['paths']['module'],
                'STORAGE_FOLDER_PATH' => self::$config['paths']['storage'],
                'COMPONENT_PATH' => self::$config['paths']['component'],
                'ROUTER_BASE_PATH' => self::$config['router']['base_path'],
                'ROUTER_ALLOWED_ORIGINS' => self::$config['router']['allowed_origins'],
                'ROUTER_ALLOWED_HEADERS' => self::$config['router']['allowed_headers'] ?? ['*'],
            ],
            self::$config['constants']
        );

        foreach ($constants as $name => $value) {
            if ($value === null) {
                continue;
            }

            if (!defined(constant_name: $name)) {
                define(constant_name: $name, value: $value);
            }
        }
    }

    /**
     * Configura fuso horário padrão
     */
    private static function setupTimezone(): void {
        $timezone = self::$config['system']['default_timezone'];
        if (!empty($timezone)) {
            date_default_timezone_set(timezoneId: $timezone);
        }
    }

    /**
     * Configura sessão PHP
     */
    private static function setupSession(): void {
        $startSession = self::$config['system']['enable_session'];
        if (($startSession === true) && session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Configura logger da aplicação
     */
    private static function setupLogger(): void {
        $logConfig = self::$config['logger'];
        if (!empty($logConfig['driver'])) {
            LoggerService::init(
                driver: $logConfig['driver'],
                logDir: $logConfig['logDir'] ?? null
            );
        }
    }

    /**
     * Configura manipuladores de erro e exceção com respostas padronizadas em JSON
     */
    private static function setupErrorHandlers(): void {
        // Captura warnings / notices
        set_error_handler(callback: function ($errno, $errstr, $errfile, $errline) {
            // Respeita operador de supressão de erro (@)
            if (!(error_reporting() & $errno)) {
                return false;
            }

            self::jsonErrorResponse(
                message: "PHP Error: {$errstr}",
                code: 500,
                extra: [
                    'severity' => $errno,
                    'file' => $errfile,
                    'line' => $errline
                ]
            );
        });

        // Captura exceções e Throwable não tratados
        set_exception_handler(callback: function (Throwable $exception) {
            $code = $exception->getCode();
            $httpCode = (is_int($code) && $code >= 400 && $code <= 599) ? $code : 500;

            self::jsonErrorResponse(
                message: $exception->getMessage(),
                code: $httpCode,
                extra: [
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'trace' => $exception->getTrace()
                ]
            );
        });

        // Captura fatal errors no encerramento (shutdown)
        register_shutdown_function(callback: function () {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                self::jsonErrorResponse(
                    message: $error['message'],
                    code: 500,
                    extra: [
                        'type' => $error['type'],
                        'file' => $error['file'],
                        'line' => $error['line']
                    ]
                );
            }
        });
    }

    /**
     * Retorna resposta JSON de erro e encerra execução com segurança de ambiente
     */
    private static function jsonErrorResponse(string $message, int $code = 500, array $extra = []): void {
        $isDev = ConstHelper::get(constant_name: 'APP_SYS_MODE') === 'DEV';
        $httpCode = ($code >= 400 && $code <= 599) ? $code : 500;

        // Se o LoggerService estiver disponível, grava o log do erro
        if (LoggerService::isInitialized()) {
            try {
                LoggerService::error($message, $extra);
            } catch (Throwable) {
                // Previne falhas recursivas
            }
        }

        if (!headers_sent()) {
            http_response_code(response_code: $httpCode);
            header(header: 'Content-Type: application/json; charset=utf-8');
        }

        $payload = [
            'status'  => 'error',
            'message' => ($isDev || $httpCode < 500) ? $message : 'An internal server error occurred.',
            'code'    => $httpCode,
        ];

        if ($isDev && !empty($extra)) {
            $payload['extra'] = $extra;
        }

        echo json_encode(
            value: $payload,
            flags: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
        exit;
    }

    /**
     * Dispara o roteador caso não esteja rodando em linha de comando (CLI)
     */
    public static function routerDispatch(): void {
        if (php_sapi_name() !== 'cli') {
            Router::init();
            Router::dispatch();
        }
    }
}