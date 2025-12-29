<?php
define('APP_ROOT', dirname(dirname(__DIR__)));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAuth('../login.php');
requirePermission('admin');

$db = Database::getInstance();

try {
    echo "<h2>Executando Migration - Aumentar Tamanho de Colunas</h2>";
    echo "<pre>";

    $migrationFile = APP_ROOT . '/database/migrations/004_increase_column_lengths.sql';

    if (!file_exists($migrationFile)) {
        throw new Exception("Arquivo de migration não encontrado: $migrationFile");
    }

    $sql = file_get_contents($migrationFile);

    // Dividir em statements separados
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && strpos($stmt, '--') !== 0;
        }
    );

    echo "Executando " . count($statements) . " comandos SQL...\n\n";

    foreach ($statements as $i => $statement) {
        echo "Comando " . ($i + 1) . ":\n";
        echo $statement . ";\n";

        try {
            $db->exec($statement);
            echo "✓ Executado com sucesso!\n\n";
        } catch (Exception $e) {
            echo "✗ Erro: " . $e->getMessage() . "\n\n";
            // Continuar mesmo com erro (pode ser que a coluna já tenha o tamanho correto)
        }
    }

    echo "\n========================================\n";
    echo "Migration concluída!\n";
    echo "========================================\n";

} catch (Exception $e) {
    echo "ERRO FATAL: " . $e->getMessage();
}

echo "</pre>";
echo '<br><a href="../../upload.php" class="btn btn-primary">← Voltar para Upload</a>';
