<?php
define('APP_ROOT', dirname(dirname(__DIR__)));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAuth();
requirePermission('admin');

$db = Database::getInstance();

// Ler o arquivo de migration
$migrationFile = APP_ROOT . '/database/migrations/003_create_api_tokens.sql';

if (!file_exists($migrationFile)) {
    die("❌ Arquivo de migration não encontrado: $migrationFile");
}

$sql = file_get_contents($migrationFile);

// Separar por ponto e vírgula e executar cada comando
$statements = array_filter(
    array_map('trim', explode(';', $sql)),
    function($stmt) {
        // Ignorar comentários e linhas vazias
        return !empty($stmt) &&
               strpos($stmt, '--') !== 0 &&
               strpos($stmt, '/*') !== 0;
    }
);

echo "<h2>Executando Migration: api_tokens</h2>\n";
echo "<pre>\n";

$success = true;

try {
    $db->beginTransaction();

    foreach ($statements as $statement) {
        echo "Executando:\n";
        echo substr($statement, 0, 100) . "...\n\n";

        try {
            $db->exec($statement);
            echo "✅ Sucesso\n\n";
        } catch (Exception $e) {
            echo "❌ Erro: " . $e->getMessage() . "\n\n";
            $success = false;
            break;
        }
    }

    if ($success) {
        $db->commit();
        echo "\n✅ Migration executada com sucesso!\n";
        echo "\n<a href='api_tokens.php'>Ir para Tokens de API</a>\n";
    } else {
        $db->rollback();
        echo "\n❌ Migration revertida devido a erros.\n";
    }

} catch (Exception $e) {
    $db->rollback();
    echo "❌ Erro fatal: " . $e->getMessage() . "\n";
}

echo "</pre>\n";
