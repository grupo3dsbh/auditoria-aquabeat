/*
================================================================================
QUERY PARA EXPORTAÇÃO - VERSÃO CORRIGIDA FINAL
================================================================================
Correção: TotalPago e QtdParcelasPagas agora contam APENAS:
- Parcelas do plano (produtos com "Sócio")
- Diferença de mensalidade

NÃO conta:
- Consumo crédito
- Pulseira Troca
- Outros produtos extras
*/

USE [MultiClubes]
GO

-- Parâmetros
DECLARE @DataInicio DATETIME = '2024-11-01T00:00:00';
DECLARE @DataFim DATETIME = GETDATE();
DECLARE @Periodo3Meses DATETIME = DATEADD(MONTH, -3, GETDATE());
DECLARE @Periodo6Meses DATETIME = DATEADD(MONTH, -6, GETDATE());
DECLARE @Periodo1Ano DATETIME = DATEADD(YEAR, -1, GETDATE());

-- SELECT principal com todas as agregações
SELECT
    -- Identificação do Título
    pt.NumeroTitulo,

    -- Produto Original (primeira venda com "Sócio")
    (SELECT TOP 1 NomeProduto
     FROM [dbo].[PaidTitles]
     WHERE NumeroTitulo = pt.NumeroTitulo
       AND NomeProduto LIKE '%Sócio%'
     ORDER BY DataVenda ASC) AS NomeProdutoOriginal,

    -- Produto Atual = CATEGORIA
    (SELECT TOP 1 Categoria
     FROM [dbo].[PaidTitles]
     WHERE NumeroTitulo = pt.NumeroTitulo
     ORDER BY DataVenda DESC) AS NomeProdutoAtual,

    -- Alterou Vagas?
    CASE WHEN
        (SELECT TOP 1 NomeProduto FROM [dbo].[PaidTitles] WHERE NumeroTitulo = pt.NumeroTitulo AND NomeProduto LIKE '%Sócio%' ORDER BY DataVenda ASC) <>
        (SELECT TOP 1 Categoria FROM [dbo].[PaidTitles] WHERE NumeroTitulo = pt.NumeroTitulo ORDER BY DataVenda DESC)
    THEN 'Sim' ELSE 'Não' END AS AlterouVagas,

    -- Categoria
    (SELECT TOP 1 Categoria
     FROM [dbo].[PaidTitles]
     WHERE NumeroTitulo = pt.NumeroTitulo
     ORDER BY DataVenda DESC) AS Categoria,

    -- Status
    (SELECT TOP 1 StatusTitulo
     FROM [dbo].[PaidTitles]
     WHERE NumeroTitulo = pt.NumeroTitulo
     ORDER BY DataVenda DESC) AS StatusTitulo,

    -- Datas
    MAX(pt.DataCadastro) AS DataCadastro,
    MIN(pt.DataVenda) AS DataPrimeiraVenda,
    MAX(pt.DataVenda) AS DataUltimaVenda,

    -- Cliente
    MAX(pt.NomeTitular) AS NomeTitular,
    MAX(pt.DocumentoTitular) AS DocumentoTitular,
    MAX(pt.ResidentialPhone) AS TelefoneResidencial,

    -- Venda
    MAX(pt.OrigemVenda) AS OrigemVenda,
    MAX(pt.Promotor) AS Promotor,
    MAX(pt.Gerente) AS Gerente,

    -- TODOS OS CARTÕES (concatenados com " | ")
    STUFF((
        SELECT DISTINCT ' | ' + NumeroCartao
        FROM [dbo].[PaidTitles]
        WHERE NumeroTitulo = pt.NumeroTitulo
          AND NumeroCartao IS NOT NULL
        FOR XML PATH(''), TYPE).value('.', 'NVARCHAR(MAX)'), 1, 3, '') AS NumeroCartao,

    -- TODAS AS BANDEIRAS (concatenadas com " | ")
    STUFF((
        SELECT DISTINCT ' | ' + Bandeira
        FROM [dbo].[PaidTitles]
        WHERE NumeroTitulo = pt.NumeroTitulo
          AND Bandeira IS NOT NULL
        FOR XML PATH(''), TYPE).value('.', 'NVARCHAR(MAX)'), 1, 3, '') AS Bandeira,

    -- Tipo de pagamento
    MAX(pt.PaymentType) AS TipoPagamentoCartao,

    -- Parcelas (CORRIGIDO: conta apenas parcelas do plano)
    MAX(pt.QuantidadeParcelasVenda) AS QuantidadeParcelasVenda,

    -- ✅ CORRIGIDO: Conta apenas parcelas válidas (Sócio ou Diferença)
    SUM(CASE
        WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL
         AND (pt.NomeProduto LIKE '%Sócio%' OR pt.NomeProduto LIKE '%Diferença%')
         AND pt.NomeProduto NOT LIKE '%Consumo%'
         AND pt.NomeProduto NOT LIKE '%Pulseira%Troca%'
        THEN 1
        ELSE 0
    END) AS QtdParcelasPagas,

    -- Valores
    MAX(CASE
        WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL
         AND (pt.NomeProduto LIKE '%Sócio%' OR pt.NomeProduto LIKE '%Diferença%')
        THEN COALESCE(pt.ValorPago, pt.Total)
    END) AS ValorParcela,

    -- ✅ CORRIGIDO: Soma apenas pagamentos de parcelas do plano
    SUM(CASE
        WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL
         AND (pt.NomeProduto LIKE '%Sócio%' OR pt.NomeProduto LIKE '%Diferença%')
         AND pt.NomeProduto NOT LIKE '%Consumo%'
         AND pt.NomeProduto NOT LIKE '%Pulseira%Troca%'
        THEN COALESCE(pt.ValorPago, pt.Total)
        ELSE 0
    END) AS TotalPago,

    -- Saldo Restante (baseado no total pago correto)
    CASE
        WHEN MAX(pt.QuantidadeParcelasVenda) = 1 THEN 0
        WHEN SUM(CASE
            WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL
             AND (pt.NomeProduto LIKE '%Sócio%' OR pt.NomeProduto LIKE '%Diferença%')
             AND pt.NomeProduto NOT LIKE '%Consumo%'
             AND pt.NomeProduto NOT LIKE '%Pulseira%Troca%'
            THEN 1 ELSE 0 END) = MAX(pt.QuantidadeParcelasVenda) THEN 0
        ELSE (MAX(CASE
                WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL
                 AND (pt.NomeProduto LIKE '%Sócio%' OR pt.NomeProduto LIKE '%Diferença%')
                THEN COALESCE(pt.ValorPago, pt.Total)
            END) * MAX(pt.QuantidadeParcelasVenda))
             - SUM(CASE
                WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL
                 AND (pt.NomeProduto LIKE '%Sócio%' OR pt.NomeProduto LIKE '%Diferença%')
                 AND pt.NomeProduto NOT LIKE '%Consumo%'
                 AND pt.NomeProduto NOT LIKE '%Pulseira%Troca%'
                THEN COALESCE(pt.ValorPago, pt.Total)
                ELSE 0
            END)
    END AS SaldoRestante,

    -- Parcelas Restantes
    CASE
        WHEN MAX(pt.QuantidadeParcelasVenda) = 1 THEN 0
        WHEN SUM(CASE
            WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL
             AND (pt.NomeProduto LIKE '%Sócio%' OR pt.NomeProduto LIKE '%Diferença%')
             AND pt.NomeProduto NOT LIKE '%Consumo%'
             AND pt.NomeProduto NOT LIKE '%Pulseira%Troca%'
            THEN 1 ELSE 0 END) = MAX(pt.QuantidadeParcelasVenda) THEN 0
        ELSE MAX(pt.QuantidadeParcelasVenda) - SUM(CASE
            WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL
             AND (pt.NomeProduto LIKE '%Sócio%' OR pt.NomeProduto LIKE '%Diferença%')
             AND pt.NomeProduto NOT LIKE '%Consumo%'
             AND pt.NomeProduto NOT LIKE '%Pulseira%Troca%'
            THEN 1 ELSE 0 END)
    END AS ParcelasRestantes,

    -- Forma de Pagamento
    MAX(CASE
        WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL
         AND (pt.NomeProduto LIKE '%Sócio%' OR pt.NomeProduto LIKE '%Diferença%')
        THEN pt.PaymentMode
    END) AS FormaPagamento,

    -- Tipo de Pagamento
    CASE
        WHEN MAX(pt.QuantidadeParcelasVenda) = 1 THEN 'À Vista'
        WHEN SUM(CASE
            WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL
             AND (pt.NomeProduto LIKE '%Sócio%' OR pt.NomeProduto LIKE '%Diferença%')
             AND pt.NomeProduto NOT LIKE '%Consumo%'
             AND pt.NomeProduto NOT LIKE '%Pulseira%Troca%'
            THEN 1 ELSE 0 END) = MAX(pt.QuantidadeParcelasVenda) THEN 'Cartão Parcelado'
        ELSE 'Recorrente'
    END AS TipoPagamento,

    -- Período
    CASE
        WHEN MIN(pt.DataVenda) >= @Periodo3Meses THEN '0-3 meses'
        WHEN MIN(pt.DataVenda) >= @Periodo6Meses THEN '3-6 meses'
        WHEN MIN(pt.DataVenda) >= @Periodo1Ano THEN '6-12 meses'
        ELSE 'Mais de 1 ano'
    END AS PeriodoTitulo,

    -- Dias desde venda
    DATEDIFF(DAY, MIN(pt.DataVenda), GETDATE()) AS DiasDesdeVenda,

    -- ✅ CORRIGIDO: Lista apenas parcelas válidas (para debug)
    STUFF((
        SELECT ',' + CAST(NumeroParcelaVenda AS VARCHAR(5))
        FROM [dbo].[PaidTitles]
        WHERE NumeroTitulo = pt.NumeroTitulo
          AND COALESCE(ValorPago, Total) IS NOT NULL
          AND (NomeProduto LIKE '%Sócio%' OR NomeProduto LIKE '%Diferença%')
          AND NomeProduto NOT LIKE '%Consumo%'
          AND NomeProduto NOT LIKE '%Pulseira%Troca%'
        FOR XML PATH(''), TYPE).value('.', 'NVARCHAR(MAX)'), 1, 1, '') AS ListaParcelasPagas,

    -- ✅ CORRIGIDO: Lista apenas valores de parcelas válidas (para debug)
    STUFF((
        SELECT ',' + CAST(COALESCE(ValorPago, Total) AS VARCHAR(20))
        FROM [dbo].[PaidTitles]
        WHERE NumeroTitulo = pt.NumeroTitulo
          AND COALESCE(ValorPago, Total) IS NOT NULL
          AND (NomeProduto LIKE '%Sócio%' OR NomeProduto LIKE '%Diferença%')
          AND NomeProduto NOT LIKE '%Consumo%'
          AND NomeProduto NOT LIKE '%Pulseira%Troca%'
        FOR XML PATH(''), TYPE).value('.', 'NVARCHAR(MAX)'), 1, 1, '') AS ListaValoresPagos

