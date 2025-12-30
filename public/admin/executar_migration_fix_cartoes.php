<?php
define('APP_ROOT', dirname(dirname(__DIR__)));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAdmin();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Corrigir Tabela de Cartões</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .log {
            background: #f5f5f5;
            padding: 20px;
            border-radius: 5px;
            font-family: monospace;
            white-space: pre-wrap;
            max-height: 600px;
            overflow-y: auto;
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
                <h3><i class="bi bi-wrench"></i> Corrigir Estrutura da Tabela titulo_cartoes</h3>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <strong><i class="bi bi-info-circle"></i> O que esta migration faz:</strong>
                    <ul class="mb-0">
                        <li>Adiciona a coluna <code>tipo_pagamento</code> se não existir</li>
                        <li>Garante que <code>numero_cartao</code> tenha VARCHAR(150)</li>
                        <li>Adiciona índice para <code>tipo_pagamento</code></li>
                    </ul>
                </div>

                <div class="log">
<?php
$db = Database::getInstance();

echo "Executando migration 007 - Correção da Tabela titulo_cartoes...\n\n";

try {
    // Verificar se tabela existe
    try {
        $db->query("SELECT 1 FROM titulo_cartoes LIMIT 1");
        echo "<span class='success'>✓ Tabela titulo_cartoes encontrada</span>\n\n";
    } catch (Exception $e) {
        echo "<span class='error'>✗ Tabela titulo_cartoes não existe!</span>\n";
        echo "Execute primeiro: executar_migration_cartoes.php\n\n";
        throw new Exception("Tabela titulo_cartoes não encontrada");
    }

    // Verificar estrutura atual
    echo "Verificando estrutura atual...\n";
    $columns = $db->fetchAll("DESCRIBE titulo_cartoes");
    $columnNames = array_column($columns, 'Field');

    echo "Colunas existentes:\n";
    foreach ($columns as $col) {
        echo "  - {$col['Field']} ({$col['Type']})\n";
    }
    echo "\n";

    $migrationFile = APP_ROOT . '/database/migrations/007_fix_titulo_cartoes_columns.sql';

    if (!file_exists($migrationFile)) {
        throw new Exception("Arquivo de migration não encontrado!");
    }

    $sql = file_get_contents($migrationFile);

    // Dividir em statements (remover comentários)
    $lines = explode("\n", $sql);
    $statements = [];
    $currentStatement = '';

    foreach ($lines as $line) {
        $line = trim($line);

        // Ignorar linhas vazias e comentários
        if (empty($line) || strpos($line, '--') === 0) {
            continue;
        }

        $currentStatement .= ' ' . $line;

        if (substr($line, -1) === ';') {
            $statements[] = trim($currentStatement);
            $currentStatement = '';
        }
    }

    echo "Executando " . count($statements) . " comandos SQL...\n\n";

    $success = 0;
    $errors = 0;
    $warnings = 0;

    foreach ($statements as $i => $statement) {
        echo "Comando " . ($i + 1) . ":\n";

        // Mostrar apenas primeira linha
        $firstLine = explode("\n", $statement)[0];
        echo substr(trim($firstLine), 0, 80) . "...\n";

        try {
            // MySQL não suporta IF NOT EXISTS para ADD COLUMN em todas as versões
            // Então vamos verificar manualmente
            if (stripos($statement, 'ADD COLUMN IF NOT EXISTS') !== false) {
                // Extrair nome da coluna
                preg_match('/ADD COLUMN IF NOT EXISTS\s+(\w+)/i', $statement, $matches);
                $colName = $matches[1] ?? '';

                if (in_array($colName, $columnNames)) {
                    echo "<span class='warning'>⚠ Coluna '$colName' já existe - ignorando</span>\n\n";
                    $warnings++;
                    continue;
                }

                // Remover IF NOT EXISTS para compatibilidade
                $statement = str_replace('IF NOT EXISTS', '', $statement);
            }

            if (stripos($statement, 'ADD INDEX IF NOT EXISTS') !== false) {
                // Remover IF NOT EXISTS para compatibilidade
                $statement = str_replace('IF NOT EXISTS', '', $statement);
            }

            $db->getConnection()->exec($statement);
            echo "<span class='success'>✓ Executado com sucesso!</span>\n\n";
            $success++;
        } catch (Exception $e) {
            $errorMsg = $e->getMessage();

            // Ignorar alguns erros comuns (duplicatas)
            if (stripos($errorMsg, 'Duplicate column') !== false ||
                stripos($errorMsg, 'already exists') !== false) {
                echo "<span class='warning'>⚠ Já existe: " . $errorMsg . "</span>\n\n";
                $warnings++;
            } else {
                echo "<span class='error'>✗ Erro: " . $errorMsg . "</span>\n\n";
                $errors++;
            }
        }
    }

    echo "\n========================================\n";
    echo "Resultado:\n";
    echo "<span class='success'>✓ Sucessos: $success</span>\n";
    if ($warnings > 0) {
        echo "<span class='warning'>⚠ Avisos: $warnings</span>\n";
    }
    if ($errors > 0) {
        echo "<span class='error'>✗ Erros: $errors</span>\n";
    }
    echo "========================================\n\n";

    // Verificar estrutura final
    echo "Verificando estrutura final...\n";
    $columnsAfter = $db->fetchAll("DESCRIBE titulo_cartoes");

    echo "Colunas após migration:\n";
    foreach ($columnsAfter as $col) {
        echo "  - {$col['Field']} ({$col['Type']})\n";
    }
    echo "\n";

    if ($success > 0 || $warnings > 0) {
        echo "<span class='success'>PRONTO! Tabela titulo_cartoes corrigida.</span>\n";
        echo "Agora você pode reprocessar as importações!\n";
    }

    Logger::logAction(Auth::userId(), 'execute_migration', 'Executou migration 007 - Correção titulo_cartoes');

} catch (Exception $e) {
    echo "<span class='error'>ERRO FATAL: " . $e->getMessage() . "</span>";
}
?>
                </div>

                <div class="mt-3">
                    <a href="reprocessar_importacao.php" class="btn btn-primary btn-lg">
                        <i class="bi bi-arrow-repeat"></i> Reprocessar Importação
                    </a>
                    <a href="corrigir_dados_importacao.php" class="btn btn-warning btn-lg">
                        <i class="bi bi-wrench"></i> Corrigir Dados
                    </a>
                    <a href="index.php" class="btn btn-secondary btn-lg">
                        <i class="bi bi-house"></i> Voltar ao Painel
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
