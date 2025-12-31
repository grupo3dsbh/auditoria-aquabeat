<?php
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/bootstrap.php';

requireAuth('login.php');

$db = Database::getInstance();

// Obter última importação
$ultimaImportacao = $db->fetchOne("SELECT * FROM importacoes WHERE status = 'concluido' ORDER BY concluido_em DESC LIMIT 1");

if (!$ultimaImportacao) {
    setFlashMessage('error', 'Nenhuma importação concluída encontrada. Faça uma importação primeiro.');
    redirect('upload.php');
}

$importacaoId = $ultimaImportacao['id'];

// Buscar token ativo do usuário atual (para auto-preenchimento no PDF)
$tokenUsuario = null;
$userId = $_SESSION['user_id'];
try {
    $tokenUsuario = $db->fetchOne("
        SELECT token FROM api_tokens
        WHERE usuario_id = ?
        AND ativo = 1
        AND (expira_em IS NULL OR expira_em > NOW())
        ORDER BY criado_em DESC
        LIMIT 1
    ", [$userId]);
} catch (Exception $e) {
    // Tabela pode não existir ainda, ignorar erro
}

// Buscar primeira data de venda no sistema (para botão "Todo Período")
$primeiraDataVenda = $db->fetchColumn("SELECT MIN(data_primeira_venda) FROM titulos WHERE importacao_id = ?", [$importacaoId]);
if ($primeiraDataVenda) {
    $primeiraDataVenda = date('Y-m-d', strtotime($primeiraDataVenda));
} else {
    $primeiraDataVenda = '2024-01-01'; // Fallback
}

// Detectar se é visualização apenas de cartões
$viewCartoes = !empty($_GET['view']) && $_GET['view'] === 'cartoes';

// Calcular data fim padrão: data de hoje
$dataFimPadrao = date('Y-m-d');

// Filtros
$filtros = [];
$where = ["importacao_id = ?"];
$params = [$importacaoId];

// Filtro padrão: Data configurável pelo admin
$dataInicio = !empty($_GET['data_inicio']) ? $_GET['data_inicio'] : getConfig('data_inicio_relatorios', '2024-11-01');
$dataFim = !empty($_GET['data_fim']) ? $_GET['data_fim'] : $dataFimPadrao;

$where[] = "data_primeira_venda >= ?";
$params[] = $dataInicio . ' 00:00:00';
$filtros['data_inicio'] = $dataInicio;

$where[] = "data_primeira_venda <= ?";
$params[] = $dataFim . ' 23:59:59';
$filtros['data_fim'] = $dataFim;

// IMPORTANTE: Filtrar apenas títulos marcados para uso em relatórios
// Verifica se a coluna usado_relatorios existe, senão usa filtro SFA/SBF
try {
    $colunaExiste = $db->fetchColumn("SHOW COLUMNS FROM titulos LIKE 'usado_relatorios'");
    if ($colunaExiste) {
        $where[] = "usado_relatorios = TRUE";
        $filtros['usado_relatorios'] = true;
    } else {
        // Fallback: usar filtro SFA/SBF se coluna não existir
        $where[] = "(numero_titulo LIKE 'SFA%' OR numero_titulo LIKE 'SBF%')";
        $filtros['prefixos'] = ['SFA', 'SBF'];
    }
} catch (Exception $e) {
    // Em caso de erro, usar filtro SFA/SBF
    $where[] = "(numero_titulo LIKE 'SFA%' OR numero_titulo LIKE 'SBF%')";
    $filtros['prefixos'] = ['SFA', 'SBF'];
}

// Filtro por status do título (permite múltiplos)
if (!empty($_GET['status_titulo'])) {
    $statusTitulos = is_array($_GET['status_titulo']) ? $_GET['status_titulo'] : [$_GET['status_titulo']];
    $statusTitulos = array_filter($statusTitulos); // Remove valores vazios

    if (!empty($statusTitulos)) {
        $placeholders = implode(',', array_fill(0, count($statusTitulos), '?'));
        $where[] = "status_titulo IN ($placeholders)";
        foreach ($statusTitulos as $status) {
            $params[] = $status;
        }
        $filtros['status_titulo'] = $statusTitulos;
    }
}

if (!empty($_GET['status_inadimplencia'])) {
    if ($_GET['status_inadimplencia'] == 'INADIMPLENTE') {
        $where[] = "status_inadimplencia LIKE 'INADIMPLENTE%'";
    } else {
        $where[] = "status_inadimplencia = ?";
        $params[] = $_GET['status_inadimplencia'];
    }
    $filtros['status_inadimplencia'] = $_GET['status_inadimplencia'];
}

// Filtro especial para categoria "REQUER ANÁLISE" (agrupamento de vários status)
if (!empty($_GET['status_inadimplencia_categoria']) && $_GET['status_inadimplencia_categoria'] == 'requer_analise') {
    $where[] = "(status_inadimplencia LIKE '%Requer análise%'
                 OR status_inadimplencia = 'INADIMPLENTE - Apenas 1ª Parcela'
                 OR status_inadimplencia = 'INADIMPLENTE - Apenas 2 Parcelas'
                 OR status_inadimplencia = 'INADIMPLENTE - Até 3 meses')";
    $filtros['status_inadimplencia'] = 'Requer Análise';
}

// Filtro de pesquisa geral (nome, CPF ou número do título)
if (!empty($_GET['pesquisa_geral'])) {
    $pesquisaGeral = $_GET['pesquisa_geral'];
    $where[] = "(nome_titular LIKE ? OR documento_titular LIKE ? OR numero_titulo LIKE ?)";
    $params[] = '%' . $pesquisaGeral . '%';
    $params[] = '%' . $pesquisaGeral . '%';
    $params[] = '%' . $pesquisaGeral . '%';
    $filtros['pesquisa_geral'] = $pesquisaGeral;
}

if (!empty($_GET['promotor'])) {
    $where[] = "promotor = ?";
    $params[] = $_GET['promotor'];
    $filtros['promotor'] = $_GET['promotor'];
}

if (!empty($_GET['qtd_parcelas_pagas'])) {
    $where[] = "qtd_parcelas_pagas = ?";
    $params[] = $_GET['qtd_parcelas_pagas'];
    $filtros['qtd_parcelas_pagas'] = $_GET['qtd_parcelas_pagas'];
}

if (!empty($_GET['tipo_titulo'])) {
    $where[] = "nome_produto_atual LIKE ?";
    $params[] = '%' . $_GET['tipo_titulo'] . '%';
    $filtros['tipo_titulo'] = $_GET['tipo_titulo'];
}

if (!empty($_GET['apenas_1parcela']) && $_GET['apenas_1parcela'] == '1') {
    $where[] = "status_inadimplencia = 'INADIMPLENTE - Apenas 1ª Parcela'";
    $filtros['apenas_1parcela'] = '1';
}

// Filtro por parcelas pagas (multi-select)
if (isset($_GET['parcelas_filtro']) && !empty($_GET['parcelas_filtro'])) {
    $parcelasFiltro = is_array($_GET['parcelas_filtro']) ? $_GET['parcelas_filtro'] : [$_GET['parcelas_filtro']];
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
            // Manter compatibilidade com filtro antigo
            $condicoesParc[] = "qtd_parcelas_pagas >= 3";
        }
    }

    if (!empty($condicoesParc)) {
        $where[] = "(" . implode(' OR ', $condicoesParc) . ")";
    }

    $filtros['parcelas_filtro'] = $parcelasFiltro;
}

// Filtro por CPF duplicado
// IMPORTANTE: Considera os filtros JÁ APLICADOS para determinar quais CPFs são duplicados
if (!empty($_GET['cpf_duplicado']) && $_GET['cpf_duplicado'] == '1') {
    // Criar subquery com os mesmos filtros já aplicados
    $whereSubquery = implode(' AND ', $where);
    $paramsSubquery = $params;

    $where[] = "documento_titular IN (
        SELECT documento_titular
        FROM titulos
        WHERE " . $whereSubquery . "
        GROUP BY documento_titular
        HAVING COUNT(*) > 1
    )";

    // Adicionar os parâmetros da subquery
    foreach ($paramsSubquery as $param) {
        $params[] = $param;
    }

    $filtros['cpf_duplicado'] = '1';
}

// Ordenação
// Se filtro CPF duplicado estiver ativo, ordenar por documento_titular automaticamente
if (!empty($_GET['cpf_duplicado']) && $_GET['cpf_duplicado'] == '1') {
    $orderBy = 'documento_titular';
    $orderDir = 'ASC';
} else {
    $orderBy = !empty($_GET['order_by']) ? $_GET['order_by'] : 'data_primeira_venda';
    $orderDir = !empty($_GET['order_dir']) && $_GET['order_dir'] == 'ASC' ? 'ASC' : 'DESC';
}
$filtros['order_by'] = $orderBy;
$filtros['order_dir'] = $orderDir;

// Paginação
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = RECORDS_PER_PAGE;
$offset = ($page - 1) * $perPage;

// Contar total
$totalSql = "SELECT COUNT(*) FROM titulos WHERE " . implode(' AND ', $where);
$total = $db->fetchColumn($totalSql, $params);

