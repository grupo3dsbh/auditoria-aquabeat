<?php
define('APP_ROOT', dirname(dirname(__DIR__)));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAdmin();

$db = Database::getInstance();

// Estatísticas do sistema
$stats = [
    'total_usuarios' => $db->count('usuarios'),
    'total_importacoes' => $db->count('importacoes'),
    'total_titulos' => $db->count('titulos'),
    'total_logs' => $db->count('log_acoes', 'criado_em >= DATE_SUB(NOW(), INTERVAL 7 DAY)')
];

// Últimas ações
$ultimasAcoes = $db->fetchAll(
    "SELECT la.*, u.nome as usuario_nome
     FROM log_acoes la
     LEFT JOIN usuarios u ON la.usuario_id = u.id
     ORDER BY la.criado_em DESC
     LIMIT 20"
);

// Usuários ativos
$usuariosAtivos = $db->fetchAll(
    "SELECT * FROM usuarios WHERE ativo = TRUE ORDER BY ultimo_login DESC LIMIT 10"
);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Admin - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>
    <?php include '../navbar.php'; ?>

    <div class="container-fluid mt-4">
        <h2><i class="bi bi-speedometer2"></i> Painel de Administração</h2>
        <p class="text-muted">Visão geral do sistema</p>

        <!-- Cards de Estatísticas -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-white bg-primary">
                    <div class="card-body">
                        <h6>Usuários</h6>
                        <h3><?php echo $stats['total_usuarios']; ?></h3>
                        <a href="usuarios.php" class="text-white"><small>Ver todos →</small></a>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card text-white bg-info">
                    <div class="card-body">
                        <h6>Importações</h6>
                        <h3><?php echo $stats['total_importacoes']; ?></h3>
                        <a href="../upload.php" class="text-white"><small>Nova importação →</small></a>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card text-white bg-success">
                    <div class="card-body">
                        <h6>Títulos Importados</h6>
                        <h3><?php echo number_format($stats['total_titulos'], 0, ',', '.'); ?></h3>
                        <a href="../relatorios.php" class="text-white"><small>Ver relatórios →</small></a>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card text-white bg-warning">
                    <div class="card-body">
                        <h6>Logs (7 dias)</h6>
                        <h3><?php echo number_format($stats['total_logs'], 0, ',', '.'); ?></h3>
                        <a href="logs.php" class="text-white text-decoration-none"><small>Ver logs →</small></a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Últimas Ações -->
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-clock-history"></i> Últimas Ações do Sistema</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>Data/Hora</th>
                                    <th>Usuário</th>
                                    <th>Ação</th>
                                    <th>Descrição</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ultimasAcoes as $acao): ?>
                                    <tr>
                                        <td><?php echo formatDateTime($acao['criado_em']); ?></td>
                                        <td><?php echo sanitize($acao['usuario_nome'] ?? 'Sistema'); ?></td>
                                        <td><span class="badge bg-secondary"><?php echo $acao['acao']; ?></span></td>
                                        <td><small><?php echo sanitize($acao['descricao']); ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <a href="logs.php" class="btn btn-sm btn-outline-primary">Ver todos os logs</a>
                    </div>
                </div>
            </div>

            <!-- Usuários Ativos -->
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-people"></i> Usuários Ativos</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tbody>
                                <?php foreach ($usuariosAtivos as $usuario): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo sanitize($usuario['nome']); ?></strong><br>
                                            <small class="text-muted"><?php echo sanitize($usuario['email']); ?></small>
                                        </td>
                                        <td class="text-end">
                                            <span class="badge bg-<?php echo $usuario['nivel_acesso'] == 'admin' ? 'danger' : 'primary'; ?>">
                                                <?php echo ucfirst($usuario['nivel_acesso']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <a href="usuarios.php" class="btn btn-sm btn-outline-primary">Gerenciar usuários</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
