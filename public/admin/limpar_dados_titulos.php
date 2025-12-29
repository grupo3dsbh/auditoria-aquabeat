<?php
define('APP_ROOT', dirname(dirname(__DIR__)));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAuth('../login.php');
requirePermission('admin');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Limpar Dados - Auditoria</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .log {
            background: #f5f5f5;
            padding: 20px;
            border-radius: 5px;
            font-family: monospace;
            white-space: pre-wrap;
        }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .warning { color: orange; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="card">
            <div class="card-header bg-warning text-dark">
                <h3>🗑️ Limpar Dados da Tabela Títulos</h3>
            </div>
            <div class="card-body">
                <?php if (!isset($_POST['confirmar'])): ?>
                    <div class="alert alert-warning">
                        <h5>⚠️ ATENÇÃO!</h5>
                        <p>Esta ação irá:</p>
                        <ul>
                            <li><strong>DELETAR TODOS os registros</strong> da tabela <code>titulos</code></li>
                            <li><strong>DELETAR TODOS os registros</strong> da tabela <code>titulo_cartoes</code></li>
                            <li><strong>DELETAR TODAS as importações</strong></li>
                        </ul>
                        <p class="mb-0"><strong>Esta ação NÃO pode ser desfeita!</strong></p>
                    </div>

                    <form method="POST">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="confirmo" required>
                            <label class="form-check-label" for="confirmo">
                                <strong>Confirmo que quero deletar TODOS os dados</strong>
                            </label>
                        </div>

                        <button type="submit" name="confirmar" value="1" class="btn btn-danger btn-lg">
                            <i class="bi bi-trash"></i> SIM, Deletar Tudo
                        </button>
                        <a href="../upload.php" class="btn btn-secondary btn-lg">
                            <i class="bi bi-x"></i> Cancelar
                        </a>
                    </form>
                <?php else: ?>
                    <div class="log">
<?php
$db = Database::getInstance();

echo "Iniciando limpeza de dados...\n\n";

try {
    // 1. Deletar cartões
    echo "1. Deletando cartões...\n";
    $result = $db->exec("DELETE FROM titulo_cartoes");
    echo "<span class='success'>✓ {$result} cartões deletados</span>\n\n";

    // 2. Deletar títulos
    echo "2. Deletando títulos...\n";
    $result = $db->exec("DELETE FROM titulos");
    echo "<span class='success'>✓ {$result} títulos deletados</span>\n\n";

    // 3. Deletar importações
    echo "3. Deletando importações...\n";
    $result = $db->exec("DELETE FROM importacoes");
    echo "<span class='success'>✓ {$result} importações deletadas</span>\n\n";

    // 4. Resetar AUTO_INCREMENT
    echo "4. Resetando contadores AUTO_INCREMENT...\n";
    $db->exec("ALTER TABLE titulo_cartoes AUTO_INCREMENT = 1");
    $db->exec("ALTER TABLE titulos AUTO_INCREMENT = 1");
    $db->exec("ALTER TABLE importacoes AUTO_INCREMENT = 1");
    echo "<span class='success'>✓ Contadores resetados</span>\n\n";

    echo "\n========================================\n";
    echo "<span class='success'>✓ LIMPEZA CONCLUÍDA!</span>\n";
    echo "========================================\n\n";
    echo "Banco de dados limpo e pronto para nova importação.\n";

} catch (Exception $e) {
    echo "\n<span class='error'>✗ ERRO: " . $e->getMessage() . "</span>\n";
}
?>
                    </div>

                    <div class="mt-3">
                        <a href="../upload.php" class="btn btn-primary btn-lg">
                            <i class="bi bi-upload"></i> Ir para Importar CSV
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
