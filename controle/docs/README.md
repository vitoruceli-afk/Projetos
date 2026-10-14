# Documentação — Sistema de Controle (`controle.cepemar.com`)

Conjunto de documentos técnicos e operacionais da aplicação **Sistema de
Controle** (rateios Microsoft e Telefonia + controle de dispositivos com
integração GLPI).

> Gerado em 2026-10-08 a partir da análise do código-fonte e do ambiente de
> produção (`10.27.10.24`).

## Índice

### 1. Documentação Técnica
| Doc | Conteúdo |
|-----|----------|
| [01 — Requisitos e Funcionalidades](01-requisitos-funcionalidades.md) | O que o sistema faz e as regras de negócio |
| [02 — Arquitetura e Estrutura do Projeto](02-arquitetura.md) | Organização do código, linguagens, padrões |
| [03 — Esquema do Banco de Dados](03-banco-de-dados.md) | DER, tabelas e relacionamentos |
| [04 — APIs e Mapa de Rotas](04-api.md) | API GLPI consumida + mapa das páginas |
| [05 — Credenciais e Acessos](05-credenciais-acessos.md) | Inventário de contas, servidores e serviços |

### 2. Documentação Operacional
| Doc | Conteúdo |
|-----|----------|
| [06 — Manual do Usuário](06-manual-do-usuario.md) | Guia passo a passo de uso |
| [07 — Instalação e Deploy](07-instalacao-deploy.md) | Como colocar e manter a aplicação no ar |
| [08 — Backup e Contingência](08-backup-contingencia.md) | Rotinas de backup e restauração |

## Resumo do ambiente de produção

| Item | Valor |
|------|-------|
| Domínio | `controle.cepemar.com` (HTTPS, redireciona 80 → 443) |
| Servidor | `10.27.10.24` — CentOS Linux 7 (Core) |
| Web server | Apache httpd 2.4.6 + `mod_ssl` |
| Linguagem | PHP 8.2.20 (NTS) |
| Banco | MySQL 5.7.44 — base `controle` (~202 MB) |
| DocumentRoot | `/var/www/html/controle` |
| Autenticação | Local + LDAP/Active Directory (`cepemar.com`) |
| Integrações | GLPI (`suporte.cepemar.com`), SMTP/OAuth (opcional) |
