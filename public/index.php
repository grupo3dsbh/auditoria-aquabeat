<?php
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAuth('login.php');

// Estatísticas rápidas
$db = Database::getInstance();

// Obter última importação
$ultimaImportacao = $db->fetchOne("SELECT * FROM importacoes ORDER BY criado_em DESC LIMIT 1");

// Estatísticas gerais
$stats = [];

if ($ultimaImportacao) {
    $importacaoId = $ultimaImportacao['id'];

    $stats = [
        'total_titulos' => $db->count('titulos', 'importacao_id = ?', [$importacaoId]),
        'total_inadimplentes' => $db->count('titulos', "importacao_id = ? AND status_inadimplencia LIKE 'INADIMPLENTE%'", [$importacaoId]),
        'total_consultores' => $db->count('analise_consultores', 'importacao_id = ?', [$importacaoId]),
        'total_cartoes_risco' => $db->count('analise_cartoes', "importacao_id = ? AND nivel_risco IN ('ALTO RISCO', 'FRAUDE PROVÁVEL')", [$importacaoId]),
        'valor_perdido' => $db->fetchColumn("SELECT SUM(saldo_restante) FROM titulos WHERE importacao_id = ?", [$importacaoId]) ?? 0,
        'taxa_inadimplencia' => $db->fetchColumn("SELECT COUNT(*) * 100.0 / NULLIF((SELECT COUNT(*) FROM titulos WHERE importacao_id = ?), 0) FROM titulos WHERE importacao_id = ? AND status_inadimplencia LIKE 'INADIMPLENTE%'", [$importacaoId, $importacaoId]) ?? 0
    ];
}

// Top consultores com problema
$topConsultoresProblema = [];
if ($ultimaImportacao) {
    $topConsultoresProblema = $db->fetchAll(
        "SELECT * FROM analise_consultores WHERE importacao_id = ? ORDER BY taxa_inadimplencia_geral DESC LIMIT 5",
        [$importacaoId]
    );
}

// Top cartões de risco
$topCartoesRisco = [];
if ($ultimaImportacao) {
    $topCartoesRisco = $db->fetchAll(
        "SELECT * FROM analise_cartoes WHERE importacao_id = ? AND nivel_risco IN ('ALTO RISCO', 'FRAUDE PROVÁVEL') ORDER BY taxa_inadimplencia_cartao DESC LIMIT 5",
        [$importacaoId]
    );
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
                            <h3><?php echo formatCurrency($stats['valor_perdido']); ?></h3>
                            <small>Saldo restante inadimplentes</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Top Consultores com Problema -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-danger text-white">
                            <h5><i class="bi bi-exclamation-triangle"></i> Top 5 Consultores com Maior Inadimplência</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($topConsultoresProblema)): ?>
                                <p class="text-muted">Nenhum dado disponível.</p>
                            <?php else: ?>
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>Consultor</th>
                                            <th class="text-end">Vendas</th>
                                            <th class="text-end">Taxa Inadimplência</th>
                                            <th class="text-center">Nível</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($topConsultoresProblema as $consultor): ?>
                                            <tr>
                                                <td><?php echo sanitize($consultor['promotor']); ?></td>
                                                <td class="text-end"><?php echo $consultor['total_vendas']; ?></td>
                                                <td class="text-end"><?php echo formatPercentage($consultor['taxa_inadimplencia_geral'], 1); ?></td>
                                                <td class="text-center">
                                                    <span class="badge bg-<?php echo getRiskBadgeClass($consultor['nivel_risco']); ?>">
                                                        <?php echo $consultor['nivel_risco']; ?>
                                                    </span>
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

                <!-- Top Cartões de Risco -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-warning">
                            <h5><i class="bi bi-credit-card"></i> Top 5 Cartões de Alto Risco</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($topCartoesRisco)): ?>
                                <p class="text-muted">Nenhum cartão de risco identificado.</p>
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
                                            <tr>
                                                <td>
                                                    <?php echo sanitize($cartao['numero_cartao']); ?><br>
                                                    <small class="text-muted"><?php echo $cartao['bandeira']; ?></small>
                                                </td>
                                                <td class="text-end"><?php echo $cartao['total_titulos_no_cartao']; ?></td>
                                                <td class="text-end"><?php echo $cartao['total_documentos_no_cartao']; ?></td>
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
