<?php
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAuth('login.php');

// Estatísticas rápidas
$db = Database::getInstance();

// Obter última importação
$ultimaImportacao = $db->fetchOne("SELECT * FROM importacoes ORDER BY criado_em DESC LIMIT 1");

// Filtro de período: 01/11/2024 até 2 meses atrás
$dataInicio = '2024-11-01';
$dataFim = date('Y-m-t', strtotime('-2 months')); // Último dia de 2 meses atrás

// Estatísticas gerais
$stats = [];

if ($ultimaImportacao) {
    $importacaoId = $ultimaImportacao['id'];

    $stats = [
        'total_titulos' => $db->fetchColumn(
            "SELECT COUNT(*) FROM titulos WHERE importacao_id = ? AND data_primeira_venda BETWEEN ? AND ?",
            [$importacaoId, $dataInicio . ' 00:00:00', $dataFim . ' 23:59:59']
        ) ?? 0,
        'total_inadimplentes' => $db->fetchColumn(
            "SELECT COUNT(*) FROM titulos WHERE importacao_id = ? AND status_inadimplencia LIKE 'INADIMPLENTE%' AND data_primeira_venda BETWEEN ? AND ?",
            [$importacaoId, $dataInicio . ' 00:00:00', $dataFim . ' 23:59:59']
        ) ?? 0,
        'total_consultores' => $db->fetchColumn(
            "SELECT COUNT(DISTINCT promotor) FROM titulos WHERE importacao_id = ? AND data_primeira_venda BETWEEN ? AND ? AND promotor IS NOT NULL",
            [$importacaoId, $dataInicio . ' 00:00:00', $dataFim . ' 23:59:59']
        ) ?? 0,
        'total_cartoes_risco' => $db->count('analise_cartoes', "importacao_id = ? AND nivel_risco IN ('ALTO RISCO', 'FRAUDE PROVÁVEL')", [$importacaoId]),
        'valor_em_risco' => $db->fetchColumn(
            "SELECT SUM(saldo_restante) FROM titulos WHERE importacao_id = ? AND status_inadimplencia LIKE 'INADIMPLENTE%' AND data_primeira_venda BETWEEN ? AND ?",
            [$importacaoId, $dataInicio . ' 00:00:00', $dataFim . ' 23:59:59']
        ) ?? 0,
        'taxa_inadimplencia' => 0
    ];

    // Calcular taxa de inadimplência
    if ($stats['total_titulos'] > 0) {
        $stats['taxa_inadimplencia'] = ($stats['total_inadimplentes'] / $stats['total_titulos']) * 100;
    }
}

