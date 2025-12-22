<?php
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAuth('login.php');

$db = Database::getInstance();

// Obter última importação
$ultimaImportacao = $db->fetchOne("SELECT * FROM importacoes WHERE status = 'concluido' ORDER BY concluido_em DESC LIMIT 1");

if (!$ultimaImportacao) {
    setFlashMessage('error', 'Nenhuma importação concluída encontrada. Faça uma importação primeiro.');
    redirect('upload.php');
}

$importacaoId = $ultimaImportacao['id'];

// Calcular data fim padrão: último dia de 2 meses antes do mês atual
$dataFimPadrao = date('Y-m-t', strtotime('-2 months'));

// Filtros
$filtros = [];
$where = ["importacao_id = ?"];
$params = [$importacaoId];

// Filtro padrão: Data desde 01/11/2024
$dataInicio = !empty($_GET['data_inicio']) ? $_GET['data_inicio'] : '2024-11-01';
$dataFim = !empty($_GET['data_fim']) ? $_GET['data_fim'] : $dataFimPadrao;

$where[] = "data_primeira_venda >= ?";
$params[] = $dataInicio . ' 00:00:00';
$filtros['data_inicio'] = $dataInicio;

$where[] = "data_primeira_venda <= ?";
$params[] = $dataFim . ' 23:59:59';
$filtros['data_fim'] = $dataFim;

// IMPORTANTE: Considerar APENAS SFA e SBF (outros prefixos são ignorados)
// Este filtro é SEMPRE aplicado e não pode ser removido
$where[] = "(numero_titulo LIKE 'SFA%' OR numero_titulo LIKE 'SBF%')";
$filtros['prefixos'] = ['SFA', 'SBF'];

// Filtro por status do título (permite múltiplos)
if (!empty($_GET['status_titulo'])) {
    $statusTitulos = is_array($_GET['status_titulo']) ? $_GET['status_titulo'] : [$_GET['status_titulo']];
    $statusTitulos = array_filter($statusTitulos); // Remove valores vazios

    if (!empty($statusTitulos)) {
        $placeholders = implode(',', array_fill(0, count($statusTitulos), '?'));
        $where[] = "status_titulo IN ($placeholders)";
        foreach ($statusTitulos as $status) {
            $params[] = $status;
        }
        $filtros['status_titulo'] = $statusTitulos;
    }
}

if (!empty($_GET['status_inadimplencia'])) {
    if ($_GET['status_inadimplencia'] == 'INADIMPLENTE') {
        $where[] = "status_inadimplencia LIKE 'INADIMPLENTE%'";
    } else {
        $where[] = "status_inadimplencia = ?";
        $params[] = $_GET['status_inadimplencia'];
    }
    $filtros['status_inadimplencia'] = $_GET['status_inadimplencia'];
}

if (!empty($_GET['promotor'])) {
    $where[] = "promotor = ?";
    $params[] = $_GET['promotor'];
    $filtros['promotor'] = $_GET['promotor'];
}

if (!empty($_GET['qtd_parcelas_pagas'])) {
    $where[] = "qtd_parcelas_pagas = ?";
    $params[] = $_GET['qtd_parcelas_pagas'];
    $filtros['qtd_parcelas_pagas'] = $_GET['qtd_parcelas_pagas'];
}

if (!empty($_GET['tipo_titulo'])) {
    $where[] = "nome_produto_atual LIKE ?";
    $params[] = '%' . $_GET['tipo_titulo'] . '%';
    $filtros['tipo_titulo'] = $_GET['tipo_titulo'];
}

if (!empty($_GET['apenas_1parcela']) && $_GET['apenas_1parcela'] == '1') {
    $where[] = "status_inadimplencia = 'INADIMPLENTE - Apenas 1ª Parcela'";
    $filtros['apenas_1parcela'] = '1';
}

// Filtro por parcelas pagas
if (isset($_GET['parcelas_filtro']) && $_GET['parcelas_filtro'] !== '') {
    $parcelasFiltro = $_GET['parcelas_filtro'];
    if ($parcelasFiltro === '0') {
        $where[] = "(qtd_parcelas_pagas = 0 OR qtd_parcelas_pagas IS NULL)";
    } elseif ($parcelasFiltro === '1') {
        $where[] = "qtd_parcelas_pagas = 1";
    } elseif ($parcelasFiltro === '2') {
        $where[] = "qtd_parcelas_pagas = 2";
    } elseif ($parcelasFiltro === '3+') {
        $where[] = "qtd_parcelas_pagas >= 3";
    }
    $filtros['parcelas_filtro'] = $parcelasFiltro;
}

// Filtro por CPF duplicado
// IMPORTANTE: Considera os filtros JÁ APLICADOS para determinar quais CPFs são duplicados
if (!empty($_GET['cpf_duplicado']) && $_GET['cpf_duplicado'] == '1') {
    // Criar subquery com os mesmos filtros já aplicados
    $whereSubquery = implode(' AND ', $where);
    $paramsSubquery = $params;

    $where[] = "documento_titular IN (
        SELECT documento_titular
        FROM titulos
        WHERE " . $whereSubquery . "
        GROUP BY documento_titular
        HAVING COUNT(*) > 1
    )";

    // Adicionar os parâmetros da subquery
    foreach ($paramsSubquery as $param) {
        $params[] = $param;
    }

    $filtros['cpf_duplicado'] = '1';
}

