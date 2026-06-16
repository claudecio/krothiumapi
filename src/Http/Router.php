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
     * caixa alta (uppercase).
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
    private static function error(int $code, string $msg): void {
        http_response_code(response_code: $code);
        header(header: 'Content-Type: application/json; charset=utf-8');
        echo json_encode(value: [
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
     * Ele chama o método `self::error()`, que envia uma resposta JSON com o código HTTP **500 Internal Server Error** e uma mensagem 
     * detalhando qual constante está faltando, encerrando a execução do script.
     *
     * @return void Este método não retorna um valor em caso de sucesso; ele apenas garante que as constantes existam. Em caso de falha, ele envia uma resposta HTTP de erro e encerra o script.
     */
    private static function checkRequiredConstants(): void {
        foreach (self::$requiredConstants as $constant) {
            if (!defined(constant_name: $constant)) {
                self::error(
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
     * Prepara e retorna o array final de parâmetros a ser passado para o método de ação do controlador.
     *
     * Este método estático privado retorna os parâmetros de rota dinâmicos extraídos do URI (`self::$params`).
     *
     * @return array Um array indexado numericamente contendo a lista final de argumentos de rota para o método do controlador.
     */
    private static function prepareMethodParameters(): array {
        return array_values(array: self::$params);
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
            self::error(code: 403, msg: "Origin '{$origin}' not allowed by CORS.");
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
     * ---
     * ## Mecanismo de Execução
     * 1. **Validação de Formato:** Verifica se a definição do middleware segue o padrão esperado: `[Classe, 'metodo', ...argumentos]`.
     * 2. **Instanciação Dinâmica:** Localiza a classe e o método, instanciando-os em tempo de execução.
     * 3. **Gestão de Bloqueios:**
     * - **Bloqueio Booleano:** Se o middleware retornar explicitamente `false`, a requisição é negada com um erro 403 padrão.
     * - **Bloqueio Estruturado:** Se retornar um `array`, o método analisa chaves como `block`, `status` e `response_code` para montar uma resposta JSON rica e encerrar o script.
     * 4. **Continuidade:** Se todos os middlewares retornarem `true` (ou um array indicando sucesso), o fluxo retorna `true`, permitindo que o `dispatch()` prossiga para o controlador.
     *
     * @param array $middlewares Lista de arrays contendo a definição dos middlewares da rota.
     * @return bool Retorna `true` se a requisição passou por todos os filtros sem ser bloqueada.
     */
    public static function runMiddlewares(array $middlewares): bool {
        foreach ($middlewares as $middleware) {
            try {
                if (!is_array(value: $middleware) || count(value: $middleware) < 2) {
                    self::error(code: 500, msg: "Invalid middleware format. Expected: [Class::class, 'method', ...args]");
                }

                $class  = $middleware[0];
                $method = $middleware[1];
                $args   = array_slice(array: $middleware, offset: 2);

                if (!class_exists(class: $class)) {
                    self::error(code: 500, msg: "Middleware class '{$class}' not found.");
                }

                if (!method_exists(object_or_class: $class, method: $method)) {
                    self::error(code: 500, msg: "Method '{$method}' does not exist in class '{$class}'.");
                }

                $instance = new $class();
                $result = call_user_func_array(callback: [$instance, $method], args: $args);

                // bloqueio simples
                if ($result === false) {
                    self::error(code: 403, msg: "{$class}::{$method} blocked the request.");
                }

                // bloqueio detalhado
                if (is_array(value: $result)) {
                    $block = $result['block'] ?? null;
                    $status = $result['status'] ?? null;
                    $response_code = (int) $result['response_code'] ?? null;

                    $shouldBlock = ($block === true) || ($status !== null && $status !== 'success') || (in_array(needle: $response_code, haystack: [403, 401, 500, 422]));
                    if ($shouldBlock) {
                        $code = (int) ($result['response_code'] ?? 403);
                        $msg  = (string) ($result['message'] ?? 'Blocked by middleware');
                        $json_response = [
                            "message" => $msg ?? "{$class}::{$method} blocked the request."
                        ];
                        if(isset($result['output']) && (!empty($result['output']) || $result['output'] !== null || $result['output'] !== '')) {
                            $json_response['output'] = $result['output'];
                        }

                        http_response_code(response_code: $response_code);
                        header(header: 'Content-Type: application/json; charset=utf-8');
                        echo json_encode(value: $json_response);
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
     * Orquestra o ciclo de vida da requisição, realizando o roteamento e a execução do controlador.
     * * Este método é o ponto de entrada principal (Front Controller) que transforma uma requisição 
     * HTTP bruta em uma ação de software. Ele gerencia desde a validação de constantes de ambiente 
     * até a resolução de parâmetros dinâmicos da URL, passando por suporte a emulação de métodos REST 
     * (via `_method`), configuração de CORS, execução de Middlewares e, por fim, a invocação do 
     * par Controller/Action correspondente.
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
            self::error(code: 405, msg: "HTTP method '{$method}' not allowed.");
        }

        self::corsSetup(method: $method);

        // remove basePath
        if (!empty(self::$basePath) && str_starts_with(haystack: $uri, needle: trim(string: self::$basePath, characters: '/'))) {
            $uri = substr(string: $uri, offset: strlen(string: trim(string: self::$basePath, characters: '/')));
        }
        $uri = trim(string: $uri, characters: '/');

        foreach (self::$routes[$method] ?? [] as $route) {
            if (!self::matchRoute(method: $method, uri: $uri, route: $route)) continue;
            
            // roda middlewares (se barrar, o runMiddlewares já respondeu JSON)
            if (!empty($route['middlewares']) && !self::runMiddlewares(middlewares: $route['middlewares'])) {
                return;
            }

            $controller = new $route['controller']();
            $action = $route['action'];

            if (!method_exists(object_or_class: $controller, method: $action)) {
                self::error(code: 500, msg: "Method '{$action}' not found.");
            }

            $params = self::prepareMethodParameters();

            // se teu controller já dá echo/json, tu nem precisa setar 200/header aqui
            call_user_func_array(callback: [$controller, $action], args: $params);
            exit;
        }

        self::error(
            code: 404,
            msg: 'Page not found.'
        );
    }
}