<?php
/**
 * Helper para Cálculo de Inadimplência
 *
 * Calcula o status de inadimplência baseado no TEMPO DECORRIDO desde a venda,
 * não apenas na porcentagem de parcelas pagas do total do plano.
 */

class InadimplenciaHelper {

    /**
     * Calcular status de inadimplência de um título
     *
     * @param array $titulo Array com dados do título
     * @return string Status de inadimplência
     */
    public static function calcularStatus($titulo) {
        // Validar dados obrigatórios
        if (!isset($titulo['data_primeira_venda']) || !$titulo['data_primeira_venda']) {
            return 'SEM DADOS';
        }

        if (!isset($titulo['qtd_parcelas_pagas']) || !isset($titulo['quantidade_parcelas_venda'])) {
            return 'SEM DADOS';
        }

        $dataPrimeiraVenda = $titulo['data_primeira_venda'];
        $parcelasPagas = (int)$titulo['qtd_parcelas_pagas'];
        $totalParcelas = (int)$titulo['quantidade_parcelas_venda'];
        $statusTitulo = $titulo['status_titulo'] ?? 'Ativo';

        // IMPORTANTE: Títulos Bloqueados ou Cancelados param de ter cobrança
        // Para esses títulos, não faz sentido calcular inadimplência baseada em tempo
        if ($statusTitulo === 'Bloqueado' || $statusTitulo === 'Cancelado') {
            // Para títulos bloqueados/cancelados, verificamos apenas se pagou o total do plano
            if ($parcelasPagas >= $totalParcelas) {
                return 'ADIMPLENTE';
            }

            // Se pagou apenas 1 parcela (requer análise - pode ser premiação)
            if ($parcelasPagas == 1) {
                return 'INADIMPLENTE - Requer análise (1ª parcela)';
            }

            // Se pagou apenas 2 parcelas (requer análise - pode ser premiação)
            if ($parcelasPagas == 2) {
                return 'INADIMPLENTE - Requer análise (2 parcelas)';
            }

            // Para outros casos, apenas marca como inadimplente genérico
            // (não usamos "Mais de 50%" porque não há mais cobrança ativa)
            return 'INADIMPLENTE';
        }

        // Para títulos ATIVOS, aplicar lógica baseada em tempo

        // Calcular parcelas esperadas até hoje (incluindo a 1ª paga no dia da compra)
        $mesesDesdeVenda = self::calcularMesesDesdeVenda($dataPrimeiraVenda);
        $parcelasEsperadas = $mesesDesdeVenda;

        // Se pagou todas as parcelas do plano = ADIMPLENTE
        if ($parcelasPagas >= $totalParcelas) {
            return 'ADIMPLENTE';
        }

        // REGRA CRÍTICA: Só considera INADIMPLENTE se tiver 2 OU MAIS parcelas em atraso
        // Com apenas 1 parcela em atraso ainda é considerado ADIMPLENTE
        $parcelasEmAtraso = max(0, $parcelasEsperadas - $parcelasPagas);

        // Se está em dia OU tem apenas 1 parcela em atraso = ADIMPLENTE
        if ($parcelasEmAtraso <= 1) {
            return 'ADIMPLENTE';
        }

        // Se chegou aqui, tem 2 ou mais parcelas em atraso = INADIMPLENTE
        // Classificar por TEMPO DE INADIMPLÊNCIA

        // Calcular meses em atraso (já sabemos que tem pelo menos 2)
        $mesesEmAtraso = $parcelasEmAtraso;

        // Caso especial: Apenas 1ª parcela paga (requer análise - pode ser premiação)
        // Já sabemos que tem pelo menos 2 parcelas em atraso
        if ($parcelasPagas == 1) {
            return 'INADIMPLENTE - Requer análise (1ª parcela)';
        }

        // Caso especial: Apenas 2 parcelas pagas (requer análise - pode ser premiação)
        // Já sabemos que tem pelo menos 2 parcelas em atraso
        if ($parcelasPagas == 2) {
            return 'INADIMPLENTE - Requer análise (2 parcelas)';
        }

        // Classificação baseada no TEMPO DESDE A VENDA (não em atraso)
        // Isso ajuda a identificar em qual etapa o cliente parou de pagar

        if ($mesesDesdeVenda <= 3) {
            // Até 3 meses: requer análise - ainda pode ser por causa da premiação
            return 'INADIMPLENTE - Requer análise (até 3 meses)';
        } elseif ($mesesDesdeVenda <= 6) {
            // 3-6 meses: cliente pode não ter tido boa experiência
            return 'INADIMPLENTE - 3 a 6 meses';
        } elseif ($mesesDesdeVenda <= 9) {
            // 6-9 meses: questão de experiência/expectativa
            return 'INADIMPLENTE - 6 a 9 meses';
        } elseif ($mesesDesdeVenda <= 12) {
            // 9-12 meses: experiência/expectativa
            return 'INADIMPLENTE - 9 a 12 meses';
        } else {
            // Mais de 12 meses: inadimplência prolongada
            return 'INADIMPLENTE - Mais de 12 meses';
        }
    }

