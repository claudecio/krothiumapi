# Changelog

Todas as alterações notáveis deste projeto serão documentadas neste arquivo.

O formato é baseado no [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/)
e este projeto adere ao [Semantic Versioning](https://semver.org/lang/pt-BR/).

---

## [1.0.0] - 2026-09-09

### Adicionado
- **Core (`KrothiumAPI`)**:
  - Inicialização centralizada com `KrothiumAPI::init()`.
  - Leitura de configurações multinível com dot notation (`KrothiumAPI::config()`).
  - Handlers globais de erros, exceções (`Throwable`) e falhas fatais com respostas padronizadas em JSON e proteção por ambiente (`DEV`/`PROD`).
  - Configuração de fuso horário padrão e inicialização de sessão.

- **Roteador (`Router`)**:
  - Suporte completo aos verbos HTTP (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `OPTIONS`, `ANY`).
  - Rotas de recursos RESTful via `Router::resource()`.
  - Resolução de handlers por Closures, notações de string (`Controller@method`, `Controller::method`) e arrays (`[Controller::class, 'method']`).
  - Parâmetros dinâmicos e nomeados (`/recurso/{id}`).
  - Grupos de rotas aninhados com prefixos e middlewares compartilhados (`Router::group()`).
  - Pilha de middlewares com aliases e prevenção de dependência circular.
  - Suporte completo a CORS com preflight `OPTIONS` automático.
  - Handlers customizáveis para `404 Not Found` e `405 Method Not Allowed`.

- **Banco de Dados (`DBManager` & Drivers)**:
  - Arquitetura desacoplada sob o contrato `DriverInterface` e classe `PDOAbstract`.
  - Driver nativo para PostgreSQL (`PostgreSQLDriver`) com suporte a múltiplos schemas (`search_path`), conexões SSL (`sslmode`, certificados), timeouts e escape de identificadores.
  - Driver nativo para MySQL (`MySQLDriver`) com charset `utf8mb4` e verificação de tabelas.
  - Driver nativo para SQLite (`SQLiteDriver`) com suporte a `:memory:`, arquivos locais, Foreign Keys e modo WAL.
  - Registro dinâmico de drivers customizados (`DBManager::registerDriver()`).
  - Pool estático de conexões nomeadas.
  - Suporte a transações ACID seguras com commit/rollback manual e helper automático `DBManager::transaction()`.
  - Helpers CRUD preparados e tipados: `insert()`, `update()`, `delete()`, `fetchOne()`, `fetchAll()`, `fetchColumn()`, `rowCount()`, `execute()`.

- **Cache & Redis (`RedisManager` / `RedisDriver`)**:
  - Integração com `predis/predis` v3.4.
  - Pool estático de conexões Redis.
  - Serialização e desserialização automática de JSON.
  - Suporte ao padrão Cache-Aside via `RedisManager::remember()`.
  - Métodos utilitários para `increment()`, `decrement()`, `expire()`, `exists()`, `del()`, `ping()`, `flushdb()`.
  - Proxy para execução de comandos nativos do Redis via `RedisManager::call()`.

- **Serviço de Logs (`LoggerService`)**:
  - Múltiplos drivers: `FILE` (arquivos diários formatados), `JSON` (JSON Lines estruturado), `STDOUT`/`CONSOLE`.
  - Suporte a múltiplos canais independentes (`LoggerService::channel()`).
  - Diagnóstico aprofundado com rastreamento de arquivo, linha, função e uso de memória (`debugDetailed()`).

- **Utilitários HTTP (`HttpUtil` & `ConstHelper`)**:
  - Captura unificada de entradas com suporte a dot notation em JSON (`HttpUtil::json()`, `HttpUtil::input()`, `HttpUtil::query()`, `HttpUtil::post()`).
  - Leitura de cabeçalhos case-insensitive (`HttpUtil::header()`).
  - Extração de token Bearer (`HttpUtil::getBearerToken()`).
  - Detecção real de IP com suporte a Cloudflare e Proxies reversos (`HttpUtil::ip()`).
  - Respostas JSON semânticas (`HttpUtil::success()`, `HttpUtil::error()`, `HttpUtil::jsonResponse()`).
  - Wrapper seguro para constantes (`ConstHelper::get()`).

- **Testes Automatizados**:
  - Suíte de testes para todos os módulos em `tests/FrameworkTest.php` e `tests/DatabaseTest.php`.
