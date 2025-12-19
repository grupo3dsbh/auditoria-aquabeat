/*
================================================================================
QUERY CORRIGIDA COM CABEÇALHO - BASEADA NA ESTRUTURA REAL DO BANCO
================================================================================
*/

USE [MultiClubes]
GO

-- PRIMEIRA LINHA: CABEÇALHOS
SELECT
    'NumeroTitulo' AS NumeroTitulo,
    'NomeProdutoOriginal' AS NomeProdutoOriginal,
    'NomeProdutoAtual' AS NomeProdutoAtual,
    'AlterouVagas' AS AlterouVagas,
    'Categoria' AS Categoria,
    'StatusTitulo' AS StatusTitulo,
    'DataCadastro' AS DataCadastro,
    'DataPrimeiraVenda' AS DataPrimeiraVenda,
    'DataUltimaVenda' AS DataUltimaVenda,
    'NomeTitular' AS NomeTitular,
    'DocumentoTitular' AS DocumentoTitular,
    'TelefoneResidencial' AS TelefoneResidencial,
    'OrigemVenda' AS OrigemVenda,
    'Promotor' AS Promotor,
    'Gerente' AS Gerente,
    'NumeroCartao' AS NumeroCartao,
    'Bandeira' AS Bandeira,
    'TipoPagamentoCartao' AS TipoPagamentoCartao,
    'QuantidadeParcelasVenda' AS QuantidadeParcelasVenda,
    'QtdParcelasPagas' AS QtdParcelasPagas,
    'ValorParcela' AS ValorParcela,
    'TotalPago' AS TotalPago,
    'SaldoRestante' AS SaldoRestante,
    'ParcelasRestantes' AS ParcelasRestantes,
    'FormaPagamento' AS FormaPagamento,
    'TipoPagamento' AS TipoPagamento,
    'StatusInadimplencia' AS StatusInadimplencia,
    'PeriodoTitulo' AS PeriodoTitulo,
    'DiasDesdeVenda' AS DiasDesdeVenda

UNION ALL

-- DADOS REAIS COM LÓGICA DE INADIMPLÊNCIA CORRIGIDA
SELECT
    pt.NumeroTitulo,
    pp.NomeProdutoOriginal,
    up.NomeProduto AS NomeProdutoAtual,
    CASE WHEN pp.NomeProdutoOriginal <> up.NomeProduto THEN 'Sim' ELSE 'Não' END AS AlterouVagas,
    up.Categoria,
    up.StatusTitulo,
    CONVERT(VARCHAR(10), MAX(pt.DataCadastro), 120) AS DataCadastro,
    CONVERT(VARCHAR(10), pp.DataPrimeiraVenda, 120) AS DataPrimeiraVenda,
    CONVERT(VARCHAR(10), MAX(pt.DataVenda), 120) AS DataUltimaVenda,
    MAX(pt.NomeTitular) AS NomeTitular,
    MAX(pt.DocumentoTitular) AS DocumentoTitular,
    MAX(pt.ResidentialPhone) AS TelefoneResidencial,
    MAX(pt.OrigemVenda) AS OrigemVenda,
    MAX(pt.Promotor) AS Promotor,
    MAX(pt.Gerente) AS Gerente,
    MAX(pt.NumeroCartao) AS NumeroCartao,
    MAX(pt.Bandeira) AS Bandeira,
    MAX(pt.PaymentType) AS TipoPagamentoCartao,
    CAST(MAX(pt.QuantidadeParcelasVenda) AS VARCHAR(10)) AS QuantidadeParcelasVenda,
    CAST(SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END) AS VARCHAR(10)) AS QtdParcelasPagas,
    CAST(MAX(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN COALESCE(pt.ValorPago, pt.Total) END) AS VARCHAR(20)) AS ValorParcela,
    CAST(SUM(COALESCE(pt.ValorPago, pt.Total, 0)) AS VARCHAR(20)) AS TotalPago,

    -- Saldo Restante
    CAST(CASE
        WHEN MAX(pt.QuantidadeParcelasVenda) = 1 THEN 0
        WHEN SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END) = MAX(pt.QuantidadeParcelasVenda) THEN 0
        ELSE (MAX(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN COALESCE(pt.ValorPago, pt.Total) END) * MAX(pt.QuantidadeParcelasVenda))
             - SUM(COALESCE(pt.ValorPago, pt.Total, 0))
    END AS VARCHAR(20)) AS SaldoRestante,

    -- Parcelas Restantes
    CAST(CASE
        WHEN MAX(pt.QuantidadeParcelasVenda) = 1 THEN 0
        WHEN SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END) = MAX(pt.QuantidadeParcelasVenda) THEN 0
        ELSE MAX(pt.QuantidadeParcelasVenda) - SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END)
    END AS VARCHAR(10)) AS ParcelasRestantes,

    MAX(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN pt.PaymentMode END) AS FormaPagamento,

    -- Tipo de Pagamento
    CASE
        WHEN MAX(pt.QuantidadeParcelasVenda) = 1 THEN 'À Vista'
        WHEN SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END) = MAX(pt.QuantidadeParcelasVenda) THEN 'Cartão Parcelado'
        ELSE 'Recorrente'
    END AS TipoPagamento,

    -- STATUS DE INADIMPLÊNCIA CORRIGIDO
    -- Compara parcelas pagas com parcelas que DEVERIAM estar pagas baseado no tempo
    CASE
        -- Calcular quantos meses se passaram desde a primeira venda
        WHEN SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END)
             < (DATEDIFF(MONTH, pp.DataPrimeiraVenda, GETDATE()) + 1)
             AND SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END)
             < MAX(pt.QuantidadeParcelasVenda)
        THEN
            CASE
                -- Se pagou apenas 1 parcela e deveria ter pago mais
                WHEN SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END) = 1
                     AND (DATEDIFF(MONTH, pp.DataPrimeiraVenda, GETDATE()) + 1) > 1
                THEN 'INADIMPLENTE - Apenas 1ª Parcela'

                -- Se pagou apenas 2 parcelas e deveria ter pago mais
                WHEN SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END) = 2
                     AND (DATEDIFF(MONTH, pp.DataPrimeiraVenda, GETDATE()) + 1) > 2
                THEN 'INADIMPLENTE - Apenas 2 Parcelas'

                -- Se está devendo menos de 50% das parcelas esperadas
                WHEN CAST(SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END) AS FLOAT)
                     / (DATEDIFF(MONTH, pp.DataPrimeiraVenda, GETDATE()) + 1) >= 0.5
                THEN 'INADIMPLENTE - Menos de 50%'

                -- Se está devendo mais de 50%
                ELSE 'INADIMPLENTE - Mais de 50%'
            END

        -- Se pagou todas as parcelas esperadas até agora OU completou o plano
        WHEN SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END)
             >= (DATEDIFF(MONTH, pp.DataPrimeiraVenda, GETDATE()) + 1)
             OR SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END)
             >= MAX(pt.QuantidadeParcelasVenda)
        THEN 'ADIMPLENTE'

        -- Default
        ELSE 'ADIMPLENTE'
    END AS StatusInadimplencia,

    -- Período
    CASE
        WHEN pp.DataPrimeiraVenda >= DATEADD(MONTH, -3, GETDATE()) THEN '0-3 meses'
        WHEN pp.DataPrimeiraVenda >= DATEADD(MONTH, -6, GETDATE()) THEN '3-6 meses'
        WHEN pp.DataPrimeiraVenda >= DATEADD(YEAR, -1, GETDATE()) THEN '6-12 meses'
        ELSE 'Mais de 1 ano'
    END AS PeriodoTitulo,

    -- Dias desde venda
    CAST(DATEDIFF(DAY, pp.DataPrimeiraVenda, GETDATE()) AS VARCHAR(10)) AS DiasDesdeVenda