    /**
     * Calcular quantas parcelas deveriam ter sido pagas até hoje
     * IMPORTANTE: 1ª parcela é paga NO DIA DA COMPRA!
     *
     * @param string|DateTime $dataVenda Data da primeira venda
     * @return int Número de parcelas esperadas (incluindo a do dia da compra)
     */
    public static function calcularMesesDesdeVenda($dataVenda) {
        if (is_string($dataVenda)) {
            $dataVenda = new DateTime($dataVenda);
        }

        $hoje = new DateTime();

        // Calcular diferença em meses completos
        $diff = $dataVenda->diff($hoje);
        $meses = ($diff->y * 12) + $diff->m;

        // CORREÇÃO CRÍTICA: Só conta o mês atual se já passou o dia de vencimento
        // Exemplo: Vendido dia 17/09/2024, hoje 22/12/2024
        // - 1ª parcela: paga em 17/09/2024 (dia da compra)
        // - 2ª parcela: vence 17/10/2024
        // - 3ª parcela: vence 17/11/2024
        // - 4ª parcela: vence 17/12/2024 (já venceu porque hoje é 22/12)
        // - Total esperado: 4 parcelas

        $diaVenda = (int)$dataVenda->format('d');
        $diaHoje = (int)$hoje->format('d');

        // Se já passou o dia de vencimento no mês atual, conta mais um mês
        if ($diaHoje >= $diaVenda) {
            $meses++;
        }

        // Parcelas esperadas = $meses (inclui a parcela do dia da compra)
        // Mínimo 1 (se comprou hoje, já pagou a 1ª no ato)
        return max(1, $meses);
    }

    /**
     * Verificar se um título está inadimplente
     *
     * @param array $titulo Array com dados do título
     * @return bool True se inadimplente
     */
    public static function estaInadimplente($titulo) {
        $status = self::calcularStatus($titulo);
        return strpos($status, 'INADIMPLENTE') !== false;
    }

    /**
     * Calcular taxa de inadimplência de um conjunto de títulos
     *
     * @param array $titulos Array de títulos
     * @return array ['total' => int, 'inadimplentes' => int, 'taxa' => float]
     */
    public static function calcularTaxaInadimplencia($titulos) {
        $total = count($titulos);
        $inadimplentes = 0;

        foreach ($titulos as $titulo) {
            if (self::estaInadimplente($titulo)) {
                $inadimplentes++;
            }
        }

        $taxa = $total > 0 ? ($inadimplentes / $total) * 100 : 0;

        return [
            'total' => $total,
            'inadimplentes' => $inadimplentes,
            'adimplentes' => $total - $inadimplentes,
            'taxa' => round($taxa, 2)
        ];
    }

    /**
     * Recalcular status de inadimplência de todos os títulos de uma importação
     *
     * @param int $importacaoId ID da importação
     * @return array Estatísticas do recálculo
     */
    public static function recalcularStatusImportacao($importacaoId) {
        $db = Database::getInstance();

        // Buscar todos os títulos da importação
        $titulos = $db->fetchAll(
            "SELECT id, data_primeira_venda, qtd_parcelas_pagas, quantidade_parcelas_venda, status_inadimplencia, status_titulo
             FROM titulos
             WHERE importacao_id = ?",
            [$importacaoId]
        );

        $total = 0;
        $atualizados = 0;
        $erros = 0;

        foreach ($titulos as $titulo) {
            $total++;

            try {
                // Calcular novo status
                $novoStatus = self::calcularStatus($titulo);

                // Atualizar apenas se mudou
                if ($titulo['status_inadimplencia'] !== $novoStatus) {
                    $db->update('titulos', [
                        'status_inadimplencia' => $novoStatus
                    ], 'id = ?', [$titulo['id']]);

                    $atualizados++;
                }

            } catch (Exception $e) {
                $erros++;
                Logger::error("Erro ao recalcular status do título {$titulo['id']}: " . $e->getMessage());
            }
        }

        Logger::info("Recálculo de inadimplência concluído", [
            'importacao_id' => $importacaoId,
            'total' => $total,
            'atualizados' => $atualizados,
            'erros' => $erros
        ]);

        return [
            'total' => $total,
            'atualizados' => $atualizados,
            'erros' => $erros
        ];
    }

