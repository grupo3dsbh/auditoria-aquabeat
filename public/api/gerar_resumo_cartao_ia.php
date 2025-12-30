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

    // Buscar estatísticas do cartão via titulo_cartoes
    $stats = $db->fetchOne("
        SELECT
            COUNT(DISTINCT t.id) as total_titulos,
            COUNT(DISTINCT t.documento_titular) as total_documentos,
            COUNT(DISTINCT t.promotor) as total_consultores,
            GROUP_CONCAT(DISTINCT tc.bandeira ORDER BY tc.bandeira SEPARATOR ', ') as bandeiras,
            GROUP_CONCAT(DISTINCT t.promotor ORDER BY t.promotor SEPARATOR ', ') as consultores,

            -- Inadimplência
            COUNT(CASE WHEN t.status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 END) as total_inadimplentes,
            COUNT(CASE WHEN t.status_inadimplencia = 'ADIMPLENTE' THEN 1 END) as total_adimplentes,

            -- Status dos títulos
            COUNT(CASE WHEN t.status_titulo IN ('Bloqueado', 'Cancelado') THEN 1 END) as titulos_bloqueados,
            COUNT(CASE WHEN t.status_titulo = 'Ativo' THEN 1 END) as titulos_ativos,

            -- Análise temporal
            COUNT(CASE WHEN t.dias_desde_venda <= 30 AND t.status_titulo IN ('Bloqueado', 'Cancelado') THEN 1 END) as bloqueios_rapidos,
            AVG(t.dias_desde_venda) as media_dias_venda,

            -- Valores
            SUM(t.valor_total_plano) as valor_total_vendas,
            SUM(t.saldo_restante) as saldo_total_restante,
            SUM(CASE WHEN t.status_inadimplencia LIKE 'INADIMPLENTE%' THEN t.saldo_restante ELSE 0 END) as valor_risco,

            -- Tipo de cartão
            CASE
                WHEN MAX(CASE WHEN tc.bandeira LIKE '%DEBITO%' OR tc.bandeira LIKE '%DEBIT%' THEN 1 ELSE 0 END) = 1 THEN 'DÉBITO'
                WHEN MAX(CASE WHEN tc.bandeira LIKE '%CREDITO%' OR tc.bandeira LIKE '%CREDIT%' THEN 1 ELSE 0 END) = 1 THEN 'CRÉDITO'
                WHEN MAX(CASE WHEN tc.tipo_pagamento LIKE '%DEBITO%' OR tc.tipo_pagamento LIKE '%DEBIT%' THEN 1 ELSE 0 END) = 1 THEN 'DÉBITO'
                WHEN MAX(CASE WHEN tc.tipo_pagamento LIKE '%CREDITO%' OR tc.tipo_pagamento LIKE '%CREDIT%' THEN 1 ELSE 0 END) = 1 THEN 'CRÉDITO'
                ELSE 'OUTRO'
            END as tipo_cartao
        FROM titulo_cartoes tc
        INNER JOIN titulos t ON tc.titulo_id = t.id
        WHERE tc.numero_cartao = ?
          {$wherePrefixos}
    ", [$numeroCartao]);

    if (!$stats || $stats['total_titulos'] == 0) {
        throw new Exception('Nenhum dado encontrado para este cartão.');
    }

    // Taxa de inadimplência
    $taxaInadimplencia = round(($stats['total_inadimplentes'] / $stats['total_titulos']) * 100, 1);
    $taxaBloqueio = round(($stats['titulos_bloqueados'] / $stats['total_titulos']) * 100, 1);

    // Buscar padrões de uso por consultor via titulo_cartoes
    $usoConsultores = $db->fetchAll("
        SELECT
            t.promotor,
            COUNT(DISTINCT t.id) as total_titulos,
            COUNT(DISTINCT t.documento_titular) as clientes_diferentes,
            COUNT(CASE WHEN t.status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 END) as inadimplentes,
            COUNT(CASE WHEN t.status_titulo IN ('Bloqueado', 'Cancelado') THEN 1 END) as bloqueados,
            ROUND(100.0 * COUNT(CASE WHEN t.status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 END) / COUNT(DISTINCT t.id), 1) as taxa_inadimplencia,
            ROUND(100.0 * COUNT(CASE WHEN t.status_titulo IN ('Bloqueado', 'Cancelado') THEN 1 END) / COUNT(DISTINCT t.id), 1) as taxa_bloqueio
        FROM titulo_cartoes tc
        INNER JOIN titulos t ON tc.titulo_id = t.id
        WHERE tc.numero_cartao = ?
          {$wherePrefixos}
        GROUP BY t.promotor
        ORDER BY clientes_diferentes DESC, total_titulos DESC
    ", [$numeroCartao]);

    // Buscar documentos diferentes via titulo_cartoes
    $documentosDiferentes = $db->fetchAll("
        SELECT
            t.documento_titular,
            COUNT(DISTINCT t.id) as total_titulos,
            COUNT(CASE WHEN t.status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 END) as inadimplentes,
            COUNT(CASE WHEN t.status_titulo IN ('Bloqueado', 'Cancelado') THEN 1 END) as bloqueados
        FROM titulo_cartoes tc
        INNER JOIN titulos t ON tc.titulo_id = t.id
        WHERE tc.numero_cartao = ?
          {$wherePrefixos}
        GROUP BY t.documento_titular
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
    // Análise por IA Especializada:
    // - Profissional expert em gestão de parques aquáticos
    // - QI 180, mais de 15 anos de experiência no setor
    // - Contexto: Para parques aquáticos, taxa de inadimplência até 35% é NORMAL
    //   (produto vendido em até 48x, pagamento de longo prazo)

    $resumo = "💳 <strong>ANÁLISE DO CARTÃO</strong>\n\n";

    // Informações básicas
    $resumo .= "<strong>📋 DADOS GERAIS:</strong>\n";
    $resumo .= sprintf("• Cartão: %s (%s)\n", $numeroCartao, $stats['tipo_cartao']);
    $resumo .= sprintf("• Bandeira(s): %s\n", $stats['bandeiras']);
    $resumo .= sprintf("• Total de títulos: %s\n", number_format($stats['total_titulos'], 0, ',', '.'));
    $resumo .= sprintf("• CPFs diferentes: %s\n", number_format($stats['total_documentos'], 0, ',', '.'));
    $resumo .= sprintf("• Consultores: %s\n\n", number_format($stats['total_consultores'], 0, ',', '.'));

    // Análise de risco - Critérios ajustados para parques aquáticos
    // Até 35% de inadimplência é considerado NORMAL no setor
    $nivelRisco = 'BAIXO';
    $pontosRisco = 0;

    // Pontuação de risco
    if ($stats['total_documentos'] >= 5) $pontosRisco += 3;
    elseif ($stats['total_documentos'] >= 3) $pontosRisco += 1;

    // Ajustado para parques aquáticos: só preocupar acima de 35%
    if ($taxaInadimplencia >= 70) $pontosRisco += 3;
    elseif ($taxaInadimplencia >= 50) $pontosRisco += 2;
    elseif ($taxaInadimplencia >= 35) $pontosRisco += 1;

    if ($taxaBloqueio >= 50) $pontosRisco += 2;
    elseif ($taxaBloqueio >= 30) $pontosRisco += 1;

    if ($stats['tipo_cartao'] == 'DÉBITO' && $stats['total_documentos'] >= 3) $pontosRisco += 2;

    // Definir nível baseado em pontos
    if ($pontosRisco >= 5) {
        $nivelRisco = 'ALTO';
    } elseif ($pontosRisco >= 2) {
        $nivelRisco = 'MÉDIO';
    }

    $resumo .= "<strong>🎯 ANÁLISE DE RISCO:</strong>\n";
    $resumo .= sprintf("• <strong>Nível:</strong> %s %s (pontuação: %d)\n",
        $nivelRisco,
        $nivelRisco == 'ALTO' ? '🚨' : ($nivelRisco == 'MÉDIO' ? '⚠️' : '✅'),
        $pontosRisco
    );
    $resumo .= sprintf("• <strong>Inadimplência:</strong> %.1f%% (%d de %d títulos)\n",
        $taxaInadimplencia,
        $stats['total_inadimplentes'],
        $stats['total_titulos']
    );
    $resumo .= sprintf("• <strong>Bloqueios:</strong> %.1f%% (%d de %d títulos)\n",
        $taxaBloqueio,
        $stats['titulos_bloqueados'],
        $stats['total_titulos']
    );
    $resumo .= sprintf("• <strong>Valor em risco:</strong> R$ %s\n\n", number_format($stats['valor_risco'], 2, ',', '.'));

    // Padrões identificados
    $alertas = [];

    // Padrão 1: Múltiplos documentos
    if ($stats['total_documentos'] >= 5) {
        $alertas[] = sprintf("🚨 <strong>USO COMPARTILHADO CRÍTICO:</strong> %d CPFs diferentes", $stats['total_documentos']);
    } elseif ($stats['total_documentos'] >= 3) {
        $alertas[] = sprintf("⚠️ <strong>Múltiplos CPFs:</strong> %d titulares diferentes", $stats['total_documentos']);
    }

    // Padrão 2: Múltiplos consultores
    if ($stats['total_consultores'] >= 3) {
        $alertas[] = sprintf("⚠️ <strong>Múltiplos consultores:</strong> %d consultores usaram este cartão", $stats['total_consultores']);
    }

    // Padrão 3: Tipo de cartão
    if ($stats['tipo_cartao'] == 'DÉBITO' && $stats['total_documentos'] >= 3) {
        $alertas[] = "🚨 <strong>DÉBITO + Múltiplos CPFs:</strong> Combinação de alto risco";
    } elseif ($stats['tipo_cartao'] == 'DÉBITO' && $taxaBloqueio >= 30) {
        $alertas[] = "⚠️ <strong>DÉBITO com bloqueios:</strong> Cartão de débito com alta taxa de bloqueio";
    }

    // Padrão 4: Bloqueios rápidos
    if ($stats['bloqueios_rapidos'] > 0) {
        $percBloqueiosRapidos = round(($stats['bloqueios_rapidos'] / $stats['total_titulos']) * 100, 1);
        if ($percBloqueiosRapidos >= 30) {
            $alertas[] = sprintf("🚨 <strong>Bloqueios rápidos:</strong> %d títulos bloqueados em até 30 dias (%.1f%%)",
                $stats['bloqueios_rapidos'], $percBloqueiosRapidos);
        }
    }

    // Padrão 5: Alta inadimplência - ajustado para parques aquáticos
    // Somente alertar acima de 50% (bem acima dos 35% normais)
    if ($taxaInadimplencia >= 70) {
        $alertas[] = sprintf("🚨 <strong>Inadimplência crítica:</strong> %.1f%% dos títulos (muito acima dos 35%% normais)", $taxaInadimplencia);
    } elseif ($taxaInadimplencia >= 50) {
        $alertas[] = sprintf("⚠️ <strong>Inadimplência elevada:</strong> %.1f%% dos títulos (acima dos 35%% normais)", $taxaInadimplencia);
    }

    if (count($alertas) > 0) {
        $resumo .= "<strong>🔍 ALERTAS:</strong>\n";
        foreach ($alertas as $alerta) {
            $resumo .= "• " . $alerta . "\n";
        }
        $resumo .= "\n";
    } else {
        $resumo .= "<strong>🔍 ALERTAS:</strong> Nenhum padrão crítico identificado\n\n";
    }

    // Análise por consultor (máximo 5)
    if (count($usoConsultores) > 0) {
        $resumo .= "<strong>👥 CONSULTORES:</strong>\n";
        $topConsultores = array_slice($usoConsultores, 0, 5);
        foreach ($topConsultores as $i => $uso) {
            $alerta = '';
            if ($uso['clientes_diferentes'] >= 5) {
                $alerta = ' 🚨';
            } elseif ($uso['clientes_diferentes'] >= 3) {
                $alerta = ' ⚠️';
            }
            $resumo .= sprintf("• <strong>%s:</strong> %d títulos, %d CPFs, inadimp. %.1f%%%s\n",
                $uso['promotor'],
                $uso['total_titulos'],
                $uso['clientes_diferentes'],
                $uso['taxa_inadimplencia'],
                $alerta
            );
        }
        $resumo .= "\n";
    }

    // Recomendações finais - baseadas em expertise de parques aquáticos
    $resumo .= "<strong>💡 RECOMENDAÇÕES:</strong>\n";

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
