# 📊 Fluxo Completo de Inadimplência - Sistema Aquabeat

## 🎯 Mudança Principal

**ANTES:**
- Query SQL calculava status de inadimplência
- Dados eram importados com status já definido
- Lógica complexa e difícil de corrigir no SQL Server

**AGORA:**
- Query SQL traz apenas dados brutos de venda
- PHP calcula status de inadimplência automaticamente
- Lógica centralizada e fácil de manter

---

## 🔄 Fluxo Completo

```
┌─────────────────────────┐
│   SQL Server            │
│   (MultiClubes)         │
│                         │
│  Execute:               │
│  query_dados_venda.sql  │
└───────────┬─────────────┘
            │
            │ Exporta CSV com dados brutos
            ▼
┌─────────────────────────┐
│   CSV File              │
│                         │
│  Campos:                │
│  - NumeroTitulo         │
│  - DataPrimeiraVenda    │
│  - QtdParcelasPagas     │
│  - QuantidadeParcelas   │
│  - ... (sem status)     │
└───────────┬─────────────┘
            │
            │ Upload
            ▼
┌─────────────────────────┐
│   upload.php            │
│                         │
│  1. Recebe CSV          │
│  2. Analisa estrutura   │
│  3. Redireciona para    │
│     mapeamento          │
└───────────┬─────────────┘
            │
            ▼
┌─────────────────────────┐
│   upload_mapping.php    │
│                         │
│  1. Mostra colunas CSV  │
│  2. Sugere mapeamento   │
│  3. Usuário confirma    │
└───────────┬─────────────┘
            │
            │ Confirma importação
            ▼
┌─────────────────────────┐
│   CSVImporter.php       │
│                         │
│  1. Lê CSV linha a      │
│     linha               │
│  2. Mapeia colunas      │
│  3. Insere em titulos   │
│  4. Cria agregações     │
│  5. CALCULA STATUS!     │
│     (InadimplenciaHelper)│
└───────────┬─────────────┘
            │
            ▼
┌─────────────────────────┐
│   Banco de Dados        │
│                         │
│  Tabelas:               │
│  - titulos              │
│    (com status calc.)   │
│  - analise_consultores  │
│  - analise_cartoes      │
└───────────┬─────────────┘
            │
            │ Consulta dados
            ▼
┌─────────────────────────┐
│   relatorios.php        │
│   Outras páginas        │
│                         │
│  Exibe dados com        │
│  status correto         │
└─────────────────────────┘
```

---

## 📁 Arquivos Principais

### 1. SQL Server
**Arquivo:** `database/query_dados_venda.sql`

```sql
-- EXPORTA APENAS DADOS BRUTOS
-- NÃO calcula status de inadimplência

SELECT
    NumeroTitulo,
    DataPrimeiraVenda,      -- IMPORTANTE!
    QtdParcelasPagas,        -- IMPORTANTE!
    QuantidadeParcelasVenda, -- IMPORTANTE!
    NomeTitular,
    ValorParcela,
    ...
FROM PaidTitles
```

**Campos Essenciais para Cálculo:**
- `DataPrimeiraVenda` → Para calcular meses decorridos
- `QtdParcelasPagas` → Quantas parcelas foram pagas
- `QuantidadeParcelasVenda` → Total de parcelas do plano

---

### 2. PHP - Lógica de Cálculo
**Arquivo:** `includes/InadimplenciaHelper.php`

**Classe:** `InadimplenciaHelper`

#### Métodos Principais:

##### `calcularStatus($titulo)`
Calcula o status de inadimplência de um título.

**Lógica:**
```php
// 1. Calcular meses desde a venda
$mesesDesdeVenda = calcularMesesDesdeVenda(DataPrimeiraVenda);

// 2. Parcelas esperadas = meses desde venda
$parcelasEsperadas = $mesesDesdeVenda;

// 3. Comparar
if (parcelasPagas >= totalParcelas) {
    return 'ADIMPLENTE'; // Quitado
}

if (parcelasPagas >= parcelasEsperadas) {
    return 'ADIMPLENTE'; // Em dia
}

// 4. Inadimplente - classificar por gravidade
if (parcelasPagas == 1 && mesesDesdeVenda > 1) {
    return 'INADIMPLENTE - Apenas 1ª Parcela';
}

if (parcelasPagas == 2 && mesesDesdeVenda > 2) {
    return 'INADIMPLENTE - Apenas 2 Parcelas';
}

$percentualPago = parcelasPagas / parcelasEsperadas;

if ($percentualPago >= 0.5) {
    return 'INADIMPLENTE - Menos de 50%';
} else {
    return 'INADIMPLENTE - Mais de 50%';
}
```

**Exemplos:**

