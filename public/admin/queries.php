<?php
define('APP_ROOT', dirname(dirname(__DIR__)));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAdmin();

$error = null;
$success = null;
$queryContent = null;
$queryFile = null;

// Diretório de queries
$queriesDir = APP_ROOT . '/database';

// Lista de queries disponíveis
$availableQueries = [
    'query_dados_venda.sql' => 'Query de Exportação de Dados (Sem Status)',
    'query_com_cabecalho_CORRIGIDA.sql' => 'Query com Cabeçalho (Antiga - Com Status)',
    'query_com_cabecalho.sql' => 'Query Original com Cabeçalho',
    'schema.sql' => 'Schema do Banco de Dados'
];

// Processar visualização
if (isset($_GET['view'])) {
    $queryFile = $_GET['view'];

    if (isset($availableQueries[$queryFile])) {
        $filePath = $queriesDir . '/' . $queryFile;

        if (file_exists($filePath)) {
            $queryContent = file_get_contents($filePath);
        } else {
            $error = "Arquivo não encontrado: {$queryFile}";
        }
    } else {
        $error = "Query não autorizada.";
    }
}

// Processar salvamento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    try {
        $queryFile = $_POST['query_file'] ?? null;
        $newContent = $_POST['query_content'] ?? '';

        if (!$queryFile || !isset($availableQueries[$queryFile])) {
            throw new Exception('Query não autorizada.');
        }

        $filePath = $queriesDir . '/' . $queryFile;

        // Backup do arquivo original
        $backupPath = $queriesDir . '/backups';
        if (!is_dir($backupPath)) {
            mkdir($backupPath, 0755, true);
        }

        $backupFile = $backupPath . '/' . $queryFile . '.' . date('Y-m-d_His') . '.bak';

        if (file_exists($filePath)) {
            copy($filePath, $backupFile);
        }

        // Salvar novo conteúdo
        file_put_contents($filePath, $newContent);

        Logger::logAction(
            Auth::userId(),
            'edit_query',
            "Editou query: {$queryFile}",
            'queries',
            null
        );

        $success = "Query salva com sucesso! Backup criado em: " . basename($backupFile);
        $queryContent = $newContent;

    } catch (Exception $e) {
        $error = $e->getMessage();
        Logger::error("Erro ao salvar query: " . $e->getMessage());
    }
}

$error = $error ?? getFlashMessage('error');
$success = $success ?? getFlashMessage('success');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Queries SQL - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">
    <style>
        .code-container {
            position: relative;
        }
        .copy-button {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 10;
        }
        pre code {
            max-height: 600px;
            overflow-y: auto;
        }
        .query-editor {
            font-family: 'Courier New', monospace;
            font-size: 14px;
            line-height: 1.5;
            min-height: 500px;
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__) . '/navbar.php'; ?>

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="bi bi-code-square"></i> Gerenciar Queries SQL</h2>
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

                <div class="row">
                    <!-- Lista de Queries -->
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="bi bi-file-earmark-code"></i> Queries Disponíveis</h5>
                            </div>
                            <div class="list-group list-group-flush">
                                <?php foreach ($availableQueries as $file => $description): ?>
                                    <a href="?view=<?php echo urlencode($file); ?>"
                                       class="list-group-item list-group-item-action <?php echo $queryFile === $file ? 'active' : ''; ?>">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1"><?php echo sanitize($file); ?></h6>
                                            <?php if ($queryFile === $file): ?>
                                                <span class="badge bg-light text-dark">Visualizando</span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="<?php echo $queryFile === $file ? 'text-white' : 'text-muted'; ?>">
                                            <?php echo sanitize($description); ?>
                                        </small>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="card mt-3">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-info-circle"></i> Informações</h6>
                            </div>
                            <div class="card-body">
                                <p class="small mb-2"><strong>Diretório:</strong><br><code><?php echo $queriesDir; ?></code></p>
                                <?php if ($queryFile): ?>
                                    <p class="small mb-2"><strong>Arquivo:</strong><br><code><?php echo $queryFile; ?></code></p>
                                    <?php
                                    $filePath = $queriesDir . '/' . $queryFile;
                                    if (file_exists($filePath)):
                                        $fileSize = filesize($filePath);
                                        $fileModified = filemtime($filePath);
                                    ?>
                                    <p class="small mb-2"><strong>Tamanho:</strong><br><?php echo number_format($fileSize / 1024, 2); ?> KB</p>
                                    <p class="small mb-0"><strong>Modificado:</strong><br><?php echo date('d/m/Y H:i:s', $fileModified); ?></p>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Visualização/Edição -->
                    <div class="col-md-8">
                        <?php if ($queryContent !== null): ?>
                            <!-- Modo Visualização -->
                            <div id="viewMode">
                                <div class="card">
                                    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                                        <h5 class="mb-0"><i class="bi bi-eye"></i> <?php echo sanitize($queryFile); ?></h5>
                                        <div>
                                            <button onclick="copyToClipboard()" class="btn btn-sm btn-light">
                                                <i class="bi bi-clipboard"></i> Copiar
                                            </button>
                                            <button onclick="toggleEditMode()" class="btn btn-sm btn-warning">
                                                <i class="bi bi-pencil"></i> Editar
                                            </button>
                                        </div>
                                    </div>
                                    <div class="card-body p-0">
                                        <div class="code-container">
                                            <pre class="m-0"><code class="language-sql"><?php echo htmlspecialchars($queryContent); ?></code></pre>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Modo Edição -->
                            <div id="editMode" style="display: none;">
                                <div class="card border-warning">
                                    <div class="card-header bg-warning d-flex justify-content-between align-items-center">
                                        <h5 class="mb-0"><i class="bi bi-pencil"></i> Editando: <?php echo sanitize($queryFile); ?></h5>
                                        <button onclick="toggleEditMode()" class="btn btn-sm btn-dark">
                                            <i class="bi bi-x"></i> Cancelar
                                        </button>
                                    </div>
                                    <div class="card-body">
                                        <div class="alert alert-warning">
                                            <i class="bi bi-exclamation-triangle"></i>
                                            <strong>Atenção!</strong> Editar queries pode afetar o funcionamento do sistema.
                                            Um backup será criado automaticamente antes de salvar.
                                        </div>

                                        <form method="POST">
                                            <input type="hidden" name="query_file" value="<?php echo htmlspecialchars($queryFile); ?>">
                                            <div class="mb-3">
                                                <textarea name="query_content" class="form-control query-editor" spellcheck="false"><?php echo htmlspecialchars($queryContent); ?></textarea>
                                            </div>
                                            <div class="d-flex gap-2">
                                                <button type="submit" name="save" class="btn btn-success">
                                                    <i class="bi bi-save"></i> Salvar Alterações
                                                </button>
                                                <button type="button" onclick="toggleEditMode()" class="btn btn-secondary">
                                                    Cancelar
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                        <?php else: ?>
                            <!-- Mensagem inicial -->
                            <div class="card">
                                <div class="card-body text-center py-5">
                                    <i class="bi bi-code-square" style="font-size: 4rem; color: #ccc;"></i>
                                    <h4 class="mt-3 text-muted">Selecione uma query para visualizar</h4>
                                    <p class="text-muted">Escolha uma query na lista ao lado para ver seu conteúdo</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/sql.min.js"></script>
    <script>
        // Syntax highlighting
        document.addEventListener('DOMContentLoaded', function() {
            hljs.highlightAll();
        });

        // Toggle edit mode
        function toggleEditMode() {
            const viewMode = document.getElementById('viewMode');
            const editMode = document.getElementById('editMode');

            if (viewMode.style.display === 'none') {
                viewMode.style.display = 'block';
                editMode.style.display = 'none';
            } else {
                viewMode.style.display = 'none';
                editMode.style.display = 'block';
            }
        }

        // Copy to clipboard
        function copyToClipboard() {
            const code = document.querySelector('.language-sql').textContent;

            navigator.clipboard.writeText(code).then(function() {
                // Mostrar feedback
                const btn = event.target.closest('button');
                const originalHTML = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-check"></i> Copiado!';
                btn.classList.remove('btn-light');
                btn.classList.add('btn-success');

                setTimeout(function() {
                    btn.innerHTML = originalHTML;
                    btn.classList.remove('btn-success');
                    btn.classList.add('btn-light');
                }, 2000);
            }).catch(function(err) {
                alert('Erro ao copiar: ' + err);
            });
        }
    </script>
</body>
</html>
