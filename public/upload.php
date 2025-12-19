<?php
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAuth('login.php');

$error = null;
$success = null;
$importacao = null;

// Processar upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    try {
        $file = uploadFile($_FILES['csv_file'], ['csv']);

        $importer = new CSVImporter();
        $importacaoId = $importer->startImport(
            $file['original_name'],
            $file['path'],
            'completo'
        );

        // Analisar CSV
        $analise = $importer->analyzeCSV($file['path']);

        $_SESSION['import_data'] = [
            'importacao_id' => $importacaoId,
            'file_path' => $file['path'],
            'analysis' => $analise
        ];

        redirect('upload_mapping.php');

    } catch (Exception $e) {
        $error = $e->getMessage();
        Logger::error("Upload failed: " . $e->getMessage());
    }
}

$error = $error ?? getFlashMessage('error');
$success = getFlashMessage('success');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importar CSV - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-8 offset-md-2">
                <h2><i class="bi bi-cloud-upload"></i> Importar CSV</h2>
                <p class="text-muted">Faça upload do arquivo CSV exportado do SQL Server</p>

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

                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Upload de Arquivo</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="csv_file" class="form-label">Selecione o arquivo CSV</label>
                                <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv" required>
                                <small class="form-text text-muted">
                                    Tamanho máximo: <?php echo (UPLOAD_MAX_SIZE / 1024 / 1024); ?>MB | Formato: CSV (UTF-8)
                                </small>
                            </div>

                            <div class="alert alert-info">
                                <h6><i class="bi bi-info-circle"></i> Instruções:</h6>
                                <ol class="mb-0">
                                    <li>Execute a query SQL no SQL Server Management Studio</li>
                                    <li>Exporte o resultado como CSV (UTF-8)</li>
                                    <li>Faça upload do arquivo aqui</li>
                                    <li>Revise o mapeamento de colunas na próxima etapa</li>
                                    <li>Confirme a importação</li>
                                </ol>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-cloud-upload"></i> Fazer Upload e Continuar
                            </button>
                            <a href="index.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Voltar
                            </a>
                        </form>
                    </div>
                </div>

                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0">Histórico de Importações</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $db = Database::getInstance();
                        $importacoes = $db->fetchAll(
                            "SELECT * FROM importacoes ORDER BY criado_em DESC LIMIT 10"
                        );

                        if (empty($importacoes)):
                        ?>
                            <p class="text-muted">Nenhuma importação realizada ainda.</p>
                        <?php else: ?>
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Data</th>
                                        <th>Arquivo</th>
                                        <th>Status</th>
                                        <th>Registros</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($importacoes as $imp): ?>
                                        <tr>
                                            <td><?php echo formatDateTime($imp['criado_em']); ?></td>
                                            <td><?php echo sanitize($imp['nome_arquivo']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo getStatusBadgeClass(ucfirst($imp['status'])); ?>">
                                                    <?php echo ucfirst($imp['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo number_format($imp['linhas_processadas'], 0, ',', '.'); ?></td>
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
</body>
</html>
