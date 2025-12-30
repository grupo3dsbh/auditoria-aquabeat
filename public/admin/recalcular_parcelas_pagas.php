<?php
/**
 * Recalcular Parcelas Pagas
 * Remove "Consumo crédito" e "Pulseira Troca" da contagem
 */

define('APP_ROOT', dirname(dirname(__DIR__)));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAdmin();

header('Content-Type: text/html; charset=utf-8');

$db = Database::getInstance();
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
    <title>Recalcular Parcelas Pagas</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 50px auto; padding: 20px; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .info { background: #e7f3ff; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .step { margin: 20px 0; padding: 15px; border-left: 4px solid #007bff; background: #f8f9fa; }
        h1 { color: #007bff; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; }
        .example { background: #fff3cd; padding: 10px; border-left: 3px solid #ffc107; margin: 10px 0; }
    </style>
</head>
<body>
    <h1>🔢 Recalcular Parcelas Pagas - Importação #{$importacaoId}</h1>";

echo "<div class='info'>";
echo "<h3>📋 Como funciona:</h3>";
echo "<p>Este script recalcula o campo <code>qtd_parcelas_pagas</code> ignorando:</p>";
echo "<ul>";
echo "<li>❌ <strong>Consumo crédito</strong> - produtos extras que não são parcelas</li>";
echo "<li>❌ <strong>Pulseira Troca</strong> - não é parcela do plano</li>";
echo "</ul>";
echo "<p>E contabiliza:</p>";
echo "<ul>";
echo "<li>✅ Parcelas do plano principal</li>";
echo "<li>✅ <strong>Diferença de mensalidade</strong> - ajuste ao mudar de vaga</li>";
echo "</ul>";
echo "</div>";

echo "<div class='example'>";
echo "<strong>Exemplo do título SFA-10156:</strong><br>";
echo "Antes: 5 parcelas (1 plano + 4 consumos)<br>";
echo "Depois: 1 parcela (apenas o plano)";
echo "</div>";

try {
    $db->beginTransaction();

    echo "<div class='step'>";
    echo "<h3>Passo 1: Buscando títulos com lista de parcelas</h3>";

    // Buscar títulos que têm lista_parcelas_pagas
    $titulos = $db->fetchAll(
        "SELECT id, numero_titulo, lista_parcelas_pagas, qtd_parcelas_pagas
         FROM titulos
         WHERE importacao_id = ?
           AND lista_parcelas_pagas IS NOT NULL
           AND lista_parcelas_pagas != ''",
        [$importacaoId]
    );

    echo "✅ Encontrados " . count($titulos) . " títulos para processar<br>";
    echo "</div>";

    echo "<div class='step'>";
    echo "<h3>Passo 2: Recalculando parcelas pagas</h3>";

    $totalCorrigidos = 0;
    $totalDiferenca = 0;

    foreach ($titulos as $titulo) {
        // Separar lista de parcelas por |
        $parcelas = array_map('trim', explode('|', $titulo['lista_parcelas_pagas']));

        // Contar apenas parcelas válidas (excluir consumos e pulseiras)
        $parcelasValidas = 0;

        foreach ($parcelas as $parcela) {
            $parcela = strtolower($parcela);

            // Ignorar consumo crédito e pulseira troca
            if (stripos($parcela, 'consumo') !== false && stripos($parcela, 'credito') !== false) {
                continue; // ❌ Consumo crédito
            }

            if (stripos($parcela, 'pulseira') !== false && stripos($parcela, 'troca') !== false) {
                continue; // ❌ Pulseira Troca
            }

            // Linhas vazias ou NULL
            if (empty($parcela) || $parcela === 'null') {
                continue;
            }

            // Tudo que sobrou é parcela válida
            $parcelasValidas++;
        }

        $parcelasPagasAntes = (int)$titulo['qtd_parcelas_pagas'];

        // Se mudou, atualizar
        if ($parcelasValidas != $parcelasPagasAntes) {
            $db->update(
                'titulos',
                ['qtd_parcelas_pagas' => $parcelasValidas],
                'id = ?',
                [$titulo['id']]
            );

            $totalCorrigidos++;
            $totalDiferenca += ($parcelasPagasAntes - $parcelasValidas);

            if ($totalCorrigidos <= 10) {
                echo "• {$titulo['numero_titulo']}: {$parcelasPagasAntes} → {$parcelasValidas} parcelas<br>";
            }
        }
    }

    if ($totalCorrigidos > 10) {
        echo "• ... e mais " . ($totalCorrigidos - 10) . " títulos corrigidos<br>";
    }

    echo "<br>✅ Total corrigido: <strong>{$totalCorrigidos}</strong> títulos<br>";
    echo "✅ Diferença total: <strong>{$totalDiferenca}</strong> parcelas removidas (consumos e pulseiras)<br>";
    echo "</div>";

    $db->commit();

    echo "<div class='success'>";
    echo "<h2>✅ Recálculo Concluído!</h2>";
    echo "<p>As parcelas foram recalculadas corretamente.</p>";
    echo "<p>Agora os relatórios mostrarão apenas parcelas reais do plano.</p>";
    echo "</div>";

    echo "<p>";
    echo "<a href='../index.php' class='btn' style='display:inline-block; padding:10px 20px; background:#007bff; color:white; text-decoration:none; border-radius:5px; margin:5px;'>🏠 Voltar ao Dashboard</a>";
    echo "<a href='../relatorios.php' class='btn' style='display:inline-block; padding:10px 20px; background:#28a745; color:white; text-decoration:none; border-radius:5px; margin:5px;'>📊 Ver Relatórios</a>";
    echo "<a href='recalcular_inadimplencia.php' class='btn' style='display:inline-block; padding:10px 20px; background:#ffc107; color:black; text-decoration:none; border-radius:5px; margin:5px;'>🔄 Recalcular Inadimplência</a>";
    echo "</p>";

    Logger::logAction(Auth::userId(), 'recalculate_parcelas', 'Recalculou parcelas pagas da importação', 'importacoes', $importacaoId);

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
    echo "<h2>❌ Erro ao Recalcular</h2>";
    echo "<p><strong>Mensagem:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Arquivo:</strong> " . basename($e->getFile()) . " <strong>Linha:</strong> " . $e->getLine() . "</p>";
    echo "</div>";
}

echo "</body></html>";
