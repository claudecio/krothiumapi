<?php
namespace KrothiumAPI\Http;

use Exception;
use KrothiumAPI\Helpers\ConstHelper;

class Router {
    private static $routes = [];
    private static $params = [];
    private static $APP_SYS_MODE = null;
    private static string $basePath = '';
    private static $currentGroupPrefix = '';
    private static $currentGroupMiddlewares = [];
    private static $ROUTER_ALLOWED_ORIGINS = ['*'];
    private static array $requiredConstants = ['APP_SYS_MODE'];
    private static array $allowedHttpRequests = ['GET','POST','PUT','PATCH','DELETE','OPTIONS'];

    /**
     * Inicializa o roteador e define as configurações essenciais, como caminhos base, modos de operação e permissões de CORS.
     *
     * Este método estático é o ponto de partida para configurar o roteador (`Router`) e garantir que todas as variáveis de ambiente necessárias estejam prontas para processar requisições.
     *
     * #### Fluxo de Operação:
     * 1.  **Verificação de Constantes:** Chama `self::checkRequiredConstants()` para garantir que todas as constantes obrigatórias do sistema estejam definidas. (Se falhar, a execução é encerrada).
     * 2.  **Definição do Caminho Base (`$basePath`):** Verifica se a constante `ROUTER_BASE_PATH` está definida. Se estiver, define o caminho base da aplicação, garantindo que ele comece com `/`.
     * 3.  **Registro na Sessão:** Armazena o caminho base (`$basePath`) na sessão (`$_SESSION['ROUTER_BASE_PATH']`).
     * 4.  **Definição de Modos:** Define as propriedades estáticas `self::$ROUTER_MODE` (modo do roteador, e.g., 'JSON', 'WEB') e `self::$APP_SYS_MODE` (modo do sistema, e.g., 'DEV', 'PROD') com seus valores em 
     *       caixa alta (uppercase).
     * 5.  **Configuração de CORS:** Se o roteador estiver no modo 'JSON' e a constante `ROUTER_ALLOWED_ORIGINS` estiver definida, define os domínios permitidos para requisições *Cross-Origin* (CORS).
     *
     * @return void
     */
    public static function init() {
        // Verifica as constantes obrigatórias
        self::checkRequiredConstants();

        // Verifica se tem diretório base definido
        self::$basePath = ConstHelper::get(constant_name: 'ROUTER_BASE_PATH') ? '/' . trim(string: ConstHelper::get(constant_name: 'ROUTER_BASE_PATH'), characters: '/') : '';
        $_SESSION['ROUTER_BASE_PATH'] = self::$basePath;

        // Define os modos do roteador e do sistema
        self::$APP_SYS_MODE = strtoupper(string: ConstHelper::get(constant_name: 'APP_SYS_MODE'));

        // Define os domínios permitidos para CORS
        if (ConstHelper::get(constant_name: 'ROUTER_ALLOWED_ORIGINS') !== null) {
            self::$ROUTER_ALLOWED_ORIGINS = ConstHelper::get(constant_name: 'ROUTER_ALLOWED_ORIGINS');
        }
    }

    /**
     * Envia uma resposta JSON padronizada de erro e encerra a execução do script.
     *
     * Este método estático é um utilitário para endpoints de API, usado para comunicar falhas de forma consistente. Ele define o código de status HTTP do erro e envia uma mensagem detalhada no corpo da resposta JSON.
     *
     * #### Fluxo de Operação:
     * 1.  **Define o Código HTTP:** O código de status HTTP (`$code`) é definido usando `http_response_code()` (ex: 400 Bad Request, 401 Unauthorized, 500 Internal Server Error).
     * 2.  **Define o Cabeçalho:** O cabeçalho `Content-Type` é configurado para `application/json; charset=utf-8`.
     * 3.  **Envia o JSON:** Uma resposta JSON é construída com um status fixo de 'error' e a mensagem de erro fornecida (`$msg`).
     * 4.  **Encerra a Execução:** A execução do script é finalizada com `exit`, impedindo que códigos adicionais sejam processados após o envio da resposta.
     *
     * @param int $code O código de status HTTP a ser enviado na resposta de erro.
     * @param string $msg A mensagem de erro detalhada a ser incluída no corpo da resposta JSON.
     * @return void Este método não retorna um valor, pois ele finaliza a execução do script.
     */
    private static function jsonError(int $code, string $msg): void {
        http_response_code(response_code: $code);
        header(header: 'Content-Type: application/json; charset=utf-8');
        echo json_encode(value: [
            "status" => 'error',
            "message" => $msg
            ]
        );
        exit;
    }