    /**
     * Recalcular status de inadimplência de TODOS os títulos do sistema
     *
     * @return array Estatísticas do recálculo
     */
    public static function recalcularTodosStatus() {
        $db = Database::getInstance();

        $db->beginTransaction();

        try {
            // Buscar todas as importações
            $importacoes = $db->fetchAll("SELECT id FROM importacoes");

            $totalGeral = 0;
            $atualizadosGeral = 0;
            $errosGeral = 0;

            foreach ($importacoes as $importacao) {
                $resultado = self::recalcularStatusImportacao($importacao['id']);
                $totalGeral += $resultado['total'];
                $atualizadosGeral += $resultado['atualizados'];
                $errosGeral += $resultado['erros'];
            }

            $db->commit();

            return [
                'total' => $totalGeral,
                'atualizados' => $atualizadosGeral,
                'erros' => $errosGeral
            ];

        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
    }

    /**
     * Obter estatísticas de inadimplência por categoria
     *
     * @param int $importacaoId ID da importação (opcional)
     * @return array Estatísticas por categoria de inadimplência
     */
    public static function obterEstatisticasPorCategoria($importacaoId = null) {
        $db = Database::getInstance();

        $where = $importacaoId ? "WHERE importacao_id = ?" : "";
        $params = $importacaoId ? [$importacaoId] : [];

        // Primeiro, recalcular todos os status
        if ($importacaoId) {
            self::recalcularStatusImportacao($importacaoId);
        } else {
            self::recalcularTodosStatus();
        }

        // Depois buscar as estatísticas
        $sql = "SELECT
                    status_inadimplencia,
                    COUNT(*) as total,
                    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM titulos {$where}), 2) as percentual
                FROM titulos
                {$where}
                GROUP BY status_inadimplencia
                ORDER BY total DESC";

        return $db->fetchAll($sql, $params);
    }

    /**
     * Formatar status para exibição com badge colorido
     *
     * @param string $status Status de inadimplência
     * @return array ['text' => string, 'class' => string]
     */
    public static function formatarStatusBadge($status) {
        $classes = [
            'ADIMPLENTE' => 'success',
            'PARCIALMENTE ADIMPLENTE' => 'info',

            // Categorias especiais - REQUER ANÁLISE
            'INADIMPLENTE - Requer análise (1ª parcela)' => 'warning',
            'INADIMPLENTE - Requer análise (2 parcelas)' => 'warning',
            'INADIMPLENTE - Requer análise (até 3 meses)' => 'warning',

            // Categorias antigas (compatibilidade)
            'INADIMPLENTE - Apenas 1ª Parcela' => 'warning',
            'INADIMPLENTE - Apenas 2 Parcelas' => 'warning',
            'INADIMPLENTE - Até 3 meses' => 'warning',

            // Novas categorias por tempo (do menos grave ao mais grave)
            'INADIMPLENTE - 3 a 6 meses' => 'warning',      // Amarelo - experiência
            'INADIMPLENTE - 6 a 9 meses' => 'danger',       // Vermelho - expectativa não atendida
            'INADIMPLENTE - 9 a 12 meses' => 'danger',      // Vermelho - problema sério
            'INADIMPLENTE - Mais de 12 meses' => 'danger',  // Vermelho - inadimplência crônica

            // Categorias antigas (manter para compatibilidade)
            'INADIMPLENTE - Menos de 50%' => 'warning',
            'INADIMPLENTE - Mais de 50%' => 'danger',

            // Genérico
            'INADIMPLENTE' => 'danger',
            'SEM DADOS' => 'secondary'
        ];

        $class = $classes[$status] ?? 'secondary';

        return [
            'text' => $status,
            'class' => $class
        ];
    }
}
