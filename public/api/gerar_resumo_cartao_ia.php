<?php
// IMPORTANTE: Desabilitar warnings/notices para não quebrar o JSON
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

define('APP_ROOT', dirname(dirname(__DIR__)));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAuth('login.php');

// Limpar qualquer output anterior
if (ob_get_level()) ob_end_clean();
ob_start();

header('Content-Type: application/json; charset=utf-8');

try {
    $numeroCartao = $_GET['numero_cartao'] ?? null;

    if (!$numeroCartao) {
        throw new Exception('Número do cartão não fornecido.');
    }

    $db = Database::getInstance();

    // IMPORTANTE: Filtrar apenas títulos marcados para uso em relatórios
    $wherePrefixos = "";
    try {
        $colunaExiste = $db->fetchColumn("SHOW COLUMNS FROM titulos LIKE 'usado_relatorios'");
        if ($colunaExiste) {
            $wherePrefixos = " AND usado_relatorios = TRUE";
        } else {
            // Fallback: usar filtro SFA/SBF se coluna não existir
            $wherePrefixos = " AND (numero_titulo LIKE 'SFA%' OR numero_titulo LIKE 'SBF%')";
        }
    } catch (Exception $e) {
        // Em caso de erro, usar filtro SFA/SBF
        $wherePrefixos = " AND (numero_titulo LIKE 'SFA%' OR numero_titulo LIKE 'SBF%')";
    }

    // Buscar estatísticas do cartão
    $stats = $db->fetchOne("
        SELECT
            COUNT(*) as total_titulos,
            COUNT(DISTINCT documento_titular) as total_documentos,
            COUNT(DISTINCT promotor) as total_consultores,
            GROUP_CONCAT(DISTINCT bandeira ORDER BY bandeira SEPARATOR ', ') as bandeiras,
            GROUP_CONCAT(DISTINCT promotor ORDER BY promotor SEPARATOR ', ') as consultores,

            -- Inadimplência
            COUNT(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 END) as total_inadimplentes,
            COUNT(CASE WHEN status_inadimplencia = 'ADIMPLENTE' THEN 1 END) as total_adimplentes,

            -- Status dos títulos
            COUNT(CASE WHEN status_titulo IN ('Bloqueado', 'Cancelado') THEN 1 END) as titulos_bloqueados,
            COUNT(CASE WHEN status_titulo = 'Ativo' THEN 1 END) as titulos_ativos,

            -- Análise temporal
            COUNT(CASE WHEN dias_desde_venda <= 30 AND status_titulo IN ('Bloqueado', 'Cancelado') THEN 1 END) as bloqueios_rapidos,
            AVG(dias_desde_venda) as media_dias_venda,

            -- Valores
            SUM(valor_total) as valor_total_vendas,
            SUM(saldo_restante) as saldo_total_restante,
            SUM(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN saldo_restante ELSE 0 END) as valor_risco,

            -- Tipo de cartão
            CASE
                WHEN MAX(CASE WHEN bandeira LIKE '%DEBITO%' OR bandeira LIKE '%DEBIT%' THEN 1 ELSE 0 END) = 1 THEN 'DÉBITO'
                WHEN MAX(CASE WHEN bandeira LIKE '%CREDITO%' OR bandeira LIKE '%CREDIT%' THEN 1 ELSE 0 END) = 1 THEN 'CRÉDITO'
                ELSE 'OUTRO'
            END as tipo_cartao
        FROM titulos
        WHERE numero_cartao = ?
          {$wherePrefixos}
    ", [$numeroCartao]);

    if (!$stats || $stats['total_titulos'] == 0) {
        throw new Exception('Nenhum dado encontrado para este cartão.');
    }

    // Taxa de inadimplência
    $taxaInadimplencia = round(($stats['total_inadimplentes'] / $stats['total_titulos']) * 100, 1);
    $taxaBloqueio = round(($stats['titulos_bloqueados'] / $stats['total_titulos']) * 100, 1);

    // Buscar padrões de uso por consultor
    $usoConsultores = $db->fetchAll("
        SELECT
            promotor,
            COUNT(*) as total_titulos,
            COUNT(DISTINCT documento_titular) as clientes_diferentes,
            COUNT(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 END) as inadimplentes,
            COUNT(CASE WHEN status_titulo IN ('Bloqueado', 'Cancelado') THEN 1 END) as bloqueados,
            ROUND(100.0 * COUNT(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 END) / COUNT(*), 1) as taxa_inadimplencia,
            ROUND(100.0 * COUNT(CASE WHEN status_titulo IN ('Bloqueado', 'Cancelado') THEN 1 END) / COUNT(*), 1) as taxa_bloqueio
        FROM titulos
        WHERE numero_cartao = ?
          {$wherePrefixos}
        GROUP BY promotor
        ORDER BY clientes_diferentes DESC, total_titulos DESC
    ", [$numeroCartao]);

    // Buscar documentos diferentes
    $documentosDiferentes = $db->fetchAll("
        SELECT
            documento_titular,
            COUNT(*) as total_titulos,
            COUNT(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 END) as inadimplentes,
            COUNT(CASE WHEN status_titulo IN ('Bloqueado', 'Cancelado') THEN 1 END) as bloqueados
        FROM titulos
        WHERE numero_cartao = ?
          {$wherePrefixos}
        GROUP BY documento_titular
        ORDER BY total_titulos DESC
    ", [$numeroCartao]);

    // Gerar resumo inteligente
    $resumo = gerarResumoCartao($numeroCartao, $stats, $taxaInadimplencia, $taxaBloqueio, $usoConsultores, $documentosDiferentes);

    // Limpar buffer de saída antes de enviar JSON
    if (ob_get_level()) ob_end_clean();

    echo json_encode([
        'success' => true,
        'resumo' => $resumo,
        'stats' => $stats,
        'taxa_inadimplencia' => $taxaInadimplencia,
        'taxa_bloqueio' => $taxaBloqueio
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    // Limpar buffer de saída antes de enviar JSON de erro
    if (ob_get_level()) ob_end_clean();

    Logger::error("Erro ao gerar resumo IA do cartão: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

exit;

function gerarResumoCartao($numeroCartao, $stats, $taxaInadimplencia, $taxaBloqueio, $usoConsultores, $documentosDiferentes) {
    $resumo = "💳 **ANÁLISE COMPORTAMENTAL DO CARTÃO**\n\n";

    // Informações básicas
    $resumo .= "**📋 INFORMAÇÕES GERAIS:**\n";
    $resumo .= sprintf("• Cartão: %s\n", $numeroCartao);
    $resumo .= sprintf("• Tipo: %s | Bandeiras: %s\n", $stats['tipo_cartao'], $stats['bandeiras']);
    $resumo .= sprintf("• Total de títulos: %s\n", number_format($stats['total_titulos'], 0, ',', '.'));
    $resumo .= sprintf("• Documentos diferentes: %s\n", number_format($stats['total_documentos'], 0, ',', '.'));
    $resumo .= sprintf("• Consultores envolvidos: %s\n\n", number_format($stats['total_consultores'], 0, ',', '.'));

    // Análise de risco
    $nivelRisco = 'BAIXO';
    if ($stats['total_documentos'] >= 5 || $taxaInadimplencia >= 50 || $taxaBloqueio >= 40 || $stats['tipo_cartao'] == 'DÉBITO') {
        $nivelRisco = 'ALTO';
    } elseif ($stats['total_documentos'] >= 3 || $taxaInadimplencia >= 30 || $taxaBloqueio >= 25) {
        $nivelRisco = 'MÉDIO';
    }

    $resumo .= "**🎯 ANÁLISE DE RISCO:**\n";
    $resumo .= sprintf("• Nível de Risco: **%s** %s\n",
        $nivelRisco,
        $nivelRisco == 'ALTO' ? '🚨' : ($nivelRisco == 'MÉDIO' ? '⚠️' : '✅')
    );
    $resumo .= sprintf("• Taxa de inadimplência: %.1f%% (%s/%s)\n",
        $taxaInadimplencia,
        number_format($stats['total_inadimplentes'], 0, ',', '.'),
        number_format($stats['total_titulos'], 0, ',', '.')
    );
    $resumo .= sprintf("• Taxa de bloqueio: %.1f%% (%s/%s)\n",
        $taxaBloqueio,
        number_format($stats['titulos_bloqueados'], 0, ',', '.'),
        number_format($stats['total_titulos'], 0, ',', '.')
    );
    $resumo .= sprintf("• Valor em risco: R$ %s\n\n", number_format($stats['valor_risco'], 2, ',', '.'));

    // Padrões identificados
    $resumo .= "**🔍 PADRÕES IDENTIFICADOS:**\n\n";

    // Padrão 1: Múltiplos documentos
    if ($stats['total_documentos'] >= 3) {
        $resumo .= "⚠️ **USO COMPARTILHADO DETECTADO**\n";
        $resumo .= sprintf("   • Mesmo cartão usado para %s CPFs diferentes\n", $stats['total_documentos']);
        $resumo .= "   • Padrão: Indica possível compartilhamento indevido ou documentação irregular\n";
        $resumo .= "   💡 **Ação recomendada:**\n";
        $resumo .= "      - Investigar relação entre os titulares\n";
        $resumo .= "      - Verificar se todos os CPFs são legítimos e ativos\n";
        $resumo .= "      - Validar titularidade do cartão com operadora\n";
        $resumo .= "      - Revisar processo de validação de documentos\n\n";
    }

    // Padrão 2: Múltiplos consultores
    if ($stats['total_consultores'] >= 2) {
        $resumo .= "⚠️ **MÚLTIPLOS CONSULTORES**\n";
        $resumo .= sprintf("   • Cartão usado por %s consultores diferentes\n", $stats['total_consultores']);
        if ($stats['total_consultores'] >= 3) {
            $resumo .= "   • Padrão: Possível rede organizada ou compartilhamento entre consultores\n";
            $resumo .= "   💡 **Ação recomendada:**\n";
            $resumo .= "      - Investigar relação entre consultores\n";
            $resumo .= "      - Verificar se há padrão geográfico comum\n";
            $resumo .= "      - Analisar histórico individual de cada consultor\n";
            $resumo .= "      - Considerar auditoria nos processos de vendas\n\n";
        } else {
            $resumo .= "   💡 **Ação recomendada:**\n";
            $resumo .= "      - Verificar se há relação entre os consultores\n";
            $resumo .= "      - Validar legitimidade das vendas\n\n";
        }
    }

    // Padrão 3: Tipo de cartão
    if ($stats['tipo_cartao'] == 'DÉBITO') {
        $resumo .= "🚨 **CARTÃO DE DÉBITO - ALTO RISCO**\n";
        $resumo .= "   • Padrão: Cartões de débito têm maior risco de bloqueio rápido\n";
        $resumo .= "   • Motivo: Cliente pode bloquear cartão imediatamente após uso\n";
        $resumo .= "   💡 **Ação recomendada:**\n";
        $resumo .= "      - Priorizar validação de identidade ANTES da venda\n";
        $resumo .= "      - Exigir comprovante de titularidade do cartão\n";
        $resumo .= "      - Implementar autenticação 3DS (3-D Secure)\n";
        $resumo .= "      - Considerar limitar vendas em débito para novos clientes\n\n";
    }

    // Padrão 4: Bloqueios rápidos
    if ($stats['bloqueios_rapidos'] > 0) {
        $percBloqueiosRapidos = round(($stats['bloqueios_rapidos'] / $stats['total_titulos']) * 100, 1);
        $resumo .= "🚨 **BLOQUEIOS RÁPIDOS DETECTADOS**\n";
        $resumo .= sprintf("   • %s títulos bloqueados em 30 dias (%.1f%%)\n",
            $stats['bloqueios_rapidos'],
            $percBloqueiosRapidos
        );
        $resumo .= "   • Padrão: Indica possível uso não autorizado ou contestação\n";
        $resumo .= "   💡 **Ação recomendada:**\n";
        $resumo .= "      - Contatar operadora para verificar contestações\n";
        $resumo .= "      - Bloquear uso futuro deste cartão no sistema\n";
        $resumo .= "      - Revisar processo de autorização de vendas\n\n";
    }

    // Análise por consultor
    if (count($usoConsultores) > 0) {
        $resumo .= "**👥 USO POR CONSULTOR:**\n";
        foreach ($usoConsultores as $i => $uso) {
            $resumo .= sprintf("%d. **%s**\n", $i + 1, $uso['promotor']);
            $resumo .= sprintf("   • Títulos: %s | Clientes diferentes: %s\n",
                $uso['total_titulos'],
                $uso['clientes_diferentes']
            );
            $resumo .= sprintf("   • Inadimplência: %.1f%% | Bloqueios: %.1f%%",
                $uso['taxa_inadimplencia'],
                $uso['taxa_bloqueio']
            );

            if ($uso['clientes_diferentes'] >= 3) {
                $resumo .= " 🚨 **ALERTA: Múltiplos clientes**";
            } elseif ($uso['taxa_bloqueio'] >= 50) {
                $resumo .= " ⚠️ **ALERTA: Alta taxa bloqueio**";
            }
            $resumo .= "\n";
        }
        $resumo .= "\n";
    }

    // Recomendações finais
    $resumo .= "**🚀 PLANO DE AÇÃO IMEDIATO:**\n\n";

    if ($nivelRisco == 'ALTO') {
        $resumo .= "**PRIORIDADE ALTA - AÇÃO IMEDIATA:**\n";
        $resumo .= "1. ⛔ **BLOQUEAR** este cartão no sistema para novos títulos\n";
        $resumo .= "2. 🔍 **INVESTIGAR** todos os títulos relacionados em detalhes\n";
        $resumo .= "3. 📞 **CONTATAR** operadora do cartão para validar transações\n";
        $resumo .= "4. 📋 **AUDITAR** consultores envolvidos e processos de venda\n";
        $resumo .= "5. ⚖️ **AVALIAR** possibilidade de medidas legais se confirmada irregularidade\n\n";
    } elseif ($nivelRisco == 'MÉDIO') {
        $resumo .= "**PRIORIDADE MÉDIA - MONITORAMENTO:**\n";
        $resumo .= "1. 👀 **MONITORAR** uso futuro deste cartão atentamente\n";
        $resumo .= "2. ✅ **VALIDAR** próximas vendas com verificação extra\n";
        $resumo .= "3. 📊 **REVISAR** histórico dos consultores envolvidos\n";
        $resumo .= "4. 📝 **DOCUMENTAR** padrões para referência futura\n\n";
    } else {
        $resumo .= "**PRIORIDADE BAIXA - ACOMPANHAMENTO:**\n";
        $resumo .= "1. 📊 **ACOMPANHAR** evolução dos títulos ativos\n";
        $resumo .= "2. ✅ **MANTER** processos padrão de validação\n";
        $resumo .= "3. 📈 **MONITORAR** indicadores periodicamente\n\n";
    }

    $resumo .= "---\n";
    $resumo .= "💡 **Nota:** Esta análise é baseada em padrões comportamentais identificados automaticamente. ";
    $resumo .= "Recomenda-se sempre validação humana antes de tomar decisões críticas.\n";

    return $resumo;
}
