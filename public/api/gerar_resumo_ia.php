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

    // IMPORTANTE: SEMPRE filtrar apenas SFA e SBF
    $conditions[] = "(numero_titulo LIKE 'SFA%' OR numero_titulo LIKE 'SBF%')";

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

    // Buscar estatísticas gerais com NOVAS categorias por tempo
    $stats = $db->fetchOne("
        SELECT
            COUNT(*) as total_titulos,
            COUNT(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 END) as total_inadimplentes,
            COUNT(CASE WHEN status_inadimplencia = 'ADIMPLENTE' THEN 1 END) as total_adimplentes,

            -- Categorias especiais
            COUNT(CASE WHEN status_inadimplencia = 'INADIMPLENTE - Apenas 1ª Parcela' THEN 1 END) as apenas_1parcela,
            COUNT(CASE WHEN status_inadimplencia = 'INADIMPLENTE - Apenas 2 Parcelas' THEN 1 END) as apenas_2parcelas,

            -- NOVAS categorias por tempo
            COUNT(CASE WHEN status_inadimplencia = 'INADIMPLENTE - Até 3 meses' THEN 1 END) as ate_3meses,
            COUNT(CASE WHEN status_inadimplencia = 'INADIMPLENTE - 3 a 6 meses' THEN 1 END) as de_3a6meses,
            COUNT(CASE WHEN status_inadimplencia = 'INADIMPLENTE - 6 a 9 meses' THEN 1 END) as de_6a9meses,
            COUNT(CASE WHEN status_inadimplencia = 'INADIMPLENTE - 9 a 12 meses' THEN 1 END) as de_9a12meses,
            COUNT(CASE WHEN status_inadimplencia = 'INADIMPLENTE - Mais de 12 meses' THEN 1 END) as mais_12meses,

            -- Categorias antigas (compatibilidade)
            COUNT(CASE WHEN status_inadimplencia = 'INADIMPLENTE - Menos de 50%' THEN 1 END) as menos_50,
            COUNT(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE - %50% ou mais%' THEN 1 END) as mais_50,

            -- Valores e métricas
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
    $resumo = "📊 **ANÁLISE COMPORTAMENTAL DE INADIMPLÊNCIA**\n\n";

    // Situação geral
    $resumo .= "**📈 SITUAÇÃO GERAL:**\n";
    $resumo .= sprintf("• Total de títulos: %s | Inadimplentes: %s (%.2f%%)\n",
        number_format($stats['total_titulos'], 0, ',', '.'),
        number_format($stats['total_inadimplentes'], 0, ',', '.'),
        $taxa);
    $resumo .= sprintf("• Valor em risco: R$ %s\n\n", number_format($stats['valor_risco_total'], 2, ',', '.'));

    // Análise por ETAPA DE ABANDONO (nova classificação)
    $resumo .= "**🎯 ANÁLISE POR ETAPA DE ABANDONO:**\n\n";

    // Até 3 meses - Premiação
    if ($stats['ate_3meses'] > 0) {
        $perc = round(($stats['ate_3meses'] / $stats['total_titulos']) * 100, 2);
        $resumo .= sprintf("🟡 **ATÉ 3 MESES** - %s clientes (%.2f%%)\n", number_format($stats['ate_3meses'], 0, ',', '.'), $perc);
        $resumo .= "   📌 Motivo provável: Pagou apenas para garantir premiação inicial\n";
        $resumo .= "   💡 Estratégia de reativação:\n";
        $resumo .= "      • Contato imediato via WhatsApp/telefone\n";
        $resumo .= "      • Oferecer benefício exclusivo para retorno (desconto, bônus)\n";
        $resumo .= "      • Taxa de recuperação esperada: 40-60%\n\n";
    }

    // 3 a 6 meses - Experiência
    if ($stats['de_3a6meses'] > 0) {
        $perc = round(($stats['de_3a6meses'] / $stats['total_titulos']) * 100, 2);
        $resumo .= sprintf("🟠 **3 A 6 MESES** - %s clientes (%.2f%%)\n", number_format($stats['de_3a6meses'], 0, ',', '.'), $perc);
        $resumo .= "   📌 Motivo provável: Experiência inicial não atendeu expectativas\n";
        $resumo .= "   💡 Estratégia de reativação:\n";
        $resumo .= "      • **PESQUISA NPS OBRIGATÓRIA**: entender o que deu errado\n";
        $resumo .= "      • Oferecer teste/experiência melhorada do produto\n";
        $resumo .= "      • Considerar renegociação de valores\n";
        $resumo .= "      • Taxa de recuperação esperada: 30-50%\n\n";
    }

    // 6 a 9 meses - Expectativa
    if ($stats['de_6a9meses'] > 0) {
        $perc = round(($stats['de_6a9meses'] / $stats['total_titulos']) * 100, 2);
        $resumo .= sprintf("🟠 **6 A 9 MESES** - %s clientes (%.2f%%)\n", number_format($stats['de_6a9meses'], 0, ',', '.'), $perc);
        $resumo .= "   📌 Motivo provável: Expectativas não foram atendidas ao longo do tempo\n";
        $resumo .= "   💡 Estratégia de reativação:\n";
        $resumo .= "      • **PESQUISA NPS + Entrevista telefônica**\n";
        $resumo .= "      • Proposta de valor revisada (novos benefícios)\n";
        $resumo .= "      • Parcelamento/condições especiais\n";
        $resumo .= "      • Taxa de recuperação esperada: 20-35%\n\n";
    }

    // 9 a 12 meses - Problema sério
    if ($stats['de_9a12meses'] > 0) {
        $perc = round(($stats['de_9a12meses'] / $stats['total_titulos']) * 100, 2);
        $resumo .= sprintf("🔴 **9 A 12 MESES** - %s clientes (%.2f%%)\n", number_format($stats['de_9a12meses'], 0, ',', '.'), $perc);
        $resumo .= "   📌 Situação: Inadimplência consolidada, recuperação difícil\n";
        $resumo .= "   💡 Estratégia de reativação:\n";
        $resumo .= "      • Proposta de quitação com desconto significativo (30-50%)\n";
        $resumo .= "      • Análise caso a caso pela equipe comercial\n";
        $resumo .= "      • Considerar acordo judicial se valores altos\n";
        $resumo .= "      • Taxa de recuperação esperada: 10-20%\n\n";
    }

    // Mais de 12 meses - Crônico
    if ($stats['mais_12meses'] > 0) {
        $perc = round(($stats['mais_12meses'] / $stats['total_titulos']) * 100, 2);
        $resumo .= sprintf("⚫ **MAIS DE 12 MESES** - %s clientes (%.2f%%)\n", number_format($stats['mais_12meses'], 0, ',', '.'), $perc);
        $resumo .= "   📌 Situação: Inadimplência crônica, provável perda total\n";
        $resumo .= "   💡 Estratégia:\n";
        $resumo .= "      • Última tentativa: quitação com desconto massivo (60-80%)\n";
        $resumo .= "      • Considerar negativação (se aplicável)\n";
        $resumo .= "      • Provisionar como perda contábil\n";
        $resumo .= "      • Taxa de recuperação esperada: <10%\n\n";
    }

    // Categorias especiais
    if ($stats['apenas_1parcela'] > 0 || $stats['apenas_2parcelas'] > 0) {
        $resumo .= "**⚠️ CATEGORIAS ESPECIAIS:**\n";
        if ($stats['apenas_1parcela'] > 0) {
            $perc1 = round(($stats['apenas_1parcela'] / $stats['total_titulos']) * 100, 2);
            $resumo .= sprintf("• Apenas 1ª parcela: %s (%.2f%%) - **FOCO EM PREMIAÇÃO**\n",
                number_format($stats['apenas_1parcela'], 0, ',', '.'), $perc1);
        }
        if ($stats['apenas_2parcelas'] > 0) {
            $perc2 = round(($stats['apenas_2parcelas'] / $stats['total_titulos']) * 100, 2);
            $resumo .= sprintf("• Apenas 2 parcelas: %s (%.2f%%) - **FOCO EM PREMIAÇÃO**\n\n",
                number_format($stats['apenas_2parcelas'], 0, ',', '.'), $perc2);
        }
    }

    // Estratégia de implementação
    $resumo .= "**🚀 PLANO DE AÇÃO SUGERIDO:**\n\n";

    $resumo .= "**1. PESQUISA NPS (CRÍTICO)**\n";
    $resumo .= "   • Implementar pesquisa automática (via WhatsApp/Email/Sistema)\n";
    $resumo .= "   • Perguntas-chave:\n";
    $resumo .= "     - Por que parou de pagar?\n";
    $resumo .= "     - O que poderia fazê-lo voltar?\n";
    $resumo .= "     - Como avalia a experiência? (0-10)\n";
    $resumo .= "   • **Tecnologia**: Sistema automatizado de pesquisa (CRM/WhatsApp API)\n";
    $resumo .= "   • **Equipe**: 1 analista para processar respostas\n\n";

    $resumo .= "**2. SEGMENTAÇÃO E ABORDAGEM**\n";
    $resumo .= "   • **Até 6 meses**: Equipe comercial + tecnologia (automação)\n";
    $resumo .= "   • **6-12 meses**: Equipe especializada em retenção\n";
    $resumo .= "   • **+12 meses**: Equipe jurídica/cobrança + propostas finais\n\n";

    $resumo .= "**3. FERRAMENTAS RECOMENDADAS**\n";
    $resumo .= "   • WhatsApp Business API (contato em massa)\n";
    $resumo .= "   • CRM com automação de follow-up\n";
    $resumo .= "   • Plataforma de pesquisa NPS (Typeform, SurveyMonkey)\n";
    $resumo .= "   • Sistema de renegociação online (self-service)\n\n";

    // Consultores
    if (!empty($topConsultores)) {
        $resumo .= "**👥 CONSULTORES COM MAIOR INADIMPLÊNCIA:**\n";
        foreach ($topConsultores as $i => $c) {
            $resumo .= sprintf("%d. %s: %s/%s (%.1f%%) %s\n",
                $i + 1,
                $c['promotor'],
                number_format($c['inadimplentes'], 0, ',', '.'),
                number_format($c['total_vendas'], 0, ',', '.'),
                $c['taxa'],
                $c['taxa'] > 35 ? '🚨' : ($c['taxa'] > 25 ? '⚠️' : '')
            );
        }
        $resumo .= "   → Agendar feedback individual e revisar processo de qualificação\n\n";
    }

    // Títulos problemáticos
    if ($problematicos > 0) {
        $resumo .= "**🚨 ALERTA: Cancelamentos Rápidos**\n";
        $resumo .= sprintf("• %s títulos bloqueados/cancelados em 30 dias\n", number_format($problematicos, 0, ',', '.'));
        $resumo .= "   → Revisar processo de onboarding URGENTEMENTE\n\n";
    }

    $resumo .= "---\n";
    $resumo .= "💡 **Próximos Passos:**\n";
    $resumo .= "1. Implementar pesquisa NPS automatizada\n";
    $resumo .= "2. Criar equipe de retenção (mín. 2 pessoas)\n";
    $resumo .= "3. Definir budget para descontos/renegociação\n";
    $resumo .= "4. Monitorar taxa de recuperação semanalmente\n";

    return $resumo;
}
