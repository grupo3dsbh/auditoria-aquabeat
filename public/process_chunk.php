<?php
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAuth('login.php');

header('Content-Type: application/json');

// Aumentar timeout e memória para processamento
set_time_limit(300); // 5 minutos
ini_set('memory_limit', '512M');

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

    Logger::info("Processing chunk", [
        'importacao_id' => $importacaoId,
        'offset' => $offset,
        'chunk_size' => $chunkSize
    ]);

    $importer = new CSVImporter();
    $result = $importer->processCSVChunk($importacaoId, $mapeamento, $offset, $chunkSize);

    Logger::info("Chunk processed successfully", [
        'importacao_id' => $importacaoId,
        'offset' => $offset,
        'processed' => $result['chunk_processed'],
        'has_more' => $result['has_more']
    ]);

    echo json_encode($result);

} catch (Exception $e) {
    Logger::error("Error processing chunk", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'line' => $e->getLine(),
        'file' => $e->getFile()
    ]);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'details' => [
            'line' => $e->getLine(),
            'file' => basename($e->getFile())
        ]
    ]);
}
