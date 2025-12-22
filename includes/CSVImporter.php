<?php
/**
 * Classe de Importação de CSV
 */

class CSVImporter {
    private $db;
    private $importacaoId;
    private $mapeamento;
    private $totalLinhas = 0;
    private $linhasProcessadas = 0;
    private $linhasErro = 0;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Iniciar nova importação
     */
    public function startImport($nomeArquivo, $caminhoArquivo, $tipoImportacao = 'completo') {
        $this->importacaoId = $this->db->insert('importacoes', [
            'nome_arquivo' => $nomeArquivo,
            'caminho_arquivo' => $caminhoArquivo,
            'tipo_importacao' => $tipoImportacao,
            'status' => 'pendente',
            'usuario_id' => Auth::userId()
        ]);

        Logger::info("Import started", ['importacao_id' => $this->importacaoId, 'file' => $nomeArquivo]);

        return $this->importacaoId;
    }

    /**
     * Detectar encoding do arquivo
     */
    private function detectEncoding($file) {
        $encodings = ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'];

        $sample = fread($file, 8192);
        rewind($file);

        foreach ($encodings as $encoding) {
            if (mb_check_encoding($sample, $encoding)) {
                return $encoding;
            }
        }

        return 'UTF-8';
    }

    /**
     * Ler cabeçalho do CSV e sugerir mapeamento automático
     */
    public function analyzeCSV($caminhoArquivo) {
        if (!file_exists($caminhoArquivo)) {
            throw new Exception('Arquivo não encontrado.');
        }

        $file = fopen($caminhoArquivo, 'r');

        if (!$file) {
            throw new Exception('Não foi possível abrir o arquivo.');
        }

        $encoding = $this->detectEncoding($file);

        // Ler cabeçalho
        $header = fgetcsv($file, 0, ',');

        if (!$header) {
            fclose($file);
            throw new Exception('Arquivo CSV inválido ou vazio.');
        }

        // Converter encoding se necessário
        if ($encoding !== 'UTF-8') {
            $header = array_map(function($col) use ($encoding) {
                return mb_convert_encoding($col, 'UTF-8', $encoding);
            }, $header);
        }

        // Ler primeira linha de dados como exemplo
        $exampleRow = fgetcsv($file, 0, ',');

        if ($exampleRow && $encoding !== 'UTF-8') {
            $exampleRow = array_map(function($col) use ($encoding) {
                return mb_convert_encoding($col, 'UTF-8', $encoding);
            }, $exampleRow);
        }

        // Contar total de linhas
        $totalLinhas = 1; // Já lemos uma linha
        while (fgets($file) !== false) {
            $totalLinhas++;
        }

        fclose($file);

        // Sugerir mapeamento automático
        $mapeamentoSugerido = $this->suggestMapping($header);

        return [
            'header' => $header,
            'example_row' => $exampleRow,
            'total_rows' => $totalLinhas,
            'encoding' => $encoding,
            'suggested_mapping' => $mapeamentoSugerido
        ];
    }

