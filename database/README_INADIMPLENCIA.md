# Análise de Inadimplência - Lógica Corrigida

## 🎯 Problema Identificado

### Lógica INCORRETA (anterior):
O sistema estava marcando clientes como **INADIMPLENTE** comparando o número de parcelas pagas com o **TOTAL** do plano:

```
Exemplo: Cliente SFA-8424
- Plano: 48 parcelas
- Parcelas pagas: 3
- Cálculo antigo: 3/48 = 6,25% < 50% → INADIMPLENTE ❌
```

**Problema**: Cliente que vendeu há 3 meses e pagou 3 parcelas está EM DIA, mas era marcado como inadimplente!

---

## ✅ Lógica CORRETA (implementada):

Agora o sistema compara parcelas pagas com parcelas que **DEVERIAM** estar pagas baseado no **TEMPO DECORRIDO** desde a venda:

```sql
DATEDIFF(MONTH, DataPrimeiraVenda, GETDATE()) + 1
```

### Exemplos Práticos:

| Situação | Meses desde venda | Parcelas pagas | Parcelas esperadas | Status |
|----------|-------------------|----------------|-------------------|--------|
| Cliente A | 3 meses | 3 | 3 | ✅ ADIMPLENTE |
| Cliente B | 5 meses | 3 | 5 | ❌ INADIMPLENTE - Menos de 50% |
| Cliente C | 12 meses | 1 | 12 | ❌ INADIMPLENTE - Apenas 1ª Parcela |
| Cliente D | 10 meses | 10 | 10 | ✅ ADIMPLENTE |
| Cliente E | 6 meses | 2 | 6 | ❌ INADIMPLENTE - Mais de 50% |

---

## 📊 Categorias de Inadimplência

### 1. **ADIMPLENTE**
- Pagou todas as parcelas esperadas até agora
- OU completou o plano inteiro
```sql
parcelas_pagas >= meses_desde_venda
OR parcelas_pagas >= total_parcelas
```

### 2. **INADIMPLENTE - Apenas 1ª Parcela**
- Pagou apenas a primeira parcela
- E já deveria ter pago mais (vendido há mais de 1 mês)
```sql
parcelas_pagas = 1 AND meses_desde_venda > 1
```

### 3. **INADIMPLENTE - Apenas 2 Parcelas**
- Pagou apenas 2 parcelas
- E já deveria ter pago mais (vendido há mais de 2 meses)
```sql
parcelas_pagas = 2 AND meses_desde_venda > 2
```

### 4. **INADIMPLENTE - Menos de 50%**
- Pagou menos parcelas que o esperado
- Mas pagou pelo menos 50% das parcelas esperadas
```sql
parcelas_pagas < meses_desde_venda
AND (parcelas_pagas / meses_desde_venda) >= 0.5
```

### 5. **INADIMPLENTE - Mais de 50%**
- Pagou menos de 50% das parcelas esperadas
```sql
parcelas_pagas < meses_desde_venda
AND (parcelas_pagas / meses_desde_venda) < 0.5
```

---

## 🚀 Como Usar a Query Corrigida

### Arquivo: `query_com_cabecalho_CORRIGIDA.sql`

### Passo a Passo:

1. **Abra o SQL Server Management Studio (SSMS)**

2. **Execute a query:**
   - Abra o arquivo `query_com_cabecalho_CORRIGIDA.sql`
   - Conecte ao banco `MultiClubes`
   - Clique em "Execute" (F5)

3. **Exporte os resultados:**
   - Clique com botão direito nos resultados
   - Selecione "Save Results As..."
   - Escolha formato **CSV**
   - Salve com:
     - Codificação: **UTF-8**
     - Delimitador: **ponto e vírgula (;)**

4. **Importe no sistema PHP:**
   - Acesse `/upload.php` no sistema
   - Faça upload do CSV exportado
   - O sistema agora calculará a inadimplência corretamente

---

## 🔧 Correções Técnicas Implementadas

