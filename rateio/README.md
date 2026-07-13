# Sistema de Rateio (Microsoft + Telefonia)

Aplicação em **PHP 8.0+ orientada a objetos** para gestão de rateios de
licenças Microsoft e de linhas Telefonia, com cadastro central de PEPs,
gestão de usuários (local e LDAP) e relatórios financeiros por PEP.

## Estrutura

```
config/        Configuração de banco e LDAP (config.php)
src/
  Core/        Config, Database (PDO), Session, Auth, Csv, RateioFinanceiro
  Models/      BaseModel + modelos (Usuario, Pep, Ms*, Telefonia*, RateioHistorico...)
  autoload.php Autoloader PSR-4 (namespace App\) + helpers
includes/      bootstrap.php, header.php (navbar por contexto), footer.php
assets/css/    Estilos
usuarios/      Gestão de usuários + configuração LDAP (admin)
peps/          Cadastro de PEP / Projeto (compartilhado)
microsoft/     contas, licencas, cobrancas, rateios (gerados), relatorios
telefonia/          contas, cobrancas, rateios (gerados), relatorios
login.php  logout.php  index.php (área inicial)
database.sql   Esquema do banco
```

## Requisitos

- PHP 8.0 ou superior
- MySQL 5.7+ / MariaDB 10.3+
- Extensões: `pdo_mysql` (obrigatória), `ldap` (opcional, só para login LDAP)

## Instalação

1. **Banco de dados** — importe o esquema:
   ```bash
   mysql -u root -p < database.sql
   ```

2. **Configuração** — edite `config/config.php`:
   - bloco `db` com host, banco, usuário e senha;
   - bloco `app.base_url` se a aplicação ficar em subpasta (ex.: `/rateio`);
   - bloco `ldap` (opcional) — também pode ser configurado pela tela
     **Usuários → Integração LDAP**.

3. **Servidor** — aponte o virtualhost para a pasta da aplicação e acesse
   `login.php`.

## Acesso padrão

- **Usuário:** `admin`
- **Senha:** `admin123`

Troque a senha após o primeiro acesso (a aplicação regrava em bcrypt).

## Perfis de acesso

| Perfil    | Permissões                                                        |
|-----------|-------------------------------------------------------------------|
| `admin`   | Acesso total: criar/editar/excluir em todas as telas + relatórios |
| `usuario` | Somente leitura das telas + geração e exportação de relatórios    |

## Integração LDAP / Active Directory

A autenticação tenta primeiro o usuário **local**; se não encontrar e o LDAP
estiver habilitado, autentica no diretório. O **grupo de segurança** é usado
como filtro:

- membros do *grupo de administradores* → perfil `admin`;
- membros do *grupo de usuários* → perfil `usuario`;
- quem não pertence a nenhum dos grupos **não consegue entrar**.

Usuários LDAP são provisionados automaticamente na tabela `usuarios`
(origem `ldap`) no primeiro login.

## Áreas

### Área Inicial
- Alternância entre **Rateio Microsoft** e **Rateio Telefonia** (com indicador
  do contexto atual na barra superior).
- **Usuários** (admin): usuários locais e configuração LDAP.
- **PEPs / Projetos**: cadastro compartilhado pelos dois rateios.

### Rateio Microsoft
- **Contas** (antiga tela de rateios): PEP e Projeto vêm do cadastro de PEPs;
  filtro em todas as colunas e exportação CSV (selecionadas ou todas).
- **Licenças** e **Cobranças**: filtro + exportação CSV.
- **Rateios Gerados**: histórico mensal dos rateios para consulta posterior.
- **Relatórios**: relatório financeiro por PEP.

### Rateio Telefonia
- **Contas**: nome do usuário, telefone, operadora, PEP, valor e Conta Telefonia.
  Inclui importação em massa e **atualização de valores por CSV** (por telefone).
- **Cobranças**: mês, ano e valor total (espelha o rateio Microsoft).
- **Rateios Gerados**: histórico mensal dos rateios para consulta posterior.
- **Relatórios**: relatório financeiro por PEP.

## Envio dos rateios por e-mail

A área inicial possui **Configuração SMTP** (admin) e **Contatos / Listas de
E-mail** (admin). Na tela **Rateios Gerados** de cada gerência há o botão
**E-mail**, que envia o **resumo por PEP** do rateio (no corpo do e-mail e em CSV anexo) para os
contatos da lista correspondente.

