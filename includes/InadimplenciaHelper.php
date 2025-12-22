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

            // Se pagou apenas 1 parcela
            if ($parcelasPagas == 1) {
                return 'INADIMPLENTE - Apenas 1ª Parcela';
            }

            // Se pagou apenas 2 parcelas
            if ($parcelasPagas == 2) {
                return 'INADIMPLENTE - Apenas 2 Parcelas';
            }

            // Para outros casos, apenas marca como inadimplente genérico
            // (não usamos "Mais de 50%" porque não há mais cobrança ativa)
            return 'INADIMPLENTE';
        }

        // Para títulos ATIVOS, aplicar lógica baseada em tempo

        // Calcular meses desde a primeira venda
        $mesesDesdeVenda = self::calcularMesesDesdeVenda($dataPrimeiraVenda);

        // Parcelas esperadas = meses desde a venda (1 parcela por mês)
        $parcelasEsperadas = $mesesDesdeVenda;

        // Se pagou todas as parcelas do plano = ADIMPLENTE
        if ($parcelasPagas >= $totalParcelas) {
            return 'ADIMPLENTE';
        }

        // Se pagou todas as parcelas esperadas até agora = ADIMPLENTE
        if ($parcelasPagas >= $parcelasEsperadas) {
            return 'ADIMPLENTE';
        }

        // Se chegou aqui, está inadimplente (pagou menos que o esperado)
        // Classificar por TEMPO DE INADIMPLÊNCIA (meses em atraso)

        // Calcular meses em atraso
        $mesesEmAtraso = $parcelasEsperadas - $parcelasPagas;

        // Caso especial: Apenas 1ª parcela paga (pode ser por premiação)
        if ($parcelasPagas == 1 && $mesesDesdeVenda > 1) {
            return 'INADIMPLENTE - Apenas 1ª Parcela';
        }

        // Caso especial: Apenas 2 parcelas pagas
        if ($parcelasPagas == 2 && $mesesDesdeVenda > 2) {
            return 'INADIMPLENTE - Apenas 2 Parcelas';
        }

        // Classificação baseada no TEMPO DESDE A VENDA (não em atraso)
        // Isso ajuda a identificar em qual etapa o cliente parou de pagar

        if ($mesesDesdeVenda <= 3) {
            // Até 3 meses: ainda pode ser por causa da premiação
            return 'INADIMPLENTE - Até 3 meses';
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
     * Calcular quantos meses se passaram desde a data de venda
     *
     * @param string|DateTime $dataVenda Data da primeira venda
     * @return int Número de meses (mínimo 1)
     */
    public static function calcularMesesDesdeVenda($dataVenda) {
        if (is_string($dataVenda)) {
            $dataVenda = new DateTime($dataVenda);
        }

        $hoje = new DateTime();

        // Calcular diferença em meses
        $diff = $dataVenda->diff($hoje);
        $meses = ($diff->y * 12) + $diff->m;

        // Se está no mesmo mês ou passou dias, conta como 1 mês
        if ($meses == 0 || $diff->d > 0) {
            $meses++;
        }

        return max(1, $meses); // Mínimo 1 mês
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

            // Categorias especiais
            'INADIMPLENTE - Apenas 1ª Parcela' => 'danger',
            'INADIMPLENTE - Apenas 2 Parcelas' => 'danger',

            // Novas categorias por tempo (do menos grave ao mais grave)
            'INADIMPLENTE - Até 3 meses' => 'warning',      // Amarelo - pode ser premiação
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
