<?php
/**
 * Serviço de Inteligência Artificial
 * Suporta Groq e OpenAI
 */

class AIService {
    private $provider;
    private $apiKey;
    private $model;

    // Modelos de fallback para cada provedor
    private const FALLBACK_MODELS = [
        'groq' => [
            'llama-3.3-70b-versatile',
            'llama-3.1-8b-instant',
            'mixtral-8x7b-32768',
            'gemma2-9b-it'
        ],
        'openai' => [
            'gpt-4o-mini',
            'gpt-3.5-turbo',
            'gpt-4'
        ]
    ];

    public function __construct() {
        $this->provider = AI_PROVIDER;
        $this->apiKey = AI_API_KEY;

        if ($this->provider === 'groq') {
            $this->model = AI_MODEL_GROQ;
        } else {
            $this->model = AI_MODEL_OPENAI;
        }
    }

    /**
     * Gerar análise de dados de inadimplência
     */
    public function analyzeDelinquencyData($data, $type = 'geral') {
        $prompt = $this->buildAnalysisPrompt($data, $type);

        return $this->chat($prompt);
    }

    /**
     * Resumir dados de relatório
     */
    public function summarizeReport($data, $filters = []) {
        $prompt = $this->buildSummaryPrompt($data, $filters);

        return $this->chat($prompt);
    }

    /**
     * Identificar pontos de atenção
     */
    public function identifyAlerts($data) {
        $prompt = "Você é um auditor financeiro especializado em análise de inadimplência.\n\n";
        $prompt .= "Analise os seguintes dados e identifique os principais pontos de atenção:\n\n";
        $prompt .= json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $prompt .= "\n\nIdentifique:\n";
        $prompt .= "1. Padrões suspeitos de fraude\n";
        $prompt .= "2. Consultores com desempenho crítico\n";
        $prompt .= "3. Cartões de alto risco\n";
        $prompt .= "4. Tendências preocupantes\n";
        $prompt .= "5. Recomendações de ações imediatas\n\n";
        $prompt .= "Seja objetivo e direto. Use bullet points.";

        return $this->chat($prompt);
    }

    /**
     * Enviar mensagem para API de chat
     */
    public function chat($message, $systemPrompt = null) {
        if (empty($this->apiKey)) {
            throw new Exception('API Key não configurada.');
        }

        $startTime = microtime(true);
        $lastError = null;
        $modelToUse = $this->model;

        // Tentar com o modelo principal primeiro
        try {
            if ($this->provider === 'groq') {
                $response = $this->callGroq($message, $systemPrompt, $modelToUse);
            } else {
                $response = $this->callOpenAI($message, $systemPrompt, $modelToUse);
            }

            $endTime = microtime(true);
            $processingTime = round($endTime - $startTime, 2);

            Logger::info("AI request successful", [
                'provider' => $this->provider,
                'model' => $modelToUse,
                'processing_time' => $processingTime,
                'tokens' => $response['tokens'] ?? 0
            ]);

            return [
                'success' => true,
                'content' => $response['content'],
                'tokens' => $response['tokens'] ?? 0,
                'processing_time' => $processingTime,
                'model' => $modelToUse,
                'provider' => $this->provider
            ];

        } catch (Exception $e) {
            $lastError = $e->getMessage();

            // Verificar se é um erro de modelo (descontinuado, não encontrado, etc.)
            if ($this->isModelError($lastError)) {
                Logger::warning("Model failed, trying fallbacks", [
                    'model' => $modelToUse,
                    'error' => $lastError
                ]);

                // Tentar com modelos de fallback
                $fallbackModels = self::FALLBACK_MODELS[$this->provider] ?? [];

                foreach ($fallbackModels as $fallbackModel) {
                    // Pular o modelo que já falhou
                    if ($fallbackModel === $modelToUse) {
                        continue;
                    }

                    try {
                        if ($this->provider === 'groq') {
                            $response = $this->callGroq($message, $systemPrompt, $fallbackModel);
                        } else {
                            $response = $this->callOpenAI($message, $systemPrompt, $fallbackModel);
                        }

                        $endTime = microtime(true);
                        $processingTime = round($endTime - $startTime, 2);

                        Logger::info("AI request successful with fallback model", [
                            'provider' => $this->provider,
                            'original_model' => $modelToUse,
                            'fallback_model' => $fallbackModel,
                            'processing_time' => $processingTime,
                            'tokens' => $response['tokens'] ?? 0
                        ]);

                        return [
                            'success' => true,
                            'content' => $response['content'],
                            'tokens' => $response['tokens'] ?? 0,
                            'processing_time' => $processingTime,
                            'model' => $fallbackModel,
                            'provider' => $this->provider,
                            'fallback_used' => true
                        ];

                    } catch (Exception $fallbackError) {
                        $lastError = $fallbackError->getMessage();
                        Logger::warning("Fallback model failed", [
                            'model' => $fallbackModel,
                            'error' => $lastError
                        ]);
                        continue;
                    }
                }
            }

            // Se chegou aqui, todos os modelos falharam
            Logger::error("AI request failed: " . $lastError);

            return [
                'success' => false,
                'error' => $lastError
            ];
        }
    }

    /**
     * Verificar se o erro é relacionado ao modelo
     */
    private function isModelError($errorMessage) {
        $modelErrorPatterns = [
            'decommissioned',
            'deprecated',
            'not found',
            'does not exist',
            'invalid model',
            'model not available'
        ];

        $lowerError = strtolower($errorMessage);

        foreach ($modelErrorPatterns as $pattern) {
            if (strpos($lowerError, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Chamar API Groq
     */
    private function callGroq($message, $systemPrompt = null, $model = null) {
        $url = 'https://api.groq.com/openai/v1/chat/completions';

        $messages = [];

        if ($systemPrompt) {
            $messages[] = [
                'role' => 'system',
                'content' => $systemPrompt
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $message
        ];

        $data = [
            'model' => $model ?? $this->model,
            'messages' => $messages,
            'max_tokens' => AI_MAX_TOKENS,
            'temperature' => AI_TEMPERATURE
        ];

        $response = $this->makeRequest($url, $data, [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json'
        ]);

        if (!isset($response['choices'][0]['message']['content'])) {
            throw new Exception('Resposta inválida da API Groq');
        }

        return [
            'content' => $response['choices'][0]['message']['content'],
            'tokens' => $response['usage']['total_tokens'] ?? 0
        ];
    }

    /**
     * Chamar API OpenAI
     */
    private function callOpenAI($message, $systemPrompt = null, $model = null) {
        $url = 'https://api.openai.com/v1/chat/completions';

        $messages = [];

        if ($systemPrompt) {
            $messages[] = [
                'role' => 'system',
                'content' => $systemPrompt
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $message
        ];

        $data = [
            'model' => $model ?? $this->model,
            'messages' => $messages,
            'max_tokens' => AI_MAX_TOKENS,
            'temperature' => AI_TEMPERATURE
        ];

        $response = $this->makeRequest($url, $data, [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json'
        ]);

        if (!isset($response['choices'][0]['message']['content'])) {
            throw new Exception('Resposta inválida da API OpenAI');
        }

        return [
            'content' => $response['choices'][0]['message']['content'],
            'tokens' => $response['usage']['total_tokens'] ?? 0
        ];
    }

    /**
     * Fazer requisição HTTP
     */
    private function makeRequest($url, $data, $headers = []) {
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            throw new Exception("Erro na requisição: {$error}");
        }

        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            $errorMessage = $errorData['error']['message'] ?? "HTTP {$httpCode}";
            throw new Exception("Erro da API: {$errorMessage}");
        }

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Erro ao decodificar resposta JSON");
        }

        return $decoded;
    }

    /**
     * Construir prompt para análise
     */
    private function buildAnalysisPrompt($data, $type) {
        $prompt = "Você é um auditor financeiro especializado em análise de inadimplência e detecção de fraudes.\n\n";

        switch ($type) {
            case 'consultores':
                $prompt .= "Analise os seguintes dados de consultores e identifique:\n";
                $prompt .= "1. Consultores com taxas de inadimplência preocupantes\n";
                $prompt .= "2. Padrões suspeitos de vendas\n";
                $prompt .= "3. Comparação de desempenho entre consultores\n";
                $prompt .= "4. Recomendações de ações\n\n";
                break;

            case 'cartoes':
                $prompt .= "Analise os seguintes dados de cartões e identifique:\n";
                $prompt .= "1. Cartões com múltiplos documentos (possível fraude)\n";
                $prompt .= "2. Padrões de uso suspeito\n";
                $prompt .= "3. Taxas de inadimplência por cartão\n";
                $prompt .= "4. Recomendações de bloqueio ou investigação\n\n";
                break;

            case 'temporal':
                $prompt .= "Analise a evolução temporal da inadimplência:\n";
                $prompt .= "1. Tendências ao longo do tempo (3 meses, 6 meses, 1 ano)\n";
                $prompt .= "2. Sazonalidade e padrões\n";
                $prompt .= "3. Projeções e previsões\n";
                $prompt .= "4. Recomendações preventivas\n\n";
                break;

            default:
                $prompt .= "Faça uma análise geral dos dados de inadimplência:\n";
                $prompt .= "1. Resumo executivo dos principais indicadores\n";
                $prompt .= "2. Pontos críticos de atenção\n";
                $prompt .= "3. Riscos identificados\n";
                $prompt .= "4. Recomendações prioritárias\n\n";
        }

        $prompt .= "Dados:\n";
        $prompt .= json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $prompt .= "\n\nForneça uma análise clara, objetiva e em português do Brasil. Use formatação markdown.";

        return $prompt;
    }

    /**
     * Construir prompt para resumo
     */
    private function buildSummaryPrompt($data, $filters) {
        $prompt = "Você é um analista de dados financeiros.\n\n";
        $prompt .= "Resuma os seguintes dados de inadimplência de forma executiva:\n\n";

        if (!empty($filters)) {
            $prompt .= "Filtros aplicados:\n";
            foreach ($filters as $key => $value) {
                if ($value) {
                    $prompt .= "- {$key}: {$value}\n";
                }
            }
            $prompt .= "\n";
        }

        $prompt .= "Dados:\n";
        $prompt .= json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $prompt .= "\n\nForneça:\n";
        $prompt .= "1. Resumo executivo (3-5 bullet points)\n";
        $prompt .= "2. Números-chave\n";
        $prompt .= "3. Principal insight\n";
        $prompt .= "4. Recomendação prioritária\n\n";
        $prompt .= "Seja conciso e direto. Use markdown.";

        return $prompt;
    }

    /**
     * Salvar resumo no banco de dados
     */
    public function saveResume($importacaoId, $relatorioId, $tipoAnalise, $prompt, $resposta, $tokens, $tempo) {
        $db = Database::getInstance();

        $data = [
            'importacao_id' => $importacaoId,
            'relatorio_salvo_id' => $relatorioId,
            'tipo_analise' => $tipoAnalise,
            'prompt' => $prompt,
            'resposta' => $resposta,
            'modelo' => $this->model,
            'tokens_utilizados' => $tokens,
            'tempo_processamento' => $tempo,
            'usuario_id' => Auth::userId()
        ];

        return $db->insert('resumos_ia', $data);
    }

    /**
     * Obter resumos salvos
     */
    public function getSavedResumes($filters = []) {
        $db = Database::getInstance();

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['importacao_id'])) {
            $where[] = 'importacao_id = ?';
            $params[] = $filters['importacao_id'];
        }

        if (!empty($filters['tipo_analise'])) {
            $where[] = 'tipo_analise = ?';
            $params[] = $filters['tipo_analise'];
        }

        $sql = "SELECT * FROM resumos_ia
                WHERE " . implode(' AND ', $where) . "
                ORDER BY criado_em DESC
                LIMIT 50";

        return $db->fetchAll($sql, $params);
    }
}
