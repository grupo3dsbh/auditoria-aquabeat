<?php
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAuth('login.php');

if (!isset($_SESSION['import_data'])) {
    redirect('upload.php');
}

$importData = $_SESSION['import_data'];
$analysis = $importData['analysis'];
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

        <div id="errorAlert" class="alert alert-danger d-none"></div>
        <div id="progressContainer" class="d-none">
            <div class="card">
                <div class="card-body">
                    <h5><i class="bi bi-hourglass-split"></i> Processando Importação...</h5>
                    <div class="progress" style="height: 30px;">
                        <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated"
                             role="progressbar" style="width: 0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                            0%
                        </div>
                    </div>
                    <p class="mt-3 text-center">
                        <strong id="progressText">Iniciando importação...</strong><br>
                        <small class="text-muted" id="progressDetails"></small>
                    </p>
                </div>
            </div>
        </div>

        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i>
            <strong>Arquivo:</strong> <?php echo sanitize(basename($importData['file_path'])); ?> |
            <strong>Total de linhas:</strong> <?php echo number_format($analysis['total_rows'], 0, ',', '.'); ?> |
            <strong>Encoding:</strong> <?php echo $analysis['encoding']; ?> |
            <strong>Delimitador:</strong> <?php echo $analysis['delimiter'] === "\t" ? 'TAB' : sanitize($analysis['delimiter']); ?>
        </div>

        <form id="mappingForm">
            <div id="mappingCard" class="card">
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
    <script>
        const importacaoId = <?php echo $importData['importacao_id']; ?>;
        const totalLinhas = <?php echo $analysis['total_rows']; ?>;
        const chunkSize = 200;

        let offset = 0;
        let totalProcessed = 0;
        let totalErrors = 0;

        document.getElementById('mappingForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            // Coletar mapeamento
            const form = e.target;
            const formData = new FormData(form);
            const mapeamento = {};

            for (let [key, value] of formData.entries()) {
                const match = key.match(/mapping\[(\d+)\]/);
                if (match && value) {
                    mapeamento[match[1]] = value;
                }
            }

            // Esconder formulário e mostrar progresso
            document.getElementById('mappingCard').classList.add('d-none');
            document.getElementById('progressContainer').classList.remove('d-none');

            // Iniciar processamento
            await processNextChunk(mapeamento);
        });

        async function processNextChunk(mapeamento) {
            try {
                const response = await fetch('process_chunk.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        importacao_id: importacaoId,
                        mapeamento: mapeamento,
                        offset: offset,
                        chunk_size: chunkSize
                    })
                });

                if (!response.ok) {
                    throw new Error('Erro na requisição: ' + response.status);
                }

                const result = await response.json();

                if (!result.success) {
                    let errorMsg = result.error || 'Erro desconhecido';
                    if (result.details) {
                        errorMsg += ` (${result.details.file}:${result.details.line})`;
                    }
                    throw new Error(errorMsg);
                }

                // Atualizar contadores
                totalProcessed = result.total_processed;
                totalErrors = result.total_errors;
                offset = result.next_offset;

                // Calcular progresso
                const progress = Math.min(100, Math.round((totalProcessed + totalErrors) / totalLinhas * 100));

                // Atualizar barra de progresso
                const progressBar = document.getElementById('progressBar');
                progressBar.style.width = progress + '%';
                progressBar.textContent = progress + '%';
                progressBar.setAttribute('aria-valuenow', progress);

                // Atualizar texto
                document.getElementById('progressText').textContent =
                    `Processando... ${totalProcessed + totalErrors} de ${totalLinhas} linhas`;
                document.getElementById('progressDetails').textContent =
                    `✓ ${totalProcessed} processadas | ✗ ${totalErrors} com erro`;

                // Se não completou, processar próximo chunk
                if (result.has_more) {
                    await processNextChunk(mapeamento);
                } else {
                    // Completou!
                    document.getElementById('progressText').innerHTML =
                        '<i class="bi bi-check-circle text-success"></i> Importação Concluída!';
                    document.getElementById('progressBar').classList.remove('progress-bar-animated');
                    document.getElementById('progressBar').classList.add('bg-success');

                    // Redirecionar após 2 segundos
                    setTimeout(() => {
                        window.location.href = 'index.php?import_success=1';
                    }, 2000);
                }

            } catch (error) {
                console.error('Erro completo:', error);
                document.getElementById('progressContainer').classList.add('d-none');
                const errorAlert = document.getElementById('errorAlert');

                let errorMsg = 'Erro ao processar importação: ' + error.message;

                // Se a mensagem indica que os dados foram importados mas houve erro nas estatísticas
                if (error.message.includes('Dados importados com sucesso')) {
                    errorMsg += '<br><br><strong>✓ Os títulos foram importados corretamente!</strong><br>' +
                                'Apenas as estatísticas agregadas falharam. Você pode visualizar os dados normalmente.';

                    // Redirecionar mesmo com erro parcial
                    setTimeout(() => {
                        window.location.href = 'index.php?import_partial=1';
                    }, 5000);
                }

                errorAlert.innerHTML = errorMsg;
                errorAlert.classList.remove('d-none');

                // Só mostrar formulário novamente se for erro total
                if (!error.message.includes('Dados importados com sucesso')) {
                    document.getElementById('mappingCard').classList.remove('d-none');
                }
            }
        }
    </script>
</body>
</html>