// Top consultores com maior inadimplência (apenas consultores com 3+ vendas)
$topConsultoresProblema = [];
if ($ultimaImportacao) {
    $topConsultoresProblema = $db->fetchAll("
        SELECT
            promotor,
            COUNT(*) as total_vendas,
            COUNT(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%'
                  AND status_inadimplencia NOT LIKE '%Requer análise%' THEN 1 END) as total_inadimplentes,
            ROUND(COUNT(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%'
                  AND status_inadimplencia NOT LIKE '%Requer análise%' THEN 1 END) * 100.0 / COUNT(*), 2) as taxa_inadimplencia,
            -- Primeira parcela paga (todos, para destacar coluna separada)
            COUNT(CASE WHEN status_inadimplencia LIKE '%1ª parcela%' THEN 1 END) as apenas_1a_parcela,
            CASE
                WHEN COUNT(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%'
                      AND status_inadimplencia NOT LIKE '%Requer análise%' THEN 1 END) * 100.0 / COUNT(*) >= 50 THEN 'ALTO RISCO'
                WHEN COUNT(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%'
                      AND status_inadimplencia NOT LIKE '%Requer análise%' THEN 1 END) * 100.0 / COUNT(*) >= 30 THEN 'MÉDIO RISCO'
                ELSE 'BAIXO'
            END as nivel_risco
        FROM titulos
        WHERE importacao_id = ?
          AND data_primeira_venda BETWEEN ? AND ?
          AND promotor IS NOT NULL
        GROUP BY promotor
        HAVING COUNT(*) >= 3 AND total_inadimplentes > 0
        ORDER BY total_inadimplentes DESC, taxa_inadimplencia DESC
        LIMIT 10
    ", [$importacaoId, $dataInicio . ' 00:00:00', $dataFim . ' 23:59:59']);
}

// Top cartões duplicados (usados em múltiplos títulos) - INDICADOR DE FRAUDE
$topCartoesRisco = [];
if ($ultimaImportacao) {
    $topCartoesRisco = $db->fetchAll("
        SELECT
            numero_cartao,
            bandeira_cartao as bandeira,
            COUNT(*) as total_titulos,
            COUNT(DISTINCT titular_cpf) as total_documentos,
            GROUP_CONCAT(DISTINCT promotor SEPARATOR ', ') as consultores,
            CASE
                WHEN bandeira_cartao LIKE '%DEBITO%' OR bandeira_cartao LIKE '%DEBIT%' THEN 'DÉBITO'
                WHEN bandeira_cartao LIKE '%CREDITO%' OR bandeira_cartao LIKE '%CREDIT%' THEN 'CRÉDITO'
                ELSE 'DESCONHECIDO'
            END as tipo_cartao,
            CASE
                WHEN COUNT(*) >= 10 THEN 'ALTO RISCO'
                WHEN COUNT(*) >= 5 THEN 'MÉDIO RISCO'
                ELSE 'BAIXO RISCO'
            END as nivel_risco
        FROM titulos
        WHERE importacao_id = ?
          AND data_primeira_venda BETWEEN ? AND ?
          AND numero_cartao IS NOT NULL
          AND numero_cartao != ''
          AND numero_cartao != 'NULL'
        GROUP BY numero_cartao, bandeira_cartao
        HAVING COUNT(*) >= 2
        ORDER BY
            CASE WHEN bandeira_cartao LIKE '%DEBITO%' OR bandeira_cartao LIKE '%DEBIT%' THEN 0 ELSE 1 END,
            COUNT(*) DESC
        LIMIT 5
    ", [$importacaoId, $dataInicio . ' 00:00:00', $dataFim . ' 23:59:59']);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container-fluid mt-4">
        <div class="row mb-4">
            <div class="col-md-12">
                <h2><i class="bi bi-speedometer2"></i> Dashboard</h2>
                <p class="text-muted">Visão geral da análise de inadimplência</p>
            </div>
        </div>

        <?php if (!$ultimaImportacao): ?>
            <div class="alert alert-info">
                <h4><i class="bi bi-info-circle"></i> Bem-vindo!</h4>
                <p>Nenhuma importação encontrada. Para começar, faça o upload de um arquivo CSV com os dados de títulos.</p>
                <a href="upload.php" class="btn btn-primary">
                    <i class="bi bi-cloud-upload"></i> Importar CSV
                </a>
            </div>
        <?php else: ?>
            <!-- Cards de Estatísticas -->
            <div class="row mb-4">
                <div class="col-md-2">
                    <div class="card text-white bg-primary">
                        <div class="card-body">
                            <h6 class="card-title">Total de Títulos</h6>
                            <h3><?php echo number_format($stats['total_titulos'], 0, ',', '.'); ?></h3>
                        </div>
                    </div>
                </div>

                <div class="col-md-2">
                    <div class="card text-white bg-danger">
                        <div class="card-body">
                            <h6 class="card-title">Inadimplentes</h6>
                            <h3><?php echo number_format($stats['total_inadimplentes'], 0, ',', '.'); ?></h3>
                            <small><?php echo formatPercentage($stats['taxa_inadimplencia'], 1); ?></small>
                        </div>
                    </div>
                </div>

                <div class="col-md-2">
                    <div class="card text-white bg-info">
                        <div class="card-body">
                            <h6 class="card-title">Consultores</h6>
                            <h3><?php echo number_format($stats['total_consultores'], 0, ',', '.'); ?></h3>
                        </div>
                    </div>
                </div>

                <div class="col-md-2">
                    <div class="card text-white bg-warning">
                        <div class="card-body">
                            <h6 class="card-title">Cartões de Risco</h6>
                            <h3><?php echo number_format($stats['total_cartoes_risco'], 0, ',', '.'); ?></h3>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card text-white bg-dark">
                        <div class="card-body">
                            <h6 class="card-title">Valor em Risco</h6>
                            <h3><?php echo formatCurrency($stats['valor_em_risco']); ?></h3>
                            <small>Saldo restante inadimplentes</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Top Consultores com Maior Inadimplência -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-danger text-white">
                            <h5><i class="bi bi-trophy"></i> Top 10 Consultores com Inadimplência</h5>
                        </div>
                        <div class="card-body" style="max-height: 500px; overflow-y: auto;">
                            <?php if (empty($topConsultoresProblema)): ?>
                                <p class="text-muted">Nenhum dado disponível.</p>
                            <?php else: ?>
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Consultor</th>
                                            <th class="text-end">Inadimp.</th>
                                            <th class="text-end">Taxa</th>
                                            <th class="text-center">1ª Parc.</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $rank = 1; foreach ($topConsultoresProblema as $consultor): ?>
                                            <tr>
                                                <td><?php echo $rank++; ?></td>
                                                <td>
                                                    <a href="relatorios.php?promotor=<?php echo urlencode($consultor['promotor']); ?>&status_inadimplencia=INADIMPLENTE">
                                                        <?php echo sanitize($consultor['promotor']); ?>
                                                    </a>
                                                </td>
                                                <td class="text-end">
                                                    <span class="badge bg-danger">
                                                        <?php echo $consultor['total_inadimplentes']; ?>/<?php echo $consultor['total_vendas']; ?>
                                                    </span>
                                                </td>
                                                <td class="text-end"><?php echo formatPercentage($consultor['taxa_inadimplencia'], 1); ?></td>
                                                <td class="text-center">
                                                    <?php if ($consultor['apenas_1a_parcela'] > 0): ?>
                                                        <span class="text-muted">-</span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <a href="relatorios.php?view=consultores" class="btn btn-sm btn-outline-primary">
                                    Ver Todos <i class="bi bi-arrow-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Top Cartões Duplicados (Indicador de Fraude) -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-warning">
                            <h5><i class="bi bi-credit-card"></i> Top 5 Cartões de Alto Risco</h5>
                            <small class="text-dark">Cartões usados em múltiplos títulos</small>
                        </div>
                        <div class="card-body" style="max-height: 500px; overflow-y: auto;">
                            <?php if (empty($topCartoesRisco)): ?>
                                <p class="text-muted">Nenhum cartão duplicado identificado.</p>
                            <?php else: ?>
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>Cartão</th>
                                            <th class="text-end">Títulos</th>
                                            <th class="text-end">Docs</th>
                                            <th class="text-center">Risco</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($topCartoesRisco as $cartao): ?>
                                            <tr <?php if ($cartao['tipo_cartao'] == 'DÉBITO'): ?>class="table-danger"<?php endif; ?>>
                                                <td>
                                                    <a href="relatorios.php?view=cartoes&numero_cartao=<?php echo urlencode($cartao['numero_cartao']); ?>">
                                                        <?php echo sanitize($cartao['numero_cartao']); ?>
                                                    </a><br>
                                                    <small class="text-muted"><?php echo $cartao['bandeira']; ?></small>
                                                    <?php if ($cartao['tipo_cartao'] == 'DÉBITO'): ?>
                                                        <span class="badge bg-danger">DÉBITO</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end"><?php echo $cartao['total_titulos']; ?></td>
                                                <td class="text-end"><?php echo $cartao['total_documentos']; ?></td>
                                                <td class="text-center">
                                                    <span class="badge bg-<?php echo getRiskBadgeClass($cartao['nivel_risco']); ?>">
                                                        <?php echo $cartao['nivel_risco']; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <a href="relatorios.php?view=cartoes" class="btn btn-sm btn-outline-primary">
                                    Ver Todos <i class="bi bi-arrow-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="bi bi-file-earmark-text"></i> Última Importação</h5>
                        </div>
                        <div class="card-body">
                            <dl class="row">
                                <dt class="col-sm-2">Arquivo:</dt>
                                <dd class="col-sm-10"><?php echo sanitize($ultimaImportacao['nome_arquivo']); ?></dd>

                                <dt class="col-sm-2">Data:</dt>
                                <dd class="col-sm-10"><?php echo formatDateTime($ultimaImportacao['criado_em']); ?></dd>

                                <dt class="col-sm-2">Status:</dt>
                                <dd class="col-sm-10">
                                    <span class="badge bg-<?php echo getStatusBadgeClass(ucfirst($ultimaImportacao['status'])); ?>">
                                        <?php echo ucfirst($ultimaImportacao['status']); ?>
                                    </span>
                                </dd>

                                <dt class="col-sm-2">Registros:</dt>
                                <dd class="col-sm-10">
                                    <?php echo number_format($ultimaImportacao['linhas_processadas'], 0, ',', '.'); ?> de
                                    <?php echo number_format($ultimaImportacao['total_linhas'], 0, ',', '.'); ?> processados
                                </dd>
                            </dl>

                            <a href="relatorios.php" class="btn btn-primary">
                                <i class="bi bi-file-earmark-bar-graph"></i> Ir para Relatórios
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
