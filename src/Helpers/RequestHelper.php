<?php
namespace KrothiumAPI\Helpers;

class RequestHelper {
    /**
     * Extrai, decodifica e filtra dados de requisições HTTP de forma polimórfica.
     *
     * Este método é o motor de captura de dados da aplicação. Ele abstrai a complexidade de lidar com 
     * diferentes verbos HTTP (GET, POST, PUT, PATCH, DELETE) e formatos de conteúdo (Form-Data, JSON, 
     * URL-Encoded). O método detecta automaticamente se a requisição contém um payload JSON, gerencia 
     * a leitura do fluxo `php://input` para métodos não-nativos do PHP e aplica filtros de 
     * higienização customizados ou padrões.
     *
     * 
     *
     * ---
     * ## Fluxo de Inteligência
     * 1. **Mapeamento de Superglobais:** Utiliza `filter_input_array` para métodos padrão (GET/POST/COOKIE), garantindo acesso seguro às variáveis globais do PHP.
     * 2. **Processamento JSON:** Se o cabeçalho `Content-Type` indicar JSON, o método lê o corpo bruto da requisição, decodifica-o e valida a sintaxe. Caso o JSON seja inválido, interrompe a execução com um erro 400.
     * 3. **Suporte a Métodos Modernos (REST):** Para PUT, PATCH e DELETE, extrai os dados via `php://input`. Se não for JSON, processa como string de consulta (`parse_str`), permitindo que esses métodos funcionem como um formulário convencional.
     * 4. **Limpeza de Protocolo:** Remove automaticamente a chave `_method`, comumente usada para emular métodos HTTP em formulários HTML, evitando que dados de controle "vazem" para a lógica de negócio.
     * 5. **Filtragem:** Delega ao método interno `applyFilters` a responsabilidade de validar os dados finais contra o mapa de filtros fornecido.
     *
     * ---
     * ## Parâmetros e Retorno
     * - **Tipos de Retorno:** Pode retornar o `array` processado (padrão) ou a `string` bruta (útil para logs ou validações externas).
     * - **Segurança:** Bloqueia a execução em caso de payloads corrompidos via `errorJson`.
     *
     * @param string $form_type O método de entrada (GET, POST, PUT, PATCH, DELETE, COOKIE, SERVER).
     * @param array|null $filters Mapa de filtros de higienização (ex: `FILTER_SANITIZE_STRING`).
     * @param string $return_type Define o formato de saída: 'array' ou 'string'.
     * @return mixed O conjunto de dados filtrados ou a string bruta da requisição.
     */
    public static function getRequestData(string $form_type = 'GET', ?array $filters = null, string $return_type = 'array'): mixed {
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
        if(isset($inputMap[$method])) {

            // POST JSON: lê o body
            if($method === 'POST' && $isJson) {
                $raw = file_get_contents(filename: 'php://input') ?: '';

                if($return_type === 'string') {
                    return $raw;
                }

                $data = json_decode(json: $raw, associative: true);
                if(json_last_error() !== JSON_ERROR_NONE) {
                    self::errorJson(statusCode: 400, message: 'Invalid JSON: ' . json_last_error_msg());
                }

                unset($data['_method']);
                return self::applyFilters(data: $data ?? [], filters: $filters);
            }

            $form = filter_input_array(type: $inputMap[$method], options: $filters ?? FILTER_DEFAULT) ?? [];
            return is_array(value: $form) ? $form : [];
        }

        // PUT/PATCH/DELETE
        if(in_array(needle: $method, haystack: ['PUT', 'PATCH', 'DELETE'], strict: true)) {
            $raw = file_get_contents(filename: 'php://input') ?: '';
            if($raw === '') {
                return [];
            }

            if($isJson) {
                if($return_type === 'string') {
                    return $raw;
                }

                $data = json_decode(json: $raw, associative: true);
                if(json_last_error() !== JSON_ERROR_NONE) {
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
     * Verifica se o cabeçalho de tipo de conteúdo (Content-Type) da requisição indica um formato JSON.
     *
     * Este método auxiliar é fundamental para a estratégia de parsing polimórfico do sistema. Em vez de 
     * realizar uma comparação estrita, ele utiliza uma busca de substring insensível a maiúsculas 
     * e minúsculas para identificar a presença da palavra "json". Isso garante compatibilidade com 
     * diversas variações de cabeçalhos comuns em APIs modernas, incluindo definições de charset e 
     * Media Types específicos.
     *
     * 
     *
     * ---
     * ## Casos de Compatibilidade
     * O método retorna `true` para padrões como:
     * - `application/json` (Padrão RFC 4627)
     * - `application/json; charset=utf-8` (Com especificação de codificação)
     * - `application/vnd.api+json` (Padrão JSON:API)
     * - `text/json` (Legado ou variações de servidores específicos)
     *
     * ---
     * ## Lógica de Implementação
     * 1. **Busca Flexível:** Utiliza `stripos()` para localizar a agulha 'json' em qualquer posição da string.
     * 2. **Performance:** Por ser um método estático e focado em uma única responsabilidade, oferece baixo custo computacional para o ciclo de vida da requisição.
     * 3. **Normalização:** Previne erros de detecção causados por variações de caixa (UpperCase vs LowerCase) enviadas por diferentes clientes HTTP (Browsers, Postman, Mobile).
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
     * * Este método é o mecanismo de terminação segura da aplicação para falhas de requisição. Ele garante 
     * que o cliente (API, Mobile ou Frontend) receba um status code HTTP semanticamente correto e um 
     * corpo de resposta estruturado. Ao utilizar o tipo de retorno `never`, o método sinaliza ao 
     * analisador estático e ao desenvolvedor que o fluxo de código é encerrado imediatamente após 
     * sua invocação, prevenindo a execução de processos subsequentes indesejados.
     * * 
     * * ---
     * ## Mecanismo de Resposta
     * 1. **Definição de Status:** Utiliza `http_response_code` para definir o estado da resposta (ex: 400 para Bad Request, 401 para Unauthorized).
     * 2. **Negociação de Conteúdo:** Força o cabeçalho `Content-Type` para `application/json` com codificação UTF-8, assegurando que caracteres especiais sejam renderizados corretamente no cliente.
     * 3. **Padronização de Payload:** Encapsula a mensagem de erro em um objeto JSON com as chaves `status` (fixo como "error") e `message` (dinâmica), facilitando o tratamento de erros no lado do cliente.
     * 4. **Encerramento:** Finaliza o processo via `exit`, impedindo que qualquer HTML ou saída residual corrompa o JSON enviado.
     * * ---
     * ## Exemplos de Aplicação
     * - Falha na decodificação de payloads JSON malformados.
     * - Erros de validação crítica em rotas de API.
     * - Bloqueio de acesso por falta de privilégios.
     * * @param int $statusCode Código de status HTTP (ex: 400, 403, 404, 500).
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
     * Este método atua como uma camada interna de proteção, permitindo que arrays associativos (como 
     * payloads JSON ou dados de formulários) sejam processados utilizando as mesmas regras nativas do 
     * PHP aplicadas em `filter_input_array`. Ele é essencial para garantir que as entradas de dados 
     * estejam em conformidade com os tipos esperados (inteiros, strings sanitizadas, flags booleanas, etc.) 
     * antes de serem distribuídas para as camadas de serviço ou objetos de transferência (DTOs).
     *
     * 
     *
     * ---
     * ## Mecanismo de Filtragem
     * 1. **Verificação de Nulidade:** Se nenhum mapa de filtros for fornecido, o método retorna os dados brutos integralmente, permitindo flexibilidade em rotas que não exigem tipagem estrita inicial.
     * 2. **Processamento `filter_var_array`:** Utiliza a função nativa do PHP para mapear as chaves do array contra as definições de filtros. A opção `add_empty: false` é crucial para garantir que o resultado contenha apenas os campos originalmente presentes no input, evitando a criação de chaves indesejadas com valores nulos.
     * 3. **Normalização de Saída:** Como as funções de filtro do PHP podem retornar `false` ou `null` em cenários de erro ou inputs corrompidos, este método assegura a consistência da tipagem sempre retornando um `array` (mesmo que vazio).
     *
     * ---
     * ## Segurança e Integridade
     * - **Padronização:** Garante que a mesma lógica de segurança usada para superglobais seja aplicada a dados decodificados manualmente (como JSON do `php://input`).
     * - **Redução de Side-Effects:** O tratamento do retorno previne erros de iteração (foreach) em camadas superiores.
     *
     * @param array $data O array associativo de dados brutos a serem processados.
     * @param array|null $filters O mapa de definições de filtros (ex: `['id' => FILTER_VALIDATE_INT]`).
     * @return array O conjunto de dados resultantes após a validação e higienização.
     */
    private static function applyFilters(array $data, ?array $filters): array {
        if($filters === null) {
            return $data;
        }

        $filtered = filter_var_array(array: $data, options: $filters, add_empty: false);
        return is_array(value: $filtered) ? $filtered : [];
    }
}