// Contar títulos por categoria de inadimplência (para legenda)
$whereBase = implode(' AND ', $where);
$contadores = [
    'requer_analise' => $db->fetchColumn("
        SELECT COUNT(*) FROM titulos
        WHERE $whereBase
          AND (status_inadimplencia LIKE '%Requer análise%'
               OR status_inadimplencia = 'INADIMPLENTE - Apenas 1ª Parcela'
               OR status_inadimplencia = 'INADIMPLENTE - Apenas 2 Parcelas'
               OR status_inadimplencia = 'INADIMPLENTE - Até 3 meses')
    ", $params),
    'tres_seis' => $db->fetchColumn("
        SELECT COUNT(*) FROM titulos
        WHERE $whereBase AND status_inadimplencia = 'INADIMPLENTE - 3 a 6 meses'
    ", $params),
    'seis_nove' => $db->fetchColumn("
        SELECT COUNT(*) FROM titulos
        WHERE $whereBase AND status_inadimplencia = 'INADIMPLENTE - 6 a 9 meses'
    ", $params),
    'nove_doze' => $db->fetchColumn("
        SELECT COUNT(*) FROM titulos
        WHERE $whereBase AND status_inadimplencia = 'INADIMPLENTE - 9 a 12 meses'
    ", $params),
    'mais_doze' => $db->fetchColumn("
        SELECT COUNT(*) FROM titulos
        WHERE $whereBase AND status_inadimplencia = 'INADIMPLENTE - Mais de 12 meses'
    ", $params),
    'adimplente' => $db->fetchColumn("
        SELECT COUNT(*) FROM titulos
        WHERE $whereBase AND status_inadimplencia = 'ADIMPLENTE'
    ", $params)
];

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
        ORDER BY " . ($orderBy == 'tipo_cartao' ? "tipo_cartao_ordem {$orderDir}, numero_cartao_usado {$orderDir}" : ($orderBy == 'numero_cartao_usado' ? "numero_cartao_usado {$orderDir}, tipo_cartao_ordem ASC" : "{$orderBy} {$orderDir}")) . "
        LIMIT ? OFFSET ?";

$params[] = $perPage;
$params[] = $offset;

$titulos = $db->fetchAll($sql, $params);

// REGRA ESPECIAL: Se período filtrado for menor que 6 meses, aplicar classificação rigorosa
// Qualquer parcela em atraso = inadimplente (sem tolerância de 2 parcelas)
$periodoRigoroso = false;

if (!empty($dataInicio) && !empty($dataFim)) {
    $dtInicio = new DateTime($dataInicio);
    $dtFim = new DateTime($dataFim);
    $diff = $dtInicio->diff($dtFim);
    $mesesPeriodo = ($diff->y * 12) + $diff->m;

    // Se período filtrado for menor que 6 meses, ativar modo rigoroso
    if ($mesesPeriodo < 6) {
        $periodoRigoroso = true;

        // Recalcular status de cada título com regra rigorosa
        foreach ($titulos as &$titulo) {
            // Pular se não tiver dados necessários
            if (empty($titulo['data_primeira_venda']) || !isset($titulo['qtd_parcelas_pagas'])) {
                continue;
            }

            // REGRA ESPECIAL: Cotas de Premiação (SAP, DIP) sempre ADIMPLENTE
            $nomeProduto = strtoupper($titulo['nome_produto_atual'] ?? $titulo['nome_produto_original'] ?? '');
            $numeroTitulo = strtoupper($titulo['numero_titulo'] ?? '');
            $promotor = strtoupper($titulo['promotor'] ?? '');

            $ehCotaPremiacao = (
                strpos($nomeProduto, 'SAP') !== false ||
                strpos($nomeProduto, 'DIP') !== false ||
                strpos($numeroTitulo, '-SAP') !== false ||
                strpos($numeroTitulo, '-DIP') !== false ||
                strpos($promotor, 'AQUABEAT') !== false ||
                strpos($promotor, 'DOUGLAS RIBEIRO') !== false ||
                strpos($promotor, 'PREMIAÇÃO') !== false ||
                strpos($promotor, 'PREMIACAO') !== false
            );

            if ($ehCotaPremiacao) {
                $titulo['status_inadimplencia'] = 'ADIMPLENTE';
                continue;
            }

            $parcelasPagas = (int)$titulo['qtd_parcelas_pagas'];
            $totalParcelas = (int)$titulo['quantidade_parcelas_venda'];
            $statusTitulo = $titulo['status_titulo'] ?? 'Ativo';
            $statusAtual = $titulo['status_inadimplencia'];

            // Se já está ADIMPLENTE no banco (pagou tudo), manter
            if ($parcelasPagas >= $totalParcelas) {
                continue;
            }

            // Se não pagou NENHUMA parcela, já está marcado como inadimplente, manter
            if ($parcelasPagas == 0) {
                continue;
            }

            // Para títulos bloqueados/cancelados, manter classificação original
            if ($statusTitulo === 'Bloqueado' || $statusTitulo === 'Cancelado') {
                continue;
            }

            // APLICAR REGRA RIGOROSA: Calcular parcelas esperadas
            $dataVenda = new DateTime($titulo['data_primeira_venda']);
            $hoje = new DateTime();
            $diffVenda = $dataVenda->diff($hoje);
            $mesesDesdeVenda = ($diffVenda->y * 12) + $diffVenda->m;

            // Se já passou o dia de vencimento no mês atual, conta mais um mês
            $diaVenda = (int)$dataVenda->format('d');
            $diaHoje = (int)$hoje->format('d');
            if ($diaHoje >= $diaVenda) {
                $mesesDesdeVenda++;
            }

            $parcelasEsperadas = max(1, $mesesDesdeVenda);
            $parcelasEmAtraso = max(0, $parcelasEsperadas - $parcelasPagas);

            // REGRA RIGOROSA: Qualquer parcela em atraso = INADIMPLENTE
            if ($parcelasEmAtraso > 0) {
                // Reclassificar baseado no tempo desde a venda
                if ($mesesDesdeVenda <= 3) {
                    if ($parcelasPagas == 1) {
                        $titulo['status_inadimplencia'] = 'INADIMPLENTE - Requer análise (1ª parcela)';
                    } elseif ($parcelasPagas == 2) {
                        $titulo['status_inadimplencia'] = 'INADIMPLENTE - Requer análise (2 parcelas)';
                    } else {
                        $titulo['status_inadimplencia'] = 'INADIMPLENTE - Requer análise (até 3 meses)';
                    }
                } elseif ($mesesDesdeVenda <= 6) {
                    $titulo['status_inadimplencia'] = 'INADIMPLENTE - 3 a 6 meses';
                } elseif ($mesesDesdeVenda <= 9) {
                    $titulo['status_inadimplencia'] = 'INADIMPLENTE - 6 a 9 meses';
                } elseif ($mesesDesdeVenda <= 12) {
                    $titulo['status_inadimplencia'] = 'INADIMPLENTE - 9 a 12 meses';
                } else {
                    $titulo['status_inadimplencia'] = 'INADIMPLENTE - Mais de 12 meses';
                }
            }
        }
        unset($titulo); // Limpar referência

        // Recalcular contadores com a nova classificação
        $contadores = [
            'requer_analise' => 0,
            'tres_seis' => 0,
            'seis_nove' => 0,
            'nove_doze' => 0,
            'mais_doze' => 0,
            'adimplente' => 0
        ];

        // Buscar TODOS os títulos (não só da página atual) para contadores corretos
        $sqlTodos = "SELECT * FROM titulos WHERE " . implode(' AND ', $where);
        $paramsTodos = array_slice($params, 0, -2); // Remover LIMIT e OFFSET
        $todosTitulos = $db->fetchAll($sqlTodos, $paramsTodos);

        // Aplicar mesma lógica rigorosa para todos os títulos
        foreach ($todosTitulos as &$tit) {
            if (empty($tit['data_primeira_venda']) || !isset($tit['qtd_parcelas_pagas'])) {
                continue;
            }

            // Verificar se é cota de premiação
            $np = strtoupper($tit['nome_produto_atual'] ?? $tit['nome_produto_original'] ?? '');
            $nt = strtoupper($tit['numero_titulo'] ?? '');
            $pr = strtoupper($tit['promotor'] ?? '');

            $ehPrem = (
                strpos($np, 'SAP') !== false ||
                strpos($np, 'DIP') !== false ||
                strpos($nt, '-SAP') !== false ||
                strpos($nt, '-DIP') !== false ||
                strpos($pr, 'AQUABEAT') !== false ||
                strpos($pr, 'DOUGLAS RIBEIRO') !== false ||
                strpos($pr, 'PREMIAÇÃO') !== false ||
                strpos($pr, 'PREMIACAO') !== false
            );

            if ($ehPrem) {
                $contadores['adimplente']++;
                continue;
            }

            $pp = (int)$tit['qtd_parcelas_pagas'];
            $tp = (int)$tit['quantidade_parcelas_venda'];
            $st = $tit['status_titulo'] ?? 'Ativo';

            if ($pp >= $tp) {
                $contadores['adimplente']++;
                continue;
            }

            if ($pp == 0 || $st === 'Bloqueado' || $st === 'Cancelado') {
                // Classificação original
                $status = $tit['status_inadimplencia'];
            } else {
                // Aplicar regra rigorosa
                $dv = new DateTime($tit['data_primeira_venda']);
                $h = new DateTime();
                $dif = $dv->diff($h);
                $m = ($dif->y * 12) + $dif->m;
                if ((int)$h->format('d') >= (int)$dv->format('d')) $m++;
                $pe = max(1, $m);
                $pa = max(0, $pe - $pp);

                if ($pa > 0) {
                    if ($m <= 3) {
                        $status = 'INADIMPLENTE - Requer análise';
                    } elseif ($m <= 6) {
                        $status = 'INADIMPLENTE - 3 a 6 meses';
                    } elseif ($m <= 9) {
                        $status = 'INADIMPLENTE - 6 a 9 meses';
                    } elseif ($m <= 12) {
                        $status = 'INADIMPLENTE - 9 a 12 meses';
                    } else {
                        $status = 'INADIMPLENTE - Mais de 12 meses';
                    }
                } else {
                    $status = 'ADIMPLENTE';
                }
            }

            // Contar baseado no status recalculado
            if ($status == 'ADIMPLENTE') {
                $contadores['adimplente']++;
            } elseif (strpos($status, 'Requer análise') !== false || strpos($status, 'Apenas 1ª') !== false ||
                      strpos($status, 'Apenas 2') !== false || strpos($status, 'Até 3 meses') !== false) {
                $contadores['requer_analise']++;
            } elseif (strpos($status, '3 a 6 meses') !== false) {
                $contadores['tres_seis']++;
            } elseif (strpos($status, '6 a 9 meses') !== false) {
                $contadores['seis_nove']++;
            } elseif (strpos($status, '9 a 12 meses') !== false) {
                $contadores['nove_doze']++;
            } elseif (strpos($status, 'Mais de 12 meses') !== false) {
                $contadores['mais_doze']++;
            }
        }
        unset($tit);
    }
}

// Se filtro CPF duplicado estiver ativo, criar array de contagem por CPF
$cpfContadores = [];
$cpfTituloIndice = []; // Mapeia titulo_id => [indice atual, total]

if (!empty($_GET['cpf_duplicado']) && $_GET['cpf_duplicado'] == '1') {
    // Buscar TODOS os títulos ordenados por CPF (para criar índices corretos)
    $sqlTodosTitulos = "SELECT id, documento_titular
                        FROM titulos
                        WHERE " . implode(' AND ', $where) . "
                        ORDER BY documento_titular ASC, id ASC";

    $paramsTodos = array_slice($params, 0, count($params) - 2); // Remove LIMIT e OFFSET
    $todosTitulos = $db->fetchAll($sqlTodosTitulos, $paramsTodos);

    // Criar índices: para cada CPF, numerar os títulos sequencialmente
    $cpfContagem = [];
    $cpfIndiceAtual = [];

    foreach ($todosTitulos as $titulo) {
        $cpf = $titulo['documento_titular'];

        if (!isset($cpfContagem[$cpf])) {
            $cpfContagem[$cpf] = 0;
            $cpfIndiceAtual[$cpf] = 0;
        }

        $cpfContagem[$cpf]++;
    }

    // Segunda passagem: criar mapa de índices
    foreach ($todosTitulos as $titulo) {
        $cpf = $titulo['documento_titular'];
        $cpfIndiceAtual[$cpf]++;

        $cpfTituloIndice[$titulo['id']] = [
            'indice' => $cpfIndiceAtual[$cpf],
            'total' => $cpfContagem[$cpf]
        ];
    }

    $cpfContadores = $cpfContagem;
}

// Estatísticas detalhadas
$stats = $db->fetchOne("
    SELECT
        COUNT(*) as total,

        -- Total de inadimplentes
        COUNT(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 END) as total_inadimplentes,
        COUNT(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' AND status_titulo = 'Ativo' THEN 1 END) as inadimplentes_ativos,
        COUNT(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' AND status_titulo = 'Bloqueado' THEN 1 END) as inadimplentes_bloqueados,
        COUNT(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' AND status_titulo = 'Cancelado' THEN 1 END) as inadimplentes_cancelados,

        -- Breakdown de títulos por status
        COUNT(CASE WHEN status_titulo = 'Ativo' THEN 1 END) as titulos_ativos,
        COUNT(CASE WHEN status_titulo = 'Bloqueado' THEN 1 END) as titulos_bloqueados,
        COUNT(CASE WHEN status_titulo = 'Cancelado' THEN 1 END) as titulos_cancelados,

        -- Valores financeiros
        COALESCE(SUM(valor_total_plano), 0) as valor_total_vendido,
        COALESCE(SUM(total_pago), 0) as valor_total_recebido,
        COALESCE(SUM(saldo_restante), 0) as valor_total_restante,

        -- Valor em risco (apenas inadimplentes)
        SUM(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN saldo_restante ELSE 0 END) as valor_em_risco,

        -- Valor perdido (apenas cancelados)
        SUM(CASE WHEN status_titulo = 'Cancelado' THEN saldo_restante ELSE 0 END) as valor_perdido,

        -- Valor a receber (adimplentes)
        SUM(CASE WHEN status_inadimplencia = 'ADIMPLENTE' THEN saldo_restante ELSE 0 END) as valor_a_receber_adimplente
    FROM titulos
    WHERE " . implode(' AND ', array_slice($where, 0, count($where))),
    array_slice($params, 0, count($params) - 2)
);

// Corrigir valor_total_vendido se estiver zerado mas tiver recebido/restante
// Isso pode acontecer se a coluna valor_total_plano estiver vazia no banco
if (($stats['valor_total_vendido'] ?? 0) == 0) {
    $somaCalculada = ($stats['valor_total_recebido'] ?? 0) + ($stats['valor_total_restante'] ?? 0);
    if ($somaCalculada > 0) {
        $stats['valor_total_vendido'] = $somaCalculada;
    }
}

// Detectar contexto do filtro para exibição condicional
$filtroStatus = $_GET['status_inadimplencia'] ?? '';
$filtroTitulo = $_GET['status_titulo'] ?? [];
// Se não for array, converte
if (!is_array($filtroTitulo)) {
    $filtroTitulo = empty($filtroTitulo) ? [] : [$filtroTitulo];
}

// Análise por tipo de inadimplência
$paramsAnalise = array_slice($params, 0, count($params) - 2);

// NOVA LÓGICA: Separar inadimplentes bloqueados/cancelados + organizar por categoria
$inadimplenciaPorTipo = $db->fetchAll("
    SELECT
        CASE
            -- Inadimplentes bloqueados ou cancelados (PRIORIDADE 1)
            WHEN status_inadimplencia LIKE 'INADIMPLENTE%' AND status_titulo IN ('Bloqueado', 'Cancelado')
                THEN 'INADIMPLENTE (Bloqueados e/ou Cancelados)'
            -- Categoria REQUER ANÁLISE (PRIORIDADE 2)
            WHEN status_inadimplencia LIKE 'INADIMPLENTE - Requer análise%'
                THEN 'REQUER ANÁLISE'
            -- 3 a 6 meses - Experiência (PRIORIDADE 3)
            WHEN status_inadimplencia = 'INADIMPLENTE - 3 a 6 meses'
                THEN '3 a 6 meses - Experiência'
            -- 6 a 9 meses - Expectativa (PRIORIDADE 4)
            WHEN status_inadimplencia = 'INADIMPLENTE - 6 a 9 meses'
                THEN '6 a 9 meses - Expectativa'
            -- 9 a 12 meses - Problema sério (PRIORIDADE 5)
            WHEN status_inadimplencia = 'INADIMPLENTE - 9 a 12 meses'
                THEN '9 a 12 meses - Problema sério'
            -- Mais de 12 meses - Crônico (PRIORIDADE 6)
            WHEN status_inadimplencia = 'INADIMPLENTE - Mais de 12 meses'
                THEN 'Mais de 12 meses - Crônico'
            -- Outros inadimplentes
            ELSE status_inadimplencia
        END as status_inadimplencia,
        COUNT(*) as total,
        SUM(saldo_restante) as valor_risco,
        ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM titulos WHERE " . implode(' AND ', $where) . "), 2) as percentual,
        -- Campo auxiliar para ordenação
        CASE
            WHEN status_inadimplencia LIKE 'INADIMPLENTE%' AND status_titulo IN ('Bloqueado', 'Cancelado') THEN 1
            WHEN status_inadimplencia LIKE 'INADIMPLENTE - Requer análise%' THEN 2
            WHEN status_inadimplencia = 'INADIMPLENTE - 3 a 6 meses' THEN 3
            WHEN status_inadimplencia = 'INADIMPLENTE - 6 a 9 meses' THEN 4
            WHEN status_inadimplencia = 'INADIMPLENTE - 9 a 12 meses' THEN 5
            WHEN status_inadimplencia = 'INADIMPLENTE - Mais de 12 meses' THEN 6
            ELSE 99
        END as ordem
    FROM titulos
    WHERE " . implode(' AND ', $where) . "
      AND status_inadimplencia LIKE 'INADIMPLENTE%'
    GROUP BY
        CASE
            WHEN status_inadimplencia LIKE 'INADIMPLENTE%' AND status_titulo IN ('Bloqueado', 'Cancelado')
                THEN 'INADIMPLENTE (Bloqueados e/ou Cancelados)'
            WHEN status_inadimplencia LIKE 'INADIMPLENTE - Requer análise%'
                THEN 'REQUER ANÁLISE'
            WHEN status_inadimplencia = 'INADIMPLENTE - 3 a 6 meses'
                THEN '3 a 6 meses - Experiência'
            WHEN status_inadimplencia = 'INADIMPLENTE - 6 a 9 meses'
                THEN '6 a 9 meses - Expectativa'
            WHEN status_inadimplencia = 'INADIMPLENTE - 9 a 12 meses'
                THEN '9 a 12 meses - Problema sério'
            WHEN status_inadimplencia = 'INADIMPLENTE - Mais de 12 meses'
                THEN 'Mais de 12 meses - Crônico'
            ELSE status_inadimplencia
        END,
        ordem
    ORDER BY ordem, total DESC",
    array_merge($paramsAnalise, $paramsAnalise) // Duplicar params: subquery + query principal
);

// Adicionar categoria Adimplente separadamente
$adimplentes = $db->fetchOne("
    SELECT
        'Adimplente - Em dia' as status_inadimplencia,
        COUNT(*) as total,
        SUM(saldo_restante) as valor_risco,
        ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM titulos WHERE " . implode(' AND ', $where) . "), 2) as percentual,
        999 as ordem
    FROM titulos
    WHERE " . implode(' AND ', $where) . "
      AND status_inadimplencia = 'ADIMPLENTE'",
    array_merge($paramsAnalise, $paramsAnalise)
);

if ($adimplentes && $adimplentes['total'] > 0) {
    $inadimplenciaPorTipo[] = $adimplentes;
}

// ====================================================================
// CARTÕES EM DESTAQUE - Cartões usados múltiplas vezes
// ====================================================================
$cartoesDestaque = $db->fetchAll("
    SELECT
        tc.numero_cartao,
        GROUP_CONCAT(DISTINCT tc.bandeira ORDER BY tc.bandeira SEPARATOR ', ') as bandeiras,
        GROUP_CONCAT(DISTINCT tc.tipo_pagamento ORDER BY tc.tipo_pagamento SEPARATOR ', ') as tipos_pagamento,
        COUNT(DISTINCT t.id) as total_titulos,
        COUNT(DISTINCT t.documento_titular) as total_cpfs,
        COUNT(DISTINCT t.promotor) as total_promotores,
        GROUP_CONCAT(DISTINCT t.promotor ORDER BY t.promotor SEPARATOR ' | ') as nomes_promotores,
        SUM(CASE WHEN t.status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 ELSE 0 END) as inadimplentes,
        SUM(CASE WHEN t.status_titulo IN ('Bloqueado', 'Cancelado') THEN 1 ELSE 0 END) as bloqueados,
        -- Detectar se é DÉBITO (alto risco)
        CASE
            WHEN MAX(CASE WHEN tc.bandeira LIKE '%DEBITO%' OR tc.bandeira LIKE '%DEBIT%' THEN 1 ELSE 0 END) = 1 THEN 1
            WHEN MAX(CASE WHEN tc.tipo_pagamento LIKE '%DEBITO%' OR tc.tipo_pagamento LIKE '%DEBIT%' THEN 1 ELSE 0 END) = 1 THEN 1
            ELSE 0
        END as eh_debito,
        ROUND(100.0 * SUM(CASE WHEN t.status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 ELSE 0 END) / COUNT(DISTINCT t.id), 1) as taxa_inadimplencia
    FROM titulo_cartoes tc
    INNER JOIN titulos t ON tc.titulo_id = t.id
    WHERE tc.numero_cartao IS NOT NULL
      AND tc.numero_cartao != ''
      AND tc.numero_cartao != 'NULL'
      AND t.importacao_id = ?
      AND t.data_primeira_venda >= ?
      AND t.data_primeira_venda <= ?
      " . (isset($where[3]) ? "AND " . $where[3] : "") . "
    GROUP BY tc.numero_cartao
    HAVING COUNT(DISTINCT t.id) >= 2
    ORDER BY
        eh_debito DESC,
        COUNT(DISTINCT t.documento_titular) DESC,
        COUNT(DISTINCT t.id) DESC
    LIMIT 20
", [$importacaoId, $dataInicio . ' 00:00:00', $dataFim . ' 23:59:59']);

// Ranking de consultores com mais inadimplência
$rankingConsultores = $db->fetchAll("
    SELECT
        promotor,
        COUNT(*) as total_vendas,
        COUNT(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 END) as inadimplentes,
        COUNT(CASE WHEN status_inadimplencia = 'INADIMPLENTE - Apenas 1ª Parcela' THEN 1 END) as apenas_1parcela,
        SUM(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN saldo_restante ELSE 0 END) as valor_risco,
        ROUND(COUNT(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 END) * 100.0 / COUNT(*), 2) as taxa_inadimplencia
    FROM titulos
    WHERE " . implode(' AND ', $where) . "
      AND promotor IS NOT NULL
    GROUP BY promotor
    HAVING inadimplentes > 0
    ORDER BY inadimplentes DESC
    LIMIT 10",
    array_slice($params, 0, count($params) - 2)
);

// Títulos bloqueados/cancelados em até 30 dias ou a partir do 2º mês
$titulosProblematicos = $db->fetchAll("
    SELECT *
    FROM titulos
    WHERE " . implode(' AND ', $where) . "
      AND (
          (status_titulo IN ('Bloqueado', 'Cancelado') AND dias_desde_venda <= 30)
          OR
          (status_inadimplencia = 'INADIMPLENTE - Apenas 1ª Parcela' AND dias_desde_venda >= 60)
      )
    ORDER BY dias_desde_venda DESC
    LIMIT 20",
    array_slice($params, 0, count($params) - 2)
);

// Buscar lista de promotores para autocomplete
$promotores = $db->fetchAll("
    SELECT DISTINCT promotor
    FROM titulos
    WHERE promotor IS NOT NULL AND importacao_id = ?
    ORDER BY promotor",
    [$importacaoId]
);

// Se view=cartoes, renderizar view específica de cartões
if ($viewCartoes):
    $numeroCartao = $_GET['numero_cartao'] ?? null;
    $pesquisa = $_GET['pesquisa'] ?? '';
    $ordenarPor = $_GET['ordenar'] ?? 'titulos'; // titulos, cpfs, consultores, inadimplentes, bloqueados

    // Definir filtro de prefixos (SFA/SBF)
    $wherePrefixos = " AND (numero_titulo LIKE 'SFA%' OR numero_titulo LIKE 'SBF%')";

    // Se tem um cartão específico, buscar detalhes
    if ($numeroCartao) {
        // Ordenação para detalhes do cartão
        $ordenarDetalhes = $_GET['ordenar_detalhes'] ?? 'data';
        $orderByDetalhes = match($ordenarDetalhes) {
            'cliente' => 'nome_titular ASC',
            'consultor' => 'promotor ASC',
            'status' => 'status_titulo DESC, status_inadimplencia DESC',
            'inadimplencia' => 'CASE WHEN status_inadimplencia LIKE \'INADIMPLENTE%\' THEN 0 ELSE 1 END, data_primeira_venda DESC',
            default => 'data_primeira_venda DESC'
        };

        // Buscar títulos que usam este cartão (via tabela titulo_cartoes)
        $titulosCartao = $db->fetchAll("
            SELECT t.*
            FROM titulos t
            INNER JOIN titulo_cartoes tc ON t.id = tc.titulo_id
            WHERE tc.numero_cartao = ?
              {$wherePrefixos}
            ORDER BY {$orderByDetalhes}
        ", [$numeroCartao]);

        $statsCartao = [
            'total_titulos' => count($titulosCartao),
            'total_documentos' => count(array_unique(array_column($titulosCartao, 'documento_titular'))),
            'total_consultores' => count(array_unique(array_column($titulosCartao, 'promotor'))),
            'consultores' => array_unique(array_column($titulosCartao, 'promotor')),
            'inadimplentes' => count(array_filter($titulosCartao, function($t) {
                return stripos($t['status_inadimplencia'], 'INADIMPLENTE') !== false;
            })),
            'adimplentes' => count(array_filter($titulosCartao, function($t) {
                return stripos($t['status_inadimplencia'], 'INADIMPLENTE') === false;
            })),
            'ativos' => count(array_filter($titulosCartao, function($t) {
                return $t['status_titulo'] === 'Ativo';
            })),
            'bloqueados' => count(array_filter($titulosCartao, function($t) {
                return $t['status_titulo'] === 'Bloqueado';
            })),
            'cancelados' => count(array_filter($titulosCartao, function($t) {
                return $t['status_titulo'] === 'Cancelado';
            })),
            // Buscar bandeiras do cartão da tabela titulo_cartoes
            'bandeiras' => $db->fetchColumn("
                SELECT GROUP_CONCAT(DISTINCT bandeira SEPARATOR ', ')
                FROM titulo_cartoes
                WHERE numero_cartao = ?
            ", [$numeroCartao]) ?? ''
        ];
    } else {
        // Construir WHERE para pesquisa
        $wherePesquisa = "";
        $paramsPesquisa = [];
        if ($pesquisa) {
            $wherePesquisa = " AND (numero_cartao LIKE ? OR bandeira LIKE ? OR promotor LIKE ?)";
            $paramsPesquisa = ['%' . $pesquisa . '%', '%' . $pesquisa . '%', '%' . $pesquisa . '%'];
        }

        // Buscar cartões agrupados via titulo_cartoes + JOIN titulos
        $orderBy = match($ordenarPor) {
            'cpfs' => 'COUNT(DISTINCT t.documento_titular) DESC',
            'consultores' => 'COUNT(DISTINCT t.promotor) DESC',
            'inadimplentes' => 'SUM(CASE WHEN t.status_inadimplencia LIKE \'INADIMPLENTE%\' THEN 1 ELSE 0 END) DESC',
            'bloqueados' => 'SUM(CASE WHEN t.status_titulo IN (\'Bloqueado\', \'Cancelado\') THEN 1 ELSE 0 END) DESC',
            default => 'COUNT(DISTINCT t.id) DESC'
        };

        // Construir WHERE para pesquisa na nova estrutura
        $wherePesquisaNew = "";
        if ($pesquisa) {
            $wherePesquisaNew = " AND (tc.numero_cartao LIKE ? OR tc.bandeira LIKE ? OR t.promotor LIKE ?)";
        }

        $cartoesMultiplos = $db->fetchAll("
            SELECT
                tc.numero_cartao,
                GROUP_CONCAT(DISTINCT tc.bandeira ORDER BY tc.bandeira SEPARATOR ', ') as bandeiras,
                COUNT(DISTINCT t.id) as total_titulos,
                COUNT(DISTINCT t.documento_titular) as total_documentos,
                COUNT(DISTINCT t.promotor) as total_consultores,
                GROUP_CONCAT(DISTINCT t.promotor ORDER BY t.promotor SEPARATOR ', ') as consultores,
                SUM(CASE WHEN t.status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 ELSE 0 END) as inadimplentes,
                SUM(CASE WHEN t.status_inadimplencia NOT LIKE 'INADIMPLENTE%' THEN 1 ELSE 0 END) as adimplentes,
                SUM(CASE WHEN t.status_titulo = 'Ativo' THEN 1 ELSE 0 END) as ativos,
                SUM(CASE WHEN t.status_titulo = 'Bloqueado' THEN 1 ELSE 0 END) as bloqueados,
                SUM(CASE WHEN t.status_titulo = 'Cancelado' THEN 1 ELSE 0 END) as cancelados,
                CASE
                    WHEN MAX(CASE WHEN tc.bandeira LIKE '%DEBITO%' OR tc.bandeira LIKE '%DEBIT%' THEN 1 ELSE 0 END) = 1 THEN 'DÉBITO'
                    WHEN MAX(CASE WHEN tc.bandeira LIKE '%CREDITO%' OR tc.bandeira LIKE '%CREDIT%' THEN 1 ELSE 0 END) = 1 THEN 'CRÉDITO'
                    WHEN MAX(CASE WHEN tc.tipo_pagamento LIKE '%DEBITO%' OR tc.tipo_pagamento LIKE '%DEBIT%' THEN 1 ELSE 0 END) = 1 THEN 'DÉBITO'
                    WHEN MAX(CASE WHEN tc.tipo_pagamento LIKE '%CREDITO%' OR tc.tipo_pagamento LIKE '%CREDIT%' THEN 1 ELSE 0 END) = 1 THEN 'CRÉDITO'
                    ELSE 'OUTRO'
                END as tipo_principal
            FROM titulo_cartoes tc
            INNER JOIN titulos t ON tc.titulo_id = t.id
            WHERE tc.numero_cartao IS NOT NULL
              AND tc.numero_cartao != ''
              AND tc.numero_cartao != 'NULL'
              {$wherePrefixos}
              {$wherePesquisaNew}
            GROUP BY tc.numero_cartao
            HAVING COUNT(DISTINCT t.id) >= 2
            ORDER BY
                MAX(CASE WHEN tc.bandeira LIKE '%DEBITO%' OR tc.bandeira LIKE '%DEBIT%' OR tc.tipo_pagamento LIKE '%DEBITO%' THEN 1 ELSE 0 END) DESC,
                {$orderBy}
        ", $paramsPesquisa);

        // Calcular estatísticas gerais
        $statsGerais = [
            'total_cartoes' => count($cartoesMultiplos),
            'total_titulos' => array_sum(array_column($cartoesMultiplos, 'total_titulos')),
            'total_inadimplentes' => array_sum(array_column($cartoesMultiplos, 'inadimplentes')),
            'total_adimplentes' => array_sum(array_column($cartoesMultiplos, 'adimplentes')),
            'total_ativos' => array_sum(array_column($cartoesMultiplos, 'ativos')),
            'total_bloqueados' => array_sum(array_column($cartoesMultiplos, 'bloqueados')),
            'total_cancelados' => array_sum(array_column($cartoesMultiplos, 'cancelados'))
        ];

        // Top 10 Consultores - MESMO CARTÃO usado para MÚLTIPLOS CLIENTES (indicador de irregularidade)
        // IMPORTANTE: Detectar consultores que usaram o MESMO CARTÃO para VÁRIOS CPFs diferentes
        $top10Consultores = $db->fetchAll("
            SELECT
                promotor,
                numero_cartao,
                GROUP_CONCAT(DISTINCT bandeira ORDER BY bandeira SEPARATOR ', ') as bandeiras,
                CASE
                    WHEN MAX(CASE WHEN bandeira LIKE '%DEBITO%' OR bandeira LIKE '%DEBIT%' THEN 1 ELSE 0 END) = 1 THEN 'DÉBITO'
                    WHEN MAX(CASE WHEN bandeira LIKE '%CREDITO%' OR bandeira LIKE '%CREDIT%' THEN 1 ELSE 0 END) = 1 THEN 'CRÉDITO'
                    ELSE 'OUTRO'
                END as tipo_cartao,
                COUNT(DISTINCT documento_titular) as cpfs_diferentes,
                COUNT(*) as total_titulos,
                SUM(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 ELSE 0 END) as inadimplentes,
                SUM(CASE WHEN status_titulo IN ('Bloqueado', 'Cancelado') THEN 1 ELSE 0 END) as bloqueados,
                ROUND(100.0 * SUM(CASE WHEN status_inadimplencia LIKE 'INADIMPLENTE%' THEN 1 ELSE 0 END) / COUNT(*), 1) as taxa_inadimplencia,
                ROUND(100.0 * SUM(CASE WHEN status_titulo IN ('Bloqueado', 'Cancelado') THEN 1 ELSE 0 END) / COUNT(*), 1) as taxa_bloqueio,
                (SELECT numero_titulo FROM titulos t2
                 WHERE t2.numero_cartao = titulos.numero_cartao
                   AND t2.promotor = titulos.promotor
                 ORDER BY t2.data_primeira_venda DESC LIMIT 1) as ultima_venda_id,
                (SELECT DATE_FORMAT(data_primeira_venda, '%d/%m/%Y') FROM titulos t2
                 WHERE t2.numero_cartao = titulos.numero_cartao
                   AND t2.promotor = titulos.promotor
                 ORDER BY t2.data_primeira_venda DESC LIMIT 1) as ultima_venda_data
            FROM titulos
            WHERE numero_cartao IS NOT NULL
              AND numero_cartao != ''
              AND numero_cartao != 'NULL'
              {$wherePrefixos}
            GROUP BY promotor, numero_cartao
            HAVING COUNT(DISTINCT documento_titular) >= 3
            ORDER BY cpfs_diferentes DESC, taxa_inadimplencia DESC
            LIMIT 10
        ");
    }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $numeroCartao ? 'Detalhes do Cartão' : 'Cartões com Múltiplos Usos'; ?> - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container-fluid mt-4">
        <?php if ($numeroCartao): ?>
            <!-- Detalhes de um cartão específico -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2><i class="bi bi-credit-card"></i> Detalhes do Cartão</h2>
                            <p class="text-muted mb-0">
                                <strong><?php echo sanitize($numeroCartao); ?></strong>
                                <span class="badge bg-secondary"><?php echo sanitize($statsCartao['bandeiras']); ?></span>
                                <?php if (stripos($statsCartao['bandeiras'], 'DEBITO') !== false || stripos($statsCartao['bandeiras'], 'DEBIT') !== false): ?>
                                    <span class="badge bg-danger">DÉBITO - ALTO RISCO</span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <a href="relatorios.php?view=cartoes" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Voltar
                        </a>
                    </div>
                </div>
            </div>

            <!-- Estatísticas do Cartão -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card border-primary">
                        <div class="card-body text-center">
                            <h5 class="text-primary"><?php echo $statsCartao['total_titulos']; ?></h5>
                            <small class="text-muted">Títulos Vendidos</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-info">
                        <div class="card-body text-center">
                            <h5 class="text-info"><?php echo $statsCartao['total_documentos']; ?></h5>
                            <small class="text-muted">CPFs Diferentes</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-warning">
                        <div class="card-body text-center">
                            <h5 class="text-warning"><?php echo $statsCartao['total_consultores']; ?></h5>
                            <small class="text-muted">Consultores</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-danger">
                        <div class="card-body text-center">
                            <h5 class="text-danger"><?php echo $statsCartao['inadimplentes']; ?></h5>
                            <small class="text-muted">Inadimplentes</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Status dos Títulos -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card border-success">
                        <div class="card-body text-center">
                            <h5 class="text-success"><?php echo $statsCartao['adimplentes']; ?></h5>
                            <small class="text-muted">Adimplentes</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-success">
                        <div class="card-body text-center">
                            <h5 class="text-success"><?php echo $statsCartao['ativos']; ?></h5>
                            <small class="text-muted">Ativos</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-warning">
                        <div class="card-body text-center">
                            <h5 class="text-warning"><?php echo $statsCartao['bloqueados']; ?></h5>
                            <small class="text-muted">Bloqueados</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-danger">
                        <div class="card-body text-center">
                            <h5 class="text-danger"><?php echo $statsCartao['cancelados']; ?></h5>
                            <small class="text-muted">Cancelados</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Consultores que usaram -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header bg-warning">
                            <h5><i class="bi bi-people"></i> Consultores que Utilizaram</h5>
                        </div>
                        <div class="card-body">
                            <p><?php echo implode(', ', array_map('sanitize', $statsCartao['consultores'])); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Análise com IA (apenas para gerente/admin) -->
            <?php if (getConfig('api_ia_key') && Auth::hasRole('gerente')): ?>
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card border-info">
                        <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bi bi-robot"></i> Análise Inteligente do Cartão</h5>
                        </div>
                        <div class="card-body">
                            <div id="iaResumoCartao">
                                <p class="text-muted mb-3">Gere uma análise completa deste cartão com sugestões de ação.</p>
                                <button class="btn btn-info" onclick="gerarResumoIACartao('<?php echo sanitize($numeroCartao); ?>')">
                                    <i class="bi bi-magic"></i> Gerar Análise com IA
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Lista de Títulos -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bi bi-list"></i> Todos os Títulos com este Cartão</h5>
                            <form method="GET" class="d-flex gap-2">
                                <input type="hidden" name="view" value="cartoes">
                                <input type="hidden" name="numero_cartao" value="<?php echo sanitize($numeroCartao); ?>">
                                <select name="ordenar_detalhes" class="form-select form-select-sm" onchange="this.form.submit()" style="width: auto;">
                                    <option value="data" <?php echo ($ordenarDetalhes ?? 'data') === 'data' ? 'selected' : ''; ?>>Ordenar por Data</option>
                                    <option value="cliente" <?php echo ($ordenarDetalhes ?? '') === 'cliente' ? 'selected' : ''; ?>>Ordenar por Cliente</option>
                                    <option value="consultor" <?php echo ($ordenarDetalhes ?? '') === 'consultor' ? 'selected' : ''; ?>>Ordenar por Consultor</option>
                                    <option value="status" <?php echo ($ordenarDetalhes ?? '') === 'status' ? 'selected' : ''; ?>>Ordenar por Status</option>
                                    <option value="inadimplencia" <?php echo ($ordenarDetalhes ?? '') === 'inadimplencia' ? 'selected' : ''; ?>>Inadimplentes Primeiro</option>
                                </select>
                            </form>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>Número Título</th>
                                            <th>Cliente</th>
                                            <th>CPF</th>
                                            <th>Consultor</th>
                                            <th>Status Título</th>
                                            <th>Status Inadimp.</th>
                                            <th>Parcelas</th>
                                            <th>Data Venda</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($titulosCartao as $titulo): ?>
                                            <tr>
                                                <td><?php echo sanitize($titulo['numero_titulo']); ?></td>
                                                <td><?php echo sanitize($titulo['nome_titular']); ?></td>
                                                <td><?php echo sanitize($titulo['documento_titular']); ?></td>
                                                <td><?php echo sanitize($titulo['promotor']); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $titulo['status_titulo'] === 'Ativo' ? 'success' : 'danger'; ?>">
                                                        <?php echo $titulo['status_titulo']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php
                                                    $badge = InadimplenciaHelper::formatarStatusBadge($titulo['status_inadimplencia']);
                                                    ?>
                                                    <span class="badge bg-<?php echo $badge['class']; ?>">
                                                        <?php echo sanitize($badge['text']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo $titulo['qtd_parcelas_pagas']; ?>/<?php echo $titulo['quantidade_parcelas_venda']; ?></td>
                                                <td><?php echo formatDate($titulo['data_primeira_venda']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- Lista de todos os cartões com múltiplos usos -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <h2><i class="bi bi-credit-card-2-front"></i> Cartões com Múltiplos Usos</h2>
                    <p class="text-muted">Cartões usados em 2 ou mais títulos (indicador de risco)</p>
                </div>
            </div>

            <!-- Cards de Resumo -->
            <div class="row mb-4">
                <div class="col-md-2">
                    <div class="card border-primary">
                        <div class="card-body text-center">
                            <h5 class="text-primary"><?php echo $statsGerais['total_cartoes']; ?></h5>
                            <small class="text-muted">Total Cartões</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card border-info">
                        <div class="card-body text-center">
                            <h5 class="text-info"><?php echo $statsGerais['total_titulos']; ?></h5>
                            <small class="text-muted">Total Títulos</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card border-success">
                        <div class="card-body text-center">
                            <h5 class="text-success"><?php echo $statsGerais['total_adimplentes']; ?></h5>
                            <small class="text-muted">Adimplentes</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card border-danger">
                        <div class="card-body text-center">
                            <h5 class="text-danger"><?php echo $statsGerais['total_inadimplentes']; ?></h5>
                            <small class="text-muted">Inadimplentes</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card border-success">
                        <div class="card-body text-center">
                            <h5 class="text-success"><?php echo $statsGerais['total_ativos']; ?></h5>
                            <small class="text-muted">Ativos</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card border-warning">
                        <div class="card-body text-center">
                            <h5 class="text-warning"><?php echo $statsGerais['total_bloqueados'] + $statsGerais['total_cancelados']; ?></h5>
                            <small class="text-muted">Bloq./Canc.</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top 10 Consultores com Alto Risco -->
            <?php if (!empty($top10Consultores)): ?>
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card border-warning">
                        <div class="card-header bg-warning text-dark">
                            <h5><i class="bi bi-exclamation-triangle-fill"></i> Top 10 Consultores - MESMO Cartão para MÚLTIPLOS Clientes (Irregularidade Detectada)</h5>
                        </div>
                        <div class="card-body">
                            <!-- Top 3 Destaque -->
                            <div class="row mb-3">
                                <?php foreach (array_slice($top10Consultores, 0, 3) as $idx => $consultor): ?>
                                <div class="col-md-4">
                                    <div class="card mb-2 text-white <?php echo $idx === 0 ? 'bg-danger' : 'bg-warning'; ?>">
                                        <div class="card-body">
                                            <div class="d-flex align-items-start mb-2">
                                                <div class="me-2">
                                                    <i class="bi <?php echo $idx === 0 ? 'bi-skull' : ($idx === 1 ? 'bi-exclamation-triangle-fill' : 'bi-exclamation-octagon-fill'); ?>" style="font-size: 2rem;"></i>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <h6 class="card-title mb-0 <?php echo $idx === 0 ? 'text-white' : 'text-dark'; ?>">
                                                        <strong><?php echo ($idx + 1); ?>º Mais Crítico</strong>
                                                    </h6>
                                                    <p class="mb-2 <?php echo $idx === 0 ? 'text-white' : 'text-dark'; ?>">
                                                        <strong><?php echo sanitize($consultor['promotor']); ?></strong>
                                                    </p>
                                                </div>
                                            </div>
                                            <hr class="<?php echo $idx === 0 ? 'bg-white' : 'bg-dark'; ?>" style="opacity: 0.3;">
                                            <div class="<?php echo $idx === 0 ? 'text-white' : 'text-dark'; ?>">
                                                <p class="mb-2">
                                                    <i class="bi bi-credit-card"></i> <strong>Cartão:</strong> <?php echo sanitize($consultor['numero_cartao']); ?>
                                                    <?php if ($consultor['tipo_cartao'] == 'DÉBITO'): ?>
                                                        <span class="badge bg-danger ms-1">DÉBITO</span>
                                                    <?php elseif ($consultor['tipo_cartao'] == 'CRÉDITO'): ?>
                                                        <span class="badge bg-primary ms-1">CRÉDITO</span>
                                                    <?php endif; ?>
                                                </p>
                                                <p class="mb-2"><i class="bi bi-person-fill-exclamation"></i> <strong><?php echo $consultor['cpfs_diferentes']; ?></strong> clientes diferentes</p>
                                                <p class="mb-2"><i class="bi bi-file-earmark-text"></i> <strong><?php echo $consultor['total_titulos']; ?></strong> títulos vendidos</p>
                                                <p class="mb-2"><i class="bi bi-exclamation-circle-fill"></i> <strong><?php echo $consultor['taxa_inadimplencia']; ?>%</strong> inadimplência</p>
                                                <p class="mb-2"><i class="bi bi-x-circle-fill"></i> <strong><?php echo $consultor['taxa_bloqueio']; ?>%</strong> bloqueio</p>
                                                <p class="mb-0"><i class="bi bi-calendar-check"></i> <strong>Última venda:</strong> <?php echo sanitize($consultor['ultima_venda_id']); ?> (<?php echo $consultor['ultima_venda_data']; ?>)</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Demais Consultores (4º ao 10º) -->
                            <?php if (count($top10Consultores) > 3): ?>
                            <h6 class="mt-4 mb-3"><i class="bi bi-list-ol"></i> Demais Consultores Críticos (4º ao 10º)</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Consultor</th>
                                            <th>Cartão</th>
                                            <th>Tipo</th>
                                            <th class="text-end">CPFs Dif.</th>
                                            <th class="text-end">Títulos</th>
                                            <th class="text-end">Taxa Inadimp.</th>
                                            <th class="text-end">Taxa Bloq.</th>
                                            <th>Última Venda</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($top10Consultores, 3) as $idx => $consultor): ?>
                                        <tr>
                                            <td><strong><?php echo ($idx + 4); ?>º</strong></td>
                                            <td><?php echo sanitize($consultor['promotor']); ?></td>
                                            <td><span class="text-muted"><?php echo sanitize($consultor['numero_cartao']); ?></span></td>
                                            <td>
                                                <?php if ($consultor['tipo_cartao'] == 'DÉBITO'): ?>
                                                    <span class="badge bg-danger">DÉBITO</span>
                                                <?php elseif ($consultor['tipo_cartao'] == 'CRÉDITO'): ?>
                                                    <span class="badge bg-primary">CRÉDITO</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">OUTRO</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end"><span class="badge bg-danger"><?php echo $consultor['cpfs_diferentes']; ?></span></td>
                                            <td class="text-end"><?php echo $consultor['total_titulos']; ?></td>
                                            <td class="text-end"><strong class="text-danger"><?php echo $consultor['taxa_inadimplencia']; ?>%</strong></td>
                                            <td class="text-end"><strong class="text-warning"><?php echo $consultor['taxa_bloqueio']; ?>%</strong></td>
                                            <td><small class="text-muted"><?php echo sanitize($consultor['ultima_venda_id']); ?> (<?php echo $consultor['ultima_venda_data']; ?>)</small></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Filtros e Pesquisa -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-body">
                            <form method="GET" class="row g-3">
                                <input type="hidden" name="view" value="cartoes">
                                <div class="col-md-6">
                                    <label class="form-label"><i class="bi bi-search"></i> Pesquisar</label>
                                    <input type="text" name="pesquisa" class="form-control" placeholder="Número do cartão, bandeira ou consultor..." value="<?php echo sanitize($pesquisa); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label"><i class="bi bi-sort-down"></i> Ordenar por</label>
                                    <select name="ordenar" class="form-select">
                                        <option value="titulos" <?php echo $ordenarPor === 'titulos' ? 'selected' : ''; ?>>Maior nº de Títulos</option>
                                        <option value="cpfs" <?php echo $ordenarPor === 'cpfs' ? 'selected' : ''; ?>>Maior nº de CPFs</option>
                                        <option value="consultores" <?php echo $ordenarPor === 'consultores' ? 'selected' : ''; ?>>Maior nº de Consultores</option>
                                        <option value="inadimplentes" <?php echo $ordenarPor === 'inadimplentes' ? 'selected' : ''; ?>>Maior nº de Inadimplentes</option>
                                        <option value="bloqueados" <?php echo $ordenarPor === 'bloqueados' ? 'selected' : ''; ?>>Maior nº de Bloqueados</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">&nbsp;</label>
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bi bi-filter"></i> Aplicar
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card de Resumo (quando houver pesquisa) -->
            <?php if ($pesquisa && !empty($cartoesMultiplos)): ?>
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card border-primary">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="bi bi-search"></i> Resumo da Pesquisa: "<?php echo sanitize($pesquisa); ?>"</h5>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-md-2">
                                    <h4 class="text-primary"><?php echo number_format($statsGerais['total_cartoes'], 0, ',', '.'); ?></h4>
                                    <p class="text-muted mb-0">Cartões</p>
                                </div>
                                <div class="col-md-2">
                                    <h4 class="text-info"><?php echo number_format($statsGerais['total_titulos'], 0, ',', '.'); ?></h4>
                                    <p class="text-muted mb-0">Títulos</p>
                                </div>
                                <div class="col-md-2">
                                    <h4 class="text-success"><?php echo number_format($statsGerais['total_adimplentes'], 0, ',', '.'); ?></h4>
                                    <p class="text-muted mb-0">Adimplentes</p>
                                </div>
                                <div class="col-md-2">
                                    <h4 class="text-danger"><?php echo number_format($statsGerais['total_inadimplentes'], 0, ',', '.'); ?></h4>
                                    <p class="text-muted mb-0">Inadimplentes</p>
                                </div>
                                <div class="col-md-2">
                                    <h4>
                                        <?php
                                        $taxaPesquisa = $statsGerais['total_titulos'] > 0 ?
                                            round(($statsGerais['total_inadimplentes'] / $statsGerais['total_titulos']) * 100, 1) : 0;
                                        $corTaxa = $taxaPesquisa >= 50 ? 'text-danger' : ($taxaPesquisa >= 30 ? 'text-warning' : 'text-success');
                                        ?>
                                        <span class="<?php echo $corTaxa; ?>"><?php echo number_format($taxaPesquisa, 1, ',', '.'); ?>%</span>
                                    </h4>
                                    <p class="text-muted mb-0">Taxa Inadimp.</p>
                                </div>
                                <div class="col-md-2">
                                    <h4 class="text-warning"><?php echo number_format($statsGerais['total_bloqueados'], 0, ',', '.'); ?></h4>
                                    <p class="text-muted mb-0">Bloqueados</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Tabela de Cartões -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Cartão</th>
                                            <th>Bandeiras/Tipos</th>
                                            <th class="text-end">
                                                <a href="?view=cartoes&ordenar=titulos<?php echo $pesquisa ? '&pesquisa='.urlencode($pesquisa) : ''; ?>" class="text-decoration-none text-dark">
                                                    Títulos <?php echo $ordenarPor === 'titulos' ? '<i class="bi bi-caret-down-fill"></i>' : '<i class="bi bi-caret-down text-muted"></i>'; ?>
                                                </a>
                                            </th>
                                            <th class="text-end">
                                                <a href="?view=cartoes&ordenar=cpfs<?php echo $pesquisa ? '&pesquisa='.urlencode($pesquisa) : ''; ?>" class="text-decoration-none text-dark">
                                                    CPFs <?php echo $ordenarPor === 'cpfs' ? '<i class="bi bi-caret-down-fill"></i>' : '<i class="bi bi-caret-down text-muted"></i>'; ?>
                                                </a>
                                            </th>
                                            <th class="text-end">
                                                <a href="?view=cartoes&ordenar=consultores<?php echo $pesquisa ? '&pesquisa='.urlencode($pesquisa) : ''; ?>" class="text-decoration-none text-dark">
                                                    Consultores <?php echo $ordenarPor === 'consultores' ? '<i class="bi bi-caret-down-fill"></i>' : '<i class="bi bi-caret-down text-muted"></i>'; ?>
                                                </a>
                                            </th>
                                            <th class="text-end">Adim.</th>
                                            <th class="text-end">
                                                <a href="?view=cartoes&ordenar=inadimplentes<?php echo $pesquisa ? '&pesquisa='.urlencode($pesquisa) : ''; ?>" class="text-decoration-none text-dark">
                                                    Inadimp. <?php echo $ordenarPor === 'inadimplentes' ? '<i class="bi bi-caret-down-fill"></i>' : '<i class="bi bi-caret-down text-muted"></i>'; ?>
                                                </a>
                                            </th>
                                            <th class="text-end">Ativos</th>
                                            <th class="text-end">
                                                <a href="?view=cartoes&ordenar=bloqueados<?php echo $pesquisa ? '&pesquisa='.urlencode($pesquisa) : ''; ?>" class="text-decoration-none text-dark">
                                                    Bloq. <?php echo $ordenarPor === 'bloqueados' ? '<i class="bi bi-caret-down-fill"></i>' : '<i class="bi bi-caret-down text-muted"></i>'; ?>
                                                </a>
                                            </th>
                                            <th class="text-end">Canc.</th>
                                            <th>Consultores</th>
                                            <th class="text-center">Ação</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($cartoesMultiplos as $cartao): ?>
                                            <tr <?php if ($cartao['tipo_principal'] == 'DÉBITO'): ?>class="table-danger"<?php endif; ?>>
                                                <td>
                                                    <strong><?php echo sanitize($cartao['numero_cartao']); ?></strong>
                                                    <?php if ($cartao['tipo_principal'] == 'DÉBITO'): ?>
                                                        <span class="badge bg-danger">DÉBITO</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><small><?php echo sanitize($cartao['bandeiras']); ?></small></td>
                                                <td class="text-end"><span class="badge bg-primary"><?php echo $cartao['total_titulos']; ?></span></td>
                                                <td class="text-end"><span class="badge bg-info"><?php echo $cartao['total_documentos']; ?></span></td>
                                                <td class="text-end"><span class="badge bg-secondary"><?php echo $cartao['total_consultores']; ?></span></td>
                                                <td class="text-end">
                                                    <?php if ($cartao['adimplentes'] > 0): ?>
                                                        <span class="badge bg-success"><?php echo $cartao['adimplentes']; ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">0</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php if ($cartao['inadimplentes'] > 0): ?>
                                                        <span class="badge bg-danger"><?php echo $cartao['inadimplentes']; ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">0</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php if ($cartao['ativos'] > 0): ?>
                                                        <span class="badge bg-success"><?php echo $cartao['ativos']; ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">0</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php if ($cartao['bloqueados'] > 0): ?>
                                                        <span class="badge bg-warning"><?php echo $cartao['bloqueados']; ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">0</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php if ($cartao['cancelados'] > 0): ?>
                                                        <span class="badge bg-danger"><?php echo $cartao['cancelados']; ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">0</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><small><?php echo sanitize(substr($cartao['consultores'], 0, 40)); ?><?php echo strlen($cartao['consultores']) > 40 ? '...' : ''; ?></small></td>
                                                <td class="text-center">
                                                    <a href="relatorios.php?view=cartoes&numero_cartao=<?php echo urlencode($cartao['numero_cartao']); ?>" class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-eye"></i> Detalhes
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if (empty($cartoesMultiplos)): ?>
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i> Nenhum cartão encontrado com os filtros aplicados.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Gerar resumo com IA para cartão específico
        function gerarResumoIACartao(numeroCartao) {
            const btn = event.target;
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Gerando...';
            btn.disabled = true;

            fetch('api/gerar_resumo_cartao_ia.php?numero_cartao=' + encodeURIComponent(numeroCartao))
                .then(response => {
                    if (!response.ok) {
                        throw new Error('HTTP error! status: ' + response.status);
                    }
                    const contentType = response.headers.get("content-type");
                    if (!contentType || !contentType.includes("application/json")) {
                        throw new Error('Resposta não é JSON! Content-Type: ' + contentType);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        const resumoFormatado = data.resumo.replace(/\n/g, '<br>').replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
                        document.getElementById('iaResumoCartao').innerHTML = '<div class="alert alert-light">' + resumoFormatado + '</div>';
                    } else {
                        alert('Erro ao gerar resumo: ' + (data.error || 'Erro desconhecido'));
                        btn.innerHTML = originalHTML;
                        btn.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Erro completo:', error);
                    alert('Erro ao gerar resumo: ' + error.message + '\n\nVerifique o console do navegador para mais detalhes.');
                    btn.innerHTML = originalHTML;
                    btn.disabled = false;
                });
        }
    </script>
</body>
</html>
<?php
exit;
endif;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatórios - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <style>
        @media print {
            .navbar, .card-header button, .btn, form, .pagination { display: none !important; }
            .card { border: none !important; box-shadow: none !important; }
            body { font-size: 10pt; }
            table { font-size: 9pt; }
        }

        /* Cores por tipo de inadimplência - Força máxima de especificidade */

        /* CATEGORIAS "REQUER ANÁLISE" - Amarelo/Laranja para análise */
        table.table tbody tr.inadimplente-requer-analise,
        table.table tbody tr.inadimplente-requer-analise > td {
            background-color: #ffc107 !important; /* Amarelo/Laranja - Requer Análise */
            color: #000 !important;
            font-weight: 600;
        }

        /* CATEGORIAS ANTIGAS (compatibilidade) - CORES VIVAS */
        table.table tbody tr.inadimplente-1parcela,
        table.table tbody tr.inadimplente-1parcela > td {
            background-color: #ffc107 !important; /* Amarelo/Laranja - Requer Análise */
            color: #000 !important;
            font-weight: 600;
        }
        table.table tbody tr.inadimplente-2parcelas,
        table.table tbody tr.inadimplente-2parcelas > td {
            background-color: #ffc107 !important; /* Amarelo/Laranja - Requer Análise */
            color: #000 !important;
            font-weight: 600;
        }

        /* NOVAS CATEGORIAS POR TEMPO - CORES MAIS VIVAS */
        /* Até 3 meses - Amarelo forte (requer análise) */
        table.table tbody tr.inadimplente-ate3,
        table.table tbody tr.inadimplente-ate3 > td {
            background-color: #ffc107 !important;
            color: #000 !important;
            font-weight: 600;
        }
        /* 3 a 6 meses - Laranja vivo (experiência) */
        table.table tbody tr.inadimplente-3a6,
        table.table tbody tr.inadimplente-3a6 > td {
            background-color: #ff9800 !important;
            color: white !important;
            font-weight: 500;
        }
        /* 6 a 9 meses - Laranja escuro forte (expectativa) */
        table.table tbody tr.inadimplente-6a9,
        table.table tbody tr.inadimplente-6a9 > td {
            background-color: #ff6f00 !important;
            color: white !important;
            font-weight: 500;
        }
        /* 9 a 12 meses - Vermelho forte */
        table.table tbody tr.inadimplente-9a12,
        table.table tbody tr.inadimplente-9a12 > td {
            background-color: #f44336 !important;
            color: white !important;
            font-weight: 500;
        }
        /* Mais de 12 meses - Vermelho escuro intenso (crônico) */
        table.table tbody tr.inadimplente-12mais,
        table.table tbody tr.inadimplente-12mais > td {
            background-color: #c62828 !important;
            color: white !important;
            font-weight: bold;
        }

        /* CATEGORIAS ANTIGAS - Manter para compatibilidade */
        table.table tbody tr.inadimplente-menos50,
        table.table tbody tr.inadimplente-menos50 > td {
            background-color: #ffeb3b !important;
            color: #000 !important;
        }
        table.table tbody tr.inadimplente-mais50,
        table.table tbody tr.inadimplente-mais50 > td {
            background-color: #f44336 !important;
            color: white !important;
        }

        /* ADIMPLENTE - Verde vivo */
        table.table tbody tr.adimplente,
        table.table tbody tr.adimplente > td {
            background-color: #4caf50 !important;
            color: white !important;
            font-weight: 500;
        }

        /* HOVER - escurece levemente */
        table.table-hover tbody tr[class*="inadimplente"]:hover,
        table.table-hover tbody tr[class*="inadimplente"]:hover > td {
            filter: brightness(0.9);
        }
        table.table-hover tbody tr.adimplente:hover,
        table.table-hover tbody tr.adimplente:hover > td {
            background-color: #c8e6c9 !important;
        }

        .cursor-pointer {
            cursor: pointer;
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="container-fluid mt-4">
        <h2><i class="bi bi-file-earmark-bar-graph"></i> Relatórios de Inadimplência</h2>
        <p class="text-muted">Importação: <?php echo sanitize($ultimaImportacao['nome_arquivo']); ?> - <?php echo formatDateTime($ultimaImportacao['concluido_em']); ?></p>

        <!-- Estatísticas Principais -->
        <div class="row mb-4">
            <!-- Card 1: Total de Títulos -->
            <div class="col-md-3">
                <div class="card text-white bg-primary h-100">
                    <div class="card-body">
                        <h6 class="mb-3"><i class="bi bi-clipboard-data"></i> Total de Títulos</h6>
                        <h3 class="mb-2"><?php echo number_format($stats['total'], 0, ',', '.'); ?></h3>
                        <small>
                            <i class="bi bi-check-circle"></i> <?php echo number_format($stats['titulos_ativos'], 0, ',', '.'); ?> Ativos<br>
                            <i class="bi bi-lock"></i> <?php echo number_format($stats['titulos_bloqueados'], 0, ',', '.'); ?> Bloqueados<br>
                            <i class="bi bi-x-circle"></i> <?php echo number_format($stats['titulos_cancelados'], 0, ',', '.'); ?> Cancelados
                        </small>
                    </div>
                </div>
            </div>

            <!-- Card 2: Inadimplentes -->
            <div class="col-md-3">
                <div class="card text-white bg-danger h-100">
                    <div class="card-body">
                        <h6 class="mb-3"><i class="bi bi-exclamation-triangle"></i> Inadimplentes</h6>
                        <h3 class="mb-2"><?php echo number_format($stats['total_inadimplentes'], 0, ',', '.'); ?></h3>
                        <small>
                            Taxa: <?php echo formatPercentage($stats['total_inadimplentes'] * 100 / max($stats['total'], 1), 1); ?><br>
                            <i class="bi bi-check-circle"></i> <?php echo number_format($stats['inadimplentes_ativos'], 0, ',', '.'); ?> Ativos<br>
                            <i class="bi bi-lock"></i> <?php echo number_format($stats['inadimplentes_bloqueados'], 0, ',', '.'); ?> Bloqueados
                        </small>
                    </div>
                </div>
            </div>

            <!-- Card 3: Valor Total Vendido -->
            <div class="col-md-3">
                <div class="card text-white bg-success h-100">
                    <div class="card-body">
                        <h6 class="mb-3"><i class="bi bi-cash-stack"></i> Valor Total</h6>
                        <h4 class="mb-2"><?php echo formatCurrency($stats['valor_total_vendido'] ?? 0); ?></h4>
                        <small>
                            <i class="bi bi-check"></i> Recebido: <?php echo formatCurrency($stats['valor_total_recebido'] ?? 0); ?><br>
                            <i class="bi bi-clock"></i> Restante: <?php echo formatCurrency($stats['valor_total_restante'] ?? 0); ?>
                        </small>
                    </div>
                </div>
            </div>

            <!-- Card 4: Valor em Risco / Perdido / A Receber (contextual) -->
            <div class="col-md-3">
                <?php if (count($filtroTitulo) === 1 && in_array('Cancelado', $filtroTitulo)): ?>
                    <!-- Filtro de Cancelado: Mostrar Valor Perdido -->
                    <div class="card text-white bg-secondary h-100">
                        <div class="card-body">
                            <h6 class="mb-3"><i class="bi bi-trash"></i> Valor Perdido</h6>
                            <h4 class="mb-2"><?php echo formatCurrency($stats['valor_perdido'] ?? 0); ?></h4>
                            <small>Títulos cancelados que não serão mais pagos</small>
                        </div>
                    </div>
                <?php elseif ($filtroStatus == 'ADIMPLENTE'): ?>
                    <!-- Filtro de Adimplente: Mostrar Valor a Receber -->
                    <div class="card text-white bg-info h-100">
                        <div class="card-body">
                            <h6 class="mb-3"><i class="bi bi-hourglass-split"></i> Valor a Receber</h6>
                            <h4 class="mb-2"><?php echo formatCurrency($stats['valor_a_receber_adimplente'] ?? 0); ?></h4>
                            <small>
                                <i class="bi bi-check-circle"></i> Recebido: <?php echo formatCurrency($stats['valor_total_recebido'] ?? 0); ?><br>
                                Risco: R$ 0,00
                            </small>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Padrão: Mostrar Valor em Risco -->
                    <div class="card text-white bg-dark h-100">
                        <div class="card-body">
                            <h6 class="mb-3"><i class="bi bi-shield-exclamation"></i> Valor em Risco</h6>
                            <h4 class="mb-2"><?php echo formatCurrency($stats['valor_em_risco'] ?? 0); ?></h4>
                            <small>
                                Saldo restante apenas de inadimplentes<br>
                                <?php
                                $percRisco = $stats['valor_total_vendido'] > 0
                                    ? ($stats['valor_em_risco'] / $stats['valor_total_vendido']) * 100
                                    : 0;
                                ?>
                                <?php echo number_format($percRisco, 2, ',', '.'); ?>% do total vendido
                            </small>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Resumo de IA -->
        <?php if (getConfig('api_ia_key')): ?>
        <div class="card mb-4 border-info">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="bi bi-robot"></i> Resumo Gerado por IA</h5>
            </div>
            <div class="card-body">
                <div id="iaResumo">
                    <div class="text-center">
                        <button class="btn btn-info" onclick="gerarResumoIA()">
                            <i class="bi bi-magic"></i> Gerar Análise com IA
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Análise Detalhada -->
        <div class="row mb-4">
            <!-- Inadimplência por Tipo -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-warning">
                        <h6 class="mb-0"><i class="bi bi-pie-chart"></i> Inadimplência por Tipo</h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($inadimplenciaPorTipo)): ?>
                            <p class="text-muted">Nenhum inadimplente no período.</p>
                        <?php else: ?>
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Tipo</th>
                                        <th class="text-end">Qtd</th>
                                        <th class="text-end">%</th>
                                        <th class="text-end">Valor Risco</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $totalQtd = 0;
                                    $totalValorRisco = 0;
                                    foreach ($inadimplenciaPorTipo as $tipo):
                                        $totalQtd += $tipo['total'];
                                        $totalValorRisco += $tipo['valor_risco'] ?? 0;
                                    ?>
                                        <tr>
                                            <td>
                                                <small><?php echo sanitize($tipo['status_inadimplencia']); ?></small>
                                            </td>
                                            <td class="text-end"><?php echo number_format($tipo['total'], 0, ',', '.'); ?></td>
                                            <td class="text-end"><?php echo number_format($tipo['percentual'], 1); ?>%</td>
                                            <td class="text-end"><small><?php echo formatCurrency($tipo['valor_risco'] ?? 0); ?></small></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-secondary">
                                    <tr>
                                        <th><strong>TOTAL</strong></th>
                                        <th class="text-end"><strong><?php echo number_format($totalQtd, 0, ',', '.'); ?></strong></th>
                                        <th class="text-end">-</th>
                                        <th class="text-end"><strong><?php echo formatCurrency($totalValorRisco); ?></strong></th>
                                    </tr>
                                </tfoot>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Ranking de Consultores -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-danger text-white">
                        <h6 class="mb-0"><i class="bi bi-trophy"></i> Top 10 Consultores com Inadimplência</h6>
                    </div>
                    <div class="card-body">
                        <?php if (empty($rankingConsultores)): ?>
                            <p class="text-muted">Nenhum dado disponível.</p>
                        <?php else: ?>
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Consultor</th>
                                        <th class="text-end">Inadimp.</th>
                                        <th class="text-end">Taxa</th>
                                        <th class="text-end">1ª Parc.</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rankingConsultores as $index => $consultor): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td>
                                                <small>
                                                    <a href="?promotor=<?php echo urlencode($consultor['promotor']); ?>" class="text-decoration-none">
                                                        <?php echo sanitize($consultor['promotor']); ?>
                                                    </a>
                                                </small>
                                            </td>
                                            <td class="text-end">
                                                <span class="badge bg-danger">
                                                    <?php echo $consultor['inadimplentes']; ?>/<?php echo $consultor['total_vendas']; ?>
                                                </span>
                                            </td>
                                            <td class="text-end"><?php echo number_format($consultor['taxa_inadimplencia'], 1); ?>%</td>
                                            <td class="text-end">
                                                <?php if ($consultor['apenas_1parcela'] > 0): ?>
                                                    <span class="badge bg-warning text-dark"><?php echo $consultor['apenas_1parcela']; ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Títulos Problemáticos -->
        <?php if (!empty($titulosProblematicos)): ?>
        <div class="card mb-4 border-danger">
            <div class="card-header bg-danger text-white">
                <h6 class="mb-0">
                    <i class="bi bi-exclamation-triangle"></i>
                    Títulos para Atenção Especial
                    <small>(Bloqueados/Cancelados em 30 dias OU Apenas 1ª parcela há 60+ dias)</small>
                </h6>
            </div>
            <div class="card-body">
                <!-- Tabs -->
                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="todos-tab" data-bs-toggle="tab" data-bs-target="#todos-problematicos" type="button" role="tab">
                            Todos (<?php echo count($titulosProblematicos); ?>)
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="ativos-tab" data-bs-toggle="tab" data-bs-target="#ativos-problematicos" type="button" role="tab">
                            Apenas Ativos (<?php echo count(array_filter($titulosProblematicos, fn($t) => $t['status_titulo'] == 'Ativo')); ?>)
                        </button>
                    </li>
                </ul>

                <!-- Tab Content -->
                <div class="tab-content">
                    <!-- Todos -->
                    <div class="tab-pane fade show active" id="todos-problematicos" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Título</th>
                                        <th>Titular</th>
                                        <th>Consultor</th>
                                        <th>Status</th>
                                        <th>Dias</th>
                                        <th>Inadimplência</th>
                                        <th class="text-end">Risco</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($titulosProblematicos as $titulo): ?>
                                        <tr>
                                            <td><small><?php echo sanitize($titulo['numero_titulo']); ?></small></td>
                                            <td><small><?php echo sanitize($titulo['nome_titular']); ?></small></td>
                                            <td><small><?php echo sanitize($titulo['promotor']); ?></small></td>
                                            <td>
                                                <span class="badge bg-<?php echo getStatusBadgeClass($titulo['status_titulo']); ?>">
                                                    <?php echo $titulo['status_titulo']; ?>
                                                </span>
                                            </td>
                                            <td><?php echo $titulo['dias_desde_venda']; ?> dias</td>
                                            <td><small><?php echo $titulo['status_inadimplencia']; ?></small></td>
                                            <td class="text-end"><small><?php echo formatCurrency($titulo['saldo_restante'] ?? 0); ?></small></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Apenas Ativos -->
                    <div class="tab-pane fade" id="ativos-problematicos" role="tabpanel">
                        <?php
                        $ativosProblematicos = array_filter($titulosProblematicos, fn($t) => $t['status_titulo'] == 'Ativo');
                        ?>
                        <?php if (empty($ativosProblematicos)): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i> Não há títulos <strong>Ativos</strong> nesta categoria. Todos os títulos problemáticos foram bloqueados ou cancelados.
                            </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Título</th>
                                        <th>Titular</th>
                                        <th>Consultor</th>
                                        <th>Dias</th>
                                        <th>Inadimplência</th>
                                        <th class="text-end">Risco</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ativosProblematicos as $titulo): ?>
                                        <tr>
                                            <td><small><?php echo sanitize($titulo['numero_titulo']); ?></small></td>
                                            <td><small><?php echo sanitize($titulo['nome_titular']); ?></small></td>
                                            <td><small><?php echo sanitize($titulo['promotor']); ?></small></td>
                                            <td><?php echo $titulo['dias_desde_venda']; ?> dias</td>
                                            <td><small><?php echo $titulo['status_inadimplencia']; ?></small></td>
                                            <td class="text-end"><small><?php echo formatCurrency($titulo['saldo_restante'] ?? 0); ?></small></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filtros -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5><i class="bi bi-funnel"></i> Filtros</h5>
                <button onclick="window.print()" class="btn btn-sm btn-success">
                    <i class="bi bi-printer"></i> Imprimir
                </button>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3" id="filterForm">
                    <!-- Campo de Pesquisa Geral -->
                    <div class="col-md-12">
                        <label class="form-label"><i class="bi bi-search"></i> Pesquisar</label>
                        <input type="text" name="pesquisa_geral" class="form-control"
                               placeholder="Buscar por nome do titular, CPF, número do título..."
                               value="<?php echo sanitize($filtros['pesquisa_geral'] ?? ''); ?>">
                        <small class="text-muted">Busque por nome, CPF ou número do título</small>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Data Início</label>
                        <input type="date" name="data_inicio" class="form-control" id="dataInicio" value="<?php echo sanitize($filtros['data_inicio']); ?>">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Data Fim</label>
                        <input type="date" name="data_fim" class="form-control" id="dataFim" value="<?php echo sanitize($filtros['data_fim']); ?>">
                    </div>

                    <!-- Botões de Períodos Rápidos -->
                    <div class="col-md-6">
                        <label class="form-label">Períodos Rápidos</label>
                        <div class="btn-group w-100 mb-2" role="group">
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setPeriodo(3)">
                                <i class="bi bi-calendar3"></i> 3 meses
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setPeriodo(6)">
                                <i class="bi bi-calendar3"></i> 6 meses
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setPeriodo(9)">
                                <i class="bi bi-calendar3"></i> 9 meses
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setPeriodo(12)">
                                <i class="bi bi-calendar3"></i> 1 ano
                            </button>
                        </div>
                        <div class="btn-group w-100" role="group">
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="setPeriodoCompleto()">
                                <i class="bi bi-infinity"></i> Todo Período
                            </button>
                            <button type="button" class="btn btn-outline-info btn-sm" onclick="focarCamposData()">
                                <i class="bi bi-calendar-range"></i> Personalizado
                            </button>
                        </div>
                        <small class="text-muted">Clique para preencher automaticamente as datas</small>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Prefixo do Título</label>
                        <select name="prefixos[]" class="form-select" multiple size="1">
                            <option value="SBF" <?php echo in_array('SBF', $filtros['prefixos'] ?? []) ? 'selected' : ''; ?>>SBF</option>
                            <option value="SFA" <?php echo in_array('SFA', $filtros['prefixos'] ?? []) ? 'selected' : ''; ?>>SFA</option>
                            <option value="SAC" <?php echo in_array('SAC', $filtros['prefixos'] ?? []) ? 'selected' : ''; ?>>SAC</option>
                            <option value="SAP" <?php echo in_array('SAP', $filtros['prefixos'] ?? []) ? 'selected' : ''; ?>>SAP</option>
                            <option value="DIP" <?php echo in_array('DIP', $filtros['prefixos'] ?? []) ? 'selected' : ''; ?>>DIP</option>
                        </select>
                        <small class="text-muted">Ctrl+clique para múltiplos</small>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Tipo de Título</label>
                        <select name="tipo_titulo" class="form-select">
                            <option value="">Todos</option>
                            <option value="1 Vaga" <?php echo ($filtros['tipo_titulo'] ?? '') == '1 Vaga' ? 'selected' : ''; ?>>1 Vaga</option>
                            <option value="2 Vagas" <?php echo ($filtros['tipo_titulo'] ?? '') == '2 Vagas' ? 'selected' : ''; ?>>2 Vagas</option>
                            <option value="3 Vagas" <?php echo ($filtros['tipo_titulo'] ?? '') == '3 Vagas' ? 'selected' : ''; ?>>3 Vagas</option>
                            <option value="4 Vagas" <?php echo ($filtros['tipo_titulo'] ?? '') == '4 Vagas' ? 'selected' : ''; ?>>4 Vagas</option>
                            <option value="5 Vagas" <?php echo ($filtros['tipo_titulo'] ?? '') == '5 Vagas' ? 'selected' : ''; ?>>5 Vagas</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Status do Título</label>
                        <select name="status_titulo[]" class="form-select" multiple size="3">
                            <?php
                            $statusSelecionados = $filtros['status_titulo'] ?? [];
                            if (!is_array($statusSelecionados)) {
                                $statusSelecionados = [$statusSelecionados];
                            }
                            ?>
                            <option value="Ativo" <?php echo in_array('Ativo', $statusSelecionados) ? 'selected' : ''; ?>>Ativo</option>
                            <option value="Bloqueado" <?php echo in_array('Bloqueado', $statusSelecionados) ? 'selected' : ''; ?>>Bloqueado</option>
                            <option value="Cancelado" <?php echo in_array('Cancelado', $statusSelecionados) ? 'selected' : ''; ?>>Cancelado</option>
                        </select>
                        <small class="text-muted">Ctrl+clique para múltiplos</small>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Status Inadimplência</label>
                        <select name="status_inadimplencia" class="form-select">
                            <option value="">Todos</option>
                            <option value="INADIMPLENTE" <?php echo ($filtros['status_inadimplencia'] ?? '') == 'INADIMPLENTE' ? 'selected' : ''; ?>>Inadimplente</option>
                            <option value="ADIMPLENTE" <?php echo ($filtros['status_inadimplencia'] ?? '') == 'ADIMPLENTE' ? 'selected' : ''; ?>>Adimplente</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Promotor/Consultor</label>
                        <select name="promotor" id="promotorSelect" class="form-select">
                            <option value="">Todos</option>
                            <?php foreach ($promotores as $p): ?>
                                <option value="<?php echo sanitize($p['promotor']); ?>" <?php echo ($filtros['promotor'] ?? '') == $p['promotor'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize($p['promotor']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Parcelas Pagas</label>
                        <select name="parcelas_filtro[]" class="form-select" multiple size="6">
                            <?php
                            $parcelasSelecionadas = $filtros['parcelas_filtro'] ?? [];
                            if (!is_array($parcelasSelecionadas)) {
                                $parcelasSelecionadas = [$parcelasSelecionadas];
                            }
                            ?>
                            <option value="0" <?php echo in_array('0', $parcelasSelecionadas) ? 'selected' : ''; ?>>Nenhuma (0)</option>
                            <option value="1" <?php echo in_array('1', $parcelasSelecionadas) ? 'selected' : ''; ?>>Apenas 1</option>
                            <option value="2" <?php echo in_array('2', $parcelasSelecionadas) ? 'selected' : ''; ?>>Apenas 2</option>
                            <option value="3" <?php echo in_array('3', $parcelasSelecionadas) ? 'selected' : ''; ?>>Apenas 3</option>
                            <option value="4-6" <?php echo in_array('4-6', $parcelasSelecionadas) ? 'selected' : ''; ?>>De 4 a 6</option>
                            <option value="7-11" <?php echo in_array('7-11', $parcelasSelecionadas) ? 'selected' : ''; ?>>De 7 a 11</option>
                            <option value="12+" <?php echo in_array('12+', $parcelasSelecionadas) ? 'selected' : ''; ?>>12 ou mais</option>
                        </select>
                        <small class="text-muted">Ctrl+clique para múltiplos</small>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">CPFs Duplicados</label>
                        <select name="cpf_duplicado" class="form-select">
                            <option value="">Todos</option>
                            <option value="1" <?php echo ($filtros['cpf_duplicado'] ?? '') == '1' ? 'selected' : ''; ?>>Apenas CPFs com múltiplas cotas</option>
                        </select>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> Filtrar
                        </button>
                        <a href="<?php echo url('relatorios.php'); ?>" class="btn btn-secondary">
                            <i class="bi bi-x-circle"></i> Limpar
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Legenda de Cores -->
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-palette"></i> Legenda de Cores - Classificação por Tempo</h6>
            </div>
            <div class="card-body">
                <p class="small text-muted mb-3">
                    <i class="bi bi-info-circle"></i>
                    <strong>Nova classificação:</strong> identifica em qual etapa o cliente parou de pagar para análise de comportamento e estratégias de reativação.
                </p>

                <?php if ($periodoRigoroso): ?>
                <div class="alert alert-warning mb-3" role="alert">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <strong>Classificação Rigorosa Ativa!</strong><br>
                    <small>Período filtrado menor que 6 meses detectado. Qualquer parcela em atraso será considerada como inadimplente (sem tolerância de 2 parcelas).</small>
                </div>
                <?php endif; ?>

                <div class="row g-2" id="legendaFiltros">
                    <!-- Categoria "REQUER ANÁLISE" -->
                    <div class="col-md-4">
                        <div class="p-2 border rounded text-center legenda-card"
                             style="background-color: #ffc107; color: #000; font-weight: 600; cursor: pointer;"
                             onclick="filtrarPorCategoria('requer_analise', this)">
                            <small><strong>REQUER ANÁLISE</strong><br>
                            <span class="text-muted">1ª, 2ª parcela ou até 3 meses</span><br>
                            <span class="badge bg-dark mt-1"><?php echo number_format($contadores['requer_analise'], 0, ',', '.'); ?> títulos</span></small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-2 border rounded text-center legenda-card"
                             style="background-color: #ff9800; color: white; cursor: pointer;"
                             onclick="filtrarPorCategoria('tres_seis', this)">
                            <small><strong>3 a 6 meses</strong><br>
                            <span style="opacity: 0.9;">Experiência</span><br>
                            <span class="badge bg-dark mt-1"><?php echo number_format($contadores['tres_seis'], 0, ',', '.'); ?> títulos</span></small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-2 border rounded text-center legenda-card"
                             style="background-color: #ff6f00; color: white; cursor: pointer;"
                             onclick="filtrarPorCategoria('seis_nove', this)">
                            <small><strong>6 a 9 meses</strong><br>
                            <span style="opacity: 0.9;">Expectativa</span><br>
                            <span class="badge bg-dark mt-1"><?php echo number_format($contadores['seis_nove'], 0, ',', '.'); ?> títulos</span></small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-2 border rounded text-center legenda-card"
                             style="background-color: #f44336; color: white; cursor: pointer;"
                             onclick="filtrarPorCategoria('nove_doze', this)">
                            <small><strong>9 a 12 meses</strong><br>
                            <span style="opacity: 0.9;">Problema sério</span><br>
                            <span class="badge bg-dark mt-1"><?php echo number_format($contadores['nove_doze'], 0, ',', '.'); ?> títulos</span></small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-2 border rounded text-center legenda-card"
                             style="background-color: #c62828; color: white; font-weight: bold; cursor: pointer;"
                             onclick="filtrarPorCategoria('mais_doze', this)">
                            <small><strong>Mais de 12 meses</strong><br>
                            <span style="opacity: 0.9;">Crônico</span><br>
                            <span class="badge bg-dark mt-1"><?php echo number_format($contadores['mais_doze'], 0, ',', '.'); ?> títulos</span></small>
                        </div>
                    </div>

                    <!-- Adimplente -->
                    <div class="col-md-3">
                        <div class="p-2 border rounded text-center legenda-card"
                             style="background-color: #4caf50; color: white; cursor: pointer;"
                             onclick="filtrarPorCategoria('adimplente', this)">
                            <small><strong>Adimplente</strong><br>
                            <span style="opacity: 0.9;">Em dia</span><br>
                            <span class="badge bg-dark mt-1"><?php echo number_format($contadores['adimplente'], 0, ',', '.'); ?> títulos</span></small>
                        </div>
                    </div>
                </div>

                <!-- Botão para limpar filtro -->
                <div class="text-center mt-3" id="limparFiltroContainer" style="display: none;">
                    <button class="btn btn-sm btn-outline-secondary" onclick="limparFiltroCategoria()">
                        <i class="bi bi-x-circle"></i> Mostrar todas as categorias
                    </button>
                </div>
            </div>
        </div>

        <!-- ============================================== -->
        <!-- CARTÕES EM DESTAQUE -->
        <!-- ============================================== -->
        <?php if (!empty($cartoesDestaque)): ?>
        <div class="card border-warning mt-4">
            <div class="card-header bg-warning d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-credit-card-2-front"></i> Cartões em Destaque - Usados Múltiplas Vezes</h6>
                <a href="?view=cartoes&data_inicio=<?php echo $dataInicio; ?>&data_fim=<?php echo $dataFim; ?>" class="btn btn-sm btn-dark">
                    <i class="bi bi-box-arrow-up-right"></i> Ver Todos
                </a>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    <i class="bi bi-info-circle"></i> Cartões usados em <strong>2 ou mais títulos</strong>.
                    Cartões de <span class="badge bg-danger">DÉBITO</span> e com <strong>múltiplos CPFs</strong> indicam possível risco.
                </p>

                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Cartão</th>
                                <th>Bandeira</th>
                                <th class="text-center">Títulos</th>
                                <th class="text-center">CPFs</th>
                                <th class="text-center">Promotores</th>
                                <th class="text-center">Inadimp.</th>
                                <th class="text-center">Bloq.</th>
                                <th class="text-center">Taxa</th>
                                <th class="text-center">Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cartoesDestaque as $cartao): ?>
                                <tr class="<?php echo $cartao['eh_debito'] ? 'table-danger' : ''; ?>">
                                    <td>
                                        <strong><?php echo sanitize($cartao['numero_cartao']); ?></strong>
                                        <?php if ($cartao['eh_debito']): ?>
                                            <span class="badge bg-danger ms-1">DÉBITO</span>
                                        <?php endif; ?>
                                        <?php if ($cartao['total_cpfs'] >= 3): ?>
                                            <span class="badge bg-warning text-dark ms-1"><?php echo $cartao['total_cpfs']; ?> CPFs</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small><?php echo sanitize($cartao['bandeiras']); ?></small></td>
                                    <td class="text-center">
                                        <span class="badge bg-primary"><?php echo $cartao['total_titulos']; ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?php echo $cartao['total_cpfs'] >= 3 ? 'bg-warning text-dark' : 'bg-info'; ?>">
                                            <?php echo $cartao['total_cpfs']; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-secondary"
                                              data-bs-toggle="tooltip"
                                              data-bs-placement="top"
                                              data-bs-html="true"
                                              title="<?php echo sanitize($cartao['nomes_promotores']); ?>">
                                            <?php echo $cartao['total_promotores']; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($cartao['inadimplentes'] > 0): ?>
                                            <span class="badge bg-danger"><?php echo $cartao['inadimplentes']; ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($cartao['bloqueados'] > 0): ?>
                                            <span class="badge bg-warning"><?php echo $cartao['bloqueados']; ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?php
                                            echo $cartao['taxa_inadimplencia'] >= 50 ? 'bg-danger' :
                                                 ($cartao['taxa_inadimplencia'] >= 30 ? 'bg-warning text-dark' : 'bg-success');
                                        ?>">
                                            <?php echo number_format($cartao['taxa_inadimplencia'], 1); ?>%
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-outline-primary" onclick="verTitulosCartao('<?php echo sanitize($cartao['numero_cartao']); ?>')">
                                            <i class="bi bi-eye"></i> Ver
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Resultados -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5><i class="bi bi-table"></i> Resultados (<?php echo number_format($total, 0, ',', '.'); ?> registros)</h5>
                <div class="d-flex gap-2">
                    <button class="btn btn-danger btn-sm" onclick="gerarPDFComIA()" id="btnGerarPDF">
                        <i class="bi bi-file-earmark-pdf"></i> Exportar PDF com Análise IA
                    </button>
                    <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-secondary <?php echo $orderBy == 'nome_titular' ? 'active' : ''; ?>" onclick="ordenar('nome_titular', '<?php echo ($orderBy == 'nome_titular' && $orderDir == 'ASC') ? 'DESC' : 'ASC'; ?>')">
                        <i class="bi bi-sort-alpha-down"></i> Nome
                        <?php if ($orderBy == 'nome_titular'): ?><i class="bi bi-arrow-<?php echo $orderDir == 'ASC' ? 'up' : 'down'; ?>"></i><?php endif; ?>
                    </button>
                    <button class="btn btn-outline-secondary <?php echo $orderBy == 'promotor' ? 'active' : ''; ?>" onclick="ordenar('promotor', '<?php echo ($orderBy == 'promotor' && $orderDir == 'ASC') ? 'DESC' : 'ASC'; ?>')">
                        <i class="bi bi-person"></i> Promotor
                        <?php if ($orderBy == 'promotor'): ?><i class="bi bi-arrow-<?php echo $orderDir == 'ASC' ? 'up' : 'down'; ?>"></i><?php endif; ?>
                    </button>
                    <button class="btn btn-outline-secondary <?php echo $orderBy == 'status_titulo' ? 'active' : ''; ?>" onclick="ordenar('status_titulo', '<?php echo ($orderBy == 'status_titulo' && $orderDir == 'ASC') ? 'DESC' : 'ASC'; ?>')">
                        <i class="bi bi-flag"></i> Status
                        <?php if ($orderBy == 'status_titulo'): ?><i class="bi bi-arrow-<?php echo $orderDir == 'ASC' ? 'up' : 'down'; ?>"></i><?php endif; ?>
                    </button>
                    <button class="btn btn-outline-secondary <?php echo $orderBy == 'status_inadimplencia' ? 'active' : ''; ?>" onclick="ordenar('status_inadimplencia', '<?php echo ($orderBy == 'status_inadimplencia' && $orderDir == 'ASC') ? 'DESC' : 'ASC'; ?>')">
                        <i class="bi bi-palette"></i> Inadimplência
                        <?php if ($orderBy == 'status_inadimplencia'): ?><i class="bi bi-arrow-<?php echo $orderDir == 'ASC' ? 'up' : 'down'; ?>"></i><?php endif; ?>
                    </button>
                    <button class="btn btn-outline-secondary <?php echo $orderBy == 'qtd_parcelas_pagas' ? 'active' : ''; ?>" onclick="ordenar('qtd_parcelas_pagas', '<?php echo ($orderBy == 'qtd_parcelas_pagas' && $orderDir == 'ASC') ? 'DESC' : 'ASC'; ?>')">
                        <i class="bi bi-cash-stack"></i> Parcelas
                        <?php if ($orderBy == 'qtd_parcelas_pagas'): ?><i class="bi bi-arrow-<?php echo $orderDir == 'ASC' ? 'up' : 'down'; ?>"></i><?php endif; ?>
                    </button>
                    <button class="btn btn-outline-secondary <?php echo $orderBy == 'data_primeira_venda' ? 'active' : ''; ?>" onclick="ordenar('data_primeira_venda', '<?php echo ($orderBy == 'data_primeira_venda' && $orderDir == 'DESC') ? 'ASC' : 'DESC'; ?>')">
                        <i class="bi bi-calendar"></i> Data
                        <?php if ($orderBy == 'data_primeira_venda'): ?><i class="bi bi-arrow-<?php echo $orderDir == 'ASC' ? 'up' : 'down'; ?>"></i><?php endif; ?>
                    </button>
                    <button class="btn btn-outline-secondary <?php echo $orderBy == 'tipo_cartao' ? 'active' : ''; ?>" onclick="ordenar('tipo_cartao', '<?php echo ($orderBy == 'tipo_cartao' && $orderDir == 'ASC') ? 'DESC' : 'ASC'; ?>')">
                        <i class="bi bi-credit-card"></i> Tipo Pgto
                        <?php if ($orderBy == 'tipo_cartao'): ?><i class="bi bi-arrow-<?php echo $orderDir == 'ASC' ? 'up' : 'down'; ?>"></i><?php endif; ?>
                    </button>
                    <button class="btn btn-outline-secondary <?php echo $orderBy == 'numero_cartao_usado' ? 'active' : ''; ?>" onclick="ordenar('numero_cartao_usado', '<?php echo ($orderBy == 'numero_cartao_usado' && $orderDir == 'ASC') ? 'DESC' : 'ASC'; ?>')">
                        <i class="bi bi-hash"></i> Nº Cartão
                        <?php if ($orderBy == 'numero_cartao_usado'): ?><i class="bi bi-arrow-<?php echo $orderDir == 'ASC' ? 'up' : 'down'; ?>"></i><?php endif; ?>
                    </button>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Título</th>
                                <th>Titular</th>
                                <th>Promotor</th>
                                <th>Cartão</th>
                                <th>Data Venda</th>
                                <th>Status</th>
                                <th>Inadimplência</th>
                                <th>Parcelas</th>
                                <th>Vlr. Parcela</th>
                                <th>Total Pago</th>
                                <th>Saldo</th>
                            </tr>
                        </thead>
                        <tbody id="tabelaTitulos">
                            <?php foreach ($titulos as $titulo):
                                // Determinar classe de cor e estilo inline (fallback)
                                $rowClass = '';
                                $rowStyle = '';
                                $statusCategoria = '';  // Para filtro client-side
                                $status = $titulo['status_inadimplencia'];

                                // CATEGORIAS "REQUER ANÁLISE"
                                if ($status == 'INADIMPLENTE - Requer análise (1ª parcela)' || $status == 'INADIMPLENTE - Apenas 1ª Parcela') {
                                    $rowClass = 'inadimplente-requer-analise';
                                    $rowStyle = 'background-color: #ffc107 !important; color: #000 !important; font-weight: 600;';
                                    $statusCategoria = 'requer_analise';
                                } elseif ($status == 'INADIMPLENTE - Requer análise (2 parcelas)' || $status == 'INADIMPLENTE - Apenas 2 Parcelas') {
                                    $rowClass = 'inadimplente-requer-analise';
                                    $rowStyle = 'background-color: #ffc107 !important; color: #000 !important; font-weight: 600;';
                                    $statusCategoria = 'requer_analise';
                                } elseif ($status == 'INADIMPLENTE - Requer análise (até 3 meses)' || $status == 'INADIMPLENTE - Até 3 meses') {
                                    $rowClass = 'inadimplente-requer-analise';
                                    $rowStyle = 'background-color: #ffc107 !important; color: #000 !important; font-weight: 600;';
                                    $statusCategoria = 'requer_analise';
                                }
                                // NOVAS CATEGORIAS POR TEMPO
                                elseif ($status == 'INADIMPLENTE - 3 a 6 meses') {
                                    $rowClass = 'inadimplente-3a6';
                                    $rowStyle = 'background-color: #ff9800 !important; color: white !important; font-weight: 500;';
                                    $statusCategoria = 'tres_seis';
                                } elseif ($status == 'INADIMPLENTE - 6 a 9 meses') {
                                    $rowClass = 'inadimplente-6a9';
                                    $rowStyle = 'background-color: #ff6f00 !important; color: white !important; font-weight: 500;';
                                    $statusCategoria = 'seis_nove';
                                } elseif ($status == 'INADIMPLENTE - 9 a 12 meses') {
                                    $rowClass = 'inadimplente-9a12';
                                    $rowStyle = 'background-color: #f44336 !important; color: white !important; font-weight: 500;';
                                    $statusCategoria = 'nove_doze';
                                } elseif ($status == 'INADIMPLENTE - Mais de 12 meses') {
                                    $rowClass = 'inadimplente-12mais';
                                    $rowStyle = 'background-color: #c62828 !important; color: white !important; font-weight: bold;';
                                    $statusCategoria = 'mais_doze';
                                }
                                // CATEGORIAS ANTIGAS (compatibilidade)
                                elseif ($status == 'INADIMPLENTE - Menos de 50%') {
                                    $rowClass = 'inadimplente-menos50';
                                    $rowStyle = 'background-color: #ffeb3b !important; color: #000 !important;';
                                    $statusCategoria = 'menos_50';
                                } elseif ($status == 'INADIMPLENTE - Mais de 50%') {
                                    $rowClass = 'inadimplente-mais50';
                                    $rowStyle = 'background-color: #f44336 !important; color: white !important;';
                                    $statusCategoria = 'mais_50';
                                }
                                // INADIMPLENTE GENÉRICO
                                elseif (strpos($status, 'INADIMPLENTE') !== false) {
                                    $rowClass = 'inadimplente-mais50';
                                    $rowStyle = 'background-color: #f44336 !important; color: white !important;';
                                    $statusCategoria = 'inadimplente_generico';
                                }
                                // ADIMPLENTE
                                elseif ($status == 'ADIMPLENTE') {
                                    $rowClass = 'adimplente';
                                    $rowStyle = 'background-color: #4caf50 !important; color: white !important; font-weight: 500;';
                                    $statusCategoria = 'adimplente';
                                }
                            ?>
                                <tr class="<?php echo $rowClass; ?>" style="<?php echo $rowStyle; ?>" data-status-categoria="<?php echo $statusCategoria; ?>">
                                    <td>
                                        <?php echo sanitize($titulo['numero_titulo']); ?><br>
                                        <small class="text-muted"><?php echo sanitize($titulo['nome_produto_atual'] ?? ''); ?></small>
                                    </td>
                                    <td>
                                        <?php echo sanitize($titulo['nome_titular']); ?><br>
                                        <small class="text-muted">
                                            <?php echo sanitize($titulo['documento_titular']); ?>
                                            <?php if (!empty($cpfTituloIndice[$titulo['id']])): ?>
                                                <span class="badge bg-info text-dark">
                                                    <?php echo $cpfTituloIndice[$titulo['id']]['indice']; ?> de <?php echo $cpfTituloIndice[$titulo['id']]['total']; ?>
                                                </span>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td><small><?php echo sanitize($titulo['promotor']); ?></small></td>
                                    <td>
                                        <?php if (!empty($titulo['numero_cartao_usado'])): ?>
                                            <small><?php echo sanitize(substr($titulo['numero_cartao_usado'], -4)); ?></small>
                                            <?php if ($titulo['tipo_cartao'] == 'DÉBITO'): ?>
                                                <span class="badge bg-danger">D</span>
                                            <?php elseif ($titulo['tipo_cartao'] == 'CRÉDITO'): ?>
                                                <span class="badge bg-success">C</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <small class="text-muted">-</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><small><?php echo formatDate($titulo['data_primeira_venda']); ?></small></td>
                                    <td>
                                        <span class="badge bg-<?php echo getStatusBadgeClass($titulo['status_titulo']); ?>">
                                            <?php echo $titulo['status_titulo']; ?>
                                        </span>
                                    </td>
                                    <td><small><?php echo $titulo['status_inadimplencia']; ?></small></td>
                                    <td><?php echo $titulo['qtd_parcelas_pagas']; ?>/<?php echo $titulo['quantidade_parcelas_venda']; ?></td>
                                    <td><small><?php echo formatCurrency($titulo['valor_parcela'] ?? 0); ?></small></td>
                                    <td><small><?php echo formatCurrency($titulo['total_pago'] ?? 0); ?></small></td>
                                    <td><small><?php echo formatCurrency($titulo['saldo_restante'] ?? 0); ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total > $perPage): ?>
                    <?php
                    $paginationParams = $filtros;
                    unset($paginationParams['page']);
                    echo pagination($total, $perPage, $page, 'relatorios.php?' . http_build_query($paginationParams));
                    ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        // Select2 para autocomplete de promotor
        $(document).ready(function() {
            $('#promotorSelect').select2({
                theme: 'bootstrap-5',
                placeholder: 'Selecione um consultor',
                allowClear: true
            });
        });

        // Função para ordenar
        function ordenar(campo, direcao) {
            const form = document.getElementById('filterForm');
            const input1 = document.createElement('input');
            input1.type = 'hidden';
            input1.name = 'order_by';
            input1.value = campo;

            const input2 = document.createElement('input');
            input2.type = 'hidden';
            input2.name = 'order_dir';
            input2.value = direcao;

            form.appendChild(input1);
            form.appendChild(input2);
            form.submit();
        }

        // Função para definir período rápido
        function setPeriodo(meses) {
            const hoje = new Date();
            const dataInicio = new Date();
            dataInicio.setMonth(dataInicio.getMonth() - meses);
            dataInicio.setDate(1); // Define para o primeiro dia do mês

            // Formatar para YYYY-MM-DD
            const formatarData = (data) => {
                const ano = data.getFullYear();
                const mes = String(data.getMonth() + 1).padStart(2, '0');
                const dia = String(data.getDate()).padStart(2, '0');
                return `${ano}-${mes}-${dia}`;
            };

            document.getElementById('dataInicio').value = formatarData(dataInicio);
            document.getElementById('dataFim').value = formatarData(hoje);
        }

        // Função para definir todo o período (da primeira venda até hoje)
        function setPeriodoCompleto() {
            const hoje = new Date();
            const primeiraData = '<?php echo $primeiraDataVenda; ?>';

            const formatarData = (data) => {
                const ano = data.getFullYear();
                const mes = String(data.getMonth() + 1).padStart(2, '0');
                const dia = String(data.getDate()).padStart(2, '0');
                return `${ano}-${mes}-${dia}`;
            };

            document.getElementById('dataInicio').value = primeiraData;
            document.getElementById('dataFim').value = formatarData(hoje);
        }

        // Função para focar nos campos de data (modo personalizado)
        function focarCamposData() {
            document.getElementById('dataInicio').focus();
            // Scroll suave até os campos de data
            document.getElementById('dataInicio').scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        // Variável para armazenar filtro ativo
        let filtroAtivo = null;

        // Função para filtrar tabela por categoria (client-side)
        function filtrarPorCategoria(categoria, elemento) {
            console.log('Filtrando por categoria:', categoria);

            const tbody = document.getElementById('tabelaTitulos');
            if (!tbody) {
                console.error('Tbody não encontrado!');
                return;
            }

            const rows = tbody.querySelectorAll('tr');
            console.log('Total de linhas encontradas:', rows.length);

            const limparBtn = document.getElementById('limparFiltroContainer');
            const legendaCards = document.querySelectorAll('.legenda-card');

            // Se clicar na mesma categoria, limpar filtro
            if (filtroAtivo === categoria) {
                limparFiltroCategoria();
                return;
            }

            filtroAtivo = categoria;

            // Remover destaque de todos os cards
            legendaCards.forEach(card => {
                card.style.boxShadow = '';
                card.style.transform = '';
            });

            // Destacar card selecionado
            elemento.style.boxShadow = '0 0 15px rgba(0,0,0,0.5)';
            elemento.style.transform = 'scale(1.05)';

            // Filtrar linhas
            let countVisible = 0;
            rows.forEach(row => {
                const statusCategoria = row.getAttribute('data-status-categoria');
                console.log('Linha com categoria:', statusCategoria);

                if (statusCategoria === categoria) {
                    row.style.display = '';
                    countVisible++;
                } else {
                    row.style.display = 'none';
                }
            });

            console.log('Linhas visíveis após filtro:', countVisible);

            // Mostrar botão de limpar filtro
            if (limparBtn) {
                limparBtn.style.display = 'block';
            }

            // Atualizar contador de resultados
            atualizarContadorResultados(countVisible);

            // Scroll suave até a tabela
            const tableContainer = document.querySelector('.table-responsive');
            if (tableContainer) {
                tableContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        // Função para limpar filtro de categoria
        function limparFiltroCategoria() {
            const tbody = document.getElementById('tabelaTitulos');
            if (!tbody) return;

            const rows = tbody.querySelectorAll('tr');
            const limparBtn = document.getElementById('limparFiltroContainer');
            const legendaCards = document.querySelectorAll('.legenda-card');

            filtroAtivo = null;

            // Remover destaque de todos os cards
            legendaCards.forEach(card => {
                card.style.boxShadow = '';
                card.style.transform = '';
            });

            // Mostrar todas as linhas
            let countTotal = 0;
            rows.forEach(row => {
                row.style.display = '';
                countTotal++;
            });

            // Esconder botão de limpar filtro
            if (limparBtn) {
                limparBtn.style.display = 'none';
            }

            // Restaurar contador original
            atualizarContadorResultados(countTotal);
        }

        // Função para atualizar contador de resultados
        function atualizarContadorResultados(count) {
            const header = document.querySelector('.card-header h5');
            if (header) {
                const countFormatted = count.toLocaleString('pt-BR');
                if (filtroAtivo) {
                    header.innerHTML = `<i class="bi bi-table"></i> Resultados (${countFormatted} registros) <span class="badge bg-info ms-2">Filtrado</span>`;
                } else {
                    header.innerHTML = `<i class="bi bi-table"></i> Resultados (${countFormatted} registros)`;
                }
            }
        }

        // Função para converter markdown básico para HTML
        function formatarResumo(texto) {
            // Converter **texto** para <strong>texto</strong>
            texto = texto.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');

            // Converter quebras de linha para <br>
            texto = texto.replace(/\n/g, '<br>');

            // Converter emojis de lista (•) em pontos de lista
            texto = texto.replace(/^• /gm, '&bull; ');

            return texto;
        }

        // Gerar resumo com IA
        function gerarResumoIA() {
            const btn = event.target;
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Gerando...';
            btn.disabled = true;

            fetch('api/gerar_resumo_ia.php?importacao_id=<?php echo $importacaoId; ?>')
                .then(response => {
                    if (!response.ok) {
                        throw new Error('HTTP error! status: ' + response.status);
                    }
                    // Verificar se a resposta é JSON válido
                    const contentType = response.headers.get("content-type");
                    if (!contentType || !contentType.includes("application/json")) {
                        throw new Error('Resposta não é JSON! Content-Type: ' + contentType);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        const resumoFormatado = formatarResumo(data.resumo);
                        document.getElementById('iaResumo').innerHTML = '<div class="alert alert-light">' + resumoFormatado + '</div>';
                    } else {
                        alert('Erro ao gerar resumo: ' + (data.error || 'Erro desconhecido'));
                        btn.innerHTML = originalHTML;
                        btn.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Erro completo:', error);
                    alert('Erro ao gerar resumo: ' + error.message + '\n\nVerifique o console do navegador para mais detalhes.');
                    btn.innerHTML = originalHTML;
                    btn.disabled = false;
                });
        }

        // Função para gerar PDF com análise IA - Abre modal de escolha
        function gerarPDFComIA() {
            const modal = new bootstrap.Modal(document.getElementById('modalEscolhaPlanilha'));
            modal.show();
        }

        // Função que executa a geração do PDF com o tipo escolhido
        async function gerarPDFComIATipo(tipoPlanilha) {
            const btn = document.getElementById('btnGerarPDF');
            const originalHTML = btn.innerHTML;

            // Verificar se há token do usuário disponível
            const tokenUsuario = <?php echo $tokenUsuario ? "'" . $tokenUsuario['token'] . "'" : 'null'; ?>;

            let token;
            if (tokenUsuario) {
                // Usar token automaticamente
                token = tokenUsuario;
            } else {
                // Solicitar token de API
                token = prompt('Cole seu token de API:\n\n(Você ainda não tem um token. Gere um em Admin → Tokens de API)');
                if (!token) {
                    alert('Token não fornecido. Gere um token em Admin → Tokens de API');
                    return;
                }
            }

            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Gerando relatório com IA...';
            btn.disabled = true;

            try {
                // Coletar filtros atuais
                const body = {
                    data_inicio: document.getElementById('dataInicio').value,
                    data_fim: document.getElementById('dataFim').value,
                    tipo_planilha: tipoPlanilha
                };

                // Adicionar outros filtros se estiverem preenchidos
                const promotor = document.getElementById('promotorSelect')?.value;
                if (promotor) body.promotor = promotor;

                // Chamar API
                const response = await fetch(window.location.origin + '/auditory/api/gerar_pdf_relatorio.php', {
                    method: 'POST',
                    headers: {
                        'Authorization': `Bearer ${token}`,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(body)
                });

                const data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(data.error || 'Erro ao gerar relatório');
                }

                // Abrir HTML em nova janela
                const newWindow = window.open('', '_blank');
                newWindow.document.write(data.html);
                newWindow.document.close();

                // Mostrar estatísticas via modal Bootstrap
                const conteudoSucesso = `
                    <div class="alert alert-info mb-3">
                        <h6 class="mb-2"><i class="bi bi-bar-chart-fill"></i> Estatísticas do Relatório:</h6>
                        <ul class="mb-0">
                            <li><strong>Total:</strong> ${data.estatisticas.total_titulos} títulos</li>
                            <li><strong>Inadimplentes:</strong> ${data.estatisticas.inadimplentes}</li>
                            <li><strong>Taxa de Inadimplência:</strong> ${data.estatisticas.taxa_inadimplencia}%</li>
                        </ul>
                    </div>
                    <div class="alert alert-success mb-0">
                        <i class="bi bi-printer"></i> <strong>Dica:</strong> Use <kbd>Ctrl+P</kbd> na nova janela para salvar como PDF
                    </div>
                `;
                document.getElementById('modalPDFSucessoConteudo').innerHTML = conteudoSucesso;
                const modalSucesso = new bootstrap.Modal(document.getElementById('modalPDFSucesso'));
                modalSucesso.show();

            } catch (error) {
                console.error('Erro:', error);
                const conteudoErro = `
                    <div class="alert alert-danger mb-3">
                        <p class="mb-2"><strong>Mensagem de erro:</strong></p>
                        <code>${error.message}</code>
                    </div>
                    <div class="alert alert-warning mb-0">
                        <h6 class="mb-2"><i class="bi bi-check2-square"></i> Verifique:</h6>
                        <ul class="mb-0">
                            <li>Token de API válido</li>
                            <li>Conexão com a internet</li>
                            <li>Console do navegador (F12) para mais detalhes</li>
                        </ul>
                    </div>
                `;
                document.getElementById('modalPDFErroConteudo').innerHTML = conteudoErro;
                const modalErro = new bootstrap.Modal(document.getElementById('modalPDFErro'));
                modalErro.show();
            } finally {
                btn.innerHTML = originalHTML;
                btn.disabled = false;
            }
        }

        // Função auxiliar para selecionar tipo de planilha
        function selecionarTipoPlanilha(tipo) {
            // Fechar modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('modalEscolhaPlanilha'));
            modal.hide();

            // Executar geração do PDF
            gerarPDFComIATipo(tipo);
        }
    </script>

    <!-- Modal de Escolha de Tipo de Planilha -->
    <div class="modal fade" id="modalEscolhaPlanilha" tabindex="-1" aria-labelledby="modalEscolhaPlanilhaLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalEscolhaPlanilhaLabel">
                        <i class="bi bi-file-earmark-pdf"></i> Escolha o tipo de planilha
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Selecione qual tipo de relatório deseja gerar:</p>

                    <div class="d-grid gap-3">
                        <!-- Opção: Planilha de Auditoria -->
                        <div class="card border-warning" style="cursor: pointer;" onclick="selecionarTipoPlanilha('auditoria')">
                            <div class="card-body">
                                <h6 class="card-title text-warning mb-2">
                                    <i class="bi bi-search"></i> Planilha de AUDITORIA
                                </h6>
                                <p class="card-text mb-2">
                                    <small>Mostra <strong>todos</strong> os títulos que requerem atenção:</small>
                                </p>
                                <ul class="mb-0 small">
                                    <li>Inadimplentes</li>
                                    <li>Pagamentos em Débito/PIX</li>
                                    <li>Sem cartão cadastrado</li>
                                </ul>
                            </div>
                        </div>

                        <!-- Opção: Planilha Padrão -->
                        <div class="card border-secondary" style="cursor: pointer;" onclick="selecionarTipoPlanilha('padrao')">
                            <div class="card-body">
                                <h6 class="card-title text-secondary mb-2">
                                    <i class="bi bi-file-text"></i> Planilha PADRÃO
                                </h6>
                                <p class="card-text mb-0">
                                    <small>Mostra apenas os <strong>primeiros 50 títulos</strong></small>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Sucesso após Gerar PDF -->
    <div class="modal fade" id="modalPDFSucesso" tabindex="-1" aria-labelledby="modalPDFSucessoLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="modalPDFSucessoLabel">✅ Relatório Gerado com Sucesso!</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="modalPDFSucessoConteudo">
                        <!-- Conteúdo será preenchido via JavaScript -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-success" data-bs-dismiss="modal">OK</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Erro ao Gerar PDF -->
    <div class="modal fade" id="modalPDFErro" tabindex="-1" aria-labelledby="modalPDFErroLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="modalPDFErroLabel">❌ Erro ao Gerar Relatório</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="modalPDFErroConteudo">
                        <!-- Conteúdo será preenchido via JavaScript -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Títulos do Cartão -->
    <div class="modal fade" id="modalTitulosCartao" tabindex="-1" aria-labelledby="modalTitulosCartaoLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalTitulosCartaoLabel">
                        <i class="bi bi-credit-card-2-front"></i> Títulos do Cartão: <span id="numeroCartaoModal"></span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div id="loadingTitulosCartao" class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Carregando...</span>
                        </div>
                        <p class="mt-2">Carregando títulos...</p>
                    </div>
                    <div id="conteudoTitulosCartao" style="display: none;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Função para ver títulos de um cartão específico
        function verTitulosCartao(numeroCartao) {
            // Mostrar modal
            const modal = new bootstrap.Modal(document.getElementById('modalTitulosCartao'));
            modal.show();

            // Atualizar número do cartão no título
            document.getElementById('numeroCartaoModal').textContent = numeroCartao;

            // Mostrar loading
            document.getElementById('loadingTitulosCartao').style.display = 'block';
            document.getElementById('conteudoTitulosCartao').style.display = 'none';

            // Buscar títulos do cartão
            const params = new URLSearchParams({
                numero_cartao: numeroCartao,
                data_inicio: '<?php echo $dataInicio; ?>',
                data_fim: '<?php echo $dataFim; ?>',
                ajax: '1'
            });

            fetch('ajax/buscar_titulos_cartao.php?' + params.toString())
                .then(response => response.json())
                .then(data => {
                    document.getElementById('loadingTitulosCartao').style.display = 'none';
                    document.getElementById('conteudoTitulosCartao').style.display = 'block';

                    if (data.success) {
                        let html = '<div class="table-responsive"><table class="table table-sm table-striped table-hover">';
                        html += '<thead class="table-dark"><tr>';
                        html += '<th>Título</th>';
                        html += '<th>Titular</th>';
                        html += '<th>CPF</th>';
                        html += '<th>Promotor</th>';
                        html += '<th>Status</th>';
                        html += '<th>Inadimplência</th>';
                        html += '<th class="text-end">Parcelas</th>';
                        html += '<th class="text-end">Valor Pago</th>';
                        html += '<th class="text-end">Saldo</th>';
                        html += '</tr></thead><tbody>';

                        data.titulos.forEach(titulo => {
                            const corStatus = titulo.status_inadimplencia.includes('INADIMPLENTE') ? 'table-danger' : 'table-success';
                            html += `<tr class="${corStatus}">`;
                            html += `<td><strong>${titulo.numero_titulo}</strong></td>`;
                            html += `<td>${titulo.nome_titular}</td>`;
                            html += `<td><small>${titulo.documento_titular}</small></td>`;
                            html += `<td><small>${titulo.promotor}</small></td>`;
                            html += `<td><span class="badge ${titulo.status_titulo === 'Ativo' ? 'bg-success' : 'bg-warning'}">${titulo.status_titulo}</span></td>`;
                            html += `<td><small>${titulo.status_inadimplencia}</small></td>`;
                            html += `<td class="text-end">${titulo.qtd_parcelas_pagas}/${titulo.quantidade_parcelas_venda}</td>`;
                            html += `<td class="text-end">R$ ${parseFloat(titulo.total_pago).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</td>`;
                            html += `<td class="text-end">R$ ${parseFloat(titulo.saldo_restante).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</td>`;
                            html += `</tr>`;
                        });

                        html += '</tbody></table></div>';

                        // Resumo
                        html += `<div class="alert alert-info mt-3">`;
                        html += `<strong>Resumo:</strong> ${data.titulos.length} títulos encontrados`;
                        html += ` | ${data.stats.total_cpfs} CPF(s) diferentes`;
                        html += ` | ${data.stats.inadimplentes} inadimplente(s)`;
                        html += ` | Taxa: ${data.stats.taxa_inadimplencia}%`;
                        html += `</div>`;

                        document.getElementById('conteudoTitulosCartao').innerHTML = html;
                    } else {
                        document.getElementById('conteudoTitulosCartao').innerHTML =
                            `<div class="alert alert-warning">Nenhum título encontrado para este cartão.</div>`;
                    }
                })
                .catch(error => {
                    document.getElementById('loadingTitulosCartao').style.display = 'none';
                    document.getElementById('conteudoTitulosCartao').style.display = 'block';
                    document.getElementById('conteudoTitulosCartao').innerHTML =
                        `<div class="alert alert-danger">Erro ao carregar títulos: ${error.message}</div>`;
                });
        }

        // Inicializar tooltips do Bootstrap
        document.addEventListener('DOMContentLoaded', function() {
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
    </script>
</body>
</html>
