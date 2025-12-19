<?php
define('APP_ROOT', dirname(dirname(__DIR__)));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAuth();

$db = Database::getInstance();

// Filtros
$filtros = [];
$where = [];
$params = [];

// Se não é admin, só pode ver seus próprios logs
if (!Auth::isAdmin()) {
    $where[] = 'la.usuario_id = ?';
    $params[] = Auth::userId();
}

if (!empty($_GET['acao'])) {
    $where[] = 'la.acao = ?';
    $params[] = $_GET['acao'];
    $filtros['acao'] = $_GET['acao'];
}

if (!empty($_GET['usuario_id']) && Auth::isAdmin()) {
    $where[] = 'la.usuario_id = ?';
    $params[] = $_GET['usuario_id'];
    $filtros['usuario_id'] = $_GET['usuario_id'];
}

if (!empty($_GET['data_inicio'])) {
    $where[] = 'la.criado_em >= ?';
    $params[] = $_GET['data_inicio'] . ' 00:00:00';
    $filtros['data_inicio'] = $_GET['data_inicio'];
}

if (!empty($_GET['data_fim'])) {
    $where[] = 'la.criado_em <= ?';
    $params[] = $_GET['data_fim'] . ' 23:59:59';
    $filtros['data_fim'] = $_GET['data_fim'];
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Paginação
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Contar total
$totalSql = "SELECT COUNT(*) FROM log_acoes la {$whereClause}";
$total = $db->fetchColumn($totalSql, $params);

// Buscar logs
$sql = "SELECT la.*, u.nome as usuario_nome, u.email as usuario_email
        FROM log_acoes la
        LEFT JOIN usuarios u ON la.usuario_id = u.id
        {$whereClause}
        ORDER BY la.criado_em DESC
        LIMIT ? OFFSET ?";

$params[] = $perPage;
$params[] = $offset;

$logs = $db->fetchAll($sql, $params);

// Usuários para filtro (só admin)
$usuarios = [];
if (Auth::isAdmin()) {
    $usuarios = $db->fetchAll("SELECT id, nome FROM usuarios ORDER BY nome");
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Logs do Sistema - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>
    <?php include '../navbar.php'; ?>

    <div class="container-fluid mt-4">
        <h2><i class="bi bi-clock-history"></i> Logs do Sistema</h2>
        <p class="text-muted">
            <?php echo Auth::isAdmin() ? 'Visualizando todos os logs' : 'Visualizando apenas seus logs'; ?>
        </p>

        <!-- Filtros -->
        <div class="card mb-4">
            <div class="card-header">
                <h5><i class="bi bi-funnel"></i> Filtros</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <?php if (Auth::isAdmin()): ?>
                    <div class="col-md-3">
                        <label class="form-label">Usuário</label>
                        <select name="usuario_id" class="form-select">
                            <option value="">Todos</option>
                            <?php foreach ($usuarios as $usuario): ?>
                                <option value="<?php echo $usuario['id']; ?>" <?php echo ($filtros['usuario_id'] ?? '') == $usuario['id'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize($usuario['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="col-md-2">
                        <label class="form-label">Data Início</label>
                        <input type="date" name="data_inicio" class="form-control" value="<?php echo $filtros['data_inicio'] ?? ''; ?>">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Data Fim</label>
                        <input type="date" name="data_fim" class="form-control" value="<?php echo $filtros['data_fim'] ?? ''; ?>">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <div>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search"></i> Filtrar
                            </button>
                            <a href="<?php echo url('admin/logs.php'); ?>" class="btn btn-secondary">
                                <i class="bi bi-x-circle"></i>
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Resultados -->
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-list"></i> Registros (<?php echo number_format($total, 0, ',', '.'); ?>)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Data/Hora</th>
                                <th>Usuário</th>
                                <th>Ação</th>
                                <th>Descrição</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?php echo formatDateTime($log['criado_em']); ?></td>
                                    <td>
                                        <?php echo sanitize($log['usuario_nome'] ?? 'Sistema'); ?><br>
                                        <small class="text-muted"><?php echo sanitize($log['usuario_email'] ?? ''); ?></small>
                                    </td>
                                    <td><span class="badge bg-secondary"><?php echo $log['acao']; ?></span></td>
                                    <td><small><?php echo sanitize($log['descricao']); ?></small></td>
                                    <td><small><?php echo $log['ip_address']; ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total > $perPage): ?>
                    <?php echo pagination($total, $perPage, $page, 'logs.php?' . http_build_query($filtros)); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
