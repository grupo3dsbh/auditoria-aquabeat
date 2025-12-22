<?php
define('APP_ROOT', dirname(dirname(__DIR__)));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAuth('admin/login.php');
requireAdmin();

$db = Database::getInstance();

// Buscar estatísticas por prefixo
$stats = $db->fetchAll("
    SELECT
        SUBSTRING(numero_titulo, 1, 3) as prefixo,
        COUNT(*) as total,
        SUM(valor_total_plano) as valor_total,
        COUNT(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 END) as inadimplentes
    FROM titulos
    GROUP BY prefixo
    ORDER BY total DESC
");

$totalSFA_SBF = $db->fetchColumn("
    SELECT COUNT(*)
    FROM titulos
    WHERE (numero_titulo LIKE 'SFA%' OR numero_titulo LIKE 'SBF%')
");

$totalOutros = $db->fetchColumn("
    SELECT COUNT(*)
    FROM titulos
    WHERE numero_titulo NOT LIKE 'SFA%' AND numero_titulo NOT LIKE 'SBF%'
");

$totalGeral = $db->fetchColumn("SELECT COUNT(*) FROM titulos");

// Verificar se a coluna usado_relatorios existe
try {
    $colunaExiste = $db->fetchColumn("SHOW COLUMNS FROM titulos LIKE 'usado_relatorios'");
} catch (Exception $e) {
    $colunaExiste = false;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Títulos por Prefixo - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>
    <?php include '../navbar.php'; ?>

<div class="container-fluid mt-4">
    <div class="row mb-4">
        <div class="col-md-12">
            <h2><i class="bi bi-filter-circle"></i> Gerenciar Títulos por Prefixo</h2>
            <p class="text-muted">Visualize e gerencie títulos por prefixo (SFA, SBF, DIP, SAC, SAP, etc.)</p>
        </div>
    </div>

    <!-- Alerta: Migration necessária -->
    <?php if (!$colunaExiste): ?>
    <div class="alert alert-info">
        <h6><i class="bi bi-info-circle"></i> Nova Funcionalidade: Controle de Exibição</h6>
        <p>Agora você pode MANTER todos os títulos no banco (incluindo DIP, SAC, SAP) mas controlar quais aparecem nos relatórios.</p>
        <p class="mb-0">
            <a href="executar_migration_usado_relatorios.php" class="btn btn-sm btn-info">
                <i class="bi bi-database-add"></i> Configurar Controle de Exibição
            </a>
        </p>
    </div>
    <?php else: ?>
    <div class="alert alert-success">
        <h6><i class="bi bi-check-circle"></i> Controle de Exibição Configurado!</h6>
        <p class="mb-0">A coluna <code>usado_relatorios</code> está ativa. Os relatórios exibem apenas títulos marcados como TRUE.</p>
    </div>
    <?php endif; ?>

    <!-- Resumo Geral -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h6><i class="bi bi-check-circle"></i> Títulos SFA/SBF</h6>
                    <h3><?php echo number_format($totalSFA_SBF, 0, ',', '.'); ?></h3>
                    <small>Utilizados nos relatórios</small>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-warning text-dark">
                <div class="card-body">
                    <h6><i class="bi bi-exclamation-triangle"></i> Outros Prefixos</h6>
                    <h3><?php echo number_format($totalOutros, 0, ',', '.'); ?></h3>
                    <small>DIP, SAC, SAP - <?php echo $colunaExiste ? 'Ocultos dos relatórios' : 'NÃO utilizados'; ?></small>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h6><i class="bi bi-database"></i> Total Geral</h6>
                    <h3><?php echo number_format($totalGeral, 0, ',', '.'); ?></h3>
                    <small>Todos os títulos no banco</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabela de Estatísticas -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="bi bi-table"></i> Detalhamento por Prefixo</h5>
        </div>
        <div class="card-body">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Prefixo</th>
                        <th>Total de Títulos</th>
                        <th>Inadimplentes</th>
                        <th>Valor Total</th>
                        <th>Usado no Relatório?</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats as $row): ?>
                    <tr class="<?php echo in_array($row['prefixo'], ['SFA', 'SBF']) ? 'table-success' : 'table-warning'; ?>">
                        <td><strong><?php echo htmlspecialchars($row['prefixo']); ?></strong></td>
                        <td><?php echo number_format($row['total'], 0, ',', '.'); ?></td>
                        <td><?php echo number_format($row['inadimplentes'], 0, ',', '.'); ?></td>
                        <td>R$ <?php echo number_format($row['valor_total'], 2, ',', '.'); ?></td>
                        <td>
                            <?php if (in_array($row['prefixo'], ['SFA', 'SBF'])): ?>
                                <span class="badge bg-success"><i class="bi bi-check-circle"></i> SIM</span>
                            <?php else: ?>
                                <span class="badge bg-secondary"><i class="bi bi-x-circle"></i> NÃO</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Informações sobre o Sistema -->
    <div class="card mb-4 border-info">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0"><i class="bi bi-info-circle"></i> Como Funciona</h5>
        </div>
        <div class="card-body">
            <h6>Sistema de Controle de Exibição</h6>
            <p>Com a coluna <code>usado_relatorios</code>, você pode:</p>
            <ul>
                <li><strong>Manter</strong> todos os títulos salvos no banco de dados</li>
                <li><strong>Controlar</strong> quais prefixos aparecem nos relatórios e análises de IA</li>
                <li><strong>Analisar</strong> dados históricos de DIP, SAC, SAP quando necessário</li>
            </ul>

            <h6 class="mt-3">Configuração Atual:</h6>
            <?php if ($colunaExiste): ?>
                <div class="alert alert-success mb-0">
                    <i class="bi bi-check-circle"></i> <strong>Ativo</strong> - Apenas títulos SFA e SBF aparecem nos relatórios
                </div>
            <?php else: ?>
                <div class="alert alert-warning mb-0">
                    <i class="bi bi-exclamation-triangle"></i> <strong>Não Configurado</strong> -
                    <a href="executar_migration_usado_relatorios.php">Clique aqui para configurar</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="mt-4">
        <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Voltar para Administração</a>
        <?php if (!$colunaExiste): ?>
        <a href="executar_migration_usado_relatorios.php" class="btn btn-info">
            <i class="bi bi-database-add"></i> Configurar Controle de Exibição
        </a>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
