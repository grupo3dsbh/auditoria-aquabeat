<?php
define('APP_ROOT', dirname(dirname(__DIR__)));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAuth('admin/login.php');
requireAdmin();

$db = Database::getInstance();
$executado = false;
$erro = null;

// Verificar se a coluna já existe
try {
    $colunas = $db->fetchAll("SHOW COLUMNS FROM titulos LIKE 'usado_relatorios'");
    $colunaExiste = !empty($colunas);
} catch (Exception $e) {
    $colunaExiste = false;
}

// Processar execução da migration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'executar') {
    try {
        $db->beginTransaction();

        // Adicionar coluna se não existir
        if (!$colunaExiste) {
            $db->query("ALTER TABLE titulos ADD COLUMN usado_relatorios BOOLEAN DEFAULT TRUE");
            Logger::info('Coluna usado_relatorios adicionada');
        }

        // Atualizar títulos SFA e SBF = TRUE
        $db->query("UPDATE titulos SET usado_relatorios = TRUE WHERE (numero_titulo LIKE 'SFA%' OR numero_titulo LIKE 'SBF%')");

        // Atualizar outros prefixos = FALSE
        $db->query("UPDATE titulos SET usado_relatorios = FALSE WHERE numero_titulo NOT LIKE 'SFA%' AND numero_titulo NOT LIKE 'SBF%'");

        // Criar índice se não existir
        try {
            $db->query("CREATE INDEX idx_titulos_usado_relatorios ON titulos(usado_relatorios)");
        } catch (Exception $e) {
            // Índice já existe, ignorar erro
        }

        $db->commit();
        $executado = true;

        setFlashMessage('success', 'Migration executada com sucesso! Coluna "usado_relatorios" adicionada e atualizada.');
        redirect('gerenciar_titulos_prefixo.php');

    } catch (Exception $e) {
        $db->rollback();
        $erro = $e->getMessage();
        Logger::error('Erro ao executar migration usado_relatorios: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Executar Migration - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>
    <?php include '../navbar.php'; ?>

<div class="container-fluid mt-4">
    <div class="row mb-4">
        <div class="col-md-12">
            <h2><i class="bi bi-database-add"></i> Executar Migration - usado_relatorios</h2>
            <p class="text-muted">Adiciona coluna para controlar quais títulos são usados nos relatórios</p>
        </div>
    </div>

    <?php if ($erro): ?>
        <div class="alert alert-danger">
            <h6><i class="bi bi-exclamation-triangle"></i> Erro ao executar migration</h6>
            <p class="mb-0"><?php echo htmlspecialchars($erro); ?></p>
        </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0"><i class="bi bi-info-circle"></i> O que esta migration faz?</h5>
        </div>
        <div class="card-body">
            <p>Esta migration adiciona uma nova coluna <strong>usado_relatorios</strong> na tabela <code>titulos</code> para controlar quais títulos aparecem nos relatórios:</p>

            <ul>
                <li><strong>Títulos SFA e SBF</strong>: <code>usado_relatorios = TRUE</code> (aparecem nos relatórios)</li>
                <li><strong>Títulos DIP, SAC, SAP e outros</strong>: <code>usado_relatorios = FALSE</code> (não aparecem nos relatórios)</li>
            </ul>

            <p class="mb-0"><strong>Vantagem</strong>: Os títulos DIP, SAC, SAP ficam salvos no banco de dados para análises futuras, mas não aparecem nos relatórios atuais.</p>
        </div>
    </div>

    <?php if ($colunaExiste): ?>
        <div class="alert alert-success">
            <h6><i class="bi bi-check-circle"></i> Migration já executada!</h6>
            <p class="mb-0">A coluna <code>usado_relatorios</code> já existe na tabela <code>titulos</code>.</p>
            <hr>
            <p class="mb-0">Você pode atualizar os valores clicando no botão abaixo (marca SFA/SBF como TRUE e outros como FALSE).</p>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="executar">
            <button type="submit" class="btn btn-warning btn-lg" onclick="return confirm('Atualizar valores da coluna usado_relatorios?\n\nSFA/SBF = TRUE\nOutros = FALSE')">
                <i class="bi bi-arrow-repeat"></i> Atualizar Valores
            </button>
        </form>
    <?php else: ?>
        <div class="card border-warning">
            <div class="card-header bg-warning">
                <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Executar Migration</h5>
            </div>
            <div class="card-body">
                <p>A coluna <code>usado_relatorios</code> ainda NÃO foi adicionada ao banco de dados.</p>
                <p>Clique no botão abaixo para executar a migration.</p>

                <form method="POST">
                    <input type="hidden" name="action" value="executar">
                    <button type="submit" class="btn btn-primary btn-lg" onclick="return confirm('Executar migration?\n\nIsso irá:\n1. Adicionar coluna usado_relatorios\n2. Marcar SFA/SBF como TRUE\n3. Marcar outros como FALSE\n4. Criar índice para performance')">
                        <i class="bi bi-play-circle"></i> Executar Migration
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div class="mt-4">
        <a href="gerenciar_titulos_prefixo.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Voltar</a>
        <a href="index.php" class="btn btn-secondary"><i class="bi bi-house"></i> Painel Admin</a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
