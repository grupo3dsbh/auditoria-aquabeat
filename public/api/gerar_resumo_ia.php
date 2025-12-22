<?php
define('APP_ROOT', dirname(dirname(__DIR__)));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAuth('login.php');

header('Content-Type: application/json');

try {
    $importacaoId = $_GET['importacao_id'] ?? null;
    $dataInicio = $_GET['data_inicio'] ?? null;
    $dataFim = $_GET['data_fim'] ?? null;
    $promotor = $_GET['promotor'] ?? null;

    $db = Database::getInstance();

    // Construir condições SQL
    $conditions = ["1=1"];
    $params = [];

    if ($importacaoId) {
        $conditions[] = "importacao_id = ?";
        $params[] = $importacaoId;
    }

    if ($dataInicio) {
        $conditions[] = "data_primeira_venda >= ?";
        $params[] = $dataInicio;
    }

    if ($dataFim) {
        $conditions[] = "data_primeira_venda <= ?";
        $params[] = $dataFim;
    }

    if ($promotor) {
        $conditions[] = "promotor = ?";
        $params[] = $promotor;
    }

    $whereClause = implode(' AND ', $conditions);

    // Buscar estatísticas gerais
    $stats = $db->fetchOne("
        SELECT
            COUNT(*) as total_titulos,
            COUNT(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 END) as total_inadimplentes,
            COUNT(CASE WHEN status_inadimplencia = 'ADIMPLENTE' THEN 1 END) as total_adimplentes,
            COUNT(CASE WHEN status_inadimplencia = 'INADIMPLENTE - Apenas 1ª Parcela' THEN 1 END) as apenas_1parcela,
            COUNT(CASE WHEN status_inadimplencia = 'INADIMPLENTE - Apenas 2 Parcelas' THEN 1 END) as apenas_2parcelas,
            COUNT(CASE WHEN status_inadimplencia = 'INADIMPLENTE - Menos de 50%' THEN 1 END) as menos_50,
            COUNT(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE - %50% ou mais%' THEN 1 END) as mais_50,
            ROUND(SUM(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN saldo_restante ELSE 0 END), 2) as valor_risco_total,
            ROUND(AVG(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN dias_desde_venda END), 0) as media_dias_inadimplentes
        FROM titulos
        WHERE $whereClause
    ", $params);

    // Taxa de inadimplência
    $taxaInadimplencia = $stats['total_titulos'] > 0
        ? round(($stats['total_inadimplentes'] / $stats['total_titulos']) * 100, 2)
        : 0;

    // Top 3 consultores com mais inadimplência
    $topConsultores = $db->fetchAll("
        SELECT
            promotor,
            COUNT(*) as total_vendas,
            COUNT(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 END) as inadimplentes,
            ROUND(COUNT(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 END) * 100.0 / COUNT(*), 2) as taxa
        FROM titulos
        WHERE $whereClause
        GROUP BY promotor
        HAVING inadimplentes > 0
        ORDER BY inadimplentes DESC
        LIMIT 3
    ", $params);

    // Títulos bloqueados/cancelados rapidamente
    $titulosProblematicos = $db->fetchColumn("
        SELECT COUNT(*)
        FROM titulos
        WHERE $whereClause
          AND status_titulo IN ('Bloqueado', 'Cancelado')
          AND dias_desde_venda <= 30
    ", $params);

    // Gerar resumo inteligente
    $resumo = gerarResumoInteligente($stats, $taxaInadimplencia, $topConsultores, $titulosProblematicos);

    echo json_encode([
        'success' => true,
        'resumo' => $resumo,
        'stats' => $stats,
        'taxa_inadimplencia' => $taxaInadimplencia
    ]);

} catch (Exception $e) {
    Logger::error("Erro ao gerar resumo IA: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

function gerarResumoInteligente($stats, $taxa, $topConsultores, $problematicos) {
    $resumo = "📊 **ANÁLISE AUTOMÁTICA DE INADIMPLÊNCIA**\n\n";

    // Situação geral
    $resumo .= "**SITUAÇÃO GERAL:**\n";
    $resumo .= sprintf("• Total de títulos analisados: %s\n", number_format($stats['total_titulos'], 0, ',', '.'));
    $resumo .= sprintf("• Taxa de inadimplência: %.2f%%\n", $taxa);

    // Avaliar gravidade
    if ($taxa < 10) {
        $resumo .= "✅ Status: EXCELENTE - Taxa de inadimplência muito baixa.\n";
    } elseif ($taxa < 20) {
        $resumo .= "⚠️ Status: BOM - Taxa de inadimplência controlada, mas requer atenção.\n";
    } elseif ($taxa < 35) {
        $resumo .= "⚠️ Status: PREOCUPANTE - Taxa de inadimplência acima do esperado.\n";
    } else {
        $resumo .= "🚨 Status: CRÍTICO - Taxa de inadimplência muito elevada. Ação urgente necessária!\n";
    }

    $resumo .= sprintf("• Valor total em risco: R$ %s\n\n", number_format($stats['valor_risco_total'], 2, ',', '.'));

    // Breakdown por tipo
    $resumo .= "**DETALHAMENTO POR GRAVIDADE:**\n";

    if ($stats['apenas_1parcela'] > 0) {
        $perc1 = round(($stats['apenas_1parcela'] / $stats['total_titulos']) * 100, 2);
        $resumo .= sprintf("🔴 Apenas 1ª parcela paga: %s títulos (%.2f%%)\n",
            number_format($stats['apenas_1parcela'], 0, ',', '.'), $perc1);

        if ($perc1 > 15) {
            $resumo .= "   → ALERTA: Alto índice de clientes que pagaram apenas a primeira parcela.\n";
            $resumo .= "   → Possível problema na conversão pós-venda ou qualidade dos leads.\n";
        }
    }

    if ($stats['apenas_2parcelas'] > 0) {
        $perc2 = round(($stats['apenas_2parcelas'] / $stats['total_titulos']) * 100, 2);
        $resumo .= sprintf("🟠 Apenas 2 parcelas pagas: %s títulos (%.2f%%)\n",
            number_format($stats['apenas_2parcelas'], 0, ',', '.'), $perc2);
    }

    if ($stats['menos_50'] > 0) {
        $percMenos50 = round(($stats['menos_50'] / $stats['total_titulos']) * 100, 2);
        $resumo .= sprintf("🟡 Menos de 50%% pago: %s títulos (%.2f%%)\n",
            number_format($stats['menos_50'], 0, ',', '.'), $percMenos50);
    }

    if ($stats['mais_50'] > 0) {
        $percMais50 = round(($stats['mais_50'] / $stats['total_titulos']) * 100, 2);
        $resumo .= sprintf("🟢 50%% ou mais pago: %s títulos (%.2f%%)\n",
            number_format($stats['mais_50'], 0, ',', '.'), $percMais50);
        $resumo .= "   → Estes clientes têm maior probabilidade de recuperação.\n";
    }

    // Média de dias
    if ($stats['media_dias_inadimplentes']) {
        $resumo .= sprintf("\n📅 Média de dias desde a venda (inadimplentes): %s dias\n",
            number_format($stats['media_dias_inadimplentes'], 0, ',', '.'));

        if ($stats['media_dias_inadimplentes'] > 180) {
            $resumo .= "   → ATENÇÃO: Inadimplência antiga, recuperação mais difícil.\n";
        }
    }

    // Top consultores
    if (!empty($topConsultores)) {
        $resumo .= "\n**CONSULTORES COM MAIS INADIMPLÊNCIA:**\n";
        $pos = 1;
        foreach ($topConsultores as $consultor) {
            $resumo .= sprintf("%d. %s: %s inadimplentes de %s vendas (%.2f%%)\n",
                $pos,
                $consultor['promotor'],
                number_format($consultor['inadimplentes'], 0, ',', '.'),
                number_format($consultor['total_vendas'], 0, ',', '.'),
                $consultor['taxa']
            );

            if ($consultor['taxa'] > 40 && $pos == 1) {
                $resumo .= "   → URGENTE: Necessário feedback e treinamento para este consultor.\n";
            }

            $pos++;
        }
    }

    // Títulos problemáticos
    if ($problematicos > 0) {
        $resumo .= "\n🚨 **ALERTA DE TÍTULOS PROBLEMÁTICOS:**\n";
        $resumo .= sprintf("• %s títulos foram bloqueados/cancelados nos primeiros 30 dias\n",
            number_format($problematicos, 0, ',', '.'));
        $resumo .= "   → Investigar: possível problema de qualificação de leads ou experiência inicial.\n";
        $resumo .= "   → Recomendação: revisar processo de onboarding e primeiros contatos.\n";
    }

    // Recomendações
    $resumo .= "\n**💡 RECOMENDAÇÕES:**\n";

    if ($stats['apenas_1parcela'] > 10) {
        $resumo .= "1. Criar campanha de retenção focada em clientes com apenas 1ª parcela paga\n";
        $resumo .= "2. Implementar contato proativo no 2º mês após a venda\n";
    }

    if (!empty($topConsultores) && $topConsultores[0]['taxa'] > 30) {
        $resumo .= "3. Agendar reunião com consultores de alta inadimplência para entender obstáculos\n";
        $resumo .= "4. Revisar processo de qualificação de leads e scripts de venda\n";
    }

    if ($taxa > 25) {
        $resumo .= "5. Considerar programa de renegociação para clientes inadimplentes\n";
        $resumo .= "6. Analisar possíveis problemas sistêmicos no produto ou serviço oferecido\n";
    }

    if ($problematicos > 5) {
        $resumo .= "7. Revisar processo de onboarding dos primeiros 30 dias\n";
        $resumo .= "8. Implementar pesquisa de satisfação logo após primeira compra\n";
    }

    $resumo .= "\n---\n";
    $resumo .= "💬 *Esta análise foi gerada automaticamente com base nos dados filtrados.*\n";
    $resumo .= "*Para ações específicas, consulte a equipe de gestão.*\n";

    return $resumo;
}