FROM [dbo].[PaidTitles] pt
WHERE pt.[DataCadastro] BETWEEN @DataInicio AND @DataFim
  -- FILTRO 1: Apenas prefixos SFA, SAF, SBF
  AND (
      pt.[NumeroTitulo] LIKE 'SFA-%'
      OR pt.[NumeroTitulo] LIKE 'SAF-%'
      OR pt.[NumeroTitulo] LIKE 'SBF-%'
  )
  -- FILTRO 2: Produto ORIGINAL deve conter "Sócio"
  AND (SELECT TOP 1 NomeProduto
       FROM [dbo].[PaidTitles]
       WHERE NumeroTitulo = pt.NumeroTitulo
       ORDER BY DataVenda ASC) LIKE '%Sócio%'
  -- FILTRO 3: CATEGORIA deve conter "Sócio"
  AND (SELECT TOP 1 Categoria
       FROM [dbo].[PaidTitles]
       WHERE NumeroTitulo = pt.NumeroTitulo
       ORDER BY DataVenda DESC) LIKE '%Sócio%'
  -- FILTRO 4: Status válidos
  AND (SELECT TOP 1 StatusTitulo
       FROM [dbo].[PaidTitles]
       WHERE NumeroTitulo = pt.NumeroTitulo
       ORDER BY DataVenda DESC) IN ('Ativo', 'Bloqueado', 'Cancelado', 'Vencido')

