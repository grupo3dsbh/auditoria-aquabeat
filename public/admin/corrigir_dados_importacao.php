<?php
/**
 * Corrigir Dados da Importação
 * - Calcula valor_total_plano e saldo_restante
 * - Popula titulo_cartoes a partir de dados em titulos
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

Auth::requireLogin();
Auth::requirePermission('admin');

header('Content-Type: text/html; charset=utf-8');

$db = Database::getInstance();

// Obter ID da importação
$importacaoId = $_GET['importacao_id'] ?? null;

if (!$importacaoId) {
    $ultimaImportacao = $db->fetchOne("SELECT * FROM importacoes WHERE status = 'concluido' ORDER BY concluido_em DESC LIMIT 1");
    if (!$ultimaImportacao) {
        die('❌ Nenhuma importação concluída encontrada.');
    }
    $importacaoId = $ultimaImportacao['id'];
}

echo "<!DOCTYPE html>
<html lang='pt-BR'>
<head>
    <meta charset='UTF-8'>
    <title>Corrigir Dados da Importação</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 50px auto; padding: 20px; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .info { background: #e7f3ff; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .step { margin: 20px 0; padding: 15px; border-left: 4px solid #007bff; background: #f8f9fa; }
        h1 { color: #007bff; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; }
    </style>
</head>
<body>
    <h1>🔧 Corrigir Dados da Importação #{$importacaoId}</h1>";

try {
    $db->beginTransaction();

    // PASSO 1: Calcular valor_total_plano e saldo_restante
    echo "<div class='step'>";
    echo "<h3>Passo 1: Calculando valor_total_plano e saldo_restante</h3>";

    $sql = "UPDATE titulos
            SET valor_total_plano = valor_parcela * quantidade_parcelas_venda,
                saldo_restante = (valor_parcela * quantidade_parcelas_venda) - COALESCE(total_pago, 0)
            WHERE importacao_id = ?
              AND valor_total_plano IS NULL
              AND valor_parcela IS NOT NULL
              AND quantidade_parcelas_venda IS NOT NULL";

    $db->query($sql, [$importacaoId]);
    $affected = $db->affectedRows();
    echo "✅ Calculados valores para {$affected} títulos<br>";
    echo "</div>";

    // PASSO 2: Popular titulo_cartoes a partir de titulos.numero_cartao
    echo "<div class='step'>";
    echo "<h3>Passo 2: Populando titulo_cartoes</h3>";

    // Verificar se tabela existe
    try {
        $db->query("SELECT 1 FROM titulo_cartoes LIMIT 1");

        // Aumentar tamanho da coluna se necessário
        $db->query("ALTER TABLE titulo_cartoes MODIFY COLUMN numero_cartao VARCHAR(150)");
        echo "✅ Coluna numero_cartao expandida para VARCHAR(150)<br>";

        // Buscar títulos com dados de cartão
        $titulosComCartao = $db->fetchAll(
            "SELECT id, numero_cartao, bandeira, tipo_pagamento_cartao
             FROM titulos
             WHERE importacao_id = ?
               AND numero_cartao IS NOT NULL
               AND numero_cartao != ''",
            [$importacaoId]
        );

        $cartoesInseridos = 0;
        foreach ($titulosComCartao as $titulo) {
            // Separar cartões por |
            $cartoes = array_map('trim', explode('|', $titulo['numero_cartao']));
            $bandeiras = array_map('trim', explode('|', $titulo['bandeira'] ?? ''));

            $ordem = 1;
            foreach ($cartoes as $i => $cartao) {
                if (empty($cartao)) continue;

                $bandeira = $bandeiras[$i] ?? end($bandeiras) ?? null;

                // Verificar se já existe
                $existe = $db->fetchOne(
                    "SELECT id FROM titulo_cartoes WHERE titulo_id = ? AND numero_cartao = ?",
                    [$titulo['id'], $cartao]
                );

                if (!$existe) {
                    $db->insert('titulo_cartoes', [
                        'titulo_id' => $titulo['id'],
                        'numero_cartao' => $cartao,
                        'bandeira' => $bandeira,
                        'tipo_pagamento' => $titulo['tipo_pagamento_cartao'],
                        'ordem_uso' => $ordem++
                    ]);
                    $cartoesInseridos++;
                }
            }
        }

        echo "✅ {$cartoesInseridos} cartões inseridos na tabela titulo_cartoes<br>";

    } catch (Exception $e) {
        echo "<div class='error'>⚠️ Tabela titulo_cartoes não existe. Execute a migration 006 primeiro.</div>";
    }

    echo "</div>";

    // PASSO 3: Reprocessar análises
    echo "<div class='step'>";
    echo "<h3>Passo 3: Reprocessando análises</h3>";

    // Deletar análises antigas
    $db->query("DELETE FROM analise_cartoes WHERE importacao_id = ?", [$importacaoId]);
    $db->query("DELETE FROM analise_consultores WHERE importacao_id = ?", [$importacaoId]);

    // Análise de Consultores
    $sql = "INSERT INTO analise_consultores (
                importacao_id, promotor, total_vendas, total_clientes,
                vendas_ativas, vendas_bloqueadas, vendas_canceladas,
                inadimplentes_1parcela, total_inadimplentes, total_adimplentes,
                taxa_inadimplencia_geral, valor_total_recebido, valor_total_perdido,
                nivel_risco, primeira_venda, ultima_venda
            )
            SELECT
                {$importacaoId} as importacao_id,
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
            WHERE importacao_id = {$importacaoId}
              AND promotor IS NOT NULL
            GROUP BY promotor";

    $db->query($sql);
    $countConsultores = $db->affectedRows();
    echo "✅ {$countConsultores} análises de consultores criadas<br>";

    // Análise de Cartões
    try {
        $sql = "INSERT INTO analise_cartoes (
                    importacao_id, numero_cartao, bandeira, tipo_pagamento_cartao,
                    total_titulos_no_cartao, total_documentos_no_cartao, total_promotores_no_cartao,
                    titulos_ativos, titulos_bloqueados, titulos_cancelados, titulos_inadimplentes,
                    taxa_inadimplencia_cartao, primeira_venda_cartao, ultima_venda_cartao,
                    nivel_risco
                )
                SELECT
                    {$importacaoId} as importacao_id,
                    tc.numero_cartao,
                    MAX(tc.bandeira) as bandeira,
                    MAX(tc.tipo_pagamento) as tipo_pagamento_cartao,
                    COUNT(DISTINCT t.id) as total_titulos_no_cartao,
                    COUNT(DISTINCT t.documento_titular) as total_documentos_no_cartao,
                    COUNT(DISTINCT t.promotor) as total_promotores_no_cartao,
                    SUM(CASE WHEN t.status_titulo = 'Ativo' THEN 1 ELSE 0 END) as titulos_ativos,
                    SUM(CASE WHEN t.status_titulo = 'Bloqueado' THEN 1 ELSE 0 END) as titulos_bloqueados,
                    SUM(CASE WHEN t.status_titulo = 'Cancelado' THEN 1 ELSE 0 END) as titulos_cancelados,
                    SUM(CASE WHEN t.status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 ELSE 0 END) as titulos_inadimplentes,
                    ROUND(SUM(CASE WHEN t.status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 ELSE 0 END) * 100.0 / COUNT(DISTINCT t.id), 2) as taxa_inadimplencia_cartao,
                    MIN(t.data_primeira_venda) as primeira_venda_cartao,
                    MAX(t.data_ultima_venda) as ultima_venda_cartao,
                    CASE
                        WHEN COUNT(DISTINCT t.documento_titular) >= 10
                             AND SUM(CASE WHEN t.status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 ELSE 0 END) * 100.0 / COUNT(DISTINCT t.id) >= 30
                        THEN 'FRAUDE PROVÁVEL'
                        WHEN COUNT(DISTINCT t.documento_titular) >= 10
                             OR SUM(CASE WHEN t.status_titulo IN ('Bloqueado', 'Cancelado') THEN 1 ELSE 0 END) * 100.0 / COUNT(DISTINCT t.id) >= 40
                        THEN 'ALTO RISCO'
                        WHEN COUNT(DISTINCT t.documento_titular) >= 5
                             OR SUM(CASE WHEN t.status_titulo IN ('Bloqueado', 'Cancelado') THEN 1 ELSE 0 END) * 100.0 / COUNT(DISTINCT t.id) >= 25
                        THEN 'MÉDIO RISCO'
                        ELSE 'BAIXO RISCO'
                    END as nivel_risco
                FROM titulo_cartoes tc
                INNER JOIN titulos t ON tc.titulo_id = t.id
                WHERE t.importacao_id = {$importacaoId}
                  AND tc.numero_cartao IS NOT NULL
                  AND tc.numero_cartao != ''
                GROUP BY tc.numero_cartao
                HAVING COUNT(DISTINCT t.id) >= 2";

        $db->query($sql);
        $countCartoes = $db->affectedRows();
        echo "✅ {$countCartoes} análises de cartões criadas<br>";
    } catch (Exception $e) {
        echo "⚠️ Erro ao criar análise de cartões: " . $e->getMessage() . "<br>";
    }

    echo "</div>";

    $db->commit();

    echo "<div class='success'>";
    echo "<h2>✅ Correção Concluída!</h2>";
    echo "<p>Os dados foram corrigidos com sucesso.</p>";
    echo "<p><a href='../index.php'>Voltar ao Dashboard</a> | <a href='gerenciar_importacoes.php'>Gerenciar Importações</a></p>";
    echo "</div>";

} catch (Exception $e) {
    $db->rollback();

    echo "<div class='error'>";
    echo "<h2>❌ Erro na Correção</h2>";
    echo "<p><strong>Mensagem:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Arquivo:</strong> " . basename($e->getFile()) . " <strong>Linha:</strong> " . $e->getLine() . "</p>";
    echo "</div>";
}

echo "</body></html>";
