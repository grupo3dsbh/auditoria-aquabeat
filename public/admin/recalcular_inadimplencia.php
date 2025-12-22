<?php
define('APP_ROOT', dirname(dirname(__DIR__)));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAdmin();

$error = null;
$success = null;
$resultado = null;

// Processar recálculo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recalcular'])) {
    try {
        $tipo = $_POST['tipo'] ?? 'todos';
        $importacaoId = $_POST['importacao_id'] ?? null;

        if ($tipo === 'importacao' && $importacaoId) {
            // Recalcular apenas uma importação específica
            $resultado = InadimplenciaHelper::recalcularStatusImportacao($importacaoId);
            $success = "Recálculo concluído para importação #{$importacaoId}!";
        } else {
            // Recalcular todos
            $resultado = InadimplenciaHelper::recalcularTodosStatus();
            $success = "Recálculo concluído para todas as importações!";
        }

        Logger::logAction(Auth::userId(), 'recalcular_inadimplencia', 'Recalculou status de inadimplência', 'titulos', null);

    } catch (Exception $e) {
        $error = $e->getMessage();
        Logger::error("Erro ao recalcular inadimplência: " . $e->getMessage());
    }
}

// Buscar estatísticas atuais
$db = Database::getInstance();

$estatisticas = $db->fetchAll("
    SELECT
        status_inadimplencia,
        COUNT(*) as total,
        ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM titulos), 2) as percentual
    FROM titulos
    GROUP BY status_inadimplencia
    ORDER BY total DESC
");

