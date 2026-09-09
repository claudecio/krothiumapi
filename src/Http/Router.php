<?php
namespace KrothiumAPI\Http;

use Closure;
use Exception;
use Throwable;
use KrothiumAPI\Helpers\ConstHelper;
use KrothiumAPI\Utils\HttpUtil;

class Router {
    private static array $routes = [];
    private static array $params = [];
    private static array $namedParams = [];
    private static ?string $APP_SYS_MODE = null;
    private static string $basePath = '';
    private static string $currentGroupPrefix = '';
    private static array $currentGroupMiddlewares = [];
    private static array $middlewareRegistry = [];
    private static array $ROUTER_ALLOWED_ORIGINS = ['*'];
    private static array $ROUTER_ALLOWED_HEADERS = ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept', 'Origin', 'X-API-Key'];
    private static array $requiredConstants = ['APP_SYS_MODE'];
    private static array $allowedHttpRequests = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /**
     * @var callable|null
     */
    private static mixed $notFoundHandler = null;

    /**
     * @var callable|null
     */
    private static mixed $methodNotAllowedHandler = null;

    /**
     * Inicializa o roteador e define as configurações essenciais.
     */
    public static function init(): void {
        self::checkRequiredConstants();

        $configuredBasePath = ConstHelper::get(constant_name: 'ROUTER_BASE_PATH');
        self::$basePath = !empty($configuredBasePath) ? '/' . trim(string: (string)$configuredBasePath, characters: '/') : '';
        $_SESSION['ROUTER_BASE_PATH'] = self::$basePath;

        self::$APP_SYS_MODE = strtoupper(string: (string)ConstHelper::get(constant_name: 'APP_SYS_MODE', default: 'DEV'));

        $allowedOrigins = ConstHelper::get(constant_name: 'ROUTER_ALLOWED_ORIGINS');
        if ($allowedOrigins !== null && is_array($allowedOrigins)) {
            self::$ROUTER_ALLOWED_ORIGINS = $allowedOrigins;
        }

        $allowedHeaders = ConstHelper::get(constant_name: 'ROUTER_ALLOWED_HEADERS');
        if ($allowedHeaders !== null && is_array($allowedHeaders)) {
            self::$ROUTER_ALLOWED_HEADERS = $allowedHeaders;
        }
    }

    /**
     * Limpa as rotas e configurações registradas (útil para testes unitários).
     */
    public static function clearRoutes(): void {
        self::$routes = [];
        self::$params = [];
        self::$namedParams = [];
        self::$currentGroupPrefix = '';
        self::$currentGroupMiddlewares = [];
        self::$notFoundHandler = null;
        self::$methodNotAllowedHandler = null;
    }

    /**
     * Retorna a lista de todas as rotas registradas.
     */
    public static function getRoutes(): array {
        return self::$routes;
    }

    /**
     * Define um handler customizado para rotas não encontradas (404).
     */
    public static function setNotFoundHandler(callable $handler): void {
        self::$notFoundHandler = $handler;
    }

    /**
     * Define um handler customizado para métodos HTTP não permitidos (405).
     */
    public static function setMethodNotAllowedHandler(callable $handler): void {
        self::$methodNotAllowedHandler = $handler;
    }