    /**
     * Sugerir mapeamento automático de colunas
     */
    private function suggestMapping($header) {
        $mapping = [];

        $patterns = [
            'numero_titulo' => ['titulo', 'numero_titulo', 'numerotitulo', 'num_titulo'],
            'nome_produto_original' => ['produto_original', 'nomeprodutooriginal', 'produto orig'],
            'nome_produto_atual' => ['produto_atual', 'nomeprodutoatual', 'produto', 'nomeproduto'],
            'alterou_vagas' => ['alterou', 'alterouvagas', 'alterou vagas'],
            'categoria' => ['categoria'],
            'status_titulo' => ['status', 'statustitulo', 'status titulo'],
            'status_inadimplencia' => ['inadimplencia', 'statusInadimplencia', 'status inadimplencia'],
            'periodo_titulo' => ['periodo', 'periodotitulo'],
            'dias_desde_venda' => ['dias', 'diasdesdevenda'],
            'nome_titular' => ['titular', 'nometitular', 'nome'],
            'documento_titular' => ['documento', 'cpf', 'documentotitular'],
            'telefone_residencial' => ['telefone', 'fone', 'telefoneresidencial', 'residentialphone'],
            'data_cadastro' => ['cadastro', 'datacadastro', 'data cadastro'],
            'data_primeira_venda' => ['primeira_venda', 'dataprimeiravenda', 'primeiravenda'],
            'data_ultima_venda' => ['ultima_venda', 'dataultimavenda', 'ultimavenda', 'datavenda'],
            'origem_venda' => ['origem', 'origemvenda'],
            'promotor' => ['promotor', 'vendedor', 'consultor'],
            'gerente' => ['gerente'],
            'quantidade_parcelas_venda' => ['parcelas', 'qtd_parcelas', 'quantidadeparcelasvenda'],
            'qtd_parcelas_pagas' => ['pagas', 'parcelaspagas', 'qtdparcelaspagas'],
            'parcelas_restantes' => ['restantes', 'parcelasrestantes'],
            'lista_parcelas_pagas' => ['lista_parcelas', 'parcelas pagas'],
            'lista_valores_pagos' => ['valores', 'valorespagos'],
            'forma_pagamento' => ['forma', 'formapagamento', 'paymentmode'],
            'tipo_pagamento' => ['tipo', 'tipopagamento'],
            'valor_parcela' => ['valor', 'valorparcela'],
            'valor_total_plano' => ['total', 'valortotalplano', 'valortotal'],
            'total_pago' => ['pago', 'totalpago'],
            'saldo_restante' => ['saldo', 'saldorestante'],
            'numero_cartao' => ['cartao', 'numerocartao', 'numero cartao'],
            'bandeira' => ['bandeira'],
            'tipo_pagamento_cartao' => ['tipo_cartao', 'paymenttype'],
            'total_titulos_no_cartao' => ['titulos_cartao', 'totaltitulosnocartao'],
            'total_documentos_no_cartao' => ['docs_cartao', 'totaldocumentosnocartao'],
            'taxa_inadimplencia_cartao' => ['taxa_cartao', 'taxainadimplenciacartao'],
            'nivel_risco_cartao' => ['risco_cartao', 'nivelriscocartao'],
            'vendas_consultor' => ['vendas', 'vendasconsultor'],
            'taxa_inadimplencia_consultor' => ['taxa_consultor', 'taxainadimplenciaconsultor'],
            'nivel_risco_consultor' => ['risco_consultor', 'nivelriscoconsultor'],
            'score_risco_geral' => ['score', 'scoreriscoger al']
        ];

        foreach ($header as $index => $columnName) {
            $normalized = strtolower(trim($columnName));
            $normalized = preg_replace('/[^a-z0-9]/', '', $normalized);

            foreach ($patterns as $field => $aliases) {
                foreach ($aliases as $alias) {
                    $normalizedAlias = preg_replace('/[^a-z0-9]/', '', strtolower($alias));

                    if (strpos($normalized, $normalizedAlias) !== false || strpos($normalizedAlias, $normalized) !== false) {
                        $mapping[$index] = $field;
                        break 2;
                    }
                }
            }

            // Se não encontrou mapeamento, deixar em branco
            if (!isset($mapping[$index])) {
                $mapping[$index] = '';
            }
        }

        return $mapping;
    }

