<?php
/**
 * Reprocessar Importação Existente
 * Recalcula título_cartoes e análises agregadas
 */

define('APP_ROOT', dirname(dirname(__DIR__)));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAdmin();

header('Content-Type: text/html; charset=utf-8');

// Obter ID da importação (se não fornecido, usa a última)
$importacaoId = $_GET['importacao_id'] ?? null;

$db = Database::getInstance();

if (!$importacaoId) {
    $ultimaImportacao = $db->fetchOne("SELECT * FROM importacoes WHERE status = 'concluido' ORDER BY concluido_em DESC LIMIT 1");
    if (!$ultimaImportacao) {
        die('❌ Nenhuma importação concluída encontrada.');
    }
    $importacaoId = $ultimaImportacao['id'];
}

// Verificar se importação existe
$importacao = $db->fetchOne("SELECT * FROM importacoes WHERE id = ?", [$importacaoId]);
if (!$importacao) {
    die('❌ Importação não encontrada.');
}

echo "<!DOCTYPE html>
<html lang='pt-BR'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Reprocessar Importação</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 900px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 { color: #007bff; margin-top: 0; }
        .info { background: #e7f3ff; padding: 15px; border-radius: 5px; margin: 20px 0; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .warning { background: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .step { margin: 20px 0; padding: 15px; border-left: 4px solid #007bff; background: #f8f9fa; }
        .step h3 { margin-top: 0; color: #007bff; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
        .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin: 20px 0; }
        .stat-box { background: #f8f9fa; padding: 15px; border-radius: 5px; text-align: center; }
        .stat-box strong { display: block; font-size: 24px; color: #007bff; }
        .btn { display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; margin: 10px 5px; }
        .btn:hover { background: #0056b3; }
        .btn-success { background: #28a745; }
        .btn-success:hover { background: #1e7e34; }
    </style>
</head>
<body>
    <div class='container'>";

echo "<h1>🔄 Reprocessar Importação</h1>";

echo "<div class='info'>";
echo "<strong>Importação Selecionada:</strong><br>";
echo "ID: {$importacao['id']}<br>";
echo "Arquivo: {$importacao['nome_arquivo']}<br>";
echo "Concluída em: " . date('d/m/Y H:i:s', strtotime($importacao['concluido_em'])) . "<br>";
echo "Total de linhas: {$importacao['total_linhas']}<br>";
echo "</div>";

// Se não foi confirmado, mostrar botão de confirmação
if (!isset($_GET['confirmar'])) {
    echo "<div class='warning'>";
    echo "<strong>⚠️ Atenção:</strong><br>";
    echo "Este processo irá:<br>";
    echo "1. Deletar todos os cartões existentes da tabela <code>titulo_cartoes</code><br>";
    echo "2. Reprocessar todos os títulos e extrair os cartões novamente<br>";
    echo "3. Deletar análises antigas de <code>analise_cartoes</code><br>";
    echo "4. Recalcular todas as agregações de cartões e consultores<br>";
    echo "</div>";

    echo "<a href='?importacao_id={$importacaoId}&confirmar=1' class='btn btn-success'>✅ Confirmar Reprocessamento</a>";
    echo "<a href='../index.php' class='btn'>❌ Cancelar</a>";

    echo "</div></body></html>";
    exit;
}

// Processar reprocessamento
echo "<h2>🚀 Iniciando Reprocessamento...</h2>";

try {
    $db->beginTransaction();

    // PASSO 1: Deletar cartões antigos
    echo "<div class='step'>";
    echo "<h3>Passo 1: Limpando dados antigos</h3>";

    $countCartoes = $db->fetchOne("SELECT COUNT(*) as total FROM titulo_cartoes tc INNER JOIN titulos t ON tc.titulo_id = t.id WHERE t.importacao_id = ?", [$importacaoId]);
    $db->query("DELETE tc FROM titulo_cartoes tc INNER JOIN titulos t ON tc.titulo_id = t.id WHERE t.importacao_id = ?", [$importacaoId]);
    echo "✅ Deletados {$countCartoes['total']} registros de <code>titulo_cartoes</code><br>";

    $countAnaliseCartoes = $db->fetchOne("SELECT COUNT(*) as total FROM analise_cartoes WHERE importacao_id = ?", [$importacaoId]);
    $db->query("DELETE FROM analise_cartoes WHERE importacao_id = ?", [$importacaoId]);
    echo "✅ Deletados {$countAnaliseCartoes['total']} registros de <code>analise_cartoes</code><br>";

    $countAnaliseConsultores = $db->fetchOne("SELECT COUNT(*) as total FROM analise_consultores WHERE importacao_id = ?", [$importacaoId]);
    $db->query("DELETE FROM analise_consultores WHERE importacao_id = ?", [$importacaoId]);
    echo "✅ Deletados {$countAnaliseConsultores['total']} registros de <code>analise_consultores</code><br>";

    echo "</div>";

    // PASSO 2: Buscar mapeamento da importação
    echo "<div class='step'>";
    echo "<h3>Passo 2: Recuperando mapeamento de colunas</h3>";

    $mapeamento = json_decode($importacao['mapeamento_colunas'], true);
    if (!$mapeamento) {
        throw new Exception('Mapeamento de colunas não encontrado na importação.');
    }
    echo "✅ Mapeamento recuperado com sucesso<br>";
    echo "</div>";

    // PASSO 3: Reprocessar títulos e extrair cartões
    echo "<div class='step'>";
    echo "<h3>Passo 3: Reprocessando títulos e extraindo cartões</h3>";

    // Buscar dados originais da importação (com campos concatenados)
    $caminhoArquivo = $importacao['caminho_arquivo'];

    if (file_exists($caminhoArquivo)) {
        // Reprocessar do arquivo original
        echo "📁 Arquivo CSV encontrado, reprocessando...<br>";

        $file = fopen($caminhoArquivo, 'r');
        $delimiter = ';'; // Ajustar se necessário

        // Detectar delimitador
        $firstLine = fgets($file);
        rewind($file);
        if (substr_count($firstLine, ';') > substr_count($firstLine, ',')) {
            $delimiter = ';';
        } else {
            $delimiter = ',';
        }

        // Pular cabeçalho
        fgetcsv($file, 0, $delimiter);

        $totalProcessados = 0;
        $cartoesInseridos = 0;

        while (($row = fgetcsv($file, 0, $delimiter)) !== false) {
            // Mapear dados
            $numeroTitulo = null;
            $numeroCartao = null;
            $bandeira = null;
            $tipoPagamentoCartao = null;

            foreach ($mapeamento as $index => $field) {
                if (!isset($row[$index])) continue;

                if ($field === 'numero_titulo') $numeroTitulo = trim($row[$index]);
                if ($field === 'numero_cartao') $numeroCartao = trim($row[$index]);
                if ($field === 'bandeira') $bandeira = trim($row[$index]);
                if ($field === 'tipo_pagamento_cartao') $tipoPagamentoCartao = trim($row[$index]);
            }

            if (!$numeroTitulo) continue;

            // Buscar título no banco com dados completos
            $titulo = $db->fetchOne("SELECT id, tipo_pagamento_cartao, data_primeira_venda FROM titulos WHERE numero_titulo = ? AND importacao_id = ?", [$numeroTitulo, $importacaoId]);

            if ($titulo && $numeroCartao) {
                // Inserir cartões
                $cartoes = array_map('trim', explode('|', $numeroCartao));
                $bandeiras = array_map('trim', explode('|', $bandeira ?? ''));

                $ordem = 1;
                foreach ($cartoes as $i => $cartao) {
                    if (empty($cartao)) continue;

                    $bandeiraCartao = $bandeiras[$i] ?? end($bandeiras) ?? null;

                    $db->insert('titulo_cartoes', [
                        'titulo_id' => $titulo['id'],
                        'numero_cartao' => $cartao,
                        'bandeira' => $bandeiraCartao,
                        'tipo_pagamento' => $titulo['tipo_pagamento_cartao'], // Pegar do banco
                        'data_primeiro_uso' => $titulo['data_primeira_venda'], // Pegar do banco
                        'ordem_uso' => $ordem++
                    ]);

                    $cartoesInseridos++;
                }
            }

            $totalProcessados++;

            if ($totalProcessados % 500 == 0) {
                echo "⏳ Processados {$totalProcessados} títulos...<br>";
                flush();
            }
        }

        fclose($file);

        echo "✅ Total processado: {$totalProcessados} títulos<br>";
        echo "✅ Cartões inseridos: {$cartoesInseridos}<br>";

    } else {
        throw new Exception('Arquivo CSV original não encontrado. Use a reimportação completa.');
    }

    echo "</div>";

    // PASSO 4: Recalcular agregações
    echo "<div class='step'>";
    echo "<h3>Passo 4: Recalculando agregações</h3>";

    // Análise de Consultores (cópia da função processAggregations)
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

    $stmt = $db->query($sql);
    $countConsultores = $stmt->rowCount();
    echo "✅ Análise de consultores: {$countConsultores} registros criados<br>";

    // Análise de Cartões
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

    $stmt = $db->query($sql);
    $countCartoes = $stmt->rowCount();
    echo "✅ Análise de cartões: {$countCartoes} registros criados<br>";

    echo "</div>";

    $db->commit();

    // Buscar estatísticas finais
    $statsCartoes = $db->fetchOne("SELECT COUNT(*) as total FROM titulo_cartoes tc INNER JOIN titulos t ON tc.titulo_id = t.id WHERE t.importacao_id = ?", [$importacaoId]);
    $statsAnaliseCartoes = $db->fetchOne("SELECT COUNT(*) as total FROM analise_cartoes WHERE importacao_id = ?", [$importacaoId]);
    $statsAnaliseConsultores = $db->fetchOne("SELECT COUNT(*) as total FROM analise_consultores WHERE importacao_id = ?", [$importacaoId]);

    echo "<div class='success'>";
    echo "<h2>✅ Reprocessamento Concluído com Sucesso!</h2>";
    echo "</div>";

    echo "<div class='stats'>";
    echo "<div class='stat-box'><strong>{$statsCartoes['total']}</strong><br>Cartões Cadastrados</div>";
    echo "<div class='stat-box'><strong>{$statsAnaliseCartoes['total']}</strong><br>Análises de Cartões</div>";
    echo "<div class='stat-box'><strong>{$statsAnaliseConsultores['total']}</strong><br>Análises de Consultores</div>";
    echo "</div>";

    echo "<a href='../index.php' class='btn btn-success'>🏠 Voltar ao Dashboard</a>";
    echo "<a href='../relatorios.php' class='btn'>📊 Ver Relatórios</a>";

    Logger::logAction(Auth::userId(), 'reprocess_import', 'Reprocessou importação', 'importacoes', $importacaoId);

} catch (Exception $e) {
    // Só fazer rollback se houver transação ativa
    try {
        if ($db->getConnection()->inTransaction()) {
            $db->rollback();
        }
    } catch (Exception $rollbackError) {
        // Ignorar erro de rollback
    }

    echo "<div class='error'>";
    echo "<h2>❌ Erro ao Reprocessar</h2>";
    echo "<strong>Mensagem:</strong> " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "<strong>Arquivo:</strong> " . basename($e->getFile()) . "<br>";
    echo "<strong>Linha:</strong> " . $e->getLine() . "<br>";
    echo "</div>";

    echo "<a href='?importacao_id={$importacaoId}' class='btn'>🔄 Tentar Novamente</a>";
    echo "<a href='../index.php' class='btn'>🏠 Voltar ao Dashboard</a>";
}

echo "</div></body></html>";
