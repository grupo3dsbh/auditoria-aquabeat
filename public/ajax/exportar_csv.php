<?php
define('APP_ROOT', dirname(dirname(__DIR__)));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAuth('login.php');

try {
    $db = Database::getInstance();

    // Obter última importação
    $ultimaImportacao = $db->fetchOne("SELECT * FROM importacoes WHERE status = 'concluido' ORDER BY concluido_em DESC LIMIT 1");
    if (!$ultimaImportacao) {
        throw new Exception('Nenhuma importação encontrada');
    }

    $importacaoId = $ultimaImportacao['id'];

    // Montar WHERE com filtros (mesma lógica de relatorios.php)
    $where = ["importacao_id = ?"];
    $params = [$importacaoId];

    $dataInicio = !empty($_POST['data_inicio']) ? $_POST['data_inicio'] : null;
    $dataFim = !empty($_POST['data_fim']) ? $_POST['data_fim'] : null;

    if ($dataInicio) {
        $where[] = "data_primeira_venda >= ?";
        $params[] = $dataInicio;
    }

    if ($dataFim) {
        $where[] = "data_primeira_venda <= ?";
        $params[] = $dataFim;
    }

    // Filtro por prefixo do título
    if (isset($_POST['prefixo_titulo']) && !empty($_POST['prefixo_titulo'])) {
        $prefixos = is_array($_POST['prefixo_titulo']) ? $_POST['prefixo_titulo'] : [$_POST['prefixo_titulo']];
        $placeholders = implode(',', array_fill(0, count($prefixos), '?'));
        $where[] = "prefixo IN ($placeholders)";
        foreach ($prefixos as $prefixo) {
            $params[] = $prefixo;
        }
    }

    // Filtro por status do título
    if (isset($_POST['status_titulo']) && !empty($_POST['status_titulo'])) {
        $statusTitulos = is_array($_POST['status_titulo']) ? $_POST['status_titulo'] : [$_POST['status_titulo']];
        $placeholders = implode(',', array_fill(0, count($statusTitulos), '?'));
        $where[] = "status_titulo IN ($placeholders)";
        foreach ($statusTitulos as $status) {
            $params[] = $status;
        }
    }

    if (!empty($_POST['status_inadimplencia'])) {
        if ($_POST['status_inadimplencia'] == 'INADIMPLENTE') {
            $where[] = "status_inadimplencia LIKE 'INADIMPLENTE%'";
        } else {
            $where[] = "status_inadimplencia = ?";
            $params[] = $_POST['status_inadimplencia'];
        }
    }

    // Filtro especial para categoria "REQUER ANÁLISE"
    if (!empty($_POST['status_inadimplencia_categoria']) && $_POST['status_inadimplencia_categoria'] == 'requer_analise') {
        $where[] = "(status_inadimplencia LIKE '%Requer análise%'
                     OR status_inadimplencia = 'INADIMPLENTE - Apenas 1ª Parcela'
                     OR status_inadimplencia = 'INADIMPLENTE - Apenas 2 Parcelas'
                     OR status_inadimplencia = 'INADIMPLENTE - Até 3 meses')";
    }

    // Filtro de pesquisa geral
    if (!empty($_POST['pesquisa_geral'])) {
        $pesquisaGeral = $_POST['pesquisa_geral'];
        $where[] = "(nome_titular LIKE ? OR documento_titular LIKE ? OR numero_titulo LIKE ?)";
        $params[] = '%' . $pesquisaGeral . '%';
        $params[] = '%' . $pesquisaGeral . '%';
        $params[] = '%' . $pesquisaGeral . '%';
    }

    if (!empty($_POST['promotor'])) {
        $where[] = "promotor = ?";
        $params[] = $_POST['promotor'];
    }

    if (!empty($_POST['qtd_parcelas_pagas'])) {
        $where[] = "qtd_parcelas_pagas = ?";
        $params[] = $_POST['qtd_parcelas_pagas'];
    }

    if (!empty($_POST['tipo_titulo'])) {
        $where[] = "nome_produto_atual LIKE ?";
        $params[] = '%' . $_POST['tipo_titulo'] . '%';
    }

    if (!empty($_POST['apenas_1parcela']) && $_POST['apenas_1parcela'] == '1') {
        $where[] = "status_inadimplencia = 'INADIMPLENTE - Apenas 1ª Parcela'";
    }

    // Filtro por parcelas pagas (multi-select)
    if (isset($_POST['parcelas_filtro']) && !empty($_POST['parcelas_filtro'])) {
        $parcelasFiltro = is_array($_POST['parcelas_filtro']) ? $_POST['parcelas_filtro'] : [$_POST['parcelas_filtro']];
        $condicoesParc = [];

        foreach ($parcelasFiltro as $filtro) {
            if ($filtro === '0') {
                $condicoesParc[] = "(qtd_parcelas_pagas = 0 OR qtd_parcelas_pagas IS NULL)";
            } elseif ($filtro === '1') {
                $condicoesParc[] = "qtd_parcelas_pagas = 1";
            } elseif ($filtro === '2') {
                $condicoesParc[] = "qtd_parcelas_pagas = 2";
            } elseif ($filtro === '3') {
                $condicoesParc[] = "qtd_parcelas_pagas = 3";
            } elseif ($filtro === '4-6') {
                $condicoesParc[] = "(qtd_parcelas_pagas >= 4 AND qtd_parcelas_pagas <= 6)";
            } elseif ($filtro === '7-11') {
                $condicoesParc[] = "(qtd_parcelas_pagas >= 7 AND qtd_parcelas_pagas <= 11)";
            } elseif ($filtro === '12+') {
                $condicoesParc[] = "qtd_parcelas_pagas >= 12";
            } elseif ($filtro === '3+') {
                $condicoesParc[] = "qtd_parcelas_pagas >= 3";
            }
        }

        if (!empty($condicoesParc)) {
            $where[] = "(" . implode(' OR ', $condicoesParc) . ")";
        }
    }

    // Filtro por CPF duplicado
    if (!empty($_POST['cpf_duplicado']) && $_POST['cpf_duplicado'] == '1') {
        $whereSubquery = implode(' AND ', $where);
        $paramsSubquery = $params;

        $where[] = "documento_titular IN (
            SELECT documento_titular
            FROM titulos
            WHERE " . $whereSubquery . "
            GROUP BY documento_titular
            HAVING COUNT(*) > 1
        )";

        foreach ($paramsSubquery as $param) {
            $params[] = $param;
        }
    }

    // Filtro por cartões usados em múltiplos títulos
    if (!empty($_POST['cartao_multiplo']) && $_POST['cartao_multiplo'] == '1') {
        $where[] = "id IN (
            SELECT t.id
            FROM titulos t
            INNER JOIN titulo_cartoes tc ON tc.titulo_id = t.id
            WHERE tc.numero_cartao IN (
                SELECT numero_cartao
                FROM titulo_cartoes
                GROUP BY numero_cartao
                HAVING COUNT(DISTINCT titulo_id) > 1
            )
        )";
    }

    // Filtro por apenas cartão de débito
    if (!empty($_POST['apenas_debito']) && $_POST['apenas_debito'] == '1') {
        $where[] = "id IN (
            SELECT t.id
            FROM titulos t
            INNER JOIN titulo_cartoes tc ON tc.titulo_id = t.id
            WHERE tc.bandeira LIKE '%DEBITO%'
               OR tc.bandeira LIKE '%DEBIT%'
               OR tc.tipo_pagamento LIKE '%DEBITO%'
               OR tc.tipo_pagamento LIKE '%DEBIT%'
        )";
    }

    // Ordenação
    $orderBy = !empty($_POST['order_by']) ? $_POST['order_by'] : 'data_primeira_venda';
    $orderDir = !empty($_POST['order_dir']) && $_POST['order_dir'] == 'ASC' ? 'ASC' : 'DESC';

    // Buscar registros COM DADOS DO CARTÃO
    $sql = "SELECT t.*,
            (SELECT tc.numero_cartao FROM titulo_cartoes tc WHERE tc.titulo_id = t.id LIMIT 1) as numero_cartao_usado,
            (SELECT tc.bandeira FROM titulo_cartoes tc WHERE tc.titulo_id = t.id LIMIT 1) as bandeira_cartao,
            (SELECT tc.tipo_pagamento FROM titulo_cartoes tc WHERE tc.titulo_id = t.id LIMIT 1) as tipo_pagamento_cartao,
            CASE
                WHEN (SELECT tc.bandeira FROM titulo_cartoes tc WHERE tc.titulo_id = t.id LIMIT 1) LIKE '%DEBITO%' THEN 'DÉBITO'
                WHEN (SELECT tc.bandeira FROM titulo_cartoes tc WHERE tc.titulo_id = t.id LIMIT 1) LIKE '%DEBIT%' THEN 'DÉBITO'
                WHEN (SELECT tc.tipo_pagamento FROM titulo_cartoes tc WHERE tc.titulo_id = t.id LIMIT 1) LIKE '%DEBITO%' THEN 'DÉBITO'
                WHEN (SELECT tc.tipo_pagamento FROM titulo_cartoes tc WHERE tc.titulo_id = t.id LIMIT 1) LIKE '%DEBIT%' THEN 'DÉBITO'
                WHEN (SELECT tc.bandeira FROM titulo_cartoes tc WHERE tc.titulo_id = t.id LIMIT 1) LIKE '%CREDITO%' THEN 'CRÉDITO'
                WHEN (SELECT tc.bandeira FROM titulo_cartoes tc WHERE tc.titulo_id = t.id LIMIT 1) LIKE '%CREDIT%' THEN 'CRÉDITO'
                WHEN (SELECT tc.tipo_pagamento FROM titulo_cartoes tc WHERE tc.titulo_id = t.id LIMIT 1) LIKE '%CREDITO%' THEN 'CRÉDITO'
                WHEN (SELECT tc.tipo_pagamento FROM titulo_cartoes tc WHERE tc.titulo_id = t.id LIMIT 1) LIKE '%CREDIT%' THEN 'CRÉDITO'
                ELSE 'OUTRO'
            END as tipo_cartao,
            CASE
                WHEN (SELECT tc.bandeira FROM titulo_cartoes tc WHERE tc.titulo_id = t.id LIMIT 1) LIKE '%DEBITO%' THEN 1
                WHEN (SELECT tc.bandeira FROM titulo_cartoes tc WHERE tc.titulo_id = t.id LIMIT 1) LIKE '%DEBIT%' THEN 1
                WHEN (SELECT tc.tipo_pagamento FROM titulo_cartoes tc WHERE tc.titulo_id = t.id LIMIT 1) LIKE '%DEBITO%' THEN 1
                WHEN (SELECT tc.tipo_pagamento FROM titulo_cartoes tc WHERE tc.titulo_id = t.id LIMIT 1) LIKE '%DEBIT%' THEN 1
                WHEN (SELECT tc.bandeira FROM titulo_cartoes tc WHERE tc.titulo_id = t.id LIMIT 1) LIKE '%CREDITO%' THEN 2
                WHEN (SELECT tc.bandeira FROM titulo_cartoes tc WHERE tc.titulo_id = t.id LIMIT 1) LIKE '%CREDIT%' THEN 2
                WHEN (SELECT tc.tipo_pagamento FROM titulo_cartoes tc WHERE tc.titulo_id = t.id LIMIT 1) LIKE '%CREDITO%' THEN 2
                WHEN (SELECT tc.tipo_pagamento FROM titulo_cartoes tc WHERE tc.titulo_id = t.id LIMIT 1) LIKE '%CREDIT%' THEN 2
                ELSE 3
            END as tipo_cartao_ordem
            FROM titulos t
            WHERE " . implode(' AND ', $where) . "
            ORDER BY " . ($orderBy == 'tipo_cartao' ? "tipo_cartao_ordem {$orderDir}, numero_cartao_usado {$orderDir}" : ($orderBy == 'numero_cartao_usado' ? "numero_cartao_usado {$orderDir}, tipo_cartao_ordem ASC" : "{$orderBy} {$orderDir}"));

    $titulos = $db->fetchAll($sql, $params);

    // Gerar CSV
    $filename = 'relatorio_inadimplencia_' . date('Y-m-d_His') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Adicionar BOM UTF-8 para Excel reconhecer encoding
    echo "\xEF\xBB\xBF";

    // Abrir output
    $output = fopen('php://output', 'w');

    // Cabeçalho
    fputcsv($output, [
        'Título',
        'Prefixo',
        'Titular',
        'CPF/CNPJ',
        'Promotor',
        'Produto',
        'Data Venda',
        'Status Título',
        'Status Inadimplência',
        'Parcelas Pagas',
        'Total Parcelas',
        'Valor Parcela',
        'Total Pago',
        'Saldo Devedor',
        'Nº Cartão',
        'Tipo Cartão',
        'Bandeira',
        'Tipo Pagamento'
    ], ';');

    // Dados
    foreach ($titulos as $titulo) {
        fputcsv($output, [
            $titulo['numero_titulo'] ?? '',
            $titulo['prefixo'] ?? '',
            $titulo['nome_titular'] ?? '',
            $titulo['documento_titular'] ?? '',
            $titulo['promotor'] ?? '',
            $titulo['nome_produto_atual'] ?? '',
            $titulo['data_primeira_venda'] ? date('d/m/Y', strtotime($titulo['data_primeira_venda'])) : '',
            $titulo['status_titulo'] ?? '',
            $titulo['status_inadimplencia'] ?? '',
            $titulo['qtd_parcelas_pagas'] ?? '0',
            $titulo['total_parcelas'] ?? '0',
            'R$ ' . number_format($titulo['valor_parcela'] ?? 0, 2, ',', '.'),
            'R$ ' . number_format($titulo['total_pago'] ?? 0, 2, ',', '.'),
            'R$ ' . number_format($titulo['saldo_devedor'] ?? 0, 2, ',', '.'),
            $titulo['numero_cartao_usado'] ? substr($titulo['numero_cartao_usado'], -4) : '',
            $titulo['tipo_cartao'] ?? '',
            $titulo['bandeira_cartao'] ?? '',
            $titulo['tipo_pagamento_cartao'] ?? ''
        ], ';');
    }

    fclose($output);
    exit;

} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    exit;
}