    /**
     * Envia uma resposta JSON padronizada de erro e encerra a execução do script.
     */
    private static function error(int $code, string $msg): void {
        if (!headers_sent()) {
            http_response_code(response_code: $code);
            header(header: 'Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(
            value: [
                'status'  => 'error',
                'message' => $msg,
                'code'    => $code,
            ],
            flags: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
        exit;
    }

    /**
     * Valida as constantes obrigatórias do sistema.
     */
    private static function checkRequiredConstants(): void {
        foreach (self::$requiredConstants as $constant) {
            if (!defined(constant_name: $constant)) {
                self::error(code: 500, msg: "Constant '{$constant}' not defined.");
            }
        }
    }

    // =====================================
    // Métodos HTTP para definição de rotas
    // =====================================
    public static function get(string $uri, callable|array|string $handler, array $middlewares = []): void {
        self::addRoute(method: 'GET', uri: $uri, handler: $handler, middlewares: $middlewares);
    }

    public static function post(string $uri, callable|array|string $handler, array $middlewares = []): void {
        self::addRoute(method: 'POST', uri: $uri, handler: $handler, middlewares: $middlewares);
    }

    public static function put(string $uri, callable|array|string $handler, array $middlewares = []): void {
        self::addRoute(method: 'PUT', uri: $uri, handler: $handler, middlewares: $middlewares);
    }

    public static function patch(string $uri, callable|array|string $handler, array $middlewares = []): void {
        self::addRoute(method: 'PATCH', uri: $uri, handler: $handler, middlewares: $middlewares);
    }

    public static function delete(string $uri, callable|array|string $handler, array $middlewares = []): void {
        self::addRoute(method: 'DELETE', uri: $uri, handler: $handler, middlewares: $middlewares);
    }

    public static function any(string $uri, callable|array|string $handler, array $middlewares = []): void {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            self::addRoute(method: $method, uri: $uri, handler: $handler, middlewares: $middlewares);
        }
    }

    public static function resource(string $uri, string $controller, array $middlewares = []): void {
        $baseUri = '/' . trim(string: $uri, characters: '/');

        self::get($baseUri, [$controller, 'index'], $middlewares);
        self::get($baseUri . '/{id}', [$controller, 'read'], $middlewares);
        self::post($baseUri, [$controller, 'create'], $middlewares);
        self::put($baseUri . '/{id}', [$controller, 'update'], $middlewares);
        self::patch($baseUri . '/{id}', [$controller, 'update'], $middlewares);
        self::delete($baseUri . '/{id}', [$controller, 'delete'], $middlewares);
    }

    public static function defineMiddleware(string $name, array|string $middleware): void {
        self::$middlewareRegistry[strtolower(string: $name)] = is_array($middleware) && isset($middleware[0]) && is_array($middleware[0])
            ? $middleware
            : [$middleware];
    }

    public static function defineMiddlewareStack(string $name, array $middlewares): void {
        self::$middlewareRegistry[strtolower(string: $name)] = $middlewares;
    }

    /**
     * Adiciona uma rota ao catálogo do roteador.
     */
    private static function addRoute(string $method, string $uri, callable|array|string $handler, array $middlewares = []): void {
        $path = '/' . trim(string: self::$currentGroupPrefix . '/' . trim(string: $uri, characters: '/'), characters: '/');
        $normalizedHandler = self::normalizeHandler(handler: $handler);
        $resolvedMiddlewares = self::resolveMiddlewares(middlewares: array_merge(self::$currentGroupMiddlewares, $middlewares));

        self::$routes[$method][] = [
            'method'      => $method,
            'path'        => $path,
            'handler'     => $normalizedHandler,
            'middlewares' => $resolvedMiddlewares,
        ];
    }

    /**
     * Agrupa rotas sob um prefixo e middlewares compartilhados.
     */
    public static function group(string $prefix, callable $callback, array $middlewares = []): void {
        $previousPrefix = self::$currentGroupPrefix ?? '';
        $previousMiddlewares = self::$currentGroupMiddlewares ?? [];

        self::$currentGroupPrefix = $previousPrefix . '/' . trim($prefix, '/');
        self::$currentGroupMiddlewares = array_merge($previousMiddlewares, $middlewares);

        try {
            $callback();
        } finally {
            self::$currentGroupPrefix = $previousPrefix;
            self::$currentGroupMiddlewares = $previousMiddlewares;
        }
    }

    /**
     * Normaliza a definição do handler (Closure, Controller@method, [Class, method]).
     */
    private static function normalizeHandler(callable|array|string $handler): mixed {
        if ($handler instanceof Closure || (is_object($handler) && is_callable($handler))) {
            return $handler;
        }

        if (is_string($handler)) {
            if (str_contains($handler, '@')) {
                return explode(separator: '@', string: $handler, limit: 2);
            }
            if (str_contains($handler, '::')) {
                return explode(separator: '::', string: $handler, limit: 2);
            }
            if (is_callable($handler)) {
                return $handler;
            }

            self::error(code: 500, msg: "Invalid handler format. Expected: Closure, ['Controller', 'method'], 'Controller@method' or 'Controller::method'.");
        }

        if (is_array($handler)) {
            if (count($handler) < 2) {
                self::error(code: 500, msg: "Invalid handler format. Expected: ['Controller', 'method'].");
            }
            return array_values(array_slice($handler, 0, 2));
        }

        self::error(code: 500, msg: "Invalid handler format provided.");
    }

    private static function resolveMiddlewares(array $middlewares, array $stack = []): array {
        $resolved = [];

        foreach ($middlewares as $middleware) {
            if (is_string($middleware)) {
                $registryKey = strtolower(string: $middleware);
                if (!isset(self::$middlewareRegistry[$registryKey])) {
                    self::error(code: 500, msg: "Middleware alias '{$middleware}' not defined.");
                }

                if (in_array(needle: $registryKey, haystack: $stack, strict: true)) {
                    self::error(code: 500, msg: "Circular middleware alias detected: '{$middleware}'.");
                }

                $resolved = array_merge(
                    $resolved,
                    self::resolveMiddlewares(
                        middlewares: self::$middlewareRegistry[$registryKey],
                        stack: array_merge($stack, [$registryKey])
                    )
                );
                continue;
            }

            if (!is_array($middleware) || count(value: $middleware) < 2) {
                self::error(code: 500, msg: "Invalid middleware format. Expected: [Class::class, 'method', ...args] or a defined alias.");
            }

            $resolved[] = $middleware;
        }

        return $resolved;
    }

    /**
     * Compara o padrão de rota com a URI da requisição e extrai parâmetros.
     */
    private static function matchPath(string $routePath, string $requestPath): bool {
        self::$params = [];
        self::$namedParams = [];

        $routeTrimmed = trim(string: $routePath, characters: '/');
        $reqTrimmed = trim(string: $requestPath, characters: '/');

        if ($routeTrimmed === '' && $reqTrimmed === '') {
            return true;
        }

        $routeParts = explode(separator: '/', string: $routeTrimmed);
        $reqParts = explode(separator: '/', string: $reqTrimmed);

        if (count($routeParts) !== count($reqParts)) {
            return false;
        }

        foreach ($routeParts as $i => $part) {
            if (preg_match(pattern: '/^\{([a-zA-Z0-9_]+)\}$/', subject: $part, matches: $matches)) {
                $paramName = $matches[1];
                $paramValue = $reqParts[$i];
                self::$params[] = $paramValue;
                self::$namedParams[$paramName] = $paramValue;
            } elseif ($part !== $reqParts[$i]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Retorna os parâmetros dinâmicos de rota.
     */
    public static function getParams(): array {
        return self::$params;
    }

    /**
     * Retorna os parâmetros nomeados de rota.
     */
    public static function getNamedParams(): array {
        return self::$namedParams;
    }

    private static function matchRoute(string $method, string $uri, array $route): bool {
        return $route['method'] === $method && self::matchPath(routePath: $route['path'], requestPath: $uri);
    }

    /**
     * Configura cabeçalhos de CORS.
     */
    private static function corsSetup(string $method): void {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
        $allowAll = in_array(needle: '*', haystack: self::$ROUTER_ALLOWED_ORIGINS, strict: true);
        $isAllowed = in_array(needle: $origin, haystack: self::$ROUTER_ALLOWED_ORIGINS, strict: true);

        if ($allowAll || $isAllowed || self::$APP_SYS_MODE === 'DEV') {
            header(header: "Access-Control-Allow-Origin: {$origin}");
            if ($origin !== '*') {
                header(header: "Access-Control-Allow-Credentials: true");
            }
        } else {
            self::error(code: 403, msg: "Origin '{$origin}' not allowed by CORS.");
        }

        $allowedRequests = implode(separator: ', ', array: self::$allowedHttpRequests);
        $allowedHeaders = implode(separator: ', ', array: self::$ROUTER_ALLOWED_HEADERS);

        header(header: "Access-Control-Allow-Methods: {$allowedRequests}");
        header(header: "Access-Control-Allow-Headers: {$allowedHeaders}");
        header(header: "Access-Control-Max-Age: 86400");

        if ($method === 'OPTIONS') {
            http_response_code(response_code: 204);
            exit;
        }
    }

    /**
     * Executa a pilha de middlewares da rota.
     */
    public static function runMiddlewares(array $middlewares): bool {
        foreach ($middlewares as $middleware) {
            try {
                if (!is_array(value: $middleware) || count(value: $middleware) < 2) {
                    self::error(code: 500, msg: "Invalid middleware format. Expected: [Class::class, 'method', ...args]");
                }

                $class = $middleware[0];
                $method = $middleware[1];
                $args = array_slice(array: $middleware, offset: 2);

                if (is_object($class)) {
                    $instance = $class;
                } else {
                    if (!class_exists(class: $class)) {
                        self::error(code: 500, msg: "Middleware class '{$class}' not found.");
                    }
                    $instance = new $class();
                }

                if (!method_exists(object_or_class: $instance, method: $method)) {
                    $className = is_object($class) ? get_class($class) : $class;
                    self::error(code: 500, msg: "Method '{$method}' does not exist in class '{$className}'.");
                }

                $result = call_user_func_array(callback: [$instance, $method], args: $args);

                // Bloqueio booleano simples
                if ($result === false) {
                    $className = is_object($class) ? get_class($class) : $class;
                    self::error(code: 403, msg: "{$className}::{$method} blocked the request.");
                }

                // Bloqueio estruturado em array
                if (is_array(value: $result)) {
                    $block = $result['block'] ?? null;
                    $status = $result['status'] ?? null;
                    $responseCode = isset($result['response_code']) ? (int)$result['response_code'] : null;

                    $shouldBlock = ($block === true)
                        || ($status !== null && $status !== 'success')
                        || ($responseCode !== null && in_array(needle: $responseCode, haystack: [401, 403, 422, 500], strict: true));

                    if ($shouldBlock) {
                        $code = $responseCode ?? 403;
                        $className = is_object($class) ? get_class($class) : $class;
                        $msg = (string)($result['message'] ?? "{$className}::{$method} blocked the request.");
                        $jsonResponse = ['status' => 'error', 'message' => $msg];

                        if (isset($result['output']) && $result['output'] !== null && $result['output'] !== '') {
                            $jsonResponse['output'] = $result['output'];
                        }

                        http_response_code(response_code: $code);
                        header(header: 'Content-Type: application/json; charset=utf-8');
                        echo json_encode(value: $jsonResponse, flags: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                        exit;
                    }
                }
            } catch (Exception $e) {
                self::error(code: 500, msg: $e->getMessage());
            }
        }
        return true;
    }

    /**
     * Executa o handler da rota e trata automaticamente o retorno (JSON ou String).
     */
    private static function executeHandler(mixed $handler, array $params): void {
        $result = null;

        if ($handler instanceof Closure || (is_object($handler) && is_callable($handler))) {
            $result = call_user_func_array($handler, $params);
        } elseif (is_array($handler)) {
            $class = $handler[0];
            $action = $handler[1];

            $controller = is_object($class) ? $class : new $class();
            if (!method_exists(object_or_class: $controller, method: $action)) {
                $className = is_object($class) ? get_class($class) : $class;
                self::error(code: 500, msg: "Method '{$action}' not found in controller '{$className}'.");
            }

            $result = call_user_func_array(callback: [$controller, $action], args: $params);
        }

        // Se o método/closure retornou um valor estruturado ou texto, formata a resposta
        if ($result !== null) {
            if (is_array($result) || is_object($result)) {
                HttpUtil::jsonResponse(response_code: 200, output: is_array($result) ? $result : (array)$result);
            } elseif (is_string($result) || is_numeric($result)) {
                echo $result;
                exit;
            }
        }

        exit;
    }

    /**
     * Orquestra o ciclo de vida da requisição HTTP.
     */
    public static function dispatch(): void {
        self::checkRequiredConstants();

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $uri = trim(string: (string)parse_url(url: $requestUri, component: PHP_URL_PATH), characters: '/');

        if ($method === 'POST' && isset($_POST['_method'])) {
            $method = strtoupper(string: (string)$_POST['_method']);
        }

        if (!in_array(needle: $method, haystack: self::$allowedHttpRequests, strict: true)) {
            if (self::$methodNotAllowedHandler !== null) {
                call_user_func(self::$methodNotAllowedHandler, $method, $uri);
                exit;
            }
            self::error(code: 405, msg: "HTTP method '{$method}' not allowed.");
        }

        self::corsSetup(method: $method);

        // Remove o basePath se configurado
        if (!empty(self::$basePath)) {
            $trimmedBase = trim(string: self::$basePath, characters: '/');
            if (str_starts_with(haystack: $uri, needle: $trimmedBase)) {
                $uri = substr(string: $uri, offset: strlen(string: $trimmedBase));
            }
        }
        $uri = '/' . trim(string: $uri, characters: '/');

        // Busca correspondência nas rotas registradas para o método
        foreach (self::$routes[$method] ?? [] as $route) {
            if (!self::matchRoute(method: $method, uri: $uri, route: $route)) {
                continue;
            }

            // Executa middlewares
            if (!empty($route['middlewares']) && !self::runMiddlewares(middlewares: $route['middlewares'])) {
                return;
            }

            self::executeHandler(handler: $route['handler'], params: self::$params);
        }

        // Se nenhuma rota for encontrada (404)
        if (self::$notFoundHandler !== null) {
            call_user_func(self::$notFoundHandler, $uri, $method);
            exit;
        }

        self::error(
            code: 404,
            msg: 'Page not found.'
        );
    }
}