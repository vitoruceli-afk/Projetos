# produtividadedev — aplicação de desenvolvimento

Cópia evolutiva da aplicação `produtividade`, onde o novo desenvolvimento acontece sem tocar na versão estável.

| Pasta do repositório | Servidor | Host | Estado |
|---|---|---|---|
| `produtividade/` | 10.1.100.123 | produtividade.faesa.br | estável, **sem** o módulo de IA (esquema v32) |
| `produtividadedev/` | 10.1.100.124 | produtividadedev.faesa.br | desenvolvimento (esquema v34, com o módulo de IA) |

Banco local: `produtividade` usa `produtividade_aw`; `produtividadedev` usa `produtividadedev_aw` (versões de esquema diferentes não podem dividir o mesmo banco). Testes: `produtividade_aw_test`.

## O que existe só aqui
- **Classificação por IA local** (Ollama + Qwen3 4B): `ia_client.php`, `ia_engine.php`, `ia_runner.php` (cron), `ia_avaliacao.php`, `views/ia.php`, `ia_rollback.sql`. A IA nasce **desligada**; modos: desligado → observação → revisão → automático. Uma sugestão aprovada vira regra normal em `categoria_regras` (origem `ia`, prioridade 200, depois das regras do administrador).
- **Testes automatizados:** `php tests/run.php` (sem dependências; usa o banco `produtividade_aw_test` e se recusa a rodar no banco de trabalho).

## Publicação no servidor de desenvolvimento
- O `config.php` do servidor tem credenciais próprias (DB_USER/DB_PASS, AD_ENC_KEY, INSTALL_ENC_KEY, AW_SERVIDOR_IP): **não sobrescrever**. Baixe o arquivo do servidor com `pscp` (nunca pelo pipe do `plink` no PowerShell: corrompe acentos), aplique só o trecho novo e envie de volta. Conferir com `diff` que só restam as linhas do servidor.
- Faça backup `.bak-<data/hora>` dos arquivos substituídos e rode `php -l`.
- SSH no .124 exige `-hostkey "SHA256:BPmPG5KW0rKnvOV2ZOyywecUhlzMaEvsTxHP4Y+hafI"` (a chave é igual à do .123, que foi clonado).
- Cron no .124: `/etc/cron.d/produtividade-ia` (runner da IA) e `/etc/cron.d/produtividade-prewarm`. O agendador do mikrotikmgr foi **desativado** no crontab do www-data (clone herdou e agiria nos roteadores reais).

## Cuidados
- O dev herdou o cadastro de máquinas e as credenciais cifradas do .123: instalação/atualização remota feita pelo dev usa `AW_SERVIDOR_IP` = .124 e pode mudar o firewall dos agentes reais.
- Não aplicar nada disto em `produtividade.faesa.br` sem decisão explícita.