    /**
     * Verifica se todas as constantes de configuração essenciais estão definidas no ambiente de execução.
     *
     * Este método estático é um **verificador de saúde** (health check) usado para garantir que o ambiente da 
     * aplicação esteja corretamente configurado antes de prosseguir com a execução. Ele itera sobre uma lista 
     * pré-definida de constantes (`self::$requiredConstants`) que são consideradas cruciais para a operação do sistema.
     *
     * #### Fluxo de Operação:
     * 1.  **Iteração:** Percorre o array estático que lista os nomes das constantes obrigatórias.
     * 2.  **Verificação:** Para cada nome de constante, ele usa `defined()` para verificar se a constante existe no escopo global do PHP.
     * 3.  **Ação em Caso de Falha:** Se uma constante obrigatória **não estiver definida**, o método assume uma falha crítica de configuração. 
     *      Ele chama o método `self::jsonError()`, que envia uma resposta JSON com o código HTTP **500 Internal Server Error** e uma mensagem 
     *      detalhando qual constante está faltando, encerrando a execução do script.
     *
     * @return void Este método não retorna um valor em caso de sucesso; ele apenas garante que as constantes existam. Em caso de falha, ele envia uma resposta HTTP de erro e encerra o script.
     */
    private static function checkRequiredConstants(): void {
        foreach (self::$requiredConstants as $constant) {
            if (!defined(constant_name: $constant)) {
                self::jsonError(
                    code: 500,
                    msg: "Constant '{$constant}' not defined."
                );
            }
        }
    }

    // =====================================
    // Métodos HTTP para definição de rotas
    // =====================================
    public static function get(string $uri, array $handler, array $middlewares = []): void {
        self::addRoute(method: 'GET', uri: $uri, handler: $handler, middlewares: $middlewares);
    }
    public static function post(string $uri, array $handler, array $middlewares = []): void {
        self::addRoute(method: 'POST', uri: $uri, handler: $handler, middlewares: $middlewares);
    }
    public static function put(string $uri, array $handler, array $middlewares = []): void {
        self::addRoute(method: 'PUT', uri: $uri, handler: $handler, middlewares: $middlewares);
    }
    public static function patch(string $uri, array $handler, array $middlewares = []): void {
        self::addRoute(method: 'PATCH', uri: $uri, handler: $handler, middlewares: $middlewares);
    }
    public static function delete(string $uri, array $handler, array $middlewares = []): void {
        self::addRoute(method: 'DELETE', uri: $uri, handler: $handler, middlewares: $middlewares);
    }

    /**
     * Adiciona uma nova definição de rota à lista de rotas do roteador.
     *
     * Este método privado é o núcleo do registro de rotas. Ele constrói o caminho final da rota (URI) combinando o prefixo de grupo atual, se houver, com o URI fornecido, e armazena os detalhes da rota (controlador, ação e middlewares) em um array estático (`self::$routes`).
     *
     * @param string $method O método HTTP (e.g., 'GET', 'POST', 'PATCH').
     * @param string $uri A URI específica da rota (relativa ao prefixo do grupo, se houver).
     * @param array $handler Um array contendo a classe do controlador e o método de ação (ex: ['Controller', 'method']).
     * @param array $middlewares Um array opcional de middlewares específicos desta rota.
     * @return void
     */
    private static function addRoute(string $method, string $uri, array $handler, array $middlewares = []) {
        $path = '/' . trim(string: self::$currentGroupPrefix . '/' . trim(string: $uri, characters: '/'), characters: '/');
        [$controller, $action] = $handler;
        self::$routes[$method][] = [
            'method' => $method,
            'path' => $path,
            'controller' => $controller,
            'action' => $action,
            'middlewares' => array_merge(self::$currentGroupMiddlewares, $middlewares)
        ];
    }

