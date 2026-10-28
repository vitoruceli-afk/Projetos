# 08 — Plano de Contingência / Backup

## 1. Situação atual (diagnóstico — 2026-10-08)

| Item | Estado |
|------|--------|
| Backup automatizado (cron) | ❌ **Não existe** cron de backup configurado |
| Backups manuais | ✅ Existem dumps/tarballs avulsos em `/root/backups/` e `/root/` (feitos antes de migrações) |
| Tamanho do banco | ~202 MB (`/var/lib/mysql`) |
| Destino externo (off-site) | ❌ Não identificado — backups ficam no próprio servidor |

Backups manuais encontrados (exemplos):
- `/root/backups/var-www-html-backup-20260913161528.tar.gz`
- `/root/backups/ms_contas_backup_20260913173431.sql`
- `/root/backup_pre_migracao005_20260915_153317.sql`
- `/root/backup_pre_merge_desktop_20260915_154340.sql`
- `/root/backup_pre_glpi_specs_20260915_155509.sql`

> ⚠️ **Risco:** não há rotina automática nem cópia off-site. Uma falha de disco
> no servidor pode levar à perda total. As seções abaixo propõem a rotina a
> implantar.

## 2. O que precisa de backup

| Componente | Caminho | Observação |
|-----------|---------|------------|
| Banco de dados | base MySQL `controle` | dado crítico (rateios, dispositivos, cadastros) |
| Código + config | `/var/www/html/controle` | inclui `config/config.php` (segredos) |
| VirtualHost | `/etc/httpd/conf.d/sites-available/controle.conf` | |
| Certificado TLS | `/etc/pki/tls/certs/cepemar.crt`, `/etc/pki/tls/private/cepemar.key` | |

## 3. Backup manual (procedimento imediato)

```bash
DATA=$(date +%Y%m%d_%H%M%S)
mkdir -p /root/backups

# Banco (dump lógico)
mysqldump -u root -p --single-transaction --routines --triggers \
  controle | gzip > /root/backups/controle_$DATA.sql.gz

# Aplicação + configuração
tar czf /root/backups/controle-app_$DATA.tar.gz -C /var/www/html controle

# Infra (vhost + certificados)
tar czf /root/backups/controle-infra_$DATA.tar.gz \
  /etc/httpd/conf.d/sites-available/controle.conf \
  /etc/pki/tls/certs/cepemar.crt /etc/pki/tls/private/cepemar.key
```

## 4. Backup automático recomendado (a implantar)

Script `/usr/local/sbin/backup-controle.sh`:

```bash
#!/bin/bash
set -euo pipefail
DEST=/root/backups
RET_DIAS=14
DATA=$(date +%Y%m%d_%H%M%S)
mkdir -p "$DEST"

# ⚠️ Em produção, leia a senha de ~/.my.cnf (ver nota abaixo), não em texto plano.
mysqldump -u root --single-transaction --routines --triggers controle \
  | gzip > "$DEST/controle_$DATA.sql.gz"
tar czf "$DEST/controle-app_$DATA.tar.gz" -C /var/www/html controle

# Retenção: remove backups com mais de RET_DIAS
find "$DEST" -name 'controle_*.sql.gz'    -mtime +$RET_DIAS -delete
find "$DEST" -name 'controle-app_*.tar.gz' -mtime +$RET_DIAS -delete

# (recomendado) cópia off-site:
# rsync -az "$DEST/" backup@servidor-backup:/backups/controle/
```

Evitar senha em texto no script usando `~/.my.cnf` (permissão `600`):

```ini
[mysqldump]
user=root
password=********
```

Agendar no cron (diário às 02h):

```bash
sudo chmod 700 /usr/local/sbin/backup-controle.sh
echo '0 2 * * * root /usr/local/sbin/backup-controle.sh >> /var/log/backup-controle.log 2>&1' \
  | sudo tee /etc/cron.d/backup-controle
```

## 5. Restauração (recuperação de desastre)

```bash
# 1. Banco
gunzip < /root/backups/controle_<DATA>.sql.gz | mysql -u root -p controle
#    (se a base não existir: mysql -u root -p -e "CREATE DATABASE controle CHARACTER SET utf8mb4;")

# 2. Aplicação
tar xzf /root/backups/controle-app_<DATA>.tar.gz -C /var/www/html
chown -R apache:apache /var/www/html/controle

# 3. Infra (se necessário)
tar xzf /root/backups/controle-infra_<DATA>.tar.gz -C /
apachectl configtest && systemctl reload httpd
```

Validação pós-restauração: acessar o login, autenticar, abrir uma listagem e
gerar um relatório.

## 6. Objetivos e recomendações

| Parâmetro | Recomendação |
|-----------|--------------|
| **RPO** (perda máxima aceitável) | 24 h → backup diário |
| **RTO** (tempo de recuperação) | algumas horas com dump + tarball |
| Retenção | 14 dias local + semanal off-site |
| Off-site | copiar para outro host/storage (rsync, NAS ou nuvem) |
| Teste de restauração | trimestral, em ambiente separado |
| Segredos | manter `config.php` e `~/.my.cnf` com permissão restrita; não versionar |

### Prioridades imediatas
1. Implantar o backup automático da seção 4 **com cópia off-site**.
2. Testar uma restauração completa para validar os dumps existentes.
3. Mover os dumps avulsos de `/root/` para `/root/backups/` e aplicar retenção.
