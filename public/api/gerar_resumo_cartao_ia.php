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
            SUM(valor_total_plano) as valor_total_vendas,
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
    $resumo = "💳 **ANÁLISE DO CARTÃO**\n\n";

    // Informações básicas
    $resumo .= "**📋 DADOS GERAIS:**\n";
    $resumo .= sprintf("• Cartão: %s (%s)\n", $numeroCartao, $stats['tipo_cartao']);
    $resumo .= sprintf("• Bandeira(s): %s\n", $stats['bandeiras']);
    $resumo .= sprintf("• Total de títulos: %s\n", number_format($stats['total_titulos'], 0, ',', '.'));
    $resumo .= sprintf("• CPFs diferentes: %s\n", number_format($stats['total_documentos'], 0, ',', '.'));
    $resumo .= sprintf("• Consultores: %s\n\n", number_format($stats['total_consultores'], 0, ',', '.'));

    // Análise de risco - Critérios mais realistas
    $nivelRisco = 'BAIXO';
    $pontosRisco = 0;

    // Pontuação de risco
    if ($stats['total_documentos'] >= 5) $pontosRisco += 3;
    elseif ($stats['total_documentos'] >= 3) $pontosRisco += 1;

    if ($taxaInadimplencia >= 60) $pontosRisco += 3;
    elseif ($taxaInadimplencia >= 40) $pontosRisco += 2;
    elseif ($taxaInadimplencia >= 25) $pontosRisco += 1;

    if ($taxaBloqueio >= 50) $pontosRisco += 2;
    elseif ($taxaBloqueio >= 30) $pontosRisco += 1;

    if ($stats['tipo_cartao'] == 'DÉBITO' && $stats['total_documentos'] >= 3) $pontosRisco += 2;

    // Definir nível baseado em pontos
    if ($pontosRisco >= 5) {
        $nivelRisco = 'ALTO';
    } elseif ($pontosRisco >= 2) {
        $nivelRisco = 'MÉDIO';
    }

    $resumo .= "**🎯 ANÁLISE DE RISCO:**\n";
    $resumo .= sprintf("• **Nível:** %s %s (pontuação: %d)\n",
        $nivelRisco,
        $nivelRisco == 'ALTO' ? '🚨' : ($nivelRisco == 'MÉDIO' ? '⚠️' : '✅'),
        $pontosRisco
    );
    $resumo .= sprintf("• **Inadimplência:** %.1f%% (%d de %d títulos)\n",
        $taxaInadimplencia,
        $stats['total_inadimplentes'],
        $stats['total_titulos']
    );
    $resumo .= sprintf("• **Bloqueios:** %.1f%% (%d de %d títulos)\n",
        $taxaBloqueio,
        $stats['titulos_bloqueados'],
        $stats['total_titulos']
    );
    $resumo .= sprintf("• **Valor em risco:** R$ %s\n\n", number_format($stats['valor_risco'], 2, ',', '.'));

    // Padrões identificados
    $alertas = [];

    // Padrão 1: Múltiplos documentos
    if ($stats['total_documentos'] >= 5) {
        $alertas[] = sprintf("🚨 **USO COMPARTILHADO CRÍTICO:** %d CPFs diferentes", $stats['total_documentos']);
    } elseif ($stats['total_documentos'] >= 3) {
        $alertas[] = sprintf("⚠️ **Múltiplos CPFs:** %d titulares diferentes", $stats['total_documentos']);
    }

    // Padrão 2: Múltiplos consultores
    if ($stats['total_consultores'] >= 3) {
        $alertas[] = sprintf("⚠️ **Múltiplos consultores:** %d consultores usaram este cartão", $stats['total_consultores']);
    }

    // Padrão 3: Tipo de cartão
    if ($stats['tipo_cartao'] == 'DÉBITO' && $stats['total_documentos'] >= 3) {
        $alertas[] = "🚨 **DÉBITO + Múltiplos CPFs:** Combinação de alto risco";
    } elseif ($stats['tipo_cartao'] == 'DÉBITO' && $taxaBloqueio >= 30) {
        $alertas[] = "⚠️ **DÉBITO com bloqueios:** Cartão de débito com alta taxa de bloqueio";
    }

    // Padrão 4: Bloqueios rápidos
    if ($stats['bloqueios_rapidos'] > 0) {
        $percBloqueiosRapidos = round(($stats['bloqueios_rapidos'] / $stats['total_titulos']) * 100, 1);
        if ($percBloqueiosRapidos >= 30) {
            $alertas[] = sprintf("🚨 **Bloqueios rápidos:** %d títulos bloqueados em até 30 dias (%.1f%%)",
                $stats['bloqueios_rapidos'], $percBloqueiosRapidos);
        }
    }

    // Padrão 5: Alta inadimplência
    if ($taxaInadimplencia >= 60) {
        $alertas[] = sprintf("🚨 **Alta inadimplência:** %.1f%% dos títulos", $taxaInadimplencia);
    } elseif ($taxaInadimplencia >= 40) {
        $alertas[] = sprintf("⚠️ **Inadimplência elevada:** %.1f%% dos títulos", $taxaInadimplencia);
    }

    if (count($alertas) > 0) {
        $resumo .= "**🔍 ALERTAS:**\n";
        foreach ($alertas as $alerta) {
            $resumo .= "• " . $alerta . "\n";
        }
        $resumo .= "\n";
    } else {
        $resumo .= "**🔍 ALERTAS:** Nenhum padrão crítico identificado\n\n";
    }

    // Análise por consultor (máximo 5)
    if (count($usoConsultores) > 0) {
        $resumo .= "**👥 CONSULTORES:**\n";
        $topConsultores = array_slice($usoConsultores, 0, 5);
        foreach ($topConsultores as $i => $uso) {
            $alerta = '';
            if ($uso['clientes_diferentes'] >= 5) {
                $alerta = ' 🚨';
            } elseif ($uso['clientes_diferentes'] >= 3) {
                $alerta = ' ⚠️';
            }
            $resumo .= sprintf("• **%s**: %d títulos, %d CPFs, inadimp. %.1f%%%s\n",
                $uso['promotor'],
                $uso['total_titulos'],
                $uso['clientes_diferentes'],
                $uso['taxa_inadimplencia'],
                $alerta
            );
        }
        $resumo .= "\n";
    }

    // Recomendações finais - mais concisas
    $resumo .= "**💡 RECOMENDAÇÕES:**\n";

    if ($nivelRisco == 'ALTO') {
        $resumo .= "1. ⛔ Bloquear cartão para novas vendas\n";
        $resumo .= "2. 🔍 Investigar títulos e consultores envolvidos\n";
        $resumo .= "3. 📞 Contatar operadora para validar transações\n";
        if ($stats['total_documentos'] >= 5) {
            $resumo .= "4. 📋 Auditar relação entre os " . $stats['total_documentos'] . " CPFs diferentes\n";
        }
    } elseif ($nivelRisco == 'MÉDIO') {
        $resumo .= "1. 👀 Monitorar uso futuro deste cartão\n";
        $resumo .= "2. ✅ Exigir validação extra em próximas vendas\n";
        $resumo .= "3. 📊 Revisar histórico dos consultores\n";
    } else {
        $resumo .= "1. 📊 Acompanhar evolução dos títulos ativos\n";
        $resumo .= "2. 📈 Monitorar indicadores periodicamente\n";
    }

    $resumo .= "\n---\n";
    $resumo .= "_Análise automatizada baseada em padrões. Validação humana recomendada._";

    return $resumo;
}
