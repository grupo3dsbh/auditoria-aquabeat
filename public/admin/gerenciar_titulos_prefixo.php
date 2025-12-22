<?php
define('APP_ROOT', dirname(dirname(__DIR__)));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAuth('admin/login.php');
requireAdmin();

$db = Database::getInstance();

// Processar remoção se solicitado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remover_outros') {
    try {
        $db->beginTransaction();

        // Remover títulos que NÃO são SFA ou SBF
        $deleted = $db->delete('titulos',
            "numero_titulo NOT LIKE 'SFA%' AND numero_titulo NOT LIKE 'SBF%'");

        $db->commit();

        setFlashMessage('success', "Removidos {$deleted} títulos (DIP, SAC, SAP e outros). Apenas SFA e SBF foram mantidos.");
        redirect('gerenciar_titulos_prefixo.php');

    } catch (Exception $e) {
        $db->rollback();
        setFlashMessage('error', 'Erro ao remover títulos: ' . $e->getMessage());
    }
}

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

$pageTitle = 'Gerenciar Títulos por Prefixo';
include APP_ROOT . '/includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row mb-4">
        <div class="col-md-12">
            <h2><i class="bi bi-filter-circle"></i> Gerenciar Títulos por Prefixo</h2>
            <p class="text-muted">Visualize e gerencie títulos por prefixo (SFA, SBF, DIP, SAC, SAP, etc.)</p>
        </div>
    </div>

    <!-- Alerta: Migration necessária -->
    <div class="alert alert-info">
        <h6><i class="bi bi-info-circle"></i> Nova Funcionalidade: Controle de Exibição</h6>
        <p>Agora você pode MANTER todos os títulos no banco (incluindo DIP, SAC, SAP) mas controlar quais aparecem nos relatórios.</p>
        <p class="mb-0">
            <a href="executar_migration_usado_relatorios.php" class="btn btn-sm btn-info">
                <i class="bi bi-database-add"></i> Configurar Controle de Exibição
            </a>
        </p>
    </div>

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
                    <small>DIP, SAC, SAP - NÃO utilizados</small>
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
                                <span class="badge bg-danger"><i class="bi bi-x-circle"></i> NÃO</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Ação de Remoção -->
    <?php if ($totalOutros > 0): ?>
    <div class="card border-danger">
        <div class="card-header bg-danger text-white">
            <h5 class="mb-0"><i class="bi bi-trash"></i> Remover Títulos Não Utilizados</h5>
        </div>
        <div class="card-body">
            <div class="alert alert-warning">
                <h6><i class="bi bi-exclamation-triangle"></i> ATENÇÃO!</h6>
                <p>Esta ação irá <strong>DELETAR PERMANENTEMENTE</strong> todos os títulos que NÃO sejam SFA ou SBF (total de <strong><?php echo number_format($totalOutros, 0, ',', '.'); ?> títulos</strong>).</p>
                <p class="mb-0">Os títulos DIP, SAC, SAP e outros prefixos serão removidos do banco de dados e <strong>NÃO PODERÃO SER RECUPERADOS</strong>.</p>
            </div>

            <form method="POST" onsubmit="return confirm('⚠️ CONFIRMAR REMOÇÃO?\n\nEsta ação irá DELETAR PERMANENTEMENTE <?php echo number_format($totalOutros, 0, ',', '.'); ?> títulos do banco de dados.\n\nApenas títulos SFA e SBF serão mantidos.\n\nDeseja continuar?');">
                <input type="hidden" name="action" value="remover_outros">
                <button type="submit" class="btn btn-danger btn-lg">
                    <i class="bi bi-trash"></i> Remover <?php echo number_format($totalOutros, 0, ',', '.'); ?> Títulos (DIP, SAC, SAP, etc.)
                </button>
            </form>
        </div>
    </div>
    <?php else: ?>
    <div class="alert alert-success">
        <h6><i class="bi bi-check-circle"></i> Banco de Dados Limpo!</h6>
        <p class="mb-0">Não existem títulos com prefixos diferentes de SFA/SBF no banco de dados.</p>
    </div>
    <?php endif; ?>

    <div class="mt-4">
        <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Voltar para Administração</a>
    </div>
</div>

<?php include APP_ROOT . '/includes/footer.php'; ?>
