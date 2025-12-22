/*
================================================================================
QUERY PARA EXPORTAÇÃO DE DADOS DE VENDA - SEM LÓGICA DE INADIMPLÊNCIA
================================================================================
A lógica de inadimplência será calculada no PHP após importação
*/

USE [MultiClubes]
GO

-- Parâmetros
DECLARE @DataInicio DATETIME = '2024-11-01T00:00:00';
DECLARE @DataFim DATETIME = GETDATE();
DECLARE @ProdutoFiltro VARCHAR(50) = '%Sócio%';
DECLARE @Periodo3Meses DATETIME = DATEADD(MONTH, -3, GETDATE());
DECLARE @Periodo6Meses DATETIME = DATEADD(MONTH, -6, GETDATE());
DECLARE @Periodo1Ano DATETIME = DATEADD(YEAR, -1, GETDATE());

-- CTE para produto mais recente
WITH UltimoProduto AS (
    SELECT
        NumeroTitulo,
        NomeProduto,
        Categoria,
        StatusTitulo,
        ROW_NUMBER() OVER (PARTITION BY NumeroTitulo ORDER BY DataVenda DESC) AS rn
    FROM [dbo].[PaidTitles]
    WHERE [NomeProduto] LIKE @ProdutoFiltro
      AND [DataCadastro] BETWEEN @DataInicio AND @DataFim
),
-- CTE para primeiro produto
PrimeiroProduto AS (
    SELECT
        NumeroTitulo,
        NomeProduto AS NomeProdutoOriginal,
        DataVenda AS DataPrimeiraVenda,
        ROW_NUMBER() OVER (PARTITION BY NumeroTitulo ORDER BY DataVenda ASC) AS rn
    FROM [dbo].[PaidTitles]
    WHERE [NomeProduto] LIKE @ProdutoFiltro
      AND [DataCadastro] BETWEEN @DataInicio AND @DataFim
)
-- SELECT principal
SELECT
    -- Identificação do Título
    pt.NumeroTitulo,
    pp.NomeProdutoOriginal,
    up.NomeProduto AS NomeProdutoAtual,
    CASE WHEN pp.NomeProdutoOriginal <> up.NomeProduto THEN 'Sim' ELSE 'Não' END AS AlterouVagas,
    up.Categoria,
    up.StatusTitulo,

    -- Datas
    MAX(pt.DataCadastro) AS DataCadastro,
    pp.DataPrimeiraVenda,
    MAX(pt.DataVenda) AS DataUltimaVenda,

    -- Cliente
    MAX(pt.NomeTitular) AS NomeTitular,
    MAX(pt.DocumentoTitular) AS DocumentoTitular,
    MAX(pt.ResidentialPhone) AS TelefoneResidencial,

    -- Venda
    MAX(pt.OrigemVenda) AS OrigemVenda,
    MAX(pt.Promotor) AS Promotor,
    MAX(pt.Gerente) AS Gerente,

    -- Cartão
    MAX(pt.NumeroCartao) AS NumeroCartao,
    MAX(pt.Bandeira) AS Bandeira,
    MAX(pt.PaymentType) AS TipoPagamentoCartao,

    -- Parcelas (DADOS BRUTOS - sem cálculo de inadimplência)
    MAX(pt.QuantidadeParcelasVenda) AS QuantidadeParcelasVenda,
    SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END) AS QtdParcelasPagas,

    -- Valores
    MAX(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN COALESCE(pt.ValorPago, pt.Total) END) AS ValorParcela,
    SUM(COALESCE(pt.ValorPago, pt.Total, 0)) AS TotalPago,

    -- Saldo Restante
    CASE
        WHEN MAX(pt.QuantidadeParcelasVenda) = 1 THEN 0
        WHEN SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END) = MAX(pt.QuantidadeParcelasVenda) THEN 0
        ELSE (MAX(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN COALESCE(pt.ValorPago, pt.Total) END) * MAX(pt.QuantidadeParcelasVenda))
             - SUM(COALESCE(pt.ValorPago, pt.Total, 0))
    END AS SaldoRestante,

    -- Parcelas Restantes
    CASE
        WHEN MAX(pt.QuantidadeParcelasVenda) = 1 THEN 0
        WHEN SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END) = MAX(pt.QuantidadeParcelasVenda) THEN 0
        ELSE MAX(pt.QuantidadeParcelasVenda) - SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END)
    END AS ParcelasRestantes,

    -- Forma de Pagamento
    MAX(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN pt.PaymentMode END) AS FormaPagamento,

    -- Tipo de Pagamento
    CASE
        WHEN MAX(pt.QuantidadeParcelasVenda) = 1 THEN 'À Vista'
        WHEN SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END) = MAX(pt.QuantidadeParcelasVenda) THEN 'Cartão Parcelado'
        ELSE 'Recorrente'
    END AS TipoPagamento,

    -- Período
    CASE
        WHEN pp.DataPrimeiraVenda >= @Periodo3Meses THEN '0-3 meses'
        WHEN pp.DataPrimeiraVenda >= @Periodo6Meses THEN '3-6 meses'
        WHEN pp.DataPrimeiraVenda >= @Periodo1Ano THEN '6-12 meses'
        ELSE 'Mais de 1 ano'
    END AS PeriodoTitulo,

    -- Dias desde venda
    DATEDIFF(DAY, pp.DataPrimeiraVenda, GETDATE()) AS DiasDesdeVenda,

    -- Lista de parcelas pagas (para referência)
    STRING_AGG(
        CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL
        THEN CAST(pt.NumeroParcelaVenda AS VARCHAR(5)) END,
        ','
    ) WITHIN GROUP (ORDER BY pt.NumeroParcelaVenda) AS ListaParcelasPagas,

    -- Lista de valores pagos
    STRING_AGG(
        CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL
        THEN CAST(COALESCE(pt.ValorPago, pt.Total) AS VARCHAR(20)) END,
        ','
    ) WITHIN GROUP (ORDER BY pt.NumeroParcelaVenda) AS ListaValoresPagos

