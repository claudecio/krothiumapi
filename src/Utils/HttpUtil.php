<?php
namespace KrothiumAPI\Utils;

class HttpUtil {
    private static ?string $rawBodyCache = null;
    private static ?array $parsedJsonCache = null;

    /**
     * Limpa o cache estático do corpo da requisição (útil em testes unitários).
     */
    public static function clearInputCache(): void {
        self::$rawBodyCache = null;
        self::$parsedJsonCache = null;
    }

    /**
     * Captura e filtra dados de entrada HTTP (GET, POST, COOKIE, SERVER) de forma segura.
     *
     * @param string $form_type O tipo de entrada a ser filtrada (constantes PHP: INPUT_GET, INPUT_POST, INPUT_COOKIE, INPUT_SERVER). Padrão: INPUT_GET.
     * @param array|null $filters Uma matriz de filtros a serem aplicados aos dados de entrada.
     * @param string $return_type 'array' ou 'string' (para corpo bruto).
     * @return mixed Retorna os dados de entrada filtrados.
     */
    public static function getRequestBody(string $form_type = 'GET', ?array $filters = null, string $return_type = 'array'): mixed {
        $method = strtoupper(string: trim(string: $form_type));
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        $isJson = self::isJsonContentType(contentType: $contentType);

        $inputMap = [
            'GET'    => INPUT_GET,
            'POST'   => INPUT_POST,
            'COOKIE' => INPUT_COOKIE,
            'SERVER' => INPUT_SERVER,
        ];

        // GET/POST/COOKIE/SERVER
        if (isset($inputMap[$method])) {
            if ($method === 'POST' && $isJson) {
                $raw = self::getRawBody();
                if ($return_type === 'string') {
                    return $raw;
                }
                $data = self::getParsedJson();
                unset($data['_method']);
                return self::applyFilters(data: $data ?? [], filters: $filters);
            }
            $form = filter_input_array(type: $inputMap[$method], options: $filters ?? FILTER_DEFAULT) ?? [];
            return is_array(value: $form) ? $form : [];
        }

        // PUT/PATCH/DELETE
        if (in_array(needle: $method, haystack: ['PUT', 'PATCH', 'DELETE'], strict: true)) {
            $raw = self::getRawBody();
            if ($raw === '') {
                return [];
            }
            if ($isJson) {
                if ($return_type === 'string') {
                    return $raw;
                }
                $data = self::getParsedJson();
            } else {
                parse_str($raw, $data);
            }
            unset($data['_method']);
            return self::applyFilters(data: $data ?? [], filters: $filters);
        }

        // Default: GET
        $form = filter_input_array(type: INPUT_GET, options: $filters ?? FILTER_DEFAULT) ?? [];
        return is_array(value: $form) ? $form : [];
    }

    /**
     * Retorna o payload JSON da requisição decodificado como array associativo.
     * Suporta busca por chave com notação de ponto (ex: `HttpUtil::json('user.email')`).
     */
    public static function json(?string $key = null, mixed $default = null): mixed {
        $data = self::getParsedJson();
        if ($key === null) {
            return $data;
        }

        $segments = explode('.', $key);
        $current = $data;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Retorna dados dos parâmetros de query (GET).
     */
    public static function query(?string $key = null, mixed $default = null): mixed {
        if ($key === null) {
            return $_GET ?? [];
        }
        return $_GET[$key] ?? $default;
    }

    /**
     * Retorna dados dos parâmetros POST.
     */
    public static function post(?string $key = null, mixed $default = null): mixed {
        if ($key === null) {
            return $_POST ?? [];
        }
        return $_POST[$key] ?? $default;
    }

    /**
     * Recupera um parâmetro de entrada de forma unificada (JSON body -> POST -> GET).
     */
    public static function input(?string $key = null, mixed $default = null): mixed {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        $isJson = self::isJsonContentType(contentType: $contentType);

        if ($isJson) {
            $jsonData = self::getParsedJson();
            if ($key === null) {
                return $jsonData;
            }
            if (array_key_exists($key, $jsonData)) {
                return $jsonData[$key];
            }
        }

        if (isset($_POST[$key])) {
            return $_POST[$key];
        }

        if (isset($_GET[$key])) {
            return $_GET[$key];
        }

        if ($key === null) {
            return array_merge($_GET ?? [], $_POST ?? [], $isJson ? self::getParsedJson() : []);
        }

        return $default;
    }

    /**
     * Lê um cabeçalho HTTP de forma case-insensitive.
     */
    public static function header(string $name, ?string $default = null): ?string {
        $nameUpper = strtoupper(str_replace('-', '_', $name));

        if (isset($_SERVER["HTTP_{$nameUpper}"])) {
            return (string)$_SERVER["HTTP_{$nameUpper}"];
        }

        if (isset($_SERVER[$nameUpper])) {
            return (string)$_SERVER[$nameUpper];
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                $normalized = array_change_key_case($headers, CASE_LOWER);
                $key = strtolower($name);
                if (isset($normalized[$key])) {
                    return (string)$normalized[$key];
                }
            }
        }

        return $default;
    }

