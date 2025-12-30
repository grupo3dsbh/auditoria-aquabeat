<?php
define('APP_ROOT', dirname(dirname(__DIR__)));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAdmin();

$error = null;
$success = null;

// Processar exclusão
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deletar'])) {
    try {
        $importacaoId = $_POST['importacao_id'] ?? null;

        if (!$importacaoId) {
            throw new Exception('ID da importação não fornecido.');
        }

        $db = Database::getInstance();

        // Buscar informações da importação
        $importacao = $db->fetchOne(
            "SELECT * FROM importacoes WHERE id = ?",
            [$importacaoId]
        );

        if (!$importacao) {
            throw new Exception('Importação não encontrada.');
        }

        // Contar títulos relacionados
        $totalTitulos = $db->fetchColumn(
            "SELECT COUNT(*) FROM titulos WHERE importacao_id = ?",
            [$importacaoId]
        );

        // Iniciar transação
        $db->beginTransaction();

        // Deletar títulos (cascade vai deletar análises)
        $db->query("DELETE FROM titulos WHERE importacao_id = ?", [$importacaoId]);

        // Deletar análises de consultores
        $db->query("DELETE FROM analise_consultores WHERE importacao_id = ?", [$importacaoId]);

        // Deletar análises de cartões
        $db->query("DELETE FROM analise_cartoes WHERE importacao_id = ?", [$importacaoId]);

        // Deletar arquivo CSV se existir
        if ($importacao['caminho_arquivo'] && file_exists($importacao['caminho_arquivo'])) {
            deleteFile($importacao['caminho_arquivo']);
        }

        // Deletar importação
        $db->query("DELETE FROM importacoes WHERE id = ?", [$importacaoId]);

        $db->commit();

        Logger::logAction(
            Auth::userId(),
            'delete_import',
            "Deletou importação #{$importacaoId} com {$totalTitulos} títulos",
            'importacoes',
            $importacaoId
        );

        $success = "Importação #{$importacaoId} deletada com sucesso! {$totalTitulos} títulos removidos.";

    } catch (Exception $e) {
        if (isset($db)) {
            $db->rollback();
        }
        $error = $e->getMessage();
        Logger::error("Erro ao deletar importação: " . $e->getMessage());
    }
}

// Processar exclusão de TODAS as importações
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deletar_todas'])) {
    try {
        $db = Database::getInstance();

        // Contar totais
        $totalImportacoes = $db->fetchColumn("SELECT COUNT(*) FROM importacoes");
        $totalTitulos = $db->fetchColumn("SELECT COUNT(*) FROM titulos");

        // Iniciar transação
        $db->beginTransaction();

        // Deletar TUDO (na ordem correta por causa das foreign keys)
        $db->query("DELETE FROM analise_consultores");
        $db->query("DELETE FROM analise_cartoes");
        $db->query("DELETE FROM titulos");

        // Deletar arquivos CSV
        $importacoes = $db->fetchAll("SELECT caminho_arquivo FROM importacoes");
        foreach ($importacoes as $imp) {
            if ($imp['caminho_arquivo'] && file_exists($imp['caminho_arquivo'])) {
                deleteFile($imp['caminho_arquivo']);
            }
        }

        $db->query("DELETE FROM importacoes");

        $db->commit();

        Logger::logAction(
            Auth::userId(),
            'delete_all_imports',
            "Deletou TODAS as importações ({$totalImportacoes}) e títulos ({$totalTitulos})",
            'importacoes',
            null
        );

        $success = "TODAS as importações deletadas! {$totalImportacoes} importações e {$totalTitulos} títulos removidos.";

    } catch (Exception $e) {
        if (isset($db)) {
            $db->rollback();
        }
        $error = $e->getMessage();
        Logger::error("Erro ao deletar todas importações: " . $e->getMessage());
    }
}

// Buscar todas as importações
$db = Database::getInstance();

