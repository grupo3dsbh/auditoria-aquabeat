<?php
define('APP_ROOT', dirname(dirname(__DIR__)));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAdmin();

$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {
    try {
        $db->beginTransaction();

        // Corrigir valores decimais (dividir por 100)
        $sql = "UPDATE titulos
                SET
                    valor_parcela = valor_parcela / 100,
                    valor_total_plano = valor_total_plano / 100,
                    total_pago = total_pago / 100,
                    saldo_restante = saldo_restante / 100
                WHERE importacao_id = ?";

        $importacaoId = $_POST['importacao_id'];
        $db->query($sql, [$importacaoId]);

        $db->commit();

        Logger::logAction(Auth::userId(), 'corrigir_valores', "Corrigiu valores decimais da importação {$importacaoId}", 'titulos', $importacaoId);

        setFlashMessage('success', 'Valores corrigidos com sucesso!');
        redirect('relatorios.php');

    } catch (Exception $e) {
        $db->rollback();
        $error = $e->getMessage();
    }
}

// Buscar importações
$importacoes = $db->fetchAll("SELECT * FROM importacoes WHERE status = 'concluido' ORDER BY concluido_em DESC");
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Corrigir Valores - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>
    <?php include '../navbar.php'; ?>

    <div class="container mt-4">
        <h2><i class="bi bi-calculator"></i> Corrigir Valores Decimais</h2>
        <p class="text-muted">Ferramenta para corrigir valores decimais importados incorretamente (divide valores por 100)</p>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle"></i> <?php echo sanitize($error); ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header bg-warning">
                <h5><i class="bi bi-exclamation-triangle"></i> ATENÇÃO</h5>
            </div>
            <div class="card-body">
                <p><strong>Esta ferramenta divide todos os valores decimais por 100 para a importação selecionada.</strong></p>
                <p>Use apenas se os valores foram importados 100x maiores do que deveriam (ex: R$ 12.177,00 quando deveria ser R$ 121,77)</p>

                <form method="POST" onsubmit="return confirm('Tem certeza que deseja corrigir os valores? Esta ação NÃO pode ser desfeita!');">
                    <div class="mb-3">
                        <label class="form-label">Selecione a Importação</label>
                        <select name="importacao_id" class="form-select" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($importacoes as $imp): ?>
                                <option value="<?php echo $imp['id']; ?>">
                                    <?php echo sanitize($imp['nome_arquivo']); ?> -
                                    <?php echo formatDateTime($imp['concluido_em']); ?> -
                                    <?php echo number_format($imp['total_linhas'], 0, ',', '.'); ?> registros
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="confirm" id="confirm" required>
                            <label class="form-check-label" for="confirm">
                                Eu entendo que esta ação NÃO pode ser desfeita e confirmo que quero corrigir os valores.
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-calculator"></i> Corrigir Valores
                    </button>
                    <a href="<?php echo url('relatorios.php'); ?>" class="btn btn-secondary">
                        <i class="bi bi-x-circle"></i> Cancelar
                    </a>
                </form>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header">
                <h5><i class="bi bi-info-circle"></i> Como Funciona</h5>
            </div>
            <div class="card-body">
                <p>A correção executa o seguinte SQL:</p>
                <pre><code>UPDATE titulos
SET
    valor_parcela = valor_parcela / 100,
    valor_total_plano = valor_total_plano / 100,
    total_pago = total_pago / 100,
    saldo_restante = saldo_restante / 100
WHERE importacao_id = [ID_SELECIONADO]</code></pre>

                <p class="mb-0"><strong>Exemplo:</strong></p>
                <ul>
                    <li>R$ 12.177,00 → R$ 121,77</li>
                    <li>R$ 572.319,00 → R$ 5.723,19</li>
                </ul>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