// Ordenação
// Se filtro CPF duplicado estiver ativo, ordenar por documento_titular automaticamente
if (!empty($_GET['cpf_duplicado']) && $_GET['cpf_duplicado'] == '1') {
    $orderBy = 'documento_titular';
    $orderDir = 'ASC';
} else {
    $orderBy = !empty($_GET['order_by']) ? $_GET['order_by'] : 'data_primeira_venda';
    $orderDir = !empty($_GET['order_dir']) && $_GET['order_dir'] == 'ASC' ? 'ASC' : 'DESC';
}
$filtros['order_by'] = $orderBy;
$filtros['order_dir'] = $orderDir;

// Paginação
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = RECORDS_PER_PAGE;
$offset = ($page - 1) * $perPage;

// Contar total
$totalSql = "SELECT COUNT(*) FROM titulos WHERE " . implode(' AND ', $where);
$total = $db->fetchColumn($totalSql, $params);

// Buscar registros
$sql = "SELECT * FROM titulos
        WHERE " . implode(' AND ', $where) . "
        ORDER BY {$orderBy} {$orderDir}
        LIMIT ? OFFSET ?";

$params[] = $perPage;
$params[] = $offset;

$titulos = $db->fetchAll($sql, $params);

// Se filtro CPF duplicado estiver ativo, criar array de contagem por CPF
$cpfContadores = [];
$cpfTituloIndice = []; // Mapeia titulo_id => [indice atual, total]

if (!empty($_GET['cpf_duplicado']) && $_GET['cpf_duplicado'] == '1') {
    // Buscar TODOS os títulos ordenados por CPF (para criar índices corretos)
    $sqlTodosTitulos = "SELECT id, documento_titular
                        FROM titulos
                        WHERE " . implode(' AND ', $where) . "
                        ORDER BY documento_titular ASC, id ASC";

    $paramsTodos = array_slice($params, 0, count($params) - 2); // Remove LIMIT e OFFSET
    $todosTitulos = $db->fetchAll($sqlTodosTitulos, $paramsTodos);

    // Criar índices: para cada CPF, numerar os títulos sequencialmente
    $cpfContagem = [];
    $cpfIndiceAtual = [];

    foreach ($todosTitulos as $titulo) {
        $cpf = $titulo['documento_titular'];

        if (!isset($cpfContagem[$cpf])) {
            $cpfContagem[$cpf] = 0;
            $cpfIndiceAtual[$cpf] = 0;
        }

        $cpfContagem[$cpf]++;
    }

    // Segunda passagem: criar mapa de índices
    foreach ($todosTitulos as $titulo) {
        $cpf = $titulo['documento_titular'];
        $cpfIndiceAtual[$cpf]++;

        $cpfTituloIndice[$titulo['id']] = [
            'indice' => $cpfIndiceAtual[$cpf],
            'total' => $cpfContagem[$cpf]
        ];
    }

    $cpfContadores = $cpfContagem;
}

