<?php
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAuth('login.php');

if (!isset($_SESSION['import_data'])) {
    redirect('upload.php');
}

$importData = $_SESSION['import_data'];
$analysis = $importData['analysis'];

// Processar mapeamento
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $mapeamento = $_POST['mapping'] ?? [];

        $importer = new CSVImporter();
        $result = $importer->processCSV($importData['importacao_id'], $mapeamento);

        unset($_SESSION['import_data']);

        setFlashMessage('success', "Importação concluída! {$result['linhas_processadas']} registros processados.");
        redirect('index.php');

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mapeamento de Colunas - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container mt-4">
        <h2><i class="bi bi-diagram-3"></i> Mapeamento de Colunas</h2>
        <p class="text-muted">Revise o mapeamento automático das colunas do CSV</p>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <?php echo sanitize($error); ?>
            </div>
        <?php endif; ?>

        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i>
            <strong>Arquivo:</strong> <?php echo sanitize(basename($importData['file_path'])); ?> |
            <strong>Total de linhas:</strong> <?php echo number_format($analysis['total_rows'], 0, ',', '.'); ?> |
            <strong>Encoding:</strong> <?php echo $analysis['encoding']; ?>
        </div>

        <form method="POST">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">Colunas Detectadas</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th style="width: 5%">#</th>
                                <th style="width: 30%">Coluna no CSV</th>
                                <th style="width: 35%">Mapear para</th>
                                <th style="width: 30%">Exemplo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($analysis['header'] as $index => $colName): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><strong><?php echo sanitize($colName); ?></strong></td>
                                    <td>
                                        <select name="mapping[<?php echo $index; ?>]" class="form-select form-select-sm">
                                            <option value="">-- Ignorar --</option>
                                            <option value="numero_titulo" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'numero_titulo' ? 'selected' : ''; ?>>Número do Título</option>
                                            <option value="nome_produto_original" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'nome_produto_original' ? 'selected' : ''; ?>>Produto Original</option>
                                            <option value="nome_produto_atual" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'nome_produto_atual' ? 'selected' : ''; ?>>Produto Atual</option>
                                            <option value="alterou_vagas" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'alterou_vagas' ? 'selected' : ''; ?>>Alterou Vagas</option>
                                            <option value="categoria" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'categoria' ? 'selected' : ''; ?>>Categoria</option>
                                            <option value="status_titulo" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'status_titulo' ? 'selected' : ''; ?>>Status do Título</option>
                                            <option value="status_inadimplencia" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'status_inadimplencia' ? 'selected' : ''; ?>>Status Inadimplência</option>
                                            <option value="periodo_titulo" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'periodo_titulo' ? 'selected' : ''; ?>>Período</option>
                                            <option value="dias_desde_venda" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'dias_desde_venda' ? 'selected' : ''; ?>>Dias Desde Venda</option>
                                            <option value="nome_titular" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'nome_titular' ? 'selected' : ''; ?>>Nome do Titular</option>
                                            <option value="documento_titular" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'documento_titular' ? 'selected' : ''; ?>>CPF/Documento</option>
                                            <option value="telefone_residencial" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'telefone_residencial' ? 'selected' : ''; ?>>Telefone</option>
                                            <option value="data_cadastro" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'data_cadastro' ? 'selected' : ''; ?>>Data Cadastro</option>
                                            <option value="data_primeira_venda" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'data_primeira_venda' ? 'selected' : ''; ?>>Data Primeira Venda</option>
                                            <option value="data_ultima_venda" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'data_ultima_venda' ? 'selected' : ''; ?>>Data Última Venda</option>
                                            <option value="origem_venda" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'origem_venda' ? 'selected' : ''; ?>>Origem da Venda</option>
                                            <option value="promotor" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'promotor' ? 'selected' : ''; ?>>Promotor/Consultor</option>
                                            <option value="gerente" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'gerente' ? 'selected' : ''; ?>>Gerente</option>
                                            <option value="quantidade_parcelas_venda" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'quantidade_parcelas_venda' ? 'selected' : ''; ?>>Quantidade de Parcelas</option>
                                            <option value="qtd_parcelas_pagas" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'qtd_parcelas_pagas' ? 'selected' : ''; ?>>Parcelas Pagas</option>
                                            <option value="parcelas_restantes" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'parcelas_restantes' ? 'selected' : ''; ?>>Parcelas Restantes</option>
                                            <option value="lista_parcelas_pagas" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'lista_parcelas_pagas' ? 'selected' : ''; ?>>Lista Parcelas Pagas</option>
                                            <option value="lista_valores_pagos" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'lista_valores_pagos' ? 'selected' : ''; ?>>Lista Valores Pagos</option>
                                            <option value="forma_pagamento" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'forma_pagamento' ? 'selected' : ''; ?>>Forma de Pagamento</option>
                                            <option value="tipo_pagamento" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'tipo_pagamento' ? 'selected' : ''; ?>>Tipo de Pagamento</option>
                                            <option value="valor_parcela" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'valor_parcela' ? 'selected' : ''; ?>>Valor da Parcela</option>
                                            <option value="total_pago" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'total_pago' ? 'selected' : ''; ?>>Total Pago</option>
                                            <option value="saldo_restante" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'saldo_restante' ? 'selected' : ''; ?>>Saldo Restante</option>
                                            <option value="numero_cartao" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'numero_cartao' ? 'selected' : ''; ?>>Número do Cartão</option>
                                            <option value="bandeira" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'bandeira' ? 'selected' : ''; ?>>Bandeira do Cartão</option>
                                            <option value="tipo_pagamento_cartao" <?php echo ($analysis['suggested_mapping'][$index] ?? '') == 'tipo_pagamento_cartao' ? 'selected' : ''; ?>>Tipo Pagamento Cartão</option>
                                        </select>
                                    </td>
                                    <td><small class="text-muted"><?php echo sanitize($analysis['example_row'][$index] ?? ''); ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-success btn-lg">
                    <i class="bi bi-check-circle"></i> Confirmar e Iniciar Importação
                </button>
                <a href="upload.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
