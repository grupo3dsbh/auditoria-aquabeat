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

// Cancelar importação
if (isset($_GET['cancelar']) && isset($_GET['id'])) {
    try {
        $db = Database::getInstance();
        $importId = (int)$_GET['id'];

        $db->update('importacoes', [
            'status' => 'cancelado',
            'mensagem_erro' => 'Cancelado pelo usuário'
        ], 'id = ?', [$importId]);

        setFlashMessage('success', 'Importação cancelada com sucesso!');
        redirect('upload.php');
    } catch (Exception $e) {
        $error = 'Erro ao cancelar importação: ' . $e->getMessage();
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
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($importacoes as $imp): ?>
                                        <tr>
                                            <td><?php echo formatDateTime($imp['criado_em']); ?></td>
                                            <td>
                                                <?php echo sanitize($imp['nome_arquivo']); ?>
                                                <?php if ($imp['mensagem_erro']): ?>
                                                    <br><small class="text-danger">
                                                        <i class="bi bi-exclamation-triangle"></i>
                                                        <?php echo sanitize($imp['mensagem_erro']); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo getStatusBadgeClass(ucfirst($imp['status'])); ?>">
                                                    <?php echo ucfirst($imp['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo number_format($imp['linhas_processadas'], 0, ',', '.'); ?></td>
                                            <td>
                                                <?php if ($imp['status'] === 'processando'): ?>
                                                    <button class="btn btn-sm btn-primary"
                                                            onclick="retomarImportacao(<?php echo $imp['id']; ?>, '<?php echo sanitize($imp['nome_arquivo']); ?>')">
                                                        <i class="bi bi-play-circle"></i> Retomar
                                                    </button>
                                                    <a href="?cancelar=1&id=<?php echo $imp['id']; ?>"
                                                       class="btn btn-sm btn-danger"
                                                       onclick="return confirm('Tem certeza que deseja cancelar esta importação?')">
                                                        <i class="bi bi-x-circle"></i> Cancelar
                                                    </a>
                                                <?php elseif ($imp['status'] === 'erro'): ?>
                                                    <a href="?cancelar=1&id=<?php echo $imp['id']; ?>"
                                                       class="btn btn-sm btn-secondary">
                                                        <i class="bi bi-trash"></i> Limpar
                                                    </a>
                                                <?php endif; ?>
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

    <!-- Modal de Progresso -->
    <div class="modal fade" id="progressModal" data-bs-backdrop="static" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Retomando Importação</h5>
                </div>
                <div class="modal-body">
                    <div id="modalErrorAlert" class="alert alert-danger d-none"></div>
                    <p><strong id="modalFileName"></strong></p>
                    <div class="progress" style="height: 30px;">
                        <div id="modalProgressBar" class="progress-bar progress-bar-striped progress-bar-animated"
                             role="progressbar" style="width: 0%">
                            0%
                        </div>
                    </div>
                    <p class="mt-3 text-center">
                        <strong id="modalProgressText">Carregando...</strong><br>
                        <small class="text-muted" id="modalProgressDetails"></small>
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="modalCancelBtn" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let progressModal;
        let currentImportId;
        let offset = 0;
        let totalProcessed = 0;
        let totalErrors = 0;
        const chunkSize = 200;

        async function retomarImportacao(importId, fileName) {
            currentImportId = importId;

            // Mostrar modal
            progressModal = new bootstrap.Modal(document.getElementById('progressModal'));
            progressModal.show();

            document.getElementById('modalFileName').textContent = fileName;
            document.getElementById('modalErrorAlert').classList.add('d-none');
            document.getElementById('modalCancelBtn').style.display = 'none';

            // Buscar dados da importação
            try {
                const response = await fetch(`get_import_info.php?id=${importId}`);
                const importData = await response.json();

                if (!importData.success) {
                    throw new Error(importData.error || 'Erro ao carregar dados da importação');
                }

                // Usar mapeamento salvo e offset atual
                const mapeamento = JSON.parse(importData.mapeamento_colunas || '{}');
                offset = importData.linhas_processadas || 0;
                totalProcessed = offset;
                totalErrors = importData.linhas_erro || 0;

                document.getElementById('modalProgressText').textContent =
                    `Retomando do registro ${offset}...`;

                // Iniciar processamento
                await processNextChunk(mapeamento, importData.total_rows || 10000);

            } catch (error) {
                console.error('Erro:', error);
                showModalError(error.message);
            }
        }

        async function processNextChunk(mapeamento, totalLinhas) {
            try {
                const response = await fetch('process_chunk.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        importacao_id: currentImportId,
                        mapeamento: mapeamento,
                        offset: offset,
                        chunk_size: chunkSize
                    })
                });

                if (!response.ok) {
                    throw new Error('Erro na requisição: ' + response.status);
                }

                const result = await response.json();

                if (!result.success) {
                    let errorMsg = result.error || 'Erro desconhecido';
                    if (result.details) {
                        errorMsg += ` (${result.details.file}:${result.details.line})`;
                    }
                    throw new Error(errorMsg);
                }

                // Atualizar contadores
                totalProcessed = result.total_processed;
                totalErrors = result.total_errors;
                offset = result.next_offset;

                // Calcular progresso
                const progress = Math.min(100, Math.round((totalProcessed + totalErrors) / totalLinhas * 100));

                // Atualizar barra
                const progressBar = document.getElementById('modalProgressBar');
                progressBar.style.width = progress + '%';
                progressBar.textContent = progress + '%';

                document.getElementById('modalProgressText').textContent =
                    `Processando... ${totalProcessed + totalErrors} de ${totalLinhas} linhas`;
                document.getElementById('modalProgressDetails').textContent =
                    `✓ ${totalProcessed} processadas | ✗ ${totalErrors} com erro`;

                // Se não completou, processar próximo chunk
                if (result.has_more) {
                    await processNextChunk(mapeamento, totalLinhas);
                } else {
                    // Completou!
                    document.getElementById('modalProgressText').innerHTML =
                        '<i class="bi bi-check-circle text-success"></i> Importação Concluída!';
                    progressBar.classList.remove('progress-bar-animated');
                    progressBar.classList.add('bg-success');

                    // Recarregar página após 2 segundos
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                }

            } catch (error) {
                console.error('Erro completo:', error);
                showModalError(error.message);
            }
        }

        function showModalError(message) {
            const errorAlert = document.getElementById('modalErrorAlert');
            errorAlert.textContent = 'Erro: ' + message;
            errorAlert.classList.remove('d-none');
            document.getElementById('modalCancelBtn').style.display = '';
        }
    </script>
</body>
</html>