- **Configuração SMTP** suporta dois métodos:
  - *Servidor SMTP*: host, porta, segurança (STARTTLS/SSL/nenhuma), usuário e senha.
  - *OAuth (XOAUTH2)*: Microsoft 365, Google ou endpoint personalizado, via
    `client_id`, `client_secret`, `refresh_token` (e `tenant`/`scope` quando
    aplicável). O método OAuth exige a extensão **cURL** e acesso de saída ao
    provedor para obtenção do token.
  - Há um botão para **enviar e-mail de teste**.
- **Contatos**: cada contato pode pertencer à lista **Microsoft**, **Telefonia**
  ou a ambas; o envio usa a lista da gerência de origem do rateio.
- O envio em si está disponível para qualquer usuário autenticado; a
  configuração SMTP e os contatos são restritos a administradores.

> O cliente SMTP é próprio (sem dependências externas). Segredos (senha SMTP,
> client secret, refresh token) são gravados na tabela `config_smtp`; proteja o
> acesso ao banco e, se possível, use uma conta de envio dedicada.

## Exportação CSV

Todos os CSVs são **separados por vírgula** e incluem BOM UTF-8 (acentos
corretos no Excel). Nas listagens é possível exportar os itens
**selecionados** (checkbox) ou **todos** os itens do filtro atual.

## Importação CSV (em massa)

As telas **Contas** de ambos os rateios (Microsoft e Telefonia) e a tela de
cadastro de **PEPs / Projetos** permitem importação em massa via arquivo CSV
(botão **Importar CSV**, disponível para administradores). A leitura remove
BOM UTF-8 e detecta automaticamente o separador de colunas (vírgula ou
ponto-e-vírgula); a linha de cabeçalho é descartada quando presente. Cada
tela oferece o download de um **modelo**.

- **PEPs / Projetos** — colunas: `PEP, Projeto`.
  Códigos de PEP já cadastrados são ignorados (relatados no resumo), de modo
  que o mesmo arquivo pode ser reimportado sem duplicar registros.
- **Microsoft** — colunas: `Nome, Email, PEP, Licencas`.
  O PEP deve já existir em PEPs/Projetos; as licenças são informadas por
  código ou descrição e, quando houver mais de uma, separadas por
  **ponto-e-vírgula** (ex.: `E3;Power BI`).
- **Telefonia** — colunas: `Nome do Usuario, Telefone, Operadora, PEP, Valor, Conta Telefonia`.
  O PEP deve já existir no cadastro de PEPs / Projetos.

A tela **PEPs / Projetos** (área inicial) também aceita importação em massa
via CSV — colunas `PEP, Projeto`. PEPs já cadastrados são ignorados (sem
duplicar) e reportados no resumo.

Ao final, é exibido um resumo com a quantidade importada e a lista de linhas
que apresentaram problemas (PEP/licença inexistente, campos
obrigatórios em branco etc.), sem interromper a importação das demais.

## Importação CSV (em massa)

As telas de **Contas** dos dois rateios têm o botão **Importar CSV** (admin),
para cadastro em massa. Cada tela oferece o download de um **modelo** e valida
linha a linha, exibindo um relatório com os registros importados e as linhas
recusadas (com o motivo). O separador de colunas pode ser vírgula ou
ponto e vírgula (detectado automaticamente); a primeira linha pode ser o
cabeçalho.

- **Microsoft** — colunas: `Nome, Email, PEP, Licencas`. O `PEP` deve já
  existir em PEPs/Projetos; as licenças são informadas por código ou descrição,
  e várias licenças na mesma conta são separadas por ponto e vírgula
  (ex.: `E3;Power BI`).
- **Telefonia** — colunas: `Nome do Usuario, Telefone, Operadora, PEP, Valor, Conta Telefonia`.
  O `PEP` (pelo código) deve já existir no cadastro de PEPs / Projetos.

## Observações técnicas

- Consultas usam **prepared statements** (PDO) para evitar SQL injection.
- Saída escapada com `htmlspecialchars` (helper `e()`).
- Senhas novas usam `password_hash` (bcrypt); o login aceita o MD5 legado e
  faz upgrade quando o usuário é editado.