    /**
     * Agrupa um conjunto de rotas sob um prefixo de URI e aplica middlewares em comum.
     *
     * Este método estático é uma ferramenta poderosa para organizar rotas, permitindo que todas as rotas definidas dentro da função de callback (`$callback`) herdem um prefixo de URI comum e uma lista de middlewares.
     *
     * #### Fluxo de Operação:
     * 1.  **Backup de Contexto:** Os prefixos e middlewares atuais (`self::$currentGroupPrefix` e `self::$currentGroupMiddlewares`) são salvos temporariamente. Isso é essencial para suportar o aninhamento (grupos dentro de grupos).
     * 2.  **Definição do Novo Contexto:** O prefixo do novo grupo (`$prefix`) é concatenado ao prefixo existente (`$previousPrefix`), e os novos middlewares são mesclados com os existentes.
     * 3.  **Execução das Rotas:** A função de callback (`$callback`) é executada. Todas as chamadas de rotas (`GET`, `POST`, etc.) feitas aqui dentro usarão o novo prefixo e herdarão os novos middlewares.
     * 4.  **Restauração do Contexto:** Após a execução do callback, os prefixos e middlewares originais são restaurados. Isso garante que rotas definidas após o grupo não sejam afetadas pelo prefixo ou middlewares internos do grupo.
     *
     * @param string $prefix O prefixo da URI a ser aplicado a todas as rotas dentro do grupo (ex: '/api/v1').
     * @param callable $callback A função que contém a definição das rotas a serem agrupadas.
     * @param array $middlewares Um array opcional de middlewares que serão aplicados a todas as rotas dentro deste grupo e em seus subgrupos.
     * @return void
     */
    public static function group(string $prefix, callable $callback, array $middlewares = []): void {
        $previousPrefix = self::$currentGroupPrefix ?? '';
        $previousMiddlewares = self::$currentGroupMiddlewares ?? [];
    
        self::$currentGroupPrefix = $previousPrefix . $prefix;
        self::$currentGroupMiddlewares = array_merge($previousMiddlewares, $middlewares);
    
        $callback();
    
        self::$currentGroupPrefix = $previousPrefix;
        self::$currentGroupMiddlewares = $previousMiddlewares;
    }

    /**
     * Verifica se o caminho da requisição (URI) corresponde ao padrão de uma rota registrada.
     *
     * Este método estático privado é essencial para o roteador. Ele compara o caminho da URI solicitada pelo 
     * cliente com o padrão de rota (`$routePath`) e extrai quaisquer parâmetros dinâmicos presentes na URI.
     *
     * @param string $routePath O padrão de URI da rota registrada (pode conter placeholders como '/users/{id}').
     * @param string $requestPath A URI real da requisição (ex: '/users/123').
     * @return bool Retorna `true` se o `$requestPath` corresponder ao `$routePath`; caso contrário, retorna `false`.
     */
    private static function matchPath($routePath, $requestPath): bool {
        self::$params = [];

        $routeParts = explode(separator: '/', string: trim(string: $routePath, characters: '/'));
        $reqParts = explode(separator: '/', string: trim(string: $requestPath, characters: '/'));

        if(count($routeParts) !== count($reqParts)) return false;
        foreach ($routeParts as $i => $part) {
            if (preg_match(pattern: '/^{\w+}$/', subject: $part)) {
                self::$params[] = $reqParts[$i];
            } elseif ($part !== $reqParts[$i]) {
                return false;
            }
        }
        return true;
    }

    /**
     * Extrai os dados enviados no corpo da requisição HTTP para métodos específicos (PUT, DELETE, PATCH).
     *
     * Este método estático privado é crucial para APIs RESTful, pois os dados para métodos não-POST e não-GET (como PUT, PATCH e DELETE) 
     * são enviados no corpo da requisição e não são automaticamente populados nas superglobais do PHP.
     *
     * #### Fluxo de Operação:
     * 1.  **Verificação de Método:** Verifica se o método HTTP (`$method`) é um dos suportados ('PUT', 'DELETE', 'PATCH'). Se não for, retorna um array vazio.
     * 2.  **Leitura do Input:** Lê o conteúdo bruto do corpo da requisição (`php://input`). Se estiver vazio, retorna um array vazio.
     * 3.  **Processamento Condicional:**
     * * **JSON (`application/json`):** Se o `Content-Type` for JSON, o conteúdo é decodificado. Se a decodificação falhar, o método chama `self::jsonError()` para enviar uma resposta de erro HTTP 500 e encerrar a execução.
     * * **Form Data (Outros):** Caso contrário, o conteúdo é tratado como uma string de query (`application/x-www-form-urlencoded`) e analisado usando `parse_str`.
     * 4.  **Limpeza:** Remove a chave `_method` (se presente), que é frequentemente usada para simular métodos HTTP em formulários HTML.
     *
     * @param string $method O método HTTP da requisição (e.g., 'PUT', 'DELETE', 'PATCH').
     * @return array Um array associativo contendo os dados extraídos do corpo da requisição.
     * @return void Este método encerra a execução com uma resposta JSON de erro (código 500) em caso de falha na decodificação JSON.
     */
    private static function extractRequestData(string $method): array {
        if(!in_array(needle: $method, haystack: ['PUT', 'DELETE', 'PATCH'])) return [];
        $input = file_get_contents(filename: 'php://input');
        if(empty($input)) return [];

        $type = $_SERVER['CONTENT_TYPE'] ?? '';
        if(str_contains(haystack: $type, needle: 'application/json')) {
            $data = json_decode(json: $input, associative: true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                self::jsonError(
                    code: 500,
                    msg: 'Erro ao decodificar JSON: ' . json_last_error_msg()
                );
            }
        } else {
            parse_str(string: $input, result: $data);
        }
        return $data;
    }