| Data Venda | Hoje | Meses | Parcelas Pagas | Parcelas Esperadas | Status |
|------------|------|-------|----------------|-------------------|--------|
| 01/09/2024 | 19/12/2024 | 4 | 4 | 4 | ✅ ADIMPLENTE |
| 01/09/2024 | 19/12/2024 | 4 | 3 | 4 | ❌ INADIMPLENTE - Menos de 50% |
| 01/09/2024 | 19/12/2024 | 4 | 1 | 4 | ❌ INADIMPLENTE - Apenas 1ª Parcela |
| 01/09/2024 | 19/12/2024 | 4 | 2 | 4 | ❌ INADIMPLENTE - Apenas 2 Parcelas |
| 01/01/2024 | 19/12/2024 | 12 | 5 | 12 | ❌ INADIMPLENTE - Mais de 50% |

##### `recalcularStatusImportacao($importacaoId)`
Recalcula status de todos os títulos de uma importação.

##### `recalcularTodosStatus()`
Recalcula status de TODOS os títulos do sistema.

---

### 3. PHP - Importação
**Arquivo:** `includes/CSVImporter.php`

**Modificação Importante:**
```php
// Após inserir todos os dados
$this->processAggregations();

// NOVO: Recalcular status de inadimplência
Logger::info("Recalculando status de inadimplência");
$recalculo = InadimplenciaHelper::recalcularStatusImportacao($importacaoId);
Logger::info("Status recalculados", $recalculo);

$this->db->commit();
```

---

### 4. Ferramenta Administrativa
**Arquivo:** `public/admin/recalcular_inadimplencia.php`

**Acesso:** Menu → Admin → Recalcular Inadimplência

**Funcionalidades:**
1. Ver estatísticas atuais de inadimplência
2. Recalcular todos os títulos do sistema
3. Recalcular apenas uma importação específica
4. Ver resultado detalhado (total, atualizados, erros)

**Quando usar:**
- Após corrigir a lógica de cálculo
- Para atualizar dados antigos
- Para testes e validação

---

## 🗂️ Estrutura do Banco de Dados

### Tabela: `titulos`

**Campos Relacionados à Inadimplência:**

```sql
-- Dados necessários para cálculo
data_primeira_venda DATETIME,        -- Data da primeira venda
qtd_parcelas_pagas INT,              -- Parcelas pagas
quantidade_parcelas_venda INT,       -- Total de parcelas

-- Status calculado
status_inadimplencia VARCHAR(100),   -- CALCULADO pelo PHP
```

**Processo:**
1. CSV importa os 3 primeiros campos
2. PHP calcula e grava `status_inadimplencia`
3. Agregações usam `status_inadimplencia` para estatísticas

---

## 🔧 Como Usar

### Passo 1: Exportar Dados do SQL Server

1. Abra SQL Server Management Studio (SSMS)
2. Conecte ao banco `MultiClubes`
3. Abra o arquivo `database/query_dados_venda.sql`
4. Execute a query (F5)
5. Clique com botão direito nos resultados
6. "Save Results As..." → CSV
7. Salve com UTF-8, delimitador `;`

### Passo 2: Importar no Sistema PHP

1. Acesse `http://seu-servidor/upload.php`
2. Faça upload do CSV exportado
3. Revise o mapeamento automático de colunas
4. Confirme a importação
5. Aguarde o processamento

**O que acontece:**
- CSV é lido linha a linha
- Dados são inseridos na tabela `titulos`
- Status de inadimplência é **calculado automaticamente**
- Agregações são criadas
- Importação concluída

### Passo 3: Visualizar Dados

1. Acesse `http://seu-servidor/relatorios.php`
2. Use os filtros para buscar títulos
3. Status de inadimplência exibido corretamente

### Passo 4 (Opcional): Recalcular Status

Se precisar recalcular por algum motivo:

1. Acesse `http://seu-servidor/admin/recalcular_inadimplencia.php`
2. Escolha o tipo de recálculo:
   - Todos os títulos
   - Apenas uma importação
3. Clique em "Recalcular Agora"
4. Aguarde o processamento
5. Veja o resultado (total, atualizados, erros)

---

## 🧪 Testando

### Teste 1: Importação Completa

```bash
# 1. Executar query SQL
# 2. Exportar CSV
# 3. Upload no sistema
# 4. Verificar logs:

tail -f data/logs/app.log

# Deve mostrar:
# [INFO] Import started
# [INFO] Recalculando status de inadimplência
# [INFO] Status recalculados: {total: 1000, atualizados: 1000, erros: 0}
# [INFO] Import completed
```

### Teste 2: Verificar Cálculo