FROM [dbo].[PaidTitles] pt

-- CTE para produto mais recente (inline)
INNER JOIN (
    SELECT
        NumeroTitulo,
        NomeProduto,
        Categoria,
        StatusTitulo,
        ROW_NUMBER() OVER (PARTITION BY NumeroTitulo ORDER BY DataVenda DESC) AS rn
    FROM [dbo].[PaidTitles]
    WHERE [NomeProduto] LIKE '%Sócio%'
      AND [DataCadastro] BETWEEN '2024-11-01' AND GETDATE()
) up ON pt.NumeroTitulo = up.NumeroTitulo AND up.rn = 1

-- CTE para primeiro produto (inline)
INNER JOIN (
    SELECT
        NumeroTitulo,
        NomeProduto AS NomeProdutoOriginal,
        DataVenda AS DataPrimeiraVenda,
        ROW_NUMBER() OVER (PARTITION BY NumeroTitulo ORDER BY DataVenda ASC) AS rn
    FROM [dbo].[PaidTitles]
    WHERE [NomeProduto] LIKE '%Sócio%'
      AND [DataCadastro] BETWEEN '2024-11-01' AND GETDATE()
) pp ON pt.NumeroTitulo = pp.NumeroTitulo AND pp.rn = 1

WHERE pt.[NomeProduto] LIKE '%Sócio%'
  AND pt.[DataCadastro] BETWEEN '2024-11-01' AND GETDATE()
  AND up.StatusTitulo IN ('Ativo', 'Bloqueado')

GROUP BY
    pt.NumeroTitulo,
    pp.NomeProdutoOriginal,
    up.NomeProduto,
    up.Categoria,
    up.StatusTitulo,
    pp.DataPrimeiraVenda

ORDER BY
    pp.DataPrimeiraVenda DESC;

GO

/*
================================================================================
INSTRUÇÕES:
================================================================================

1. Execute esta query no SQL Server Management Studio
2. Clique com botão direito nos resultados
3. "Save Results As..." → escolha CSV
4. Salve com codificação UTF-8
5. Use delimitador ponto e vírgula (;)

A primeira linha contém os cabeçalhos.
As linhas seguintes contêm os dados com a lógica de inadimplência CORRIGIDA.

================================================================================
*/