    /**
     * Prepara e retorna o array final de parâmetros a ser passado para o método de ação do controlador.
     *
     * Este método estático privado combina os parâmetros de rota dinâmicos extraídos do URI (`self::$params`) com quaisquer dados adicionais passados no corpo da requisição (`$params`), especificamente para métodos que enviam dados de forma não-tradicional (PUT, DELETE, PATCH).
     *
     * #### Fluxo de Operação:
     * 1.  **Verificação de Método:** Checa se o método HTTP é 'PUT', 'DELETE' ou 'PATCH'.
     * 2.  **Combinação Condicional:**
     * * **Se for PUT, DELETE ou PATCH:** Os parâmetros da rota (`self::$params`) são combinados com os dados do corpo da requisição (`$params`) usando `array_merge`.
     * * **Se for GET, POST, etc.:** Apenas os parâmetros da rota (`self::$params`) são usados.
     * 3.  **Normalização:** O array resultante é reindexado numericamente usando `array_values()` para garantir que os argumentos sejam passados corretamente para a função de ação do controlador.
     *
     * @param string $method O método HTTP da requisição (e.g., 'GET', 'POST', 'PUT').
     * @param array|null $params Um array opcional contendo dados adicionais da requisição (geralmente o corpo do payload para PUT/PATCH/DELETE).
     * @return array Um array indexado numericamente contendo a lista final de argumentos para o método do controlador.
     */
    private static function prepareMethodParameters(string $method, ?array $params = []): array {
        return array_values(array: in_array(needle: $method, haystack: ['PUT', 'DELETE', 'PATCH']) ? array_merge(self::$params, $params) : self::$params);
    }

    /**
     * Determina se uma rota registrada corresponde ao método HTTP e à URI da requisição atual.
     *
     * Este método estático privado é o principal mecanismo de correspondência de rotas do roteador. 
     * Ele verifica se o método HTTP da rota é o mesmo da requisição e, em seguida, usa o método auxiliar 
     * `matchPath` para verificar se o padrão da URI da rota corresponde ao caminho solicitado, considerando 
     * quaisquer parâmetros dinâmicos.
     *
     * @param string $method O método HTTP da requisição atual (e.g., 'GET', 'POST').
     * @param string $uri A URI solicitada pelo cliente (caminho da requisição).
     * @param array $route Um array de definição de rota contendo as chaves 'method' e 'path'.
     * @return bool Retorna `true` se o método HTTP e o caminho da URI corresponderem; caso contrário, retorna `false`.
     */
    private static function matchRoute(string $method, string $uri, array $route): bool {
        return $route['method'] === $method && self::matchPath(routePath: $route['path'], requestPath: $uri);
    }

