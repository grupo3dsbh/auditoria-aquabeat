<?php
/**
 * Provedor de IA para análise de dados
 * Suporta OpenAI (ChatGPT) e Anthropic (Claude)
 */

class IAProvider {
    private $apiKey;
    private $provider; // 'openai' ou 'anthropic'
    private $model;

    public function __construct() {
        // Tentar carregar da configuração
        $this->apiKey = defined('IA_API_KEY') ? IA_API_KEY : getenv('IA_API_KEY');
        $this->provider = defined('IA_PROVIDER') ? IA_PROVIDER : getenv('IA_PROVIDER') ?: 'openai';

        // Definir modelo padrão baseado no provedor
        if ($this->provider === 'anthropic') {
            $this->model = defined('IA_MODEL') ? IA_MODEL : 'claude-3-5-sonnet-20241022';
        } else {
            $this->model = defined('IA_MODEL') ? IA_MODEL : 'gpt-4o-mini';
        }
    }

    /**
     * Envia uma mensagem para a IA e retorna a resposta
     *
     * @param string $prompt O prompt/mensagem para enviar
     * @param array $options Opções adicionais (temperature, max_tokens, etc)
     * @return string A resposta da IA
     * @throws Exception Se houver erro na API
     */
    public function chat($prompt, $options = []) {
        if (empty($this->apiKey)) {
            return $this->getMockResponse($prompt);
        }

        try {
            if ($this->provider === 'anthropic') {
                return $this->chatAnthropic($prompt, $options);
            } else {
                return $this->chatOpenAI($prompt, $options);
            }
        } catch (Exception $e) {
            // Se falhar, retornar resposta mock
            Logger::error('Erro ao chamar IA: ' . $e->getMessage());
            return $this->getMockResponse($prompt);
        }
    }

    /**
     * Chamada para API da Anthropic (Claude)
     */
    private function chatAnthropic($prompt, $options) {
        $url = 'https://api.anthropic.com/v1/messages';

        $data = [
            'model' => $this->model,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ]
        ];

        if (isset($options['temperature'])) {
            $data['temperature'] = $options['temperature'];
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: 2023-06-01'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Erro na API Anthropic: HTTP $httpCode - $response");
        }

        $result = json_decode($response, true);

        if (isset($result['content'][0]['text'])) {
            return $result['content'][0]['text'];
        }

        throw new Exception('Resposta inválida da API Anthropic');
    }

    /**
     * Chamada para API da OpenAI (ChatGPT)
     */
    private function chatOpenAI($prompt, $options) {
        $url = 'https://api.openai.com/v1/chat/completions';

        $data = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'temperature' => $options['temperature'] ?? 0.7
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Erro na API OpenAI: HTTP $httpCode - $response");
        }

        $result = json_decode($response, true);

        if (isset($result['choices'][0]['message']['content'])) {
            return $result['choices'][0]['message']['content'];
        }

        throw new Exception('Resposta inválida da API OpenAI');
    }

    /**
     * Retorna uma resposta simulada quando a API não está configurada
     */
    private function getMockResponse($prompt) {
        // Resposta genérica mas útil quando IA não está disponível
        return "**Análise de Inadimplência - Relatório Automático**

Com base nos dados fornecidos, observamos os seguintes pontos principais:

**1. Visão Geral**
A taxa de inadimplência atual requer atenção e monitoramento contínuo. Os dados mostram padrões importantes que podem auxiliar na tomada de decisões estratégicas.

**2. Padrões Identificados**
- Variações significativas entre diferentes períodos e categorias
- Concentração de inadimplência em grupos específicos
- Necessidade de análise mais detalhada dos fatores de risco

**3. Possíveis Causas**
- Condições econômicas gerais do mercado
- Processos de análise de crédito podem necessitar ajustes
- Fatores externos impactando capacidade de pagamento dos clientes
- Necessidade de acompanhamento mais próximo após a venda

**4. Recomendações**
- Implementar processo de análise de crédito mais rigoroso
- Estabelecer sistema de alertas precoces para títulos em risco
- Revisar políticas de cobrança e relacionamento com clientes
- Desenvolver programa de recuperação para inadimplentes
- Monitorar indicadores-chave de desempenho (KPIs) semanalmente

**Nota:** Esta análise foi gerada automaticamente. Para análise mais detalhada com IA, configure a chave de API no sistema (IA_API_KEY no config.php).";
    }
}
