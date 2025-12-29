<?php
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAuth('login.php');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Corrigir Colunas - Fix Urgente</title>
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
            <div class="card-header bg-danger text-white">
                <h3>🔧 Correção Urgente - Colunas do Banco</h3>
            </div>
            <div class="card-body">
                <div class="log">
<?php
$db = Database::getInstance();

echo "Iniciando correção das colunas...\n\n";

$commands = [
    "ALTER TABLE titulos MODIFY COLUMN bandeira VARCHAR(100)",
    "ALTER TABLE titulos MODIFY COLUMN numero_cartao VARCHAR(150)",
    "ALTER TABLE titulos MODIFY COLUMN tipo_pagamento VARCHAR(100)",
    "ALTER TABLE titulos MODIFY COLUMN forma_pagamento VARCHAR(150)",
    "ALTER TABLE titulos MODIFY COLUMN origem_venda VARCHAR(150)"
];

$success = 0;
$errors = 0;

foreach ($commands as $i => $sql) {
    echo "Comando " . ($i + 1) . ":\n";
    echo "$sql\n";

    try {
        $db->exec($sql);
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
    echo "<span class='success'>PRONTO! Colunas corrigidas.</span>\n";
    echo "Agora você pode retomar a importação!\n";
}
?>
                </div>

                <div class="mt-3">
                    <a href="upload.php" class="btn btn-success btn-lg">
                        <i class="bi bi-arrow-left"></i> Voltar para Importar e Retomar
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