    /**
     * Configura os cabeçalhos Cross-Origin Resource Sharing (CORS) para requisições de API.
     *
     * Este método privado verifica se a requisição é permitida de acordo com a política de CORS definida na aplicação.
     * Ele permite ou nega o acesso de origens externas com base nas configurações e no modo de operação do sistema.
     *
     * @param string $method O método HTTP da requisição atual (e.g., 'OPTIONS', 'GET', 'POST').
     * @return void Este método encerra a execução em caso de requisições OPTIONS ou de origem não permitida.
     */
    private static function corsSetup(string $method): void {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
        $allowAll = in_array(needle: '*', haystack: self::$ROUTER_ALLOWED_ORIGINS);
        $isAllowed = in_array(needle: $origin, haystack: self::$ROUTER_ALLOWED_ORIGINS);

        if ($allowAll || $isAllowed || self::$APP_SYS_MODE === 'DEV') {
            header(header: "Access-Control-Allow-Origin: $origin");
        } else {
            self::jsonError(code: 403, msg: "Origin '{$origin}' not allowed by CORS.");
        }

        $allowedRequests = implode(separator: ', ', array: self::$allowedHttpRequests);
        header(header: "Access-Control-Allow-Methods: {$allowedRequests}");
        header(header: 'Access-Control-Allow-Headers: Content-Type, Authorization');

        if ($method === 'OPTIONS') {
            http_response_code(response_code: 204);
            exit;
        }
    }
    
    /**
     * Executa sequencialmente a pilha de Middlewares vinculada a uma rota.
     *
     * Este método atua como uma barreira de segurança e processamento pré-execução (Pipeline). Ele permite 
     * interceptar a requisição antes que ela chegue ao controlador, sendo ideal para verificações de 
     * autenticação, autorização de perfis (ACL), logs de acesso ou manutenção de sistema. O método 
     * suporta injeção dinâmica de argumentos para os middlewares e oferece dois níveis de bloqueio: 
     * um booleano simples e um detalhado com respostas JSON customizadas.
     *
     * 
     *
     * ---
     * ## Mecanismo de Execução
     * 1. **Validação de Formato:** Verifica se a definição do middleware segue o padrão esperado: `[Classe, 'metodo', ...argumentos]`.
     * 2. **Instanciação Dinâmica:** Localiza a classe e o método, instanciando-os em tempo de execução.
     * 3. **Gestão de Bloqueios:**
     * - **Bloqueio Booleano:** Se o middleware retornar explicitamente `false`, a requisição é negada com um erro 403 padrão.
     * - **Bloqueio Estruturado:** Se retornar um `array`, o método analisa chaves como `block`, `status` e `response_code` para montar uma resposta JSON rica e encerrar o script.
     * 4. **Continuidade:** Se todos os middlewares retornarem `true` (ou um array indicando sucesso), o fluxo retorna `true`, permitindo que o `dispatch()` prossiga para o controlador.
     *
     * ---
     * ## Tratamento de Erros de Configuração
     * - **500 Internal Server Error:** Disparado se a classe do middleware não existir, se o método for inválido ou se ocorrer uma exceção durante a execução lógica do filtro.
     *
     * @param array $middlewares Lista de arrays contendo a definição dos middlewares da rota.
     * @return bool Retorna `true` se a requisição passou por todos os filtros sem ser bloqueada.
     */
    public static function runMiddlewares(array $middlewares): bool {
        foreach ($middlewares as $middleware) {
            try {
                if (!is_array(value: $middleware) || count(value: $middleware) < 2) {
                    self::jsonError(code: 500, msg: "Invalid middleware format. Expected: [Class::class, 'method', ...args]");
                }

                $class  = $middleware[0];
                $method = $middleware[1];
                $args   = array_slice(array: $middleware, offset: 2);

                if (!class_exists(class: $class)) {
                    self::jsonError(code: 500, msg: "Middleware class '{$class}' not found.");
                }

                if (!method_exists(object_or_class: $class, method: $method)) {
                    self::jsonError(code: 500, msg: "Method '{$method}' does not exist in class '{$class}'.");
                }

                $instance = new $class();
                $result = call_user_func_array(callback: [$instance, $method], args: $args);

                // bloqueio simples
                if ($result === false) {
                    self::jsonError(code: 403, msg: "{$class}::{$method} blocked the request.");
                }

                // bloqueio detalhado
                if (is_array(value: $result)) {
                    $block = $result['block'] ?? null;
                    $status = $result['status'] ?? null;

                    $shouldBlock = ($block === true) || ($status !== null && $status !== 'success');

                    if ($shouldBlock) {
                        $code = (int)($result['response_code'] ?? 403);
                        $msg  = (string)($result['message'] ?? 'Blocked by middleware');
                        $json_response = [
                            "status" => $result['status'] ?? 'error',
                            "message" => $msg
                        ];
                        if(isset($result['output']) && (!empty($result['output']) || $result['output'] !== null || $result['output'] !== '')) {
                            $json_response['output'] = $result['output'];
                        }

                        http_response_code(response_code: $code);
                        header(header: 'Content-Type: application/json; charset=utf-8');
                        echo json_encode(value: $json_response);
                        exit;
                    }
                }
            } catch (Exception $e) {
                self::jsonError(code: 500, msg: $e->getMessage());
            }
        }
        return true;
    }

