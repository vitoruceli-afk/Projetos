# 05 — Credenciais e Acessos

> ⚠️ **Este documento não contém senhas em texto plano — por segurança.**
> Ele inventaria **quais** acessos existem, **onde** cada segredo está
> armazenado e **quem** deve guardá-lo. Os valores reais devem ficar em um
> **gerenciador de senhas corporativo** (ex.: Bitwarden/Vaultwarden, KeePass,
> 1Password), nunca versionados no Git.

## 1. Inventário de acessos

| # | Sistema / Serviço | Identificador / Conta | Onde o segredo está hoje | Guardar no cofre? |
|---|-------------------|-----------------------|--------------------------|:-----------------:|
| 1 | **Servidor (SSH)** `10.27.10.24` — CentOS 7 | `root` | — (conhecido pela equipe de infra) | ✅ |
| 2 | **MySQL** (base `controle`) | `root@localhost` | `config/config.php` → bloco `db.senha` (texto plano) | ✅ |
| 3 | **Aplicação — admin local** | `admin` (perfil admin) | tabela `usuarios` (hash). Senha inicial padrão `admin123` | ✅ |
| 4 | **LDAP / Active Directory** | bind com as credenciais **do próprio usuário** (UPN `usuario@cepemar.com`) | não há conta de serviço dedicada; sem senha fixa armazenada | — |
| 5 | **GLPI** (`suporte.cepemar.com`) | `app_token` + `user_token` | tabela `config_glpi` | ✅ |
| 6 | **SMTP / OAuth** (envio de e-mail) | conforme método configurado | tabela `config_smtp` (`senha`, `oauth_client_secret`, `oauth_refresh_token`) | ✅ (quando configurado) |
| 7 | **Certificado TLS** `cepemar.crt`/`cepemar.key` | — | `/etc/pki/tls/{certs,private}/` | ✅ (chave privada) |
| 8 | **Domínio / DNS** `cepemar.com` | registrador/provedor DNS | fora da aplicação | ✅ |

> Em 2026-10-08, `config_smtp` está **vazia** (envio de e-mail ainda não
> configurado em produção) e não há conta de serviço LDAP dedicada (o bind usa
> as credenciais de quem faz login).

## 2. Usuários da aplicação (produção)

| id | usuário | nome | perfil | origem |
|----|---------|------|--------|--------|
| 1 | `admin` | Administrador | admin | local |
| 3 | `vitor.uceli` | Vitor Uceli | admin | ldap |
| 4 | `ewerton.nunes` | Ewerton Bortolozo Nunes | admin | ldap |

Grupos de segurança do AD que concedem acesso:
- `CN=Controle_ADM,CN=Users,DC=cepemar,DC=com` → perfil **admin**
- `CN=Controle_USR,CN=Users,DC=cepemar,DC=com` → perfil **usuario**

## 3. Parâmetros de integração (não sensíveis)

**LDAP** (`config_ldap`): host `ldap://sv000010.cepemar.com:389`, base_dn
`DC=cepemar,DC=com`, domínio `cepemar.com`, filtro `sAMAccountName`.

**GLPI** (`config_glpi`): url `https://suporte.cepemar.com/index.php`,
`verificar_ssl = 1`, status `conectado`.

## 4. Onde cada segredo vive (para rotação)

| Segredo | Local exato |
|---------|-------------|
| Senha do MySQL | `/var/www/html/controle/config/config.php` (`db` → `senha`) |
| Hash do admin local | `controle.usuarios.senha` (bcrypt/MD5 legado) |
| Tokens GLPI | `controle.config_glpi.app_token` / `user_token` |
| Segredos SMTP/OAuth | `controle.config_smtp.*` |
| Chave privada TLS | `/etc/pki/tls/private/cepemar.key` (permissão `600`) |

## 5. Recomendações de segurança (achados)

1. **Senha do MySQL em texto plano** em `config/config.php`. É inerente à
   aplicação, mas: restrinja a permissão do arquivo (`chmod 640`, dono
   `apache`), **não** o versione, e considere um usuário MySQL dedicado
   (não `root`) com privilégios apenas sobre a base `controle`.
2. **Certificado autoassinado** (sem HSTS, por isso). Avaliar emissão por CA
   interna/corporativa ou Let's Encrypt para remover o aviso do navegador.
3. **Trocar a senha padrão** `admin/admin123` no primeiro acesso (o sistema
   regrava em bcrypt ao editar o usuário).
4. **Centralizar os segredos** desta lista no gerenciador de senhas, com
   responsável e política de rotação; remover cópias avulsas.
5. Revisar periodicamente os membros dos grupos `Controle_ADM`/`Controle_USR`.

## 6. Modelo de registro no cofre (preencher no gerenciador de senhas)

```
Título: Controle - MySQL root (10.27.10.24)
Usuário: root
Senha: ********
URL/Host: localhost:3306  (via SSH em 10.27.10.24)
Notas: base "controle"; usado por config/config.php

Título: Controle - SSH root (10.27.10.24)
Usuário: root
Senha: ********

Título: Controle - Admin da aplicação
Usuário: admin
Senha: ********
URL: https://controle.cepemar.com

Título: Controle - GLPI API tokens
Campos: app_token=********  user_token=********
URL: https://suporte.cepemar.com
```
