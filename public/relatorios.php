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

// Filtros
$filtros = [];
$where = ["importacao_id = ?"];
$params = [$importacaoId];

// Filtro padrão: Data desde 01/11/2024
$dataInicio = !empty($_GET['data_inicio']) ? $_GET['data_inicio'] : date('Y-m-01', strtotime('-2 months'));
$dataFim = !empty($_GET['data_fim']) ? $_GET['data_fim'] : date('Y-m-d');

$where[] = "data_primeira_venda >= ?";
$params[] = $dataInicio . ' 00:00:00';
$filtros['data_inicio'] = $dataInicio;

if (!empty($_GET['data_fim'])) {
    $where[] = "data_primeira_venda <= ?";
    $params[] = $dataFim . ' 23:59:59';
    $filtros['data_fim'] = $dataFim;
}

// Filtro por prefixo do título (padrão: SBF, SFA)
$prefixos = !empty($_GET['prefixos']) ? $_GET['prefixos'] : ['SBF', 'SFA'];
if (!empty($prefixos)) {
    $prefixoConditions = [];
    foreach ($prefixos as $prefixo) {
        $prefixoConditions[] = "numero_titulo LIKE ?";
        $params[] = $prefixo . '%';
    }
    $where[] = '(' . implode(' OR ', $prefixoConditions) . ')';
    $filtros['prefixos'] = $prefixos;
}

if (!empty($_GET['status_titulo'])) {
    $where[] = "status_titulo = ?";
    $params[] = $_GET['status_titulo'];
    $filtros['status_titulo'] = $_GET['status_titulo'];
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
    $where[] = "promotor LIKE ?";
    $params[] = '%' . $_GET['promotor'] . '%';
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
        ORDER BY data_primeira_venda DESC
        LIMIT ? OFFSET ?";

$params[] = $perPage;
$params[] = $offset;

$titulos = $db->fetchAll($sql, $params);

// Estatísticas
$stats = $db->fetchOne("
    SELECT
        COUNT(*) as total,
        COUNT(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 END) as inadimplentes,
        SUM(saldo_restante) as valor_perdido
    FROM titulos
    WHERE " . implode(' AND ', array_slice($where, 0, count($where))),
    array_slice($params, 0, count($params) - 2)
);

// Buscar lista de promotores para autocomplete
$promotores = $db->fetchAll("SELECT DISTINCT promotor FROM titulos WHERE promotor IS NOT NULL AND importacao_id = ? ORDER BY promotor", [$importacaoId]);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatórios - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        @media print {
            .navbar, .card-header button, .btn, form, .pagination { display: none !important; }
            .card { border: none !important; box-shadow: none !important; }
            body { font-size: 10pt; }
            table { font-size: 9pt; }
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container-fluid mt-4">
        <h2><i class="bi bi-file-earmark-bar-graph"></i> Relatórios de Inadimplência</h2>
        <p class="text-muted">Importação: <?php echo sanitize($ultimaImportacao['nome_arquivo']); ?> - <?php echo formatDateTime($ultimaImportacao['concluido_em']); ?></p>

        <!-- Estatísticas -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card text-white bg-primary">
                    <div class="card-body">
                        <h6>Total de Títulos</h6>
                        <h3><?php echo number_format($stats['total'], 0, ',', '.'); ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-white bg-danger">
                    <div class="card-body">
                        <h6>Inadimplentes</h6>
                        <h3><?php echo number_format($stats['inadimplentes'], 0, ',', '.'); ?></h3>
                        <small><?php echo formatPercentage($stats['inadimplentes'] * 100 / max($stats['total'], 1), 1); ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-white bg-dark">
                    <div class="card-body">
                        <h6>Valor em Risco</h6>
                        <h3><?php echo formatCurrency($stats['valor_perdido'] ?? 0); ?></h3>
                    </div>
                </div>
            </div>
        </div>

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
                        <input type="date" name="data_inicio" class="form-control" value="<?php echo sanitize($filtros['data_inicio'] ?? ''); ?>">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Data Fim</label>
                        <input type="date" name="data_fim" class="form-control" value="<?php echo sanitize($filtros['data_fim'] ?? ''); ?>">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Prefixo do Título</label>
                        <select name="prefixos[]" class="form-select" multiple size="1">
                            <option value="SBF" <?php echo in_array('SBF', $filtros['prefixos'] ?? ['SBF', 'SFA']) ? 'selected' : ''; ?>>SBF</option>
                            <option value="SFA" <?php echo in_array('SFA', $filtros['prefixos'] ?? ['SBF', 'SFA']) ? 'selected' : ''; ?>>SFA</option>
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
                        <select name="status_titulo" class="form-select">
                            <option value="">Todos</option>
                            <option value="Ativo" <?php echo ($filtros['status_titulo'] ?? '') == 'Ativo' ? 'selected' : ''; ?>>Ativo</option>
                            <option value="Bloqueado" <?php echo ($filtros['status_titulo'] ?? '') == 'Bloqueado' ? 'selected' : ''; ?>>Bloqueado</option>
                            <option value="Cancelado" <?php echo ($filtros['status_titulo'] ?? '') == 'Cancelado' ? 'selected' : ''; ?>>Cancelado</option>
                        </select>
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
                        <input type="text" name="promotor" id="promotorInput" class="form-control" value="<?php echo sanitize($filtros['promotor'] ?? ''); ?>" placeholder="Nome do consultor" list="promotoresList">
                        <datalist id="promotoresList">
                            <?php foreach ($promotores as $p): ?>
                                <option value="<?php echo sanitize($p['promotor']); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Parcelas Pagas</label>
                        <input type="number" name="qtd_parcelas_pagas" class="form-control" value="<?php echo sanitize($filtros['qtd_parcelas_pagas'] ?? ''); ?>" placeholder="Quantidade" min="0">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Apenas 1ª Parcela</label>
                        <select name="apenas_1parcela" class="form-select">
                            <option value="">Não</option>
                            <option value="1" <?php echo ($filtros['apenas_1parcela'] ?? '') == '1' ? 'selected' : ''; ?>>Sim</option>
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

        <!-- Resultados -->
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-table"></i> Resultados (<?php echo number_format($total, 0, ',', '.'); ?> registros)</h5>
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
                            <?php foreach ($titulos as $titulo): ?>
                                <tr>
                                    <td>
                                        <?php echo sanitize($titulo['numero_titulo']); ?><br>
                                        <small class="text-muted"><?php echo sanitize($titulo['nome_produto_atual'] ?? ''); ?></small>
                                    </td>
                                    <td>
                                        <?php echo sanitize($titulo['nome_titular']); ?><br>
                                        <small class="text-muted"><?php echo sanitize($titulo['documento_titular']); ?></small>
                                    </td>
                                    <td><?php echo sanitize($titulo['promotor']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo getStatusBadgeClass($titulo['status_titulo']); ?>">
                                            <?php echo $titulo['status_titulo']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small><?php echo $titulo['status_inadimplencia']; ?></small>
                                    </td>
                                    <td>
                                        <?php echo $titulo['qtd_parcelas_pagas']; ?>/<?php echo $titulo['quantidade_parcelas_venda']; ?>
                                    </td>
                                    <td><?php echo formatCurrency($titulo['total_pago'] ?? 0); ?></td>
                                    <td><?php echo formatCurrency($titulo['saldo_restante'] ?? 0); ?></td>
                                    <td><?php echo formatDate($titulo['data_primeira_venda']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total > $perPage): ?>
                    <?php echo pagination($total, $perPage, $page, 'relatorios.php?' . http_build_query($filtros)); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