    /**
     * Orquestra o ciclo de vida da requisição, realizando o roteamento e a execução do controlador.
     * * Este método é o ponto de entrada principal (Front Controller) que transforma uma requisição 
     * HTTP bruta em uma ação de software. Ele gerencia desde a validação de constantes de ambiente 
     * até a resolução de parâmetros dinâmicos, passando por suporte a emulação de métodos REST 
     * (via `_method`), configuração de CORS, execução de Middlewares e, por fim, a invocação do 
     * par Controller/Action correspondente.
     * * 
     * * ---
     * ## Fluxo de Processamento
     * 1. **Sanitização de URI:** Extrai o caminho da URL e remove o prefixo global (`basePath`), normalizando a rota para comparação.
     * 2. **Emulação de Verbos:** Detecta campos `_method` em requisições POST para suportar verbos como PUT, PATCH e DELETE em ambientes que não os suportam nativamente.
     * 3. **Segurança e CORS:** Valida se o método HTTP é permitido e configura os cabeçalhos de Cross-Origin Resource Sharing.
     * 4. **Match de Rotas:** Percorre as rotas registradas. Para cada correspondência:
     * - **Middlewares:** Executa camadas de pré-processamento (Autenticação, Logs, etc.). Se um middleware falhar, a execução é interrompida.
     * - **Injeção de Parâmetros:** Prepara os argumentos necessários para o método do controlador (como IDs de URL ou dados de payload).
     * 5. **Execução:** Instancia o controlador dinamicamente e invoca a ação via `call_user_func_array`.
     * 6. **Fallback 404:** Caso nenhuma rota coincida com a URI e o método, encerra a execução com um erro de "Página não encontrada".
     * * ---
     * ## Tratamento de Erros
     * - **405 Method Not Allowed:** Quando o método HTTP não está na lista branca.
     * - **500 Internal Server Error:** Quando a classe do controlador existe, mas o método (Action) não foi definido.
     * - **404 Not Found:** Quando a rota solicitada não existe no mapa de rotas.
     * * @return void Este método encerra a execução do script (`exit`) ao encontrar e executar uma rota válida.
     */
    public static function dispatch(): void {
        self::checkRequiredConstants();
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = trim(string: parse_url(url: $_SERVER['REQUEST_URI'], component: PHP_URL_PATH), characters: '/');

        if ($method === 'POST' && isset($_POST['_method'])) {
            $method = strtoupper(string: $_POST['_method']);
        }
        if (!in_array(needle: $method, haystack: self::$allowedHttpRequests)) {
            self::jsonError(code: 405, msg: "HTTP method '{$method}' not allowed.");
        }

        self::corsSetup(method: $method);

        // remove basePath
        if (!empty(self::$basePath) && str_starts_with(haystack: $uri, needle: trim(string: self::$basePath, characters: '/'))) {
            $uri = substr(string: $uri, offset: strlen(string: trim(string: self::$basePath, characters: '/')));
        }
        $uri = trim(string: $uri, characters: '/');

        // payload (PUT/PATCH/DELETE)
        $requestData = self::extractRequestData(method: $method);

        foreach (self::$routes[$method] ?? [] as $route) {
            if (!self::matchRoute(method: $method, uri: $uri, route: $route)) continue;
            // roda middlewares (se barrar, o runMiddlewares já respondeu JSON)
            if (!empty($route['middlewares']) && !self::runMiddlewares(middlewares: $route['middlewares'])) {
                return;
            }

            $controller = new $route['controller']();
            $action = $route['action'];

            if (!method_exists(object_or_class: $controller, method: $action)) {
                self::jsonError(code: 500, msg: "Method {$action} not found.");
            }

            $params = self::prepareMethodParameters(method: $method, params: $requestData);

            // se teu controller já dá echo/json, tu nem precisa setar 200/header aqui
            call_user_func_array(callback: [$controller, $action], args: $params);
            exit;
        }

        self::jsonError(
            code: 404,
            msg: 'Page not found.'
        );
    }
}