$importacoes = $db->fetchAll("
    SELECT
        i.*,
        COUNT(t.id) as total_titulos,
        SUM(CASE WHEN t.status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 ELSE 0 END) as inadimplentes
    FROM importacoes i
    LEFT JOIN titulos t ON t.importacao_id = i.id
    GROUP BY i.id
    ORDER BY i.criado_em DESC
");

$totalGeralImportacoes = count($importacoes);
$totalGeralTitulos = $db->fetchColumn("SELECT COUNT(*) FROM titulos");

$error = $error ?? getFlashMessage('error');
$success = $success ?? getFlashMessage('success');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Importações - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>
    <?php include dirname(__DIR__) . '/navbar.php'; ?>

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="bi bi-trash"></i> Gerenciar Importações</h2>
                    <a href="../index.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Voltar
                    </a>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle"></i> <?php echo sanitize($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle"></i> <?php echo sanitize($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Estatísticas Gerais -->
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-bar-chart"></i> Estatísticas Gerais</h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-6">
                                <h3 class="text-primary"><?php echo number_format($totalGeralImportacoes, 0, ',', '.'); ?></h3>
                                <p class="text-muted">Total de Importações</p>
                            </div>
                            <div class="col-md-6">
                                <h3 class="text-success"><?php echo number_format($totalGeralTitulos, 0, ',', '.'); ?></h3>
                                <p class="text-muted">Total de Títulos</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Ação Perigosa: Deletar TODAS -->
                <?php if ($totalGeralImportacoes > 0): ?>
                <div class="card mb-4 border-danger">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Zona de Perigo</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-danger"><strong>ATENÇÃO:</strong> Esta ação irá deletar <strong>TODAS</strong> as importações e todos os títulos do sistema. Esta operação <strong>NÃO PODE SER DESFEITA!</strong></p>

                        <form method="POST" onsubmit="return confirm('⚠️ ATENÇÃO!\n\nVocê está prestes a deletar:\n- <?php echo $totalGeralImportacoes; ?> importações\n- <?php echo number_format($totalGeralTitulos, 0, ',', '.'); ?> títulos\n- Todas as análises de consultores\n- Todas as análises de cartões\n\nEsta ação NÃO PODE SER DESFEITA!\n\nTem CERTEZA ABSOLUTA que deseja continuar?\n\nDigite OK para confirmar:') && prompt('Digite OK para confirmar:') === 'OK';">
                            <button type="submit" name="deletar_todas" class="btn btn-danger">
                                <i class="bi bi-trash3"></i> Deletar TODAS as Importações
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Lista de Importações -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-list"></i> Importações</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($importacoes)): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> Nenhuma importação encontrada.
                                <a href="../upload.php" class="alert-link">Fazer primeira importação</a>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Arquivo</th>
                                            <th>Data</th>
                                            <th>Status</th>
                                            <th class="text-end">Títulos</th>
                                            <th class="text-end">Inadimplentes</th>
                                            <th class="text-end">Taxa</th>
                                            <th class="text-end">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($importacoes as $imp): ?>
                                            <tr>
                                                <td><?php echo $imp['id']; ?></td>
                                                <td>
                                                    <small class="text-muted"><?php echo sanitize($imp['nome_arquivo']); ?></small>
                                                </td>
                                                <td><?php echo formatDateTime($imp['criado_em']); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo getStatusBadgeClass(ucfirst($imp['status'])); ?>">
                                                        <?php echo ucfirst($imp['status']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <?php echo number_format($imp['total_titulos'], 0, ',', '.'); ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php echo number_format($imp['inadimplentes'], 0, ',', '.'); ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php
                                                    $taxa = $imp['total_titulos'] > 0 ? ($imp['inadimplentes'] / $imp['total_titulos']) * 100 : 0;
                                                    $badgeClass = $taxa >= 50 ? 'danger' : ($taxa >= 30 ? 'warning' : 'success');
                                                    ?>
                                                    <span class="badge bg-<?php echo $badgeClass; ?>">
                                                        <?php echo number_format($taxa, 2, ',', '.'); ?>%
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="reprocessar_importacao.php?importacao_id=<?php echo $imp['id']; ?>"
                                                           class="btn btn-warning"
                                                           title="Reprocessar importação (atualizar cartões e análises)">
                                                            <i class="bi bi-arrow-repeat"></i> Reprocessar
                                                        </a>
                                                        <button type="button"
                                                                class="btn btn-danger"
                                                                onclick="if(confirm('Tem certeza que deseja deletar esta importação?\n\nImportação: #<?php echo $imp['id']; ?>\nArquivo: <?php echo addslashes($imp['nome_arquivo']); ?>\nTítulos: <?php echo number_format($imp['total_titulos'] ?? 0, 0, ',', '.'); ?>\n\nEsta ação NÃO PODE SER DESFEITA!')) { deletarImportacao(<?php echo $imp['id']; ?>); }">
                                                            <i class="bi bi-trash"></i> Deletar
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function deletarImportacao(id) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `<input type="hidden" name="importacao_id" value="${id}"><input type="hidden" name="deletar" value="1">`;
            document.body.appendChild(form);
            form.submit();
        }
    </script>
</body>
</html>