    /**
     * Obtém o IP real do cliente, considerando Cloudflare e proxies reversos.
     */
    public static function ip(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_TRUE_CLIENT_IP',       // Cloudflare Enterprise / Akamai
            'HTTP_X_FORWARDED_FOR',      // Proxy chain
            'HTTP_X_REAL_IP',            // Nginx reverse proxy
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'                // Endereço direto
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ipList = explode(',', $_SERVER[$header]);
                $ip = trim($ipList[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '127.0.0.1';
    }

    /**
     * Retorna o corpo bruto da requisição em cache.
     */
    public static function getRawBody(): string {
        if (self::$rawBodyCache === null) {
            self::$rawBodyCache = file_get_contents(filename: 'php://input') ?: '';
        }
        return self::$rawBodyCache;
    }

    /**
     * Retorna o corpo JSON decodificado em cache.
     */
    public static function getParsedJson(): array {
        if (self::$parsedJsonCache === null) {
            $raw = self::getRawBody();
            if ($raw === '') {
                self::$parsedJsonCache = [];
            } else {
                $data = json_decode(json: $raw, associative: true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    self::errorJson(statusCode: 400, message: 'Invalid JSON: ' . json_last_error_msg());
                }
                self::$parsedJsonCache = is_array($data) ? $data : [];
            }
        }
        return self::$parsedJsonCache;
    }

    /**
     * Despacha uma resposta JSON customizada e encerra a execução.
     */
    public static function jsonResponse(int $response_code, ?string $message = null, ?array $output = null): void {
        $response = [];
        if ($message !== null) {
            $response['message'] = $message;
        }
        if ($output !== null) {
            $response = array_merge($response, $output);
        }

        if (!headers_sent()) {
            http_response_code(response_code: $response_code);
            header(header: 'Content-Type: application/json; charset=utf-8');
        }

        if (!empty($response)) {
            echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }
        exit;
    }

    /**
     * Despacha uma resposta padronizada de sucesso em JSON.
     */
    public static function success(mixed $data = null, string $message = 'Success', int $statusCode = 200): void {
        $payload = [
            'status'  => 'success',
            'message' => $message,
        ];
        if ($data !== null) {
            $payload['data'] = $data;
        }

        self::jsonResponse(response_code: $statusCode, output: $payload);
    }

    /**
     * Despacha uma resposta padronizada de erro em JSON.
     */
    public static function error(string $message, int $statusCode = 400, ?array $extra = null): void {
        $payload = [
            'status'  => 'error',
            'message' => $message,
            'code'    => $statusCode,
        ];
        if ($extra !== null) {
            $payload['extra'] = $extra;
        }

        self::jsonResponse(response_code: $statusCode, output: $payload);
    }

    /**
     * Realiza redirecionamento HTTP seguro e encerra a execução.
     */
    public static function redirect(string $url): void {
        $subpath = rtrim(string: self::getSubpath(), characters: '/');
        $url = str_starts_with(haystack: $url, needle: '/') ? $url : "/{$url}";

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $location = "{$subpath}{$url}" . self::getQueryString();
        header(header: "Location: {$location}");
        exit;
    }

    public static function getQueryString(): ?string {
        $parts = explode(separator: '?', string: $_SERVER['REQUEST_URI'] ?? '', limit: 2);
        $request = $parts[1] ?? null;
        return ($request !== null && $request !== '') ? "?{$request}" : '';
    }

    public static function getSubpath(): string {
        return $_SESSION['ROUTER_BASE_PATH'] ?? '';
    }

    private static function isJsonContentType(string $contentType): bool {
        return stripos(haystack: $contentType, needle: 'json') !== false;
    }

    private static function errorJson(int $statusCode, string $message): never {
        if (!headers_sent()) {
            http_response_code(response_code: $statusCode);
            header(header: "Content-Type: application/json; charset=utf-8");
        }
        echo json_encode(value: [
            "status"  => "error",
            "message" => $message,
            "code"    => $statusCode
        ], flags: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    private static function applyFilters(array $data, ?array $filters): array {
        if ($filters === null) {
            return $data;
        }
        $filtered = filter_var_array(array: $data, options: $filters, add_empty: false);
        return is_array(value: $filtered) ? $filtered : [];
    }

    /**
     * Extrai o token Bearer do cabeçalho de autenticação.
     */
    public static function getBearerToken(): ?string {
        $header = self::header('Authorization') ?? self::header('AUTHORIZATION') ?? '';
        if ($header && preg_match(pattern: '/Bearer\s(\S+)/i', subject: $header, matches: $matches)) {
            return $matches[1];
        }
        return null;
    }
}