    /**
     * Processar CSV com mapeamento fornecido
     */
    public function processCSV($importacaoId, $mapeamento) {
        $this->importacaoId = $importacaoId;
        $this->mapeamento = $mapeamento;

        // Obter dados da importação
        $importacao = $this->db->fetchOne(
            "SELECT * FROM importacoes WHERE id = ?",
            [$importacaoId]
        );

        if (!$importacao) {
            throw new Exception('Importação não encontrada.');
        }

        $caminhoArquivo = $importacao['caminho_arquivo'];

        if (!file_exists($caminhoArquivo)) {
            throw new Exception('Arquivo não encontrado.');
        }

        // Atualizar status
        $this->db->update('importacoes', [
            'status' => 'processando',
            'mapeamento_colunas' => json_encode($mapeamento),
            'iniciado_em' => date('Y-m-d H:i:s')
        ], 'id = ?', [$importacaoId]);

        try {
            $this->db->beginTransaction();

            $file = fopen($caminhoArquivo, 'r');
            $encoding = $this->detectEncoding($file);

            // Pular cabeçalho
            fgetcsv($file, 0, ',');

            $batchSize = 100;
            $batch = [];

            while (($row = fgetcsv($file, 0, ',')) !== false) {
                $this->totalLinhas++;

                // Converter encoding se necessário
                if ($encoding !== 'UTF-8') {
                    $row = array_map(function($col) use ($encoding) {
                        return mb_convert_encoding($col, 'UTF-8', $encoding);
                    }, $row);
                }

                try {
                    $data = $this->mapRowToData($row);
                    $data['importacao_id'] = $importacaoId;

                    $batch[] = $data;

                    if (count($batch) >= $batchSize) {
                        $this->insertBatch($batch);
                        $batch = [];

                        $this->linhasProcessadas += $batchSize;
                        $this->updateProgress();
                    }

                } catch (Exception $e) {
                    $this->linhasErro++;
                    Logger::warning("Error processing CSV row", [
                        'importacao_id' => $importacaoId,
                        'linha' => $this->totalLinhas,
                        'erro' => $e->getMessage()
                    ]);
                }
            }

            // Inserir lote restante
            if (!empty($batch)) {
                $this->insertBatch($batch);
                $this->linhasProcessadas += count($batch);
            }

            fclose($file);

            // Processar análises agregadas
            $this->processAggregations();

            // Recalcular status de inadimplência baseado em tempo decorrido
            Logger::info("Recalculando status de inadimplência", ['importacao_id' => $importacaoId]);
            $recalculo = InadimplenciaHelper::recalcularStatusImportacao($importacaoId);
            Logger::info("Status de inadimplência recalculados", $recalculo);

            $this->db->commit();

            // Atualizar status final
            $this->db->update('importacoes', [
                'status' => 'concluido',
                'total_linhas' => $this->totalLinhas,
                'linhas_processadas' => $this->linhasProcessadas,
                'linhas_erro' => $this->linhasErro,
                'concluido_em' => date('Y-m-d H:i:s')
            ], 'id = ?', [$importacaoId]);

            Logger::info("Import completed", [
                'importacao_id' => $importacaoId,
                'total' => $this->totalLinhas,
                'processadas' => $this->linhasProcessadas,
                'erros' => $this->linhasErro
            ]);

            Logger::logAction(Auth::userId(), 'import_csv', 'Completou importação de CSV', 'importacoes', $importacaoId);

            return [
                'success' => true,
                'total_linhas' => $this->totalLinhas,
                'linhas_processadas' => $this->linhasProcessadas,
                'linhas_erro' => $this->linhasErro
            ];

        } catch (Exception $e) {
            $this->db->rollback();

            $this->db->update('importacoes', [
                'status' => 'erro',
                'mensagem_erro' => $e->getMessage()
            ], 'id = ?', [$importacaoId]);

            Logger::error("Import failed", [
                'importacao_id' => $importacaoId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Mapear linha do CSV para dados do banco
     */
    private function mapRowToData($row) {
        $data = [];

        foreach ($this->mapeamento as $index => $field) {
            if (empty($field) || !isset($row[$index])) {
                continue;
            }

            $value = trim($row[$index]);

            // Campos booleanos - processar ANTES de verificar se está vazio
            if (in_array($field, ['alterou_vagas', 'alerta_apenas_1parcela', 'alerta_cartao_risco', 'alerta_consultor_risco', 'alerta_alterou_vagas_inadimplente'])) {
                if ($value === '' || $value === null) {
                    $data[$field] = 0; // FALSE para campos booleanos vazios
                } else {
                    $data[$field] = in_array(strtolower($value), ['sim', 'yes', '1', 'true', 's']) ? 1 : 0;
                }
                continue;
            }

            // Campos numéricos
            if (in_array($field, ['dias_desde_venda', 'quantidade_parcelas_venda', 'qtd_parcelas_pagas', 'parcelas_restantes', 'total_titulos_no_cartao', 'total_documentos_no_cartao', 'total_promotores_no_cartao', 'titulos_inadimplentes_no_cartao', 'vendas_consultor', 'clientes_consultor', 'score_risco_geral'])) {
                $data[$field] = $value !== '' ? (int)$value : null;
                continue;
            }

            // Campos decimais
            if (in_array($field, ['valor_parcela', 'valor_total_plano', 'total_pago', 'saldo_restante', 'taxa_inadimplencia_cartao', 'taxa_inadimplencia_consultor', 'taxa_inadimplencia_3meses_consultor', 'taxa_inadimplencia_6meses_consultor', 'taxa_inadimplencia_1ano_consultor', 'valor_recebido_consultor', 'valor_perdido_consultor'])) {
                $data[$field] = $value !== '' ? floatval(str_replace(',', '.', str_replace('.', '', $value))) : null;
                continue;
            }

            // Campos de data
            if (in_array($field, ['data_cadastro', 'data_primeira_venda', 'data_ultima_venda'])) {
                if ($value !== '' && $value !== null) {
                    try {
                        $data[$field] = date('Y-m-d H:i:s', strtotime($value));
                    } catch (Exception $e) {
                        $data[$field] = null;
                    }
                } else {
                    $data[$field] = null;
                }
                continue;
            }

            // Campos de texto normais
            $data[$field] = $value !== '' ? $value : null;
        }

        return $data;
    }

    /**
     * Inserir lote de dados (com UPSERT - atualiza se existir)
     */
    private function insertBatch($batch) {
        foreach ($batch as $data) {
            // Verificar se já existe um título com este numero_titulo e importacao_id
            $existing = $this->db->fetchOne(
                "SELECT id FROM titulos WHERE numero_titulo = ? AND importacao_id = ?",
                [$data['numero_titulo'], $data['importacao_id']]
            );

            if ($existing) {
                // Atualizar registro existente se houver diferenças
                $this->db->update('titulos', $data, 'id = ?', [$existing['id']]);
            } else {
                // Inserir novo registro
                $this->db->insert('titulos', $data);
            }
        }
    }

    /**
     * Processar agregações (consultores e cartões)
     */
    private function processAggregations() {
        // Análise de Consultores
        $sql = "INSERT INTO analise_consultores (
                    importacao_id, promotor, total_vendas, total_clientes,
                    vendas_ativas, vendas_bloqueadas, vendas_canceladas,
                    inadimplentes_1parcela, total_inadimplentes, total_adimplentes,
                    taxa_inadimplencia_geral, valor_total_recebido, valor_total_perdido,
                    nivel_risco, primeira_venda, ultima_venda
                )
                SELECT
                    {$this->importacaoId} as importacao_id,
                    promotor,
                    COUNT(*) as total_vendas,
                    COUNT(DISTINCT documento_titular) as total_clientes,
                    SUM(CASE WHEN status_titulo = 'Ativo' THEN 1 ELSE 0 END) as vendas_ativas,
                    SUM(CASE WHEN status_titulo = 'Bloqueado' THEN 1 ELSE 0 END) as vendas_bloqueadas,
                    SUM(CASE WHEN status_titulo = 'Cancelado' THEN 1 ELSE 0 END) as vendas_canceladas,
                    SUM(CASE WHEN status_inadimplencia = 'INADIMPLENTE - Apenas 1ª Parcela' THEN 1 ELSE 0 END) as inadimplentes_1parcela,
                    SUM(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 ELSE 0 END) as total_inadimplentes,
                    SUM(CASE WHEN status_inadimplencia = 'ADIMPLENTE' THEN 1 ELSE 0 END) as total_adimplentes,
                    ROUND(SUM(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as taxa_inadimplencia_geral,
                    SUM(COALESCE(total_pago, 0)) as valor_total_recebido,
                    SUM(COALESCE(saldo_restante, 0)) as valor_total_perdido,
                    CASE
                        WHEN SUM(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 ELSE 0 END) * 100.0 / COUNT(*) >= 50 THEN 'CRÍTICO'
                        WHEN SUM(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 ELSE 0 END) * 100.0 / COUNT(*) >= 30 THEN 'ALTO'
                        WHEN SUM(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 ELSE 0 END) * 100.0 / COUNT(*) >= 15 THEN 'MÉDIO'
                        ELSE 'BAIXO'
                    END as nivel_risco,
                    MIN(data_primeira_venda) as primeira_venda,
                    MAX(data_ultima_venda) as ultima_venda
                FROM titulos
                WHERE importacao_id = {$this->importacaoId}
                  AND promotor IS NOT NULL
                GROUP BY promotor";

        $this->db->query($sql);

        // Análise de Cartões
        $sql = "INSERT INTO analise_cartoes (
                    importacao_id, numero_cartao, bandeira, tipo_pagamento_cartao,
                    total_titulos_no_cartao, total_documentos_no_cartao, total_promotores_no_cartao,
                    titulos_ativos, titulos_bloqueados, titulos_cancelados, titulos_inadimplentes,
                    taxa_inadimplencia_cartao, primeira_venda_cartao, ultima_venda_cartao,
                    nivel_risco
                )
                SELECT
                    {$this->importacaoId} as importacao_id,
                    numero_cartao,
                    MAX(bandeira) as bandeira,
                    MAX(tipo_pagamento_cartao) as tipo_pagamento_cartao,
                    COUNT(*) as total_titulos_no_cartao,
                    COUNT(DISTINCT documento_titular) as total_documentos_no_cartao,
                    COUNT(DISTINCT promotor) as total_promotores_no_cartao,
                    SUM(CASE WHEN status_titulo = 'Ativo' THEN 1 ELSE 0 END) as titulos_ativos,
                    SUM(CASE WHEN status_titulo = 'Bloqueado' THEN 1 ELSE 0 END) as titulos_bloqueados,
                    SUM(CASE WHEN status_titulo = 'Cancelado' THEN 1 ELSE 0 END) as titulos_cancelados,
                    SUM(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 ELSE 0 END) as titulos_inadimplentes,
                    ROUND(SUM(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as taxa_inadimplencia_cartao,
                    MIN(data_primeira_venda) as primeira_venda_cartao,
                    MAX(data_ultima_venda) as ultima_venda_cartao,
                    CASE
                        WHEN COUNT(DISTINCT documento_titular) >= 10
                             AND SUM(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 ELSE 0 END) * 100.0 / COUNT(*) >= 30
                        THEN 'FRAUDE PROVÁVEL'
                        WHEN COUNT(DISTINCT documento_titular) >= 10
                             OR SUM(CASE WHEN status_titulo IN ('Bloqueado', 'Cancelado') THEN 1 ELSE 0 END) * 100.0 / COUNT(*) >= 40
                        THEN 'ALTO RISCO'
                        WHEN COUNT(DISTINCT documento_titular) >= 5
                             OR SUM(CASE WHEN status_titulo IN ('Bloqueado', 'Cancelado') THEN 1 ELSE 0 END) * 100.0 / COUNT(*) >= 25
                        THEN 'MÉDIO RISCO'
                        ELSE 'BAIXO RISCO'
                    END as nivel_risco
                FROM titulos
                WHERE importacao_id = {$this->importacaoId}
                  AND numero_cartao IS NOT NULL
                  AND numero_cartao != ''
                GROUP BY numero_cartao
                HAVING COUNT(*) >= 2";

        $this->db->query($sql);
    }

    /**
     * Atualizar progresso da importação
     */
    private function updateProgress() {
        $this->db->update('importacoes', [
            'linhas_processadas' => $this->linhasProcessadas,
            'linhas_erro' => $this->linhasErro
        ], 'id = ?', [$this->importacaoId]);
    }

    /**
     * Salvar mapeamento como template
     */
    public function saveMapping($nome, $descricao, $tipoDados, $mapeamento, $exemploLinha = null) {
        $data = [
            'nome' => $nome,
            'descricao' => $descricao,
            'tipo_dados' => $tipoDados,
            'mapeamento' => json_encode($mapeamento),
            'exemplo_linha' => $exemploLinha,
            'usuario_id' => Auth::userId()
        ];

        return $this->db->insert('mapeamentos_colunas', $data);
    }

    /**
     * Obter mapeamentos salvos
     */
    public function getSavedMappings($tipoDados = null) {
        $where = '1=1';
        $params = [];

        if ($tipoDados) {
            $where = 'tipo_dados = ?';
            $params[] = $tipoDados;
        }

        return $this->db->fetchAll(
            "SELECT * FROM mapeamentos_colunas WHERE {$where} ORDER BY padrao DESC, nome ASC",
            $params
        );
    }
}
