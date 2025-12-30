/*
================================================================================
QUERY PARA EXPORTAÇÃO - VERSÃO FINAL FILTRADA
================================================================================
✅ FILTRA "Consumo crédito" e "Pulseira Troca" no cálculo de parcelas
✅ Conta APENAS parcelas do plano ("Sócio" ou "Diferença de mensalidade")
✅ Concatena TODOS os cartões usados com pipe (|)

IMPORTANTE: Esta é a versão CORRETA para uso!
*/

USE [MultiClubes]
GO

-- Parâmetros
DECLARE @DataInicio DATETIME = '2024-11-01T00:00:00';
DECLARE @DataFim DATETIME = GETDATE();
DECLARE @Periodo3Meses DATETIME = DATEADD(MONTH, -3, GETDATE());
DECLARE @Periodo6Meses DATETIME = DATEADD(MONTH, -6, GETDATE());
DECLARE @Periodo1Ano DATETIME = DATEADD(YEAR, -1, GETDATE());

-- SELECT principal com filtros corretos
SELECT
    -- Identificação do Título
    pt.NumeroTitulo,

    -- Produto Original (primeira venda com "Sócio")
    (SELECT TOP 1 NomeProduto
     FROM [dbo].[PaidTitles]
     WHERE NumeroTitulo = pt.NumeroTitulo
       AND NomeProduto LIKE '%Sócio%'
     ORDER BY DataVenda ASC) AS NomeProdutoOriginal,

    -- Produto Atual = CATEGORIA (ignora Pulseira Troca, Mudança de categoria, etc)
    (SELECT TOP 1 Categoria
     FROM [dbo].[PaidTitles]
     WHERE NumeroTitulo = pt.NumeroTitulo
     ORDER BY DataVenda DESC) AS NomeProdutoAtual,

    -- Alterou Vagas? (verifica se pagou "Diferença" OU se produto original é diferente da categoria atual)
    CASE WHEN
        -- Verifica se tem "Diferença de Mensalidade" (sinal claro de mudança de vaga)
        EXISTS (SELECT 1 FROM [dbo].[PaidTitles]
                WHERE NumeroTitulo = pt.NumeroTitulo
                AND (NomeProduto LIKE '%Diferença%' OR NomeProduto LIKE '%Mudança%' OR NomeProduto LIKE '%Alteração%'))
        -- OU se o produto original é diferente da categoria atual
        OR RTRIM(LTRIM((SELECT TOP 1 NomeProduto FROM [dbo].[PaidTitles] WHERE NumeroTitulo = pt.NumeroTitulo AND NomeProduto LIKE '%Sócio%' ORDER BY DataVenda ASC))) <>
           RTRIM(LTRIM((SELECT TOP 1 Categoria FROM [dbo].[PaidTitles] WHERE NumeroTitulo = pt.NumeroTitulo ORDER BY DataVenda DESC)))
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

    -- ====================================================================
    -- ✅ PARCELAS - FILTRADAS (SÓ CONTA PARCELAS DO PLANO)
    -- ====================================================================
    MAX(pt.QuantidadeParcelasVenda) AS QuantidadeParcelasVenda,

    -- Quantidade de parcelas pagas - FILTRANDO consumos, pulseiras E diferenças
    -- Diferença NÃO é parcela, é ajuste de valor quando muda de vaga
    SUM(CASE
        WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL
         AND pt.NomeProduto LIKE '%Sócio%'
         AND pt.NomeProduto NOT LIKE '%Consumo%'
         AND pt.NomeProduto NOT LIKE '%Pulseira%Troca%'
        THEN 1
        ELSE 0
    END) AS QtdParcelasPagas,

    -- ====================================================================
    -- ✅ VALORES - FILTRADOS (SÓ SOMA PARCELAS DO PLANO)
    -- ====================================================================

    -- Valor da parcela (pega o valor da parcela Sócio, NÃO da Diferença)
    MAX(CASE
        WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL
         AND pt.NomeProduto LIKE '%Sócio%'
         AND pt.NomeProduto NOT LIKE '%Consumo%'
         AND pt.NomeProduto NOT LIKE '%Pulseira%Troca%'
        THEN COALESCE(pt.ValorPago, pt.Total)
    END) AS ValorParcela,

    -- Total pago - FILTRANDO consumos e pulseiras
    SUM(CASE
        WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL
         AND (pt.NomeProduto LIKE '%Sócio%' OR pt.NomeProduto LIKE '%Diferença%')
         AND pt.NomeProduto NOT LIKE '%Consumo%'
         AND pt.NomeProduto NOT LIKE '%Pulseira%Troca%'
        THEN COALESCE(pt.ValorPago, pt.Total)
        ELSE 0
    END) AS TotalPago,

    -- Saldo Restante (calculado com valores filtrados - conta Diferença no valor mas não na quantidade)
    CASE
        WHEN MAX(pt.QuantidadeParcelasVenda) = 1 THEN 0
        WHEN SUM(CASE
            WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL
             AND pt.NomeProduto LIKE '%Sócio%'
             AND pt.NomeProduto NOT LIKE '%Consumo%'
             AND pt.NomeProduto NOT LIKE '%Pulseira%Troca%'
            THEN 1 ELSE 0 END) = MAX(pt.QuantidadeParcelasVenda) THEN 0
        ELSE (MAX(CASE
                WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL
                 AND pt.NomeProduto LIKE '%Sócio%'
                 AND pt.NomeProduto NOT LIKE '%Consumo%'
                 AND pt.NomeProduto NOT LIKE '%Pulseira%Troca%'
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

    -- Parcelas Restantes (calculado com parcelas filtradas - NÃO conta Diferença)
    CASE
        WHEN MAX(pt.QuantidadeParcelasVenda) = 1 THEN 0
        WHEN SUM(CASE
            WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL
             AND pt.NomeProduto LIKE '%Sócio%'
             AND pt.NomeProduto NOT LIKE '%Consumo%'
             AND pt.NomeProduto NOT LIKE '%Pulseira%Troca%'
            THEN 1 ELSE 0 END) = MAX(pt.QuantidadeParcelasVenda) THEN 0
        ELSE MAX(pt.QuantidadeParcelasVenda) - SUM(CASE
            WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL
             AND pt.NomeProduto LIKE '%Sócio%'
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
             AND pt.NomeProduto LIKE '%Sócio%'
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

    -- ====================================================================
    -- ✅ LISTAS - FILTRADAS (SÓ PARCELAS DO PLANO)
    -- ====================================================================

    -- Lista de parcelas pagas (APENAS do plano)
    STRING_AGG(
        CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL
             AND (pt.NomeProduto LIKE '%Sócio%' OR pt.NomeProduto LIKE '%Diferença%')
             AND pt.NomeProduto NOT LIKE '%Consumo%'
             AND pt.NomeProduto NOT LIKE '%Pulseira%Troca%'
        THEN pt.NomeProduto END,
        ' | '
    ) AS ListaParcelasPagas,

    -- Lista de valores pagos (APENAS do plano)
    STRING_AGG(
        CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL
             AND (pt.NomeProduto LIKE '%Sócio%' OR pt.NomeProduto LIKE '%Diferença%')
             AND pt.NomeProduto NOT LIKE '%Consumo%'
             AND pt.NomeProduto NOT LIKE '%Pulseira%Troca%'
        THEN CAST(COALESCE(pt.ValorPago, pt.Total) AS VARCHAR(20)) END,
        ' | '
    ) AS ListaValoresPagos

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
RESULTADO ESPERADO PARA SFA-10156:
================================================================================

QtdParcelasPagas: 1 (apenas a parcela do plano)
TotalPago: 120.52 (apenas o valor da parcela)
ListaParcelasPagas: "Sócio Safira Título - 3 Vagas"
ListaValoresPagos: "120.52"

❌ NÃO INCLUI:
- 4x "Consumo crédito" (200.00, 30.00, 200.00, 200.00)
- 3x "Pulseira Troca"

✅ Valor correto: R$ 120,52 (ao invés de R$ 750,52)

================================================================================
RESULTADO ESPERADO PARA SFA-10152 (COM DIFERENÇA DE MENSALIDADE):
================================================================================

QtdParcelasPagas: 1 (NÃO conta a Diferença como parcela adicional!)
TotalPago: 265.06 (181.06 + 84.00 - soma AMBOS os valores pagos)
ListaParcelasPagas: "Sócio Safira Título - 6 Vagas | Diferença Mensalidade"
ListaValoresPagos: "181.06 | 84.00"
AlterouVagas: Sim
ValorParcela: 181.06 (valor da parcela Sócio, não da Diferença)

✅ IMPORTANTE:
- Diferença de Mensalidade NÃO é contada como parcela separada (seria 2, mas fica 1)
- Diferença de Mensalidade É somada no TotalPago (ajuste de valor quando muda vagas)
- Diferença de Mensalidade APARECE nas listas para transparência
*/
