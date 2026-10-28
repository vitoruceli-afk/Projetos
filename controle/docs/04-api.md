# 04 — APIs e Mapa de Rotas

> **Importante:** o Sistema de Controle **não expõe uma API REST/JSON
> própria**. É uma aplicação web server-side: cada funcionalidade é uma página
> `.php` que recebe `GET`/`POST` via formulários HTML e responde com HTML (ou
> um arquivo CSV no caso das exportações). Não há tokens/Bearer, Swagger ou
> coleção Postman aplicáveis a ela.
>
> Este documento cobre **(A)** a API externa que a aplicação **consome** (GLPI)
> e **(B)** o **mapa de rotas internas** (páginas), que é o equivalente prático
> a uma "coleção de endpoints" para esta arquitetura.

---

## A. API consumida — GLPI REST (`apirest.php`)

Cliente: [`src/Core/GLPIClient.php`](../src/Core/GLPIClient.php).
Servidor em produção: `https://suporte.cepemar.com/index.php` (o cliente
acrescenta `/apirest.php` automaticamente). Versão-alvo: **GLPI 11**.

### Autenticação (fluxo)

| Passo | Método / Endpoint | Cabeçalhos |
|-------|-------------------|------------|
| 1. Abrir sessão | `GET /initSession` | `App-Token: <app_token>`, `Authorization: user_token <user_token>` |
| 2. Chamadas seguintes | `GET /<itemtype>...` | `App-Token: <app_token>`, `Session-Token: <session_token>` |
| 3. Encerrar sessão | `GET /killSession` | `App-Token`, `Session-Token` |

Tokens ficam em `config_glpi` (`app_token`, `user_token`). `verificar_ssl`
controla a validação do certificado TLS na chamada cURL.

### Endpoints usados na sincronização

| Finalidade | Chamada |
|-----------|---------|
| Listar itens (paginado, 500/página) | `GET /<itemtype>?range=INI-FIM&expand_dropdowns=true` |
| Specs de hardware do computador | `GET /Computer/{id}?with_devices=true&expand_dropdowns=true` |
| Sistema operacional | `GET /Computer/{id}/Item_OperatingSystem?expand_dropdowns=true` |

A paginação é guiada pelo header **`Content-Range`** da resposta.

### `itemtype`s importados e mapeamento para tipos locais

| GLPI `itemtype` | Tipo local (`tipos_dispositivos.nome`) |
|-----------------|----------------------------------------|
| `Computer` | Computador |
| `Monitor` | Monitor |
| `Printer` | Impressora |
| `NetworkEquipment` | Roteador/Switch |
| `Peripheral` | Periféricos |
| `Phone` | Celular/Smartphone |

Cada item é **normalizado**: os campos de dropdown do GLPI
(`*models_id`, `manufacturers_id`, `locations_id`) viram
`model` / `manufacturer` / `location`; o JSON bruto do GLPI é preservado em
`dispositivos.dados_glpi` para auditoria. Itens já existentes são casados por
`(id_glpi, origem='glpi')`.

---

## B. Mapa de rotas internas (páginas)

Convenção por módulo: `listar.php` (lista + filtros), `form.php`
(criar/editar), `excluir.php` (`POST`), `exportar.php` (CSV),
`importar.php` (upload CSV). Páginas de escrita exigem perfil `admin`
(`Auth::exigirAdmin`); as demais exigem login (`Auth::exigirLogin`).

### Raiz / autenticação
| Rota | Método | Descrição | Acesso |
|------|:------:|-----------|--------|
| `/login.php` | GET, POST | Formulário e submissão de login | público |
| `/logout.php` | GET | Encerra a sessão | logado |
| `/index.php` | GET | Área inicial (seleção de rateio + atalhos) | logado |

### PEPs / Projetos (`/peps`)
| Rota | Método | Descrição | Acesso |
|------|:------:|-----------|--------|
| `/peps/listar.php` | GET | Lista + filtros | logado |
| `/peps/form.php` | GET, POST | Criar/editar PEP | admin |
| `/peps/excluir.php` | POST | Excluir PEP | admin |
| `/peps/exportar.php` | GET | Exporta CSV | logado |
| `/peps/importar.php` | GET, POST | Importa CSV (`PEP, Projeto`) | admin |

### Microsoft (`/microsoft/{contas,licencas,cobrancas}`)
Mesmo conjunto `listar/form/excluir/exportar/importar` por submódulo.
`contas` importa `Nome, Email, PEP, Licencas`; `licencas` e `cobrancas` têm
`listar/form/excluir/exportar/importar`.

| Rota | Método | Descrição | Acesso |
|------|:------:|-----------|--------|
| `/microsoft/contas/listar.php` | GET | Contas Microsoft | logado |
| `/microsoft/rateios/listar.php` | GET | Rateios gerados (histórico) | logado |
| `/microsoft/rateios/ver.php` | GET | Detalhe de um rateio | logado |
| `/microsoft/rateios/enviar.php` | POST | Envia rateio por e-mail | logado |
| `/microsoft/rateios/exportar.php` | GET | Exporta rateio (CSV) | logado |
| `/microsoft/rateios/excluir.php` | POST | Exclui rateio do histórico | admin |
| `/microsoft/relatorios/index.php` | GET | Tela de relatório por PEP | logado |
| `/microsoft/relatorios/gerar.php` | GET, POST | Gera/persiste o rateio | logado |

### Telefonia (`/telefonia/{contas,cobrancas,rateios,relatorios}`)
Estrutura idêntica à Microsoft. `contas` importa
`Nome do Usuario, Telefone, Operadora, PEP, Valor, Conta Telefonia` e permite
atualização de valores por CSV (casada por telefone).

### Dispositivos (`/dispositivos`)
| Rota | Método | Descrição | Acesso |
|------|:------:|-----------|--------|
| `/dispositivos/listar.php` | GET | Inventário + filtros | logado |
| `/dispositivos/form.php` | GET, POST | Criar/editar dispositivo | admin |
| `/dispositivos/excluir.php` | POST | Excluir dispositivo | admin |
| `/dispositivos/importar.php` | GET, POST | Importar CSV | admin |
| `/dispositivos/sincronizar_glpi.php` | GET, POST | Sincroniza com o GLPI | admin |
| `/dispositivos/vincular_pep.php` | POST | Vincula PEP a dispositivos | admin |
| `/dispositivos/relatorios.php` | GET | Relatórios de dispositivos | logado |

### Administração (`/usuarios`, `/contatos`, `/smtp`)
| Rota | Método | Descrição | Acesso |
|------|:------:|-----------|--------|
| `/usuarios/listar.php` + `form`/`excluir` | GET/POST | Usuários locais e **config LDAP** | admin |
| `/contatos/listar.php` + `form`/`excluir` | GET/POST | Listas de e-mail | admin |
| `/smtp/index.php` | GET, POST | Config SMTP/OAuth + e-mail de teste | admin |

### Parâmetros comuns
- **Filtros de lista:** querystring por coluna (ex.: `?pep=...&projeto=...`).
- **Exportação:** `exportar.php` aceita `ids[]` (selecionados) ou exporta todos
  do filtro; resposta `text/csv` com BOM UTF-8.
- **Importação:** `multipart/form-data` com o arquivo CSV; resposta HTML com
  resumo (importados × recusados com motivo).