$importacoes = $db->fetchAll("
    SELECT
        i.id,
        i.nome_arquivo,
        i.criado_em,
        COUNT(t.id) as total_titulos,
        SUM(CASE WHEN t.status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 ELSE 0 END) as inadimplentes
    FROM importacoes i
    LEFT JOIN titulos t ON t.importacao_id = i.id
    WHERE i.status = 'concluido'
    GROUP BY i.id, i.nome_arquivo, i.criado_em
    ORDER BY i.criado_em DESC
    LIMIT 20
");

$totalTitulos = $db->fetchColumn("SELECT COUNT(*) FROM titulos");

$error = $error ?? getFlashMessage('error');
$success = $success ?? getFlashMessage('success');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recalcular Inadimplência - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>
    <?php include dirname(__DIR__) . '/navbar.php'; ?>

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="bi bi-calculator"></i> Recalcular Status de Inadimplência</h2>
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

                <?php if ($resultado): ?>
                    <div class="card mb-4 border-success">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0"><i class="bi bi-check-circle"></i> Resultado do Recálculo</h5>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-md-4">
                                    <h3 class="text-primary"><?php echo number_format($resultado['total'], 0, ',', '.'); ?></h3>
                                    <p class="text-muted">Títulos Processados</p>
                                </div>
                                <div class="col-md-4">
                                    <h3 class="text-success"><?php echo number_format($resultado['atualizados'], 0, ',', '.'); ?></h3>
                                    <p class="text-muted">Status Atualizados</p>
                                </div>
                                <div class="col-md-4">
                                    <h3 class="text-danger"><?php echo number_format($resultado['erros'], 0, ',', '.'); ?></h3>
                                    <p class="text-muted">Erros</p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Card de Informação -->
                <div class="alert alert-info">
                    <h6><i class="bi bi-info-circle"></i> O que é o Recálculo de Inadimplência?</h6>
                    <p class="mb-2">Esta ferramenta recalcula o status de inadimplência de todos os títulos usando a <strong>lógica correta baseada em tempo</strong>:</p>
                    <ul class="mb-0">
                        <li>Calcula quantos <strong>meses</strong> se passaram desde a primeira venda</li>
                        <li>Compara parcelas pagas com parcelas <strong>esperadas</strong> (não com o total do plano)</li>
                        <li>Exemplo: Vendido há 3 meses, pago 3 parcelas = <span class="badge bg-success">ADIMPLENTE</span></li>
                        <li>Exemplo: Vendido há 5 meses, pago 3 parcelas = <span class="badge bg-danger">INADIMPLENTE</span></li>
                    </ul>
                </div>

                <!-- Estatísticas Atuais -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-bar-chart"></i> Estatísticas Atuais</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">Total de títulos no sistema: <strong><?php echo number_format($totalTitulos, 0, ',', '.'); ?></strong></p>

                        <?php if (empty($estatisticas)): ?>
                            <p class="text-muted">Nenhum título encontrado.</p>
                        <?php else: ?>
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Status de Inadimplência</th>
                                        <th class="text-end">Quantidade</th>
                                        <th class="text-end">Percentual</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($estatisticas as $stat): ?>
                                        <?php
                                        $badge = InadimplenciaHelper::formatarStatusBadge($stat['status_inadimplencia'] ?? 'SEM DADOS');
                                        ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-<?php echo $badge['class']; ?>">
                                                    <?php echo sanitize($badge['text']); ?>
                                                </span>
                                            </td>
                                            <td class="text-end"><?php echo number_format($stat['total'], 0, ',', '.'); ?></td>
                                            <td class="text-end"><?php echo number_format($stat['percentual'], 2, ',', '.'); ?>%</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Formulário de Recálculo -->
                <div class="card mb-4">
                    <div class="card-header bg-warning">
                        <h5 class="mb-0"><i class="bi bi-calculator"></i> Recalcular Status</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" onsubmit="return confirm('Tem certeza que deseja recalcular os status de inadimplência? Esta operação pode levar alguns minutos.');">
                            <div class="mb-3">
                                <label class="form-label">Tipo de Recálculo</label>
                                <select name="tipo" class="form-select" id="tipoRecalculo" onchange="toggleImportacao()">
                                    <option value="todos">Recalcular TODOS os títulos do sistema</option>
                                    <option value="importacao">Recalcular apenas uma importação específica</option>
                                </select>
                            </div>

                            <div class="mb-3" id="importacaoSelect" style="display: none;">
                                <label class="form-label">Importação</label>
                                <select name="importacao_id" class="form-select">
                                    <option value="">-- Selecione uma importação --</option>
                                    <?php foreach ($importacoes as $imp): ?>
                                        <option value="<?php echo $imp['id']; ?>">
                                            #<?php echo $imp['id']; ?> - <?php echo sanitize($imp['nome_arquivo']); ?>
                                            (<?php echo formatDateTime($imp['criado_em']); ?> -
                                            <?php echo number_format($imp['total_titulos'], 0, ',', '.'); ?> títulos)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i>
                                <strong>Atenção:</strong> Esta operação irá recalcular o status de inadimplência
                                de todos os títulos selecionados. O processo pode levar alguns minutos dependendo
                                da quantidade de registros.
                            </div>

                            <button type="submit" name="recalcular" class="btn btn-warning btn-lg">
                                <i class="bi bi-calculator"></i> Recalcular Agora
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Lista de Importações -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-list"></i> Importações Recentes</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($importacoes)): ?>
                            <p class="text-muted">Nenhuma importação encontrada.</p>
                        <?php else: ?>
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Arquivo</th>
                                        <th>Data</th>
                                        <th class="text-end">Total Títulos</th>
                                        <th class="text-end">Inadimplentes</th>
                                        <th class="text-end">Taxa</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($importacoes as $imp): ?>
                                        <tr>
                                            <td><?php echo $imp['id']; ?></td>
                                            <td><?php echo sanitize($imp['nome_arquivo']); ?></td>
                                            <td><?php echo formatDateTime($imp['criado_em']); ?></td>
                                            <td class="text-end"><?php echo number_format($imp['total_titulos'], 0, ',', '.'); ?></td>
                                            <td class="text-end"><?php echo number_format($imp['inadimplentes'], 0, ',', '.'); ?></td>
                                            <td class="text-end">
                                                <?php
                                                $taxa = $imp['total_titulos'] > 0 ? ($imp['inadimplentes'] / $imp['total_titulos']) * 100 : 0;
                                                $badgeClass = $taxa >= 50 ? 'danger' : ($taxa >= 30 ? 'warning' : 'success');
                                                ?>
                                                <span class="badge bg-<?php echo $badgeClass; ?>">
                                                    <?php echo number_format($taxa, 2, ',', '.'); ?>%
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleImportacao() {
            const tipo = document.getElementById('tipoRecalculo').value;
            const importacaoDiv = document.getElementById('importacaoSelect');

            if (tipo === 'importacao') {
                importacaoDiv.style.display = 'block';
            } else {
                importacaoDiv.style.display = 'none';
            }
        }
    </script>
</body>
</html>