GROUP BY
    pt.NumeroTitulo

ORDER BY
    MIN(pt.DataVenda) DESC,
    pt.NumeroTitulo;

GO

/*
================================================================================
MUDANÇAS REALIZADAS:
================================================================================

1. QtdParcelasPagas - Conta apenas:
   ✅ Produtos com "Sócio" no nome
   ✅ Produtos com "Diferença" no nome
   ❌ NÃO conta "Consumo"
   ❌ NÃO conta "Pulseira Troca"

2. TotalPago - Soma apenas:
   ✅ Valores de parcelas do plano (Sócio)
   ✅ Valores de diferença de mensalidade
   ❌ NÃO soma consumos extras
   ❌ NÃO soma pulseiras

3. SaldoRestante - Calculado baseado no TotalPago correto

4. ParcelasRestantes - Calculado baseado nas parcelas pagas corretas

5. ListaParcelasPagas - Mostra apenas parcelas válidas (para debug)

6. ListaValoresPagos - Mostra apenas valores válidos (para debug)

================================================================================
EXEMPLO - Título SFA-10156:
================================================================================

ANTES:
- QtdParcelasPagas: 5 (1 plano + 4 consumos)
- TotalPago: R$ 750,52 (R$ 120,52 + R$ 630 de consumos)

DEPOIS:
- QtdParcelasPagas: 1 (apenas plano)
- TotalPago: R$ 120,52 (apenas plano)
- Os R$ 630 de consumos são ignorados ✅

================================================================================
*/
