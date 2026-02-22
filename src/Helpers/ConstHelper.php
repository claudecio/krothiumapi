<?php
namespace KrothiumAPI\Helpers;

class ConstHelper {
    /**
     * Recupera o valor de uma constante global de forma segura, permitindo um valor padrão de fallback.
     * * Este método atua como um wrapper de proteção para a função nativa `constant()` do PHP. Ele previne 
     * erros de execução (Warning/Error) que ocorreriam ao tentar acessar uma constante não definida 
     * diretamente. É ideal para acessar configurações dinâmicas, variáveis de ambiente ou flags de 
     * sistema onde a existência da constante não é garantida em todos os ambientes (desenvolvimento, 
     * homologação e produção).
     * * 
     * * ---
     * ## Lógica de Funcionamento
     * 1. **Verificação de Existência:** Utiliza `defined()` para checar se o identificador fornecido existe na tabela de símbolos globais do script.
     * 2. **Resolução de Valor:** Caso a constante exista, o método retorna seu valor original (que pode ser qualquer tipo escalar ou array, dependendo da versão do PHP).
     * 3. **Tratamento de Fallback:** Se a constante não estiver definida, o método retorna o valor estipulado no parâmetro `$default`, garantindo que o fluxo da aplicação não seja interrompido.
     * * ---
     * ## Exemplos de Uso
     * - **Configuração de API:** `Config::get('API_KEY', 'default_key_123');`
     * - **Flags de Debug:** `Config::get('DEBUG_MODE', false);`
     * * @param string $constant_name O nome da constante global a ser verificada e recuperada.
     * @param mixed $default O valor a ser retornado caso a constante não esteja definida. O padrão é `null`.
     * @return mixed Retorna o valor da constante se definida; caso contrário, retorna o valor de `$default`.
     */
    public static function get(string $constant_name, $default = null): mixed {
        if (defined(constant_name: $constant_name)) {
            return constant(name: $constant_name);
        }
        return $default;
    }
}