### 1. Nomes de Colunas Corretos
Agora usa os nomes reais da tabela `MultiClubes.PaidTitles`:
- `QuantidadeParcelasVenda` (em vez de QuantidadeParcelas)
- `ResidentialPhone` (em vez de TelefoneTitular)
- `Bandeira` (em vez de BandeiraCartao)
- `PaymentType` (em vez de TipoPagamento)

### 2. Cálculo de Parcelas Pagas
```sql
SUM(CASE WHEN COALESCE(pt.ValorPago, pt.Total) IS NOT NULL THEN 1 ELSE 0 END)
```

### 3. Tratamento de Valores NULL
```sql
COALESCE(pt.ValorPago, pt.Total, 0)
```

### 4. ORDER BY com UNION
```sql
-- Usa número da coluna em vez de alias (evita erro)
ORDER BY 8 DESC  -- Coluna 8 = DataPrimeiraVenda
```

---

## 📋 Campos Exportados

A query exporta os seguintes campos:

| Campo | Descrição |
|-------|-----------|
| NumeroTitulo | Número identificador do título |
| NomeProdutoOriginal | Produto comprado originalmente |
| NomeProdutoAtual | Produto atual (após mudanças de vaga) |
| AlterouVagas | Se mudou de plano (Sim/Não) |
| Categoria | Categoria do produto |
| StatusTitulo | Status do título (Ativo/Bloqueado/Cancelado) |
| DataCadastro | Data de cadastro |
| DataPrimeiraVenda | Data da primeira venda |
| DataUltimaVenda | Data da última venda |
| NomeTitular | Nome do titular |
| DocumentoTitular | CPF do titular |
| TelefoneResidencial | Telefone residencial |
| OrigemVenda | Origem da venda |
| Promotor | Nome do promotor/consultor |
| Gerente | Nome do gerente |
| NumeroCartao | Número do cartão |
| Bandeira | Bandeira do cartão |
| TipoPagamentoCartao | Tipo de pagamento do cartão |
| QuantidadeParcelasVenda | Total de parcelas do plano |
| QtdParcelasPagas | Quantidade de parcelas pagas |
| ValorParcela | Valor de cada parcela |
| TotalPago | Total pago até agora |
| SaldoRestante | Saldo restante a pagar |
| ParcelasRestantes | Parcelas restantes |
| FormaPagamento | Forma de pagamento |
| TipoPagamento | Tipo (À Vista/Cartão Parcelado/Recorrente) |
| **StatusInadimplencia** | **Status CORRIGIDO baseado em tempo** |
| PeriodoTitulo | Período desde a venda (0-3 meses, etc) |
| DiasDesdeVenda | Dias desde a venda |

---

## 🎓 Entendendo a Lógica

### Cenário Real:
Um cliente comprou um plano de **48 meses** em **01/09/2024**.

**Hoje é 19/12/2024** (aproximadamente 3 meses depois).

#### Cliente ADIMPLENTE:
- Parcelas pagas: **3**
- Parcelas esperadas: **3** (3 meses se passaram)
- Status: ✅ **ADIMPLENTE** (pagou tudo que deveria até agora)

#### Cliente INADIMPLENTE:
- Parcelas pagas: **1**
- Parcelas esperadas: **3** (3 meses se passaram)
- Status: ❌ **INADIMPLENTE - Apenas 1ª Parcela**

---

## ⚠️ Importante

### Filtros Padrão da Query:
- **Produtos**: Apenas com `NomeProduto LIKE '%Sócio%'`
- **Período**: `DataCadastro BETWEEN '2024-11-01' AND GETDATE()`
- **Status**: Apenas títulos `Ativo` ou `Bloqueado`

### Para Modificar os Filtros:
Edite as linhas 155-156 e 167-168 da query para ajustar o período desejado.

---

## 📞 Suporte

Se encontrar problemas:
1. Verifique se está conectado ao banco `MultiClubes`
2. Confirme que a tabela `PaidTitles` existe e tem dados
3. Verifique os nomes das colunas com: `SELECT TOP 1 * FROM [dbo].[PaidTitles]`

---

**Data da Correção**: 19/12/2024
**Arquivo**: `query_com_cabecalho_CORRIGIDA.sql`
**Branch Git**: `claude/delinquency-rate-analysis-2H9Om`
