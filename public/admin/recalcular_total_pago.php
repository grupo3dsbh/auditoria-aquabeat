<?php
define('APP_ROOT', dirname(dirname(__DIR__)));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAdmin();

$db = Database::getInstance();

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recalcular Total Pago - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <?php include '../navbar.php'; ?>

    <div class="container mt-4">
        <h2><i class="bi bi-cash-coin"></i> Recalcular Total Pago (Filtrar Consumos e Pulseiras)</h2>
        <p class="text-muted">Remove "Consumo crédito" e "Pulseira Troca" do cálculo do Total Pago</p>

        <div class="alert alert-warning">
            <strong>⚠️ Importante:</strong> Esta operação recalcula o valor <code>total_pago</code>
            removendo valores de "Consumo crédito" e "Pulseira Troca" da soma total.
        </div>

        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar'])) {
            try {
                $db->beginTransaction();

                // Buscar todos os títulos com lista_parcelas_pagas e lista_valores_pagos
                $titulos = $db->fetchAll("
                    SELECT id, numero_titulo, total_pago, valor_total_plano,
                           lista_parcelas_pagas, lista_valores_pagos
                    FROM titulos
                    WHERE lista_parcelas_pagas IS NOT NULL
                      AND lista_valores_pagos IS NOT NULL
                      AND lista_parcelas_pagas != ''
                      AND lista_valores_pagos != ''
                    ORDER BY id
                ");

                $totalProcessados = 0;
                $totalCorrigidos = 0;
                $erros = [];

                foreach ($titulos as $titulo) {
                    $totalProcessados++;

                    // Separar parcelas e valores
                    $parcelas = array_map('trim', explode('|', $titulo['lista_parcelas_pagas']));
                    $valores = array_map('trim', explode('|', $titulo['lista_valores_pagos']));

                    // Garantir que parcelas e valores têm o mesmo tamanho
                    if (count($parcelas) !== count($valores)) {
                        $erros[] = "Título {$titulo['numero_titulo']}: Quantidade de parcelas (" . count($parcelas) . ") diferente de valores (" . count($valores) . ")";
                        continue;
                    }

                    // Filtrar valores válidos (remover consumos e pulseiras)
                    $totalPagoValido = 0;
                    for ($i = 0; $i < count($parcelas); $i++) {
                        $parcela = strtolower($parcelas[$i]);
                        $valor = $valores[$i];

                        // Pular se vazio ou NULL
                        if (empty($parcela) || $parcela === 'null' || empty($valor) || $valor === 'null') {
                            continue;
                        }

                        // Ignorar consumo crédito
                        if (stripos($parcela, 'consumo') !== false && stripos($parcela, 'credito') !== false) {
                            continue;
                        }

                        // Ignorar pulseira troca
                        if (stripos($parcela, 'pulseira') !== false && stripos($parcela, 'troca') !== false) {
                            continue;
                        }

                        // Converter valor para decimal
                        $valorDecimal = floatval(str_replace(',', '.', $valor));
                        $totalPagoValido += $valorDecimal;
                    }

                    // Arredondar para 2 casas decimais
                    $totalPagoValido = round($totalPagoValido, 2);

                    // Verificar se mudou
                    $totalPagoAntes = floatval($titulo['total_pago']);
                    if (abs($totalPagoValido - $totalPagoAntes) > 0.01) {
                        // Atualizar total_pago
                        $db->update('titulos', [
                            'total_pago' => $totalPagoValido
                        ], 'id = ?', [$titulo['id']]);

                        // Recalcular saldo_restante
                        $valorTotalPlano = floatval($titulo['valor_total_plano']);
                        $saldoRestante = $valorTotalPlano - $totalPagoValido;
                        $db->update('titulos', [
                            'saldo_restante' => $saldoRestante
                        ], 'id = ?', [$titulo['id']]);

                        $totalCorrigidos++;
                    }
                }

                $db->commit();

                echo '<div class="alert alert-success">';
                echo '<h5>✅ Recálculo Concluído!</h5>';
                echo '<ul>';
                echo '<li><strong>Títulos processados:</strong> ' . number_format($totalProcessados, 0, ',', '.') . '</li>';
                echo '<li><strong>Títulos corrigidos:</strong> ' . number_format($totalCorrigidos, 0, ',', '.') . '</li>';
                echo '</ul>';
                echo '</div>';

                if (count($erros) > 0) {
                    echo '<div class="alert alert-warning">';
                    echo '<h6>⚠️ Avisos (' . count($erros) . '):</h6>';
                    echo '<ul>';
                    foreach (array_slice($erros, 0, 10) as $erro) {
                        echo '<li>' . htmlspecialchars($erro) . '</li>';
                    }
                    if (count($erros) > 10) {
                        echo '<li><em>... e mais ' . (count($erros) - 10) . ' avisos</em></li>';
                    }
                    echo '</ul>';
                    echo '</div>';
                }

            } catch (Exception $e) {
                if ($db->getConnection()->inTransaction()) {
                    $db->rollback();
                }
                echo '<div class="alert alert-danger">';
                echo '<strong>❌ Erro:</strong> ' . htmlspecialchars($e->getMessage());
                echo '</div>';
            }
        } else {
            ?>
            <div class="card">
                <div class="card-body">
                    <h5>Como funciona?</h5>
                    <ol>
                        <li>Busca todos os títulos com <code>lista_parcelas_pagas</code> e <code>lista_valores_pagos</code></li>
                        <li>Para cada título, recalcula <code>total_pago</code> somando APENAS:</li>
                        <ul>
                            <li>✅ Parcelas do plano ("Sócio...")</li>
                            <li>✅ "Diferença de mensalidade"</li>
                        </ul>
                        <li>Ignora (não soma):</li>
                        <ul>
                            <li>❌ "Consumo crédito"</li>
                            <li>❌ "Pulseira Troca"</li>
                        </ul>
                        <li>Atualiza <code>total_pago</code> e recalcula <code>saldo_restante</code></li>
                    </ol>

                    <form method="POST" class="mt-4">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="confirmar" name="confirmar" required>
                            <label class="form-check-label" for="confirmar">
                                Confirmo que desejo recalcular o Total Pago de todos os títulos
                            </label>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-calculator"></i> Recalcular Total Pago
                        </button>
                        <a href="<?php echo url('admin/'); ?>" class="btn btn-secondary">Voltar</a>
                    </form>
                </div>
            </div>
            <?php
        }
        ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