FROM [dbo].[PaidTitles] pt
INNER JOIN UltimoProduto up ON pt.NumeroTitulo = up.NumeroTitulo AND up.rn = 1
INNER JOIN PrimeiroProduto pp ON pt.NumeroTitulo = pp.NumeroTitulo AND pp.rn = 1
WHERE pt.[NomeProduto] LIKE @ProdutoFiltro
  AND pt.[DataCadastro] BETWEEN @DataInicio AND @DataFim
  AND up.StatusTitulo IN ('Ativo', 'Bloqueado', 'Cancelado', 'Vencido')
GROUP BY
    pt.NumeroTitulo,
    pp.NomeProdutoOriginal,
    up.NomeProduto,
    up.Categoria,
    up.StatusTitulo,
    pp.DataPrimeiraVenda
ORDER BY
    pp.DataPrimeiraVenda DESC,
    pt.NumeroTitulo;

GO

/*
================================================================================
INSTRUÇÕES PARA EXPORTAÇÃO:
================================================================================

1. Execute esta query no SQL Server Management Studio (SSMS)
2. Clique com botão direito nos resultados
3. "Save Results As..." → escolha CSV
4. Salve com:
   - Codificação: UTF-8
   - Delimitador: ponto e vírgula (;)

5. Faça upload do CSV no sistema PHP em /upload.php
6. A lógica de inadimplência será calculada automaticamente pelo PHP

================================================================================
CAMPOS EXPORTADOS (sem StatusInadimplencia):
================================================================================

1. NumeroTitulo - Identificador do título
2. NomeProdutoOriginal - Produto comprado originalmente
3. NomeProdutoAtual - Produto atual após mudanças
4. AlterouVagas - Se mudou de plano (Sim/Não)
5. Categoria - Categoria do produto
6. StatusTitulo - Status (Ativo/Bloqueado/Cancelado/Vencido)
7. DataCadastro - Data de cadastro
8. DataPrimeiraVenda - Data da primeira venda (IMPORTANTE para cálculo)
9. DataUltimaVenda - Data da última venda
10. NomeTitular - Nome do cliente
11. DocumentoTitular - CPF
12. TelefoneResidencial - Telefone
13. OrigemVenda - Origem da venda
14. Promotor - Nome do promotor
15. Gerente - Nome do gerente
16. NumeroCartao - Número do cartão
17. Bandeira - Bandeira do cartão
18. TipoPagamentoCartao - Tipo de pagamento
19. QuantidadeParcelasVenda - Total de parcelas do plano
20. QtdParcelasPagas - Parcelas pagas até agora
21. ValorParcela - Valor de cada parcela
22. TotalPago - Total pago
23. SaldoRestante - Saldo a pagar
24. ParcelasRestantes - Parcelas restantes
25. FormaPagamento - Forma de pagamento
26. TipoPagamento - À Vista/Cartão Parcelado/Recorrente
27. PeriodoTitulo - 0-3 meses / 3-6 meses / etc
28. DiasDesdeVenda - Dias desde a primeira venda
29. ListaParcelasPagas - Lista de parcelas pagas (ex: 1,2,3)
30. ListaValoresPagos - Lista de valores pagos

NOTA: O campo StatusInadimplencia será calculado pelo PHP após importação!
================================================================================
*/