// Estatísticas detalhadas
$stats = $db->fetchOne("
    SELECT
        COUNT(*) as total,

        -- Total de inadimplentes
        COUNT(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 END) as total_inadimplentes,
        COUNT(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' AND status_titulo = 'Ativo' THEN 1 END) as inadimplentes_ativos,
        COUNT(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' AND status_titulo = 'Bloqueado' THEN 1 END) as inadimplentes_bloqueados,
        COUNT(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' AND status_titulo = 'Cancelado' THEN 1 END) as inadimplentes_cancelados,

        -- Breakdown de títulos por status
        COUNT(CASE WHEN status_titulo = 'Ativo' THEN 1 END) as titulos_ativos,
        COUNT(CASE WHEN status_titulo = 'Bloqueado' THEN 1 END) as titulos_bloqueados,
        COUNT(CASE WHEN status_titulo = 'Cancelado' THEN 1 END) as titulos_cancelados,

        -- Valores financeiros
        COALESCE(SUM(valor_total_plano), 0) as valor_total_vendido,
        COALESCE(SUM(total_pago), 0) as valor_total_recebido,
        COALESCE(SUM(saldo_restante), 0) as valor_total_restante,

        -- Valor em risco (apenas inadimplentes)
        SUM(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN saldo_restante ELSE 0 END) as valor_em_risco,

        -- Valor perdido (apenas cancelados)
        SUM(CASE WHEN status_titulo = 'Cancelado' THEN saldo_restante ELSE 0 END) as valor_perdido,

        -- Valor a receber (adimplentes)
        SUM(CASE WHEN status_inadimplencia = 'ADIMPLENTE' THEN saldo_restante ELSE 0 END) as valor_a_receber_adimplente
    FROM titulos
    WHERE " . implode(' AND ', array_slice($where, 0, count($where))),
    array_slice($params, 0, count($params) - 2)
);

// Corrigir valor_total_vendido se estiver zerado mas tiver recebido/restante
// Isso pode acontecer se a coluna valor_total_plano estiver vazia no banco
if (($stats['valor_total_vendido'] ?? 0) == 0) {
    $somaCalculada = ($stats['valor_total_recebido'] ?? 0) + ($stats['valor_total_restante'] ?? 0);
    if ($somaCalculada > 0) {
        $stats['valor_total_vendido'] = $somaCalculada;
    }
}

// Detectar contexto do filtro para exibição condicional
$filtroStatus = $_GET['status_inadimplencia'] ?? '';
$filtroTitulo = $_GET['status_titulo'] ?? [];
// Se não for array, converte
if (!is_array($filtroTitulo)) {
    $filtroTitulo = empty($filtroTitulo) ? [] : [$filtroTitulo];
}

// Análise por tipo de inadimplência
$paramsAnalise = array_slice($params, 0, count($params) - 2);
$inadimplenciaPorTipo = $db->fetchAll("
    SELECT
        status_inadimplencia,
        COUNT(*) as total,
        SUM(saldo_restante) as valor_risco,
        ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM titulos WHERE " . implode(' AND ', $where) . "), 2) as percentual
    FROM titulos
    WHERE " . implode(' AND ', $where) . "
      AND status_inadimplencia LIKE 'INADIMPLENTE%'
    GROUP BY status_inadimplencia
    ORDER BY total DESC",
    array_merge($paramsAnalise, $paramsAnalise) // Duplicar params: subquery + query principal
);

// Ranking de consultores com mais inadimplência
$rankingConsultores = $db->fetchAll("
    SELECT
        promotor,
        COUNT(*) as total_vendas,
        COUNT(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 END) as inadimplentes,
        COUNT(CASE WHEN status_inadimplencia = 'INADIMPLENTE - Apenas 1ª Parcela' THEN 1 END) as apenas_1parcela,
        SUM(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN saldo_restante ELSE 0 END) as valor_risco,
        ROUND(COUNT(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 END) * 100.0 / COUNT(*), 2) as taxa_inadimplencia
    FROM titulos
    WHERE " . implode(' AND ', $where) . "
      AND promotor IS NOT NULL
    GROUP BY promotor
    HAVING inadimplentes > 0
    ORDER BY inadimplentes DESC
    LIMIT 10",
    array_slice($params, 0, count($params) - 2)
);

// Títulos bloqueados/cancelados em até 30 dias ou a partir do 2º mês
$titulosProblematicos = $db->fetchAll("
    SELECT *
    FROM titulos
    WHERE " . implode(' AND ', $where) . "
      AND (
          (status_titulo IN ('Bloqueado', 'Cancelado') AND dias_desde_venda <= 30)
          OR
          (status_inadimplencia = 'INADIMPLENTE - Apenas 1ª Parcela' AND dias_desde_venda >= 60)
      )
    ORDER BY dias_desde_venda DESC
    LIMIT 20",
    array_slice($params, 0, count($params) - 2)
);

// Buscar lista de promotores para autocomplete
$promotores = $db->fetchAll("
    SELECT DISTINCT promotor
    FROM titulos
    WHERE promotor IS NOT NULL AND importacao_id = ?
    ORDER BY promotor",
    [$importacaoId]
);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatórios - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <style>
        @media print {
            .navbar, .card-header button, .btn, form, .pagination { display: none !important; }
            .card { border: none !important; box-shadow: none !important; }
            body { font-size: 10pt; }
            table { font-size: 9pt; }
        }

        /* Cores por tipo de inadimplência - Força máxima de especificidade */

        /* CATEGORIAS ESPECIAIS - Vermelho claro/Rosa */
        table.table tbody tr.inadimplente-1parcela,
        table.table tbody tr.inadimplente-1parcela > td {
            background-color: #ffebee !important;
        }
        table.table tbody tr.inadimplente-2parcelas,
        table.table tbody tr.inadimplente-2parcelas > td {
            background-color: #ffe0b2 !important;
        }

        /* NOVAS CATEGORIAS POR TEMPO */
        /* Até 3 meses - Amarelo claro (premiação) */
        table.table tbody tr.inadimplente-ate3,
        table.table tbody tr.inadimplente-ate3 > td {
            background-color: #fff9c4 !important;
        }
        /* 3 a 6 meses - Laranja claro (experiência) */
        table.table tbody tr.inadimplente-3a6,
        table.table tbody tr.inadimplente-3a6 > td {
            background-color: #ffe0b2 !important;
        }
        /* 6 a 9 meses - Laranja escuro (expectativa) */
        table.table tbody tr.inadimplente-6a9,
        table.table tbody tr.inadimplente-6a9 > td {
            background-color: #ffcc80 !important;
        }
        /* 9 a 12 meses - Vermelho claro */
        table.table tbody tr.inadimplente-9a12,
        table.table tbody tr.inadimplente-9a12 > td {
            background-color: #ffcdd2 !important;
        }
        /* Mais de 12 meses - Vermelho escuro (crônico) */
        table.table tbody tr.inadimplente-12mais,
        table.table tbody tr.inadimplente-12mais > td {
            background-color: #ef5350 !important;
            color: white !important;
        }

        /* CATEGORIAS ANTIGAS - Manter para compatibilidade */
        table.table tbody tr.inadimplente-menos50,
        table.table tbody tr.inadimplente-menos50 > td {
            background-color: #fff9c4 !important;
        }
        table.table tbody tr.inadimplente-mais50,
        table.table tbody tr.inadimplente-mais50 > td {
            background-color: #ffcdd2 !important;
        }

        /* ADIMPLENTE - Verde */
        table.table tbody tr.adimplente,
        table.table tbody tr.adimplente > td {
            background-color: #e8f5e9 !important;
        }

        /* HOVER - escurece levemente */
        table.table-hover tbody tr[class*="inadimplente"]:hover,
        table.table-hover tbody tr[class*="inadimplente"]:hover > td {
            filter: brightness(0.9);
        }
        table.table-hover tbody tr.adimplente:hover,
        table.table-hover tbody tr.adimplente:hover > td {
            background-color: #c8e6c9 !important;
        }

        .cursor-pointer {
            cursor: pointer;
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container-fluid mt-4">
        <h2><i class="bi bi-file-earmark-bar-graph"></i> Relatórios de Inadimplência</h2>
        <p class="text-muted">Importação: <?php echo sanitize($ultimaImportacao['nome_arquivo']); ?> - <?php echo formatDateTime($ultimaImportacao['concluido_em']); ?></p>

        <!-- Estatísticas Principais -->
        <div class="row mb-4">
            <!-- Card 1: Total de Títulos -->
            <div class="col-md-3">
                <div class="card text-white bg-primary h-100">
                    <div class="card-body">
                        <h6 class="mb-3"><i class="bi bi-clipboard-data"></i> Total de Títulos</h6>
                        <h3 class="mb-2"><?php echo number_format($stats['total'], 0, ',', '.'); ?></h3>
                        <small>
                            <i class="bi bi-check-circle"></i> <?php echo number_format($stats['titulos_ativos'], 0, ',', '.'); ?> Ativos<br>
                            <i class="bi bi-lock"></i> <?php echo number_format($stats['titulos_bloqueados'], 0, ',', '.'); ?> Bloqueados<br>
                            <i class="bi bi-x-circle"></i> <?php echo number_format($stats['titulos_cancelados'], 0, ',', '.'); ?> Cancelados
                        </small>
                    </div>
                </div>
            </div>

            <!-- Card 2: Inadimplentes -->
            <div class="col-md-3">
                <div class="card text-white bg-danger h-100">
                    <div class="card-body">
                        <h6 class="mb-3"><i class="bi bi-exclamation-triangle"></i> Inadimplentes</h6>
                        <h3 class="mb-2"><?php echo number_format($stats['total_inadimplentes'], 0, ',', '.'); ?></h3>
                        <small>
                            Taxa: <?php echo formatPercentage($stats['total_inadimplentes'] * 100 / max($stats['total'], 1), 1); ?><br>
                            <i class="bi bi-check-circle"></i> <?php echo number_format($stats['inadimplentes_ativos'], 0, ',', '.'); ?> Ativos<br>
                            <i class="bi bi-lock"></i> <?php echo number_format($stats['inadimplentes_bloqueados'], 0, ',', '.'); ?> Bloqueados
                        </small>
                    </div>
                </div>
            </div>

            <!-- Card 3: Valor Total Vendido -->
            <div class="col-md-3">
                <div class="card text-white bg-success h-100">
                    <div class="card-body">
                        <h6 class="mb-3"><i class="bi bi-cash-stack"></i> Valor Total</h6>
                        <h4 class="mb-2"><?php echo formatCurrency($stats['valor_total_vendido'] ?? 0); ?></h4>
                        <small>
                            <i class="bi bi-check"></i> Recebido: <?php echo formatCurrency($stats['valor_total_recebido'] ?? 0); ?><br>
                            <i class="bi bi-clock"></i> Restante: <?php echo formatCurrency($stats['valor_total_restante'] ?? 0); ?>
                        </small>
                    </div>
                </div>
            </div>

            <!-- Card 4: Valor em Risco / Perdido / A Receber (contextual) -->
            <div class="col-md-3">
                <?php if (count($filtroTitulo) === 1 && in_array('Cancelado', $filtroTitulo)): ?>
                    <!-- Filtro de Cancelado: Mostrar Valor Perdido -->
                    <div class="card text-white bg-secondary h-100">
                        <div class="card-body">
                            <h6 class="mb-3"><i class="bi bi-trash"></i> Valor Perdido</h6>
                            <h4 class="mb-2"><?php echo formatCurrency($stats['valor_perdido'] ?? 0); ?></h4>
                            <small>Títulos cancelados que não serão mais pagos</small>
                        </div>
                    </div>
                <?php elseif ($filtroStatus == 'ADIMPLENTE'): ?>
                    <!-- Filtro de Adimplente: Mostrar Valor a Receber -->
                    <div class="card text-white bg-info h-100">
                        <div class="card-body">
                            <h6 class="mb-3"><i class="bi bi-hourglass-split"></i> Valor a Receber</h6>
                            <h4 class="mb-2"><?php echo formatCurrency($stats['valor_a_receber_adimplente'] ?? 0); ?></h4>
                            <small>
                                <i class="bi bi-check-circle"></i> Recebido: <?php echo formatCurrency($stats['valor_total_recebido'] ?? 0); ?><br>
                                Risco: R$ 0,00
                            </small>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Padrão: Mostrar Valor em Risco -->
                    <div class="card text-white bg-dark h-100">
                        <div class="card-body">
                            <h6 class="mb-3"><i class="bi bi-shield-exclamation"></i> Valor em Risco</h6>
                            <h4 class="mb-2"><?php echo formatCurrency($stats['valor_em_risco'] ?? 0); ?></h4>
                            <small>
                                Saldo restante apenas de inadimplentes<br>
                                <?php
                                $percRisco = $stats['valor_total_vendido'] > 0
                                    ? ($stats['valor_em_risco'] / $stats['valor_total_vendido']) * 100
                                    : 0;
                                ?>
                                <?php echo number_format($percRisco, 2, ',', '.'); ?>% do total vendido
                            </small>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Resumo de IA -->
        <?php if (getConfig('api_ia_key')): ?>
        <div class="card mb-4 border-info">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="bi bi-robot"></i> Resumo Gerado por IA</h5>
            </div>
            <div class="card-body">
                <div id="iaResumo">
                    <div class="text-center">
                        <button class="btn btn-info" onclick="gerarResumoIA()">
                            <i class="bi bi-magic"></i> Gerar Análise com IA
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Análise Detalhada -->
        <div class="row mb-4">
            <!-- Inadimplência por Tipo -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-warning">
                        <h6 class="mb-0"><i class="bi bi-pie-chart"></i> Inadimplência por Tipo</h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($inadimplenciaPorTipo)): ?>
                            <p class="text-muted">Nenhum inadimplente no período.</p>
                        <?php else: ?>
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Tipo</th>
                                        <th class="text-end">Qtd</th>
                                        <th class="text-end">%</th>
                                        <th class="text-end">Valor Risco</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($inadimplenciaPorTipo as $tipo): ?>
                                        <tr>
                                            <td>
                                                <small><?php echo sanitize($tipo['status_inadimplencia']); ?></small>
                                            </td>
                                            <td class="text-end"><?php echo number_format($tipo['total'], 0, ',', '.'); ?></td>
                                            <td class="text-end"><?php echo number_format($tipo['percentual'], 1); ?>%</td>
                                            <td class="text-end"><small><?php echo formatCurrency($tipo['valor_risco'] ?? 0); ?></small></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Ranking de Consultores -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-danger text-white">
                        <h6 class="mb-0"><i class="bi bi-trophy"></i> Top 10 Consultores com Inadimplência</h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($rankingConsultores)): ?>
                            <p class="text-muted">Nenhum dado disponível.</p>
                        <?php else: ?>
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Consultor</th>
                                        <th class="text-end">Inadimp.</th>
                                        <th class="text-end">Taxa</th>
                                        <th class="text-end">1ª Parc.</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rankingConsultores as $index => $consultor): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td>
                                                <small>
                                                    <a href="?promotor=<?php echo urlencode($consultor['promotor']); ?>" class="text-decoration-none">
                                                        <?php echo sanitize($consultor['promotor']); ?>
                                                    </a>
                                                </small>
                                            </td>
                                            <td class="text-end">
                                                <span class="badge bg-danger">
                                                    <?php echo $consultor['inadimplentes']; ?>/<?php echo $consultor['total_vendas']; ?>
                                                </span>
                                            </td>
                                            <td class="text-end"><?php echo number_format($consultor['taxa_inadimplencia'], 1); ?>%</td>
                                            <td class="text-end">
                                                <?php if ($consultor['apenas_1parcela'] > 0): ?>
                                                    <span class="badge bg-warning text-dark"><?php echo $consultor['apenas_1parcela']; ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Títulos Problemáticos -->
        <?php if (!empty($titulosProblematicos)): ?>
        <div class="card mb-4 border-danger">
            <div class="card-header bg-danger text-white">
                <h6 class="mb-0">
                    <i class="bi bi-exclamation-triangle"></i>
                    Títulos para Atenção Especial
                    <small>(Bloqueados/Cancelados em 30 dias OU Apenas 1ª parcela há 60+ dias)</small>
                </h6>
            </div>
            <div class="card-body">
                <!-- Tabs -->
                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="todos-tab" data-bs-toggle="tab" data-bs-target="#todos-problematicos" type="button" role="tab">
                            Todos (<?php echo count($titulosProblematicos); ?>)
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="ativos-tab" data-bs-toggle="tab" data-bs-target="#ativos-problematicos" type="button" role="tab">
                            Apenas Ativos (<?php echo count(array_filter($titulosProblematicos, fn($t) => $t['status_titulo'] == 'Ativo')); ?>)
                        </button>
                    </li>
                </ul>

                <!-- Tab Content -->
                <div class="tab-content">
                    <!-- Todos -->
                    <div class="tab-pane fade show active" id="todos-problematicos" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Título</th>
                                        <th>Titular</th>
                                        <th>Consultor</th>
                                        <th>Status</th>
                                        <th>Dias</th>
                                        <th>Inadimplência</th>
                                        <th class="text-end">Risco</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($titulosProblematicos as $titulo): ?>
                                        <tr>
                                            <td><small><?php echo sanitize($titulo['numero_titulo']); ?></small></td>
                                            <td><small><?php echo sanitize($titulo['nome_titular']); ?></small></td>
                                            <td><small><?php echo sanitize($titulo['promotor']); ?></small></td>
                                            <td>
                                                <span class="badge bg-<?php echo getStatusBadgeClass($titulo['status_titulo']); ?>">
                                                    <?php echo $titulo['status_titulo']; ?>
                                                </span>
                                            </td>
                                            <td><?php echo $titulo['dias_desde_venda']; ?> dias</td>
                                            <td><small><?php echo $titulo['status_inadimplencia']; ?></small></td>
                                            <td class="text-end"><small><?php echo formatCurrency($titulo['saldo_restante'] ?? 0); ?></small></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Apenas Ativos -->
                    <div class="tab-pane fade" id="ativos-problematicos" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Título</th>
                                        <th>Titular</th>
                                        <th>Consultor</th>
                                        <th>Dias</th>
                                        <th>Inadimplência</th>
                                        <th class="text-end">Risco</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $ativosProblematicos = array_filter($titulosProblematicos, fn($t) => $t['status_titulo'] == 'Ativo');
                                    foreach ($ativosProblematicos as $titulo):
                                    ?>
                                        <tr>
                                            <td><small><?php echo sanitize($titulo['numero_titulo']); ?></small></td>
                                            <td><small><?php echo sanitize($titulo['nome_titular']); ?></small></td>
                                            <td><small><?php echo sanitize($titulo['promotor']); ?></small></td>
                                            <td><?php echo $titulo['dias_desde_venda']; ?> dias</td>
                                            <td><small><?php echo $titulo['status_inadimplencia']; ?></small></td>
                                            <td class="text-end"><small><?php echo formatCurrency($titulo['saldo_restante'] ?? 0); ?></small></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filtros -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5><i class="bi bi-funnel"></i> Filtros</h5>
                <button onclick="window.print()" class="btn btn-sm btn-success">
                    <i class="bi bi-printer"></i> Imprimir
                </button>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3" id="filterForm">
                    <div class="col-md-3">
                        <label class="form-label">Data Início</label>
                        <input type="date" name="data_inicio" class="form-control" value="<?php echo sanitize($filtros['data_inicio']); ?>">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Data Fim</label>
                        <input type="date" name="data_fim" class="form-control" value="<?php echo sanitize($filtros['data_fim']); ?>">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Prefixo do Título</label>
                        <select name="prefixos[]" class="form-select" multiple size="1">
                            <option value="SBF" <?php echo in_array('SBF', $filtros['prefixos'] ?? []) ? 'selected' : ''; ?>>SBF</option>
                            <option value="SFA" <?php echo in_array('SFA', $filtros['prefixos'] ?? []) ? 'selected' : ''; ?>>SFA</option>
                            <option value="SAC" <?php echo in_array('SAC', $filtros['prefixos'] ?? []) ? 'selected' : ''; ?>>SAC</option>
                            <option value="SAP" <?php echo in_array('SAP', $filtros['prefixos'] ?? []) ? 'selected' : ''; ?>>SAP</option>
                            <option value="DIP" <?php echo in_array('DIP', $filtros['prefixos'] ?? []) ? 'selected' : ''; ?>>DIP</option>
                        </select>
                        <small class="text-muted">Ctrl+clique para múltiplos</small>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Tipo de Título</label>
                        <select name="tipo_titulo" class="form-select">
                            <option value="">Todos</option>
                            <option value="1 Vaga" <?php echo ($filtros['tipo_titulo'] ?? '') == '1 Vaga' ? 'selected' : ''; ?>>1 Vaga</option>
                            <option value="2 Vagas" <?php echo ($filtros['tipo_titulo'] ?? '') == '2 Vagas' ? 'selected' : ''; ?>>2 Vagas</option>
                            <option value="3 Vagas" <?php echo ($filtros['tipo_titulo'] ?? '') == '3 Vagas' ? 'selected' : ''; ?>>3 Vagas</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Status do Título</label>
                        <select name="status_titulo[]" class="form-select" multiple size="3">
                            <?php
                            $statusSelecionados = $filtros['status_titulo'] ?? [];
                            if (!is_array($statusSelecionados)) {
                                $statusSelecionados = [$statusSelecionados];
                            }
                            ?>
                            <option value="Ativo" <?php echo in_array('Ativo', $statusSelecionados) ? 'selected' : ''; ?>>Ativo</option>
                            <option value="Bloqueado" <?php echo in_array('Bloqueado', $statusSelecionados) ? 'selected' : ''; ?>>Bloqueado</option>
                            <option value="Cancelado" <?php echo in_array('Cancelado', $statusSelecionados) ? 'selected' : ''; ?>>Cancelado</option>
                        </select>
                        <small class="text-muted">Ctrl+clique para múltiplos</small>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Status Inadimplência</label>
                        <select name="status_inadimplencia" class="form-select">
                            <option value="">Todos</option>
                            <option value="INADIMPLENTE" <?php echo ($filtros['status_inadimplencia'] ?? '') == 'INADIMPLENTE' ? 'selected' : ''; ?>>Inadimplente</option>
                            <option value="ADIMPLENTE" <?php echo ($filtros['status_inadimplencia'] ?? '') == 'ADIMPLENTE' ? 'selected' : ''; ?>>Adimplente</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Promotor/Consultor</label>
                        <select name="promotor" id="promotorSelect" class="form-select">
                            <option value="">Todos</option>
                            <?php foreach ($promotores as $p): ?>
                                <option value="<?php echo sanitize($p['promotor']); ?>" <?php echo ($filtros['promotor'] ?? '') == $p['promotor'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize($p['promotor']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Parcelas Pagas</label>
                        <select name="parcelas_filtro" class="form-select">
                            <option value="">Todas</option>
                            <option value="0" <?php echo ($filtros['parcelas_filtro'] ?? '') === '0' ? 'selected' : ''; ?>>Nenhuma (0)</option>
                            <option value="1" <?php echo ($filtros['parcelas_filtro'] ?? '') === '1' ? 'selected' : ''; ?>>Apenas 1ª</option>
                            <option value="2" <?php echo ($filtros['parcelas_filtro'] ?? '') === '2' ? 'selected' : ''; ?>>Apenas 2</option>
                            <option value="3+" <?php echo ($filtros['parcelas_filtro'] ?? '') === '3+' ? 'selected' : ''; ?>>3 ou mais</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">CPFs Duplicados</label>
                        <select name="cpf_duplicado" class="form-select">
                            <option value="">Todos</option>
                            <option value="1" <?php echo ($filtros['cpf_duplicado'] ?? '') == '1' ? 'selected' : ''; ?>>Apenas CPFs com múltiplas cotas</option>
                        </select>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> Filtrar
                        </button>
                        <a href="<?php echo url('relatorios.php'); ?>" class="btn btn-secondary">
                            <i class="bi bi-x-circle"></i> Limpar
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Legenda de Cores -->
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-palette"></i> Legenda de Cores - Classificação por Tempo</h6>
            </div>
            <div class="card-body">
                <p class="small text-muted mb-3">
                    <i class="bi bi-info-circle"></i>
                    <strong>Nova classificação:</strong> identifica em qual etapa o cliente parou de pagar para análise de comportamento e estratégias de reativação.
                </p>

                <div class="row g-2">
                    <!-- Categorias Especiais -->
                    <div class="col-md-3">
                        <div class="p-2 inadimplente-1parcela border rounded text-center" style="background-color: #ffebee;">
                            <small><strong>Apenas 1ª Parcela</strong><br>
                            <span class="text-muted">Pode ser premiação</span></small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-2 inadimplente-2parcelas border rounded text-center" style="background-color: #ffe0b2;">
                            <small><strong>Apenas 2 Parcelas</strong><br>
                            <span class="text-muted">Premiação</span></small>
                        </div>
                    </div>

                    <!-- Novas Categorias por Tempo -->
                    <div class="col-md-3">
                        <div class="p-2 inadimplente-ate3 border rounded text-center" style="background-color: #fff9c4;">
                            <small><strong>Até 3 meses</strong><br>
                            <span class="text-muted">Premiação inicial</span></small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-2 inadimplente-3a6 border rounded text-center" style="background-color: #ffe0b2;">
                            <small><strong>3 a 6 meses</strong><br>
                            <span class="text-muted">Experiência</span></small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-2 inadimplente-6a9 border rounded text-center" style="background-color: #ffcc80;">
                            <small><strong>6 a 9 meses</strong><br>
                            <span class="text-muted">Expectativa</span></small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-2 inadimplente-9a12 border rounded text-center" style="background-color: #ffcdd2;">
                            <small><strong>9 a 12 meses</strong><br>
                            <span class="text-muted">Problema sério</span></small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-2 inadimplente-12mais border rounded text-center" style="background-color: #ef5350; color: white;">
                            <small><strong>Mais de 12 meses</strong><br>
                            <span style="opacity: 0.9;">Crônico</span></small>
                        </div>
                    </div>

                    <!-- Adimplente -->
                    <div class="col-md-3">
                        <div class="p-2 adimplente border rounded text-center" style="background-color: #e8f5e9;">
                            <small><strong>Adimplente</strong><br>
                            <span class="text-muted">Em dia</span></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Resultados -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5><i class="bi bi-table"></i> Resultados (<?php echo number_format($total, 0, ',', '.'); ?> registros)</h5>
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-secondary" onclick="ordenar('nome_titular', '<?php echo $orderDir == 'ASC' ? 'DESC' : 'ASC'; ?>')">
                        <i class="bi bi-sort-alpha-down"></i> Nome
                    </button>
                    <button class="btn btn-outline-secondary" onclick="ordenar('status_inadimplencia', 'ASC')">
                        <i class="bi bi-palette"></i> Cor
                    </button>
                    <button class="btn btn-outline-secondary" onclick="ordenar('data_primeira_venda', 'DESC')">
                        <i class="bi bi-calendar"></i> Data
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Título</th>
                                <th>Titular</th>
                                <th>Promotor</th>
                                <th>Status</th>
                                <th>Inadimplência</th>
                                <th>Parcelas</th>
                                <th>Total Pago</th>
                                <th>Saldo</th>
                                <th>Data Venda</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($titulos as $titulo):
                                // Determinar classe de cor e estilo inline (fallback)
                                $rowClass = '';
                                $rowStyle = '';
                                $status = $titulo['status_inadimplencia'];

                                // CATEGORIAS ESPECIAIS
                                if ($status == 'INADIMPLENTE - Apenas 1ª Parcela') {
                                    $rowClass = 'inadimplente-1parcela';
                                    $rowStyle = 'background-color: #ffebee !important;';
                                } elseif ($status == 'INADIMPLENTE - Apenas 2 Parcelas') {
                                    $rowClass = 'inadimplente-2parcelas';
                                    $rowStyle = 'background-color: #ffe0b2 !important;';
                                }
                                // NOVAS CATEGORIAS POR TEMPO
                                elseif ($status == 'INADIMPLENTE - Até 3 meses') {
                                    $rowClass = 'inadimplente-ate3';
                                    $rowStyle = 'background-color: #fff9c4 !important;';
                                } elseif ($status == 'INADIMPLENTE - 3 a 6 meses') {
                                    $rowClass = 'inadimplente-3a6';
                                    $rowStyle = 'background-color: #ffe0b2 !important;';
                                } elseif ($status == 'INADIMPLENTE - 6 a 9 meses') {
                                    $rowClass = 'inadimplente-6a9';
                                    $rowStyle = 'background-color: #ffcc80 !important;';
                                } elseif ($status == 'INADIMPLENTE - 9 a 12 meses') {
                                    $rowClass = 'inadimplente-9a12';
                                    $rowStyle = 'background-color: #ffcdd2 !important;';
                                } elseif ($status == 'INADIMPLENTE - Mais de 12 meses') {
                                    $rowClass = 'inadimplente-12mais';
                                    $rowStyle = 'background-color: #ef5350 !important; color: white !important;';
                                }
                                // CATEGORIAS ANTIGAS (compatibilidade)
                                elseif ($status == 'INADIMPLENTE - Menos de 50%') {
                                    $rowClass = 'inadimplente-menos50';
                                    $rowStyle = 'background-color: #fff9c4 !important;';
                                } elseif ($status == 'INADIMPLENTE - Mais de 50%') {
                                    $rowClass = 'inadimplente-mais50';
                                    $rowStyle = 'background-color: #ffcdd2 !important;';
                                }
                                // INADIMPLENTE GENÉRICO
                                elseif (strpos($status, 'INADIMPLENTE') !== false) {
                                    $rowClass = 'inadimplente-mais50';
                                    $rowStyle = 'background-color: #ffcdd2 !important;';
                                }
                                // ADIMPLENTE
                                elseif ($status == 'ADIMPLENTE') {
                                    $rowClass = 'adimplente';
                                    $rowStyle = 'background-color: #e8f5e9 !important;';
                                }
                            ?>
                                <tr class="<?php echo $rowClass; ?>" style="<?php echo $rowStyle; ?>">
                                    <td>
                                        <?php echo sanitize($titulo['numero_titulo']); ?><br>
                                        <small class="text-muted"><?php echo sanitize($titulo['nome_produto_atual'] ?? ''); ?></small>
                                    </td>
                                    <td>
                                        <?php echo sanitize($titulo['nome_titular']); ?><br>
                                        <small class="text-muted">
                                            <?php echo sanitize($titulo['documento_titular']); ?>
                                            <?php if (!empty($cpfTituloIndice[$titulo['id']])): ?>
                                                <span class="badge bg-info text-dark">
                                                    <?php echo $cpfTituloIndice[$titulo['id']]['indice']; ?> de <?php echo $cpfTituloIndice[$titulo['id']]['total']; ?>
                                                </span>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td><small><?php echo sanitize($titulo['promotor']); ?></small></td>
                                    <td>
                                        <span class="badge bg-<?php echo getStatusBadgeClass($titulo['status_titulo']); ?>">
                                            <?php echo $titulo['status_titulo']; ?>
                                        </span>
                                    </td>
                                    <td><small><?php echo $titulo['status_inadimplencia']; ?></small></td>
                                    <td><?php echo $titulo['qtd_parcelas_pagas']; ?>/<?php echo $titulo['quantidade_parcelas_venda']; ?></td>
                                    <td><small><?php echo formatCurrency($titulo['total_pago'] ?? 0); ?></small></td>
                                    <td><small><?php echo formatCurrency($titulo['saldo_restante'] ?? 0); ?></small></td>
                                    <td><small><?php echo formatDate($titulo['data_primeira_venda']); ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total > $perPage): ?>
                    <?php
                    $paginationParams = $filtros;
                    unset($paginationParams['page']);
                    echo pagination($total, $perPage, $page, 'relatorios.php?' . http_build_query($paginationParams));
                    ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        // Select2 para autocomplete de promotor
        $(document).ready(function() {
            $('#promotorSelect').select2({
                theme: 'bootstrap-5',
                placeholder: 'Selecione um consultor',
                allowClear: true
            });
        });

        // Função para ordenar
        function ordenar(campo, direcao) {
            const form = document.getElementById('filterForm');
            const input1 = document.createElement('input');
            input1.type = 'hidden';
            input1.name = 'order_by';
            input1.value = campo;

            const input2 = document.createElement('input');
            input2.type = 'hidden';
            input2.name = 'order_dir';
            input2.value = direcao;

            form.appendChild(input1);
            form.appendChild(input2);
            form.submit();
        }

        // Função para converter markdown básico para HTML
        function formatarResumo(texto) {
            // Converter **texto** para <strong>texto</strong>
            texto = texto.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');

            // Converter quebras de linha para <br>
            texto = texto.replace(/\n/g, '<br>');

            // Converter emojis de lista (•) em pontos de lista
            texto = texto.replace(/^• /gm, '&bull; ');

            return texto;
        }

        // Gerar resumo com IA
        function gerarResumoIA() {
            const btn = event.target;
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Gerando...';
            btn.disabled = true;

            fetch('api/gerar_resumo_ia.php?importacao_id=<?php echo $importacaoId; ?>')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const resumoFormatado = formatarResumo(data.resumo);
                        document.getElementById('iaResumo').innerHTML = '<div class="alert alert-light">' + resumoFormatado + '</div>';
                    } else {
                        alert('Erro ao gerar resumo: ' + data.error);
                        btn.innerHTML = originalHTML;
                        btn.disabled = false;
                    }
                })
                .catch(error => {
                    alert('Erro ao gerar resumo: ' + error);
                    btn.innerHTML = originalHTML;
                    btn.disabled = false;
                });
        }
    </script>
</body>
</html>
