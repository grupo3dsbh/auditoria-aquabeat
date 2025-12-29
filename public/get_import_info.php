<?php
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAuth('login.php');

header('Content-Type: application/json');

try {
    if (!isset($_GET['id'])) {
        throw new Exception('ID da importação não fornecido');
    }

    $importId = (int)$_GET['id'];

    $db = Database::getInstance();
    $importacao = $db->fetchOne("SELECT * FROM importacoes WHERE id = ?", [$importId]);

    if (!$importacao) {
        throw new Exception('Importação não encontrada');
    }

    // Calcular total de linhas do arquivo se não estiver salvo
    if (!$importacao['total_linhas'] || $importacao['total_linhas'] == 0) {
        $caminhoArquivo = $importacao['caminho_arquivo'];

        if (file_exists($caminhoArquivo)) {
            $file = fopen($caminhoArquivo, 'r');

            // Contar linhas
            $totalLinhas = 0;
            while (fgets($file) !== false) {
                $totalLinhas++;
            }
            fclose($file);

            // Atualizar no banco
            $db->update('importacoes', [
                'total_linhas' => $totalLinhas
            ], 'id = ?', [$importId]);

            $importacao['total_linhas'] = $totalLinhas;
        }
    }

    echo json_encode([
        'success' => true,
        'id' => $importacao['id'],
        'nome_arquivo' => $importacao['nome_arquivo'],
        'caminho_arquivo' => $importacao['caminho_arquivo'],
        'mapeamento_colunas' => $importacao['mapeamento_colunas'],
        'total_linhas' => (int)$importacao['total_linhas'],
        'linhas_processadas' => (int)$importacao['linhas_processadas'],
        'linhas_erro' => (int)$importacao['linhas_erro'],
        'status' => $importacao['status']
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
