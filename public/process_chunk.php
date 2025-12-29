<?php
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAuth('login.php');

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método não permitido');
    }

    // Receber dados JSON
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['importacao_id']) || !isset($input['mapeamento'])) {
        throw new Exception('Parâmetros inválidos');
    }

    $importacaoId = (int)$input['importacao_id'];
    $mapeamento = $input['mapeamento'];
    $offset = isset($input['offset']) ? (int)$input['offset'] : 0;
    $chunkSize = isset($input['chunk_size']) ? (int)$input['chunk_size'] : 200;

    $importer = new CSVImporter();
    $result = $importer->processCSVChunk($importacaoId, $mapeamento, $offset, $chunkSize);

    echo json_encode($result);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
