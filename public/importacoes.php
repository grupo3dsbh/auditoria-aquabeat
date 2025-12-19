<?php
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAdmin();

$db = Database::getInstance();

$importacoes = $db->fetchAll(
    "SELECT i.*, u.nome as usuario_nome
     FROM importacoes i
     LEFT JOIN usuarios u ON i.usuario_id = u.id
     ORDER BY i.criado_em DESC"
);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Histórico de Importações - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container-fluid mt-4">
        <h2><i class="bi bi-list-ul"></i> Histórico de Importações</h2>
        <p class="text-muted">Todas as importações realizadas no sistema</p>

        <div class="card">
            <div class="card-body">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Data</th>
                            <th>Arquivo</th>
                            <th>Tipo</th>
                            <th>Usuário</th>
                            <th>Status</th>
                            <th>Total Linhas</th>
                            <th>Processadas</th>
                            <th>Erros</th>
                            <th>Duração</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($importacoes as $imp): ?>
                            <tr>
                                <td><?php echo $imp['id']; ?></td>
                                <td><?php echo formatDateTime($imp['criado_em']); ?></td>
                                <td><?php echo sanitize($imp['nome_arquivo']); ?></td>
                                <td><span class="badge bg-info"><?php echo ucfirst($imp['tipo_importacao']); ?></span></td>
                                <td><?php echo sanitize($imp['usuario_nome']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo getStatusBadgeClass(ucfirst($imp['status'])); ?>">
                                        <?php echo ucfirst($imp['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo number_format($imp['total_linhas'], 0, ',', '.'); ?></td>
                                <td><?php echo number_format($imp['linhas_processadas'], 0, ',', '.'); ?></td>
                                <td><?php echo number_format($imp['linhas_erro'], 0, ',', '.'); ?></td>
                                <td>
                                    <?php
                                    if ($imp['iniciado_em'] && $imp['concluido_em']) {
                                        $inicio = strtotime($imp['iniciado_em']);
                                        $fim = strtotime($imp['concluido_em']);
                                        $duracao = $fim - $inicio;
                                        echo gmdate('H:i:s', $duracao);
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
