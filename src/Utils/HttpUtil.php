<?php
namespace KrothiumAPI\Utils;

class HttpUtil {
    /**
     * Captura e filtra dados de entrada HTTP (GET, POST, COOKIE, SERVER) de forma segura.
     *
     * Este método centraliza a obtenção de dados de requisição, aplicando opcionalmente filtros.
     * Ele lida com submissões **POST** padrão (urlencoded) e submissões **JSON** (tipo "application/json"),
     * retornando os dados como um array associativo ou como a string JSON bruta, se especificado.
     * Em caso de JSON inválido, a função encerra a execução e retorna um erro HTTP 500.
     *
     * @param string $form_type O tipo de entrada a ser filtrada (constantes PHP: INPUT_GET, INPUT_POST, INPUT_COOKIE, INPUT_SERVER). O padrão é INPUT_GET.
     * @param array|null $filters Uma matriz de filtros a serem aplicados aos dados de entrada, compatível com a função `filter_input_array()`.
     * @return array|string Retorna um array associativo dos dados de entrada filtrados (ou array vazio se não houver dados), ou a string JSON bruta se $asJson for true e o POST for JSON.
     * @return mixed Encerra a execução e envia uma resposta HTTP 500 se ocorrer um erro de decodificação JSON.
     */
    public static function getRequestBody(string $form_type = 'GET', ?array $filters = null, string $return_type = 'array'): mixed {
        $method = strtoupper(string: trim(string: $form_type));
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $isJson = self::isJsonContentType(contentType: $contentType);

        $inputMap = [
            'GET'    => INPUT_GET,
            'POST'   => INPUT_POST,
            'COOKIE' => INPUT_COOKIE,
            'SERVER' => INPUT_SERVER,
        ];

        // GET/POST/COOKIE/SERVER
        if (isset($inputMap[$method])) {
            // POST JSON: lê o body
            if ($method === 'POST' && $isJson) {
                $raw = file_get_contents(filename: 'php://input') ?: '';
                if ($return_type === 'string') {
                    return $raw;
                }
                $data = json_decode(json: $raw, associative: true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    self::errorJson(statusCode: 400, message: 'Invalid JSON: ' . json_last_error_msg());
                }
                unset($data['_method']);
                return self::applyFilters(data: $data ?? [], filters: $filters);
            }
            $form = filter_input_array(type: $inputMap[$method], options: $filters ?? FILTER_DEFAULT) ?? [];
            return is_array(value: $form) ? $form : [];
        }

        // PUT/PATCH/DELETE
        if (in_array(needle: $method, haystack: ['PUT', 'PATCH', 'DELETE'], strict: true)) {
            $raw = file_get_contents(filename: 'php://input') ?: '';
            if ($raw === '') {
                return [];
            }
            if ($isJson) {
                if ($return_type === 'string') {
                    return $raw;
                }
                $data = json_decode(json: $raw, associative: true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    self::errorJson(statusCode: 400, message: 'Invalid JSON: ' . json_last_error_msg());
                }
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
     * Despacha uma resposta JSON customizada e encerra a execução do script.
     * Ele permite a mesclagem (merge) de um array de saída personalizado 
     * diretamente na raiz do objeto JSON, além de suportar mensagens de feedback opcionais.
     *
     * @param int $response_code Código de status HTTP (ex: 200, 201, 403).
     * @param string|null $message Mensagem de texto opcional para o cliente.
     * @param array|null $output Array associativo de dados extras a serem mesclados na resposta.
     * @return void Este método interrompe o fluxo do programa imediatamente.
     */
    public static function jsonResponse(int $response_code, ?string $message = null, ?array $output = null): void {
        // Constrói o array de resposta com os campos "message" e "data" se eles forem fornecidos
        $response = [];
        if ($message) {
            $response['message'] = $message;
        }
        if ($output) {
            $response = array_merge($response, $output);
        }
        // Define o código de resposta HTTP e o cabeçalho de conteúdo, e envia a resposta JSON
        http_response_code(response_code: $response_code);
        header(header: 'Content-Type: application/json; charset=utf-8');
        if (!empty($response)) {
            echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }
        exit;
    }

    /**
     * Realiza o redirecionamento do navegador para uma nova URL e encerra a execução do script.
     *
     * @param string $url O endereço de destino (URL absoluta ou caminho relativo) para onde o usuário será redirecionado.
     * @return void Não retorna valor, pois encerra a execução do processo PHP.
     */
    public static function redirect(string $url): void {
        // Remove barra final do subpath para evitar //
        $subpath = rtrim(string: self::getSubpath(), characters: '/');
        // Garante que a URL comece com /
        $url = str_starts_with(haystack: $url, needle: '/')
            ? $url
            : "/{$url}";
        // Persiste sessão antes do redirect
        session_write_close();
        // Monta URL final
        $location = "{$subpath}{$url}" . self::getQueryString();
        header(header: "Location: {$location}");
        exit;
    }

    /**
     * Extrai a string de query da URI da requisição.
     *
     * @return string|null A string de query completa, ou `null` se a URI não contiver uma string de query.
     */
    public static function getQueryString(): ?string {
        $parts = explode(separator: '?', string: $_SERVER['REQUEST_URI'], limit: 2); // limite 2 garante que só divide em duas partes
        $request = $parts[1] ?? null; // se não existir, define null
        return ($request !== null && $request !== '') ? "?{$request}" : '';
    }

    public static function getSubpath(): string {
        return $_SESSION['ROUTER_BASE_PATH'] ?? '';
    }

    /**
     * Verifica se o cabeçalho de tipo de conteúdo (Content-Type) da requisição indica um formato JSON.
     *
     * @param string $contentType O valor bruto extraído do cabeçalho `$_SERVER['CONTENT_TYPE']`.
     * @return bool Retorna `true` se o formato JSON for detectado, caso contrário, `false`.
     */
    private static function isJsonContentType(string $contentType): bool {
        // pega application/json, application/json; charset=utf-8, application/vnd.api+json, etc.
        return stripos(haystack: $contentType, needle: 'json') !== false;
    }

    /**
     * Interrompe a execução do script e envia uma resposta de erro padronizada em formato JSON.
     *
     * @param int $statusCode Código de status HTTP (ex: 400, 403, 404, 500).
     * @param string $message Mensagem descritiva detalhando o motivo do erro.
     * @return never Este método encerra a execução do script e nunca retorna ao chamador.
     */
    private static function errorJson(int $statusCode, string $message): never {
        http_response_code(response_code: $statusCode);
        header(header: "Content-Type: application/json; charset=utf-8");
        echo json_encode(value: [
            "status"  => "error",
            "message" => $message,
        ]);
        exit;
    }

    /**
     * Aplica filtros de higienização e validação em um conjunto de dados brutos de forma segura.
     *
     * @param array $data O array associativo de dados brutos a serem processados.
     * @param array|null $filters O mapa de definições de filtros (ex: `['id' => FILTER_VALIDATE_INT]`).
     * @return array O conjunto de dados resultantes após a validação e higienização.
     */
    private static function applyFilters(array $data, ?array $filters): array {
        if ($filters === null) {
            return $data;
        }
        $filtered = filter_var_array(array: $data, options: $filters, add_empty: false);
        return is_array(value: $filtered) ? $filtered : [];
    }

    /**
     * Extrai o token de autenticação do tipo 'Bearer' do cabeçalho da requisição HTTP.
     *
     * @return string|null O token de autenticação do tipo 'Bearer' como uma string, ou `null` 
     * se o cabeçalho não for encontrado ou não estiver no formato esperado.
     */
    public static function getBearerToken(): ?string {
        // Tenta pegar o header de todas as fontes possíveis
        $headers = $_SERVER['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? (function_exists(function: 'apache_request_headers') ? apache_request_headers() : []);
        // Se veio do apache_request_headers, normaliza chaves
        if (is_array(value: $headers)) {
            $headers = array_change_key_case(array: $headers, case: CASE_LOWER);
            $headers = $headers['authorization'] ?? '';
        }
        $headers = trim(string: $headers);
        if ($headers && preg_match(pattern: '/Bearer\s(\S+)/', subject: $headers, matches: $matches)) {
            return $matches[1];
        }
        return null;
    }
}