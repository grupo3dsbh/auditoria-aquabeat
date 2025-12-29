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
    <title>Criar Tabela de Cartões</title>
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
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h3>✅ Criar Tabela titulo_cartoes</h3>
            </div>
            <div class="card-body">
                <div class="log">
<?php
$db = Database::getInstance();

echo "Executando migration 006 - Tabela de Cartões...\n\n";

try {
    $migrationFile = APP_ROOT . '/database/migrations/006_create_titulo_cartoes_table.sql';

    if (!file_exists($migrationFile)) {
        throw new Exception("Arquivo de migration não encontrado!");
    }

    $sql = file_get_contents($migrationFile);

    // Dividir em statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && strpos($stmt, '--') !== 0;
        }
    );

    echo "Executando " . count($statements) . " comandos SQL...\n\n";

    $success = 0;
    $errors = 0;

    foreach ($statements as $i => $statement) {
        echo "Comando " . ($i + 1) . ":\n";

        // Mostrar apenas primeira linha
        $firstLine = explode("\n", $statement)[0];
        echo substr($firstLine, 0, 80) . "...\n";

        try {
            $db->getConnection()->exec($statement);
            echo "<span class='success'>✓ Executado com sucesso!</span>\n\n";
            $success++;
        } catch (Exception $e) {
            echo "<span class='error'>✗ Erro: " . $e->getMessage() . "</span>\n\n";
            $errors++;
        }
    }

    echo "\n========================================\n";
    echo "Resultado:\n";
    echo "<span class='success'>✓ Sucessos: $success</span>\n";
    if ($errors > 0) {
        echo "<span class='error'>✗ Erros: $errors</span>\n";
    }
    echo "========================================\n\n";

    if ($success >= 1) {
        echo "<span class='success'>PRONTO! Tabela titulo_cartoes criada.</span>\n";
        echo "Agora você pode importar com histórico completo de cartões!\n";
    }

} catch (Exception $e) {
    echo "<span class='error'>ERRO FATAL: " . $e->getMessage() . "</span>";
}
?>
                </div>

                <div class="mt-3">
                    <a href="limpar_dados_titulos.php" class="btn btn-warning btn-lg">
                        <i class="bi bi-trash"></i> Limpar Dados Antigos
                    </a>
                    <a href="../upload.php" class="btn btn-primary btn-lg">
                        <i class="bi bi-upload"></i> Ir para Importar
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
