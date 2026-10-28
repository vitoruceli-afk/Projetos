# 01 — Especificação de Requisitos e Funcionalidades

## 1. Visão geral

O **Sistema de Controle** é uma aplicação web interna da Cepemar para:

1. **Ratear custos** de licenças **Microsoft** e de linhas de **Telefonia**
   entre os projetos (PEPs) da empresa, de forma proporcional ao consumo de
   cada usuário;
2. **Inventariar dispositivos** de TI (computadores, monitores, celulares,
   impressoras, periféricos etc.), com cadastro manual e **importação
   automática do GLPI**;
3. Gerar **relatórios financeiros por PEP** e **enviá-los por e-mail** às
   listas de contato de cada gerência.

Público-alvo: equipe de TI/infraestrutura e áreas administrativas/financeiras.

## 2. Perfis de acesso (regra de autorização)

| Perfil | Permissões |
|--------|------------|
| `admin` | Acesso total: criar, editar, excluir em todas as telas, importar CSV, configurar LDAP/SMTP/GLPI, gerir usuários e relatórios |
| `usuario` | **Somente leitura** das telas + geração e exportação de relatórios (sem CRUD, sem configurações) |

- A autenticação tenta primeiro o **usuário local**; se não existir e o LDAP
  estiver habilitado, autentica no **Active Directory**.
- No login via AD, o **grupo de segurança define o perfil**:
  - membro de `Controle_ADM` → `admin`;
  - membro de `Controle_USR` → `usuario`;
  - quem não pertence a nenhum dos dois **não entra**.
- Usuários LDAP são provisionados automaticamente na tabela `usuarios`
  (origem `ldap`) no primeiro login.

## 3. Módulos e funcionalidades

### 3.1 Cadastros compartilhados

- **PEPs / Projetos** — cadastro central usado pelos dois rateios e pelos
  dispositivos. Campos: PEP (código único), projeto, centro de custo, período
  (meses), datas de início/término, responsável (nome e CPF). Suporta
  importação em massa por CSV (colunas `PEP, Projeto`; duplicados são
  ignorados).
- **Usuários** (admin) — usuários locais e **configuração LDAP**.
- **Contatos / Listas de e-mail** (admin) — cada contato pode pertencer à
  lista **Microsoft**, **Telefonia** ou ambas.
- **Configuração SMTP** (admin) — servidor SMTP ou OAuth (XOAUTH2) para envio
  dos rateios.

### 3.2 Rateio Microsoft

- **Contas**: nome, e-mail e PEP do usuário, com as **licenças** vinculadas
  (relação N:N). Filtro por coluna e exportação CSV.
- **Licenças**: código, descrição, valor e modo de cobrança.
- **Cobranças**: mês, ano e valor total (o "boleto" do período).
- **Rateios Gerados**: histórico mensal dos rateios calculados, para consulta
  posterior e reenvio.
- **Relatórios**: relatório financeiro proporcional por PEP.
- Importação CSV de contas: colunas `Nome, Email, PEP, Licencas` (licenças
  separadas por `;`).

### 3.3 Rateio Telefonia

- **Contas**: nome do usuário, telefone, operadora, PEP, valor e **Conta
  Telefonia**. Inclui importação em massa e **atualização de valores por CSV**
  (casada por telefone).
- **Cobranças**: mês, ano, valor total e Conta Telefonia.
- **Rateios Gerados** e **Relatórios**: espelham o módulo Microsoft.
- Importação CSV de contas: colunas
  `Nome do Usuario, Telefone, Operadora, PEP, Valor, Conta Telefonia`.

### 3.4 Controle de Dispositivos

- Cadastro manual de dispositivos de 11 tipos pré-definidos (computador,
  monitor, celular, tablet, impressora, servidor, roteador/switch, webcam,
  fone, periféricos, outros).
- Campos: tipo, PEP, nome, número de série, IMEI, modelo, fabricante, data de
  aquisição, status (`ativo`/`inativo`/`descartado`/`manutenção`), localização,
  responsável, valor de aquisição, observações.
- **Importação/sincronização do GLPI**: importa Computer, Monitor, Printer,
  NetworkEquipment, Peripheral e Phone, normalizando modelo/fabricante/local;
  para computadores traz também specs de hardware e SO. Cada sincronização é
  registrada em `sincronizacoes_glpi`.
- **Vínculo de PEP** aos dispositivos e **relatórios** por tipo/PEP/status.

## 4. Regra de negócio central — cálculo do rateio

Implementada em [`src/Core/RateioFinanceiro.php`](../src/Core/RateioFinanceiro.php),
reutilizada por Microsoft e Telefonia:

```
U          = valor das contas/licenças do usuário
totalContas = soma de U de todos os usuários
diferenca  = valorBoleto (cobrança do período) − totalContas
percentual = (U / totalContas) × diferenca        (0 se totalContas = 0)
X          = U + percentual                         (valor final do usuário)
```

- A **diferença** entre o valor cobrado no período (boleto) e a soma das contas
  é **redistribuída proporcionalmente** ao peso de cada usuário.
- Os valores finais `X` são somados por PEP, produzindo o rateio por projeto.
- Cada rateio gerado é persistido em `rateios_historico` (com `dados_json`
  completo) para auditoria e reenvio.

## 5. Funcionalidades transversais

- **Exportação CSV**: separador vírgula, com BOM UTF-8 (acentos corretos no
  Excel); exporta itens selecionados ou todos do filtro atual.
- **Importação CSV**: remove BOM, autodetecta separador (`,` ou `;`), descarta
  cabeçalho, valida linha a linha e exibe resumo com importados e recusados.
- **Envio por e-mail**: botão **E-mail** na tela de Rateios Gerados envia o
  resumo por PEP (corpo + CSV anexo) para a lista de contatos da gerência.
- **Segurança de dados**: prepared statements (PDO) e saída escapada com
  `htmlspecialchars` (`e()`).

## 6. Requisitos de ambiente

- PHP 8.0+ (produção: 8.2). Extensões: `pdo_mysql` (obrigatória), `ldap`
  (login AD), `curl` + `openssl` (GLPI e OAuth), `mbstring`.
- MySQL 5.7+ / MariaDB 10.3+.
- Servidor web com virtualhost apontando para a pasta da aplicação.