```sql
-- Query de teste no banco PHP
SELECT
    numero_titulo,
    data_primeira_venda,
    qtd_parcelas_pagas,
    quantidade_parcelas_venda,
    status_inadimplencia,
    DATEDIFF(NOW(), data_primeira_venda) / 30 as meses_decorridos
FROM titulos
WHERE numero_titulo = 'SFA-8424'
LIMIT 1;
```

**Exemplo de resultado esperado:**
```
numero_titulo: SFA-8424
data_primeira_venda: 2024-09-01
qtd_parcelas_pagas: 3
quantidade_parcelas_venda: 48
status_inadimplencia: ADIMPLENTE  (3 pagas em 3 meses)
meses_decorridos: 3
```

### Teste 3: Recálculo Manual

1. Acessar `/admin/recalcular_inadimplencia.php`
2. Escolher "Recalcular TODOS"
3. Confirmar
4. Verificar resultado:
   - Total: deve ser igual ao total de títulos
   - Atualizados: depende de quantos mudaram
   - Erros: deve ser 0

---

## 📝 Logs

**Arquivo:** `data/logs/app.log`

**Mensagens importantes:**

```
[INFO] Import started
[INFO] Recalculando status de inadimplência
[INFO] Status de inadimplência recalculados: {total: 1000, atualizados: 985, erros: 0}
[INFO] Import completed
[INFO] recalcular_inadimplencia - Recalculou status de inadimplência
```

---

## ⚠️ Troubleshooting

### Problema: Status aparecem como NULL

**Causa:** Dados necessários (data_primeira_venda, qtd_parcelas_pagas) não foram importados

**Solução:**
1. Verificar mapeamento de colunas em `upload_mapping.php`
2. Garantir que CSV tem os campos necessários
3. Reimportar com mapeamento correto

### Problema: Todos aparecem como "SEM DADOS"

**Causa:** Campos vazios ou NULL no banco

**Solução:**
```sql
-- Verificar dados
SELECT
    numero_titulo,
    data_primeira_venda,
    qtd_parcelas_pagas,
    quantidade_parcelas_venda
FROM titulos
WHERE status_inadimplencia = 'SEM DADOS'
LIMIT 10;

-- Se campos estão NULL, reimportar CSV
```

### Problema: Cálculo parece incorreto

**Solução:**
1. Verificar lógica em `InadimplenciaHelper::calcularStatus()`
2. Testar manualmente:

```php
// Em um script de teste
$titulo = [
    'data_primeira_venda' => '2024-09-01',
    'qtd_parcelas_pagas' => 3,
    'quantidade_parcelas_venda' => 48
];

$status = InadimplenciaHelper::calcularStatus($titulo);
echo "Status: $status\n";

// Deve retornar: ADIMPLENTE (se hoje é dez/2024)
```

---

## 🎉 Vantagens da Nova Abordagem

### 1. **Manutenção Simplificada**
- Lógica centralizada em um único lugar (PHP)
- Fácil de entender e modificar
- Não precisa mexer em queries SQL complexas

### 2. **Correção Rápida**
- Bug encontrado? Corrige no PHP e roda recálculo
- Não precisa reexportar/reimportar CSV
- Atualização em massa com 1 clique

### 3. **Histórico e Auditoria**
- Todos os recálculos ficam registrados em logs
- Possível ver quem e quando recalculou
- Rastreabilidade completa

### 4. **Flexibilidade**
- Adicionar novas categorias de inadimplência é simples
- Criar regras especiais por produto
- Ajustar lógica sem mexer no banco SQL Server

### 5. **Performance**
- Query SQL mais rápida (não faz cálculos complexos)
- Cálculo em batch no PHP é eficiente
- Possível adicionar cache se necessário

---

## 🔮 Próximos Passos

### Possíveis Melhorias:

1. **Cache de Status**
   - Evitar recalcular o mesmo título múltiplas vezes
   - Atualizar cache quando dados mudarem

2. **Cálculo em Background**
   - Para importações grandes, processar via fila
   - Não bloquear interface do usuário

3. **Regras Personalizadas**
   - Permitir diferentes regras por categoria
   - Inadimplência baseada em valor, não só parcelas

4. **Dashboard de Inadimplência**
   - Gráficos de evolução temporal
   - Comparação entre períodos
   - Alertas automáticos

5. **API REST**
   - Endpoint para consultar status
   - Integração com outros sistemas
   - Webhook para notificações

---

## 📚 Referências

- **Arquivo SQL:** `database/query_dados_venda.sql`
- **Helper PHP:** `includes/InadimplenciaHelper.php`
- **Importador:** `includes/CSVImporter.php`
- **Admin Tool:** `public/admin/recalcular_inadimplencia.php`
- **Schema DB:** `database/schema.sql`

---

**Última atualização:** 22/12/2024
**Versão:** 2.0.0
**Autor:** Claude Code Assistant
