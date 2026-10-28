# 02 — Arquitetura do Sistema e Estrutura do Projeto

## 1. Estilo arquitetural

Aplicação **PHP 8 orientada a objetos**, server-side rendered (gera HTML no
servidor), sem framework externo. Não é SPA e **não expõe API REST própria** —
cada funcionalidade é uma página `.php` que processa o request e devolve HTML.

Organização em camadas, próxima de um **MVC leve**:

- **Core** (`src/Core`) — infraestrutura: configuração, conexão, sessão,
  autenticação, serviços de rateio, CSV, e-mail e cliente GLPI.
- **Models** (`src/Models`) — acesso a dados por tabela, estendendo um
  `BaseModel` (papel de "Model").
- **Views/Controllers** — as páginas `.php` em `microsoft/`, `telefonia/`,
  `peps/`, `dispositivos/`, `usuarios/`, `contatos/`, `smtp/`. Cada arquivo
  concentra o controller (trata POST/GET) e a view (HTML), apoiado em
  `includes/header.php` e `includes/footer.php`.

Padrões aplicados: **Front-controller por página** + **bootstrap** comum,
**Autoload PSR-4**, **Singleton** de conexão (PDO), **Active-Record-like**
models, **Service** (RateioFinanceiro, RateioEmail), **Gateway/Client** (GLPI,
Mailer).

## 2. Stack

| Camada | Tecnologia |
|--------|------------|
| Linguagem | PHP 8.2 (`declare(strict_types=1)`) |
| Banco | MySQL 5.7 via **PDO** (prepared statements) |
| Front-end | HTML server-side + **Bootstrap 5.3** e **Bootstrap Icons** (via CDN); CSS próprio em `assets/css` |
| Autenticação | Sessão PHP; LDAP via extensão `ldap` |
| Integrações | GLPI REST API (cURL), SMTP próprio/XOAUTH2 (cURL+OpenSSL) |

## 3. Estrutura de diretórios

```
controle/
├── index.php              Área inicial (seleção de rateio + cadastros)
├── login.php / logout.php Autenticação
├── config/config.php      Configuração (db, app, ldap)
├── database.sql           Esquema completo do banco
├── migrations/            Migrações incrementais (001..004)
├── src/
│   ├── autoload.php       Autoloader PSR-4 (App\) + helpers e()/money()/url()...
│   ├── Core/
│   │   ├── Config.php           Lê config.php (acesso por chave "a.b")
│   │   ├── Database.php         Conexão PDO (singleton)
│   │   ├── Session.php          Wrapper de sessão + flash messages
│   │   ├── Auth.php             Login local/LDAP, perfis, guardas
│   │   ├── Csv.php              Geração de CSV (BOM UTF-8)
│   │   ├── CsvReader.php        Leitura/parse de CSV de importação
│   │   ├── RateioFinanceiro.php Cálculo do rateio proporcional
│   │   ├── RateioEmail.php      Montagem do e-mail de rateio
│   │   ├── Mailer.php           Cliente SMTP próprio (SMTP e XOAUTH2)
│   │   └── GLPIClient.php       Cliente da API REST do GLPI
│   └── Models/
│       ├── BaseModel.php        CRUD genérico sobre PDO
│       ├── Usuario.php, Pep.php, Contato.php
│       ├── ConfigLdap.php, ConfigSmtp.php, ConfigGlpi.php
│       ├── MsConta.php, MsLicenca.php, MsCobranca.php
│       ├── TelefoniaConta.php, TelefoniaCobranca.php
│       ├── Dispositivo.php, TipoDispositivo.php
│       └── RateioHistorico.php
├── includes/
│   ├── bootstrap.php      Timezone, autoload, sessão, config
│   ├── header.php         Navbar sensível ao contexto (inicial/ms/telefonia)
│   └── footer.php
├── assets/css/            app.css, theme.css
├── microsoft/   {contas,licencas,cobrancas,rateios,relatorios}/
├── telefonia/   {contas,cobrancas,rateios,relatorios}/
├── peps/                  listar/form/excluir/exportar/importar
├── dispositivos/          listar/form/excluir/importar/sincronizar_glpi/vincular_pep/relatorios
├── contatos/              listar/form/excluir
└── smtp/index.php         Configuração SMTP/OAuth
```

## 4. Fluxo de uma requisição

1. A página inclui `includes/bootstrap.php`, que: define timezone, registra o
   **autoload** (`src/autoload.php`), carrega `config/config.php` e inicia a
   **sessão**.
2. Páginas protegidas chamam `Auth::exigirLogin()` (ou `Auth::exigirAdmin()`
   para ações restritas), que redirecionam quem não tem permissão.
3. O controller trata o POST/GET usando os **Models** (que usam `Database::pdo()`
   com prepared statements).
4. A view renderiza HTML entre `header.php` e `footer.php`; a saída dinâmica é
   escapada por `e()`.

## 5. Autenticação (resumo técnico)

[`src/Core/Auth.php`](../src/Core/Auth.php):

- `tentar()` → tenta local; se falhar e LDAP habilitado, tenta AD.
- Senha local: `password_verify` (bcrypt); aceita **MD5 legado** e faz
  *upgrade* para bcrypt ao editar o usuário.
- LDAP: *bind* com UPN `usuario@cepemar.com`, busca `memberOf` e valida
  pertencimento aos grupos `Controle_ADM`/`Controle_USR` para definir o perfil.
- Sessão guarda `usuario_id`, `usuario_nome`, `perfil`, `origem` e o
  `contexto` (inicial/microsoft/telefonia) para a navbar.

## 6. Decisões e observações

- **Sem dependências externas de runtime** (sem Composer/vendor): autoload e
  cliente SMTP próprios; só CDNs para CSS/JS de layout.
- **Segurança**: PDO prepared statements, `htmlspecialchars` na saída, hashes
  bcrypt. Pontos de atenção estão em
  [05 — Credenciais e Acessos](05-credenciais-acessos.md).
- **Base URL configurável** (`app.base_url`) permite rodar na raiz do domínio
  (produção) ou em subpasta.
