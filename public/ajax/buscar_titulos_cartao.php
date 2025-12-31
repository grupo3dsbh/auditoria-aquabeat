<?php
define('APP_ROOT', dirname(dirname(__DIR__)));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAuth('login.php');

header('Content-Type: application/json');

try {
    $numeroCartao = $_GET['numero_cartao'] ?? null;
    $dataInicio = $_GET['data_inicio'] ?? null;
    $dataFim = $_GET['data_fim'] ?? null;

    if (!$numeroCartao) {
        throw new Exception('Número do cartão não fornecido');
    }

    $db = Database::getInstance();

    // Obter última importação
    $ultimaImportacao = $db->fetchOne("SELECT * FROM importacoes WHERE status = 'concluido' ORDER BY concluido_em DESC LIMIT 1");
    if (!$ultimaImportacao) {
        throw new Exception('Nenhuma importação encontrada');
    }

    $importacaoId = $ultimaImportacao['id'];

    // Montar WHERE com filtros
    $where = ["t.importacao_id = ?"];
    $params = [$importacaoId];

    if ($dataInicio) {
        $where[] = "t.data_primeira_venda >= ?";
        $params[] = $dataInicio . ' 00:00:00';
    }

    if ($dataFim) {
        $where[] = "t.data_primeira_venda <= ?";
        $params[] = $dataFim . ' 23:59:59';
    }

    // Filtrar apenas títulos marcados para relatórios
    try {
        $colunaExiste = $db->fetchColumn("SHOW COLUMNS FROM titulos LIKE 'usado_relatorios'");
        if ($colunaExiste) {
            $where[] = "t.usado_relatorios = TRUE";
        } else {
            $where[] = "(t.numero_titulo LIKE 'SFA%' OR t.numero_titulo LIKE 'SBF%')";
        }
    } catch (Exception $e) {
        $where[] = "(t.numero_titulo LIKE 'SFA%' OR t.numero_titulo LIKE 'SBF%')";
    }

    // Buscar títulos que usaram este cartão
    $sql = "
        SELECT t.*
        FROM titulo_cartoes tc
        INNER JOIN titulos t ON tc.titulo_id = t.id
        WHERE tc.numero_cartao = ?
          AND " . implode(' AND ', $where) . "
        ORDER BY t.data_primeira_venda DESC
    ";

    $paramsCartao = array_merge([$numeroCartao], $params);
    $titulos = $db->fetchAll($sql, $paramsCartao);

    // Calcular estatísticas
    $stats = [
        'total_titulos' => count($titulos),
        'total_cpfs' => count(array_unique(array_column($titulos, 'documento_titular'))),
        'inadimplentes' => count(array_filter($titulos, fn($t) => stripos($t['status_inadimplencia'], 'INADIMPLENTE') !== false)),
        'adimplentes' => count(array_filter($titulos, fn($t) => $t['status_inadimplencia'] === 'ADIMPLENTE')),
    ];

    if ($stats['total_titulos'] > 0) {
        $stats['taxa_inadimplencia'] = round(($stats['inadimplentes'] / $stats['total_titulos']) * 100, 1);
    } else {
        $stats['taxa_inadimplencia'] = 0;
    }

    echo json_encode([
        'success' => true,
        'titulos' => $titulos,
        'stats' => $stats
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
