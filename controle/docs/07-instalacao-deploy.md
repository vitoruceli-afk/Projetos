# 07 — Guia de Instalação e Deploy

Como instalar o Sistema de Controle do zero e como atualizar o ambiente de
produção atual.

## 1. Ambiente de produção atual

| Item | Valor |
|------|-------|
| Host | `10.27.10.24` — CentOS Linux 7 (Core) |
| Acesso | SSH como `root` |
| Web | Apache httpd 2.4.6 + `mod_ssl` (serviço `httpd`) |
| PHP | 8.2.20 — módulos presentes: `pdo_mysql`, `ldap`, `curl`, `openssl`, `mbstring` |
| Banco | MySQL 5.7.44 (serviço `mysqld`) |
| DocumentRoot | `/var/www/html/controle` |
| VirtualHost | `/etc/httpd/conf.d/sites-available/controle.conf` (symlink em `sites-enabled/`) |
| Certificado | `/etc/pki/tls/certs/cepemar.crt` + `/etc/pki/tls/private/cepemar.key` |
| Logs | `/var/log/httpd/controle.cepemar.com-*.log` |
| SELinux | **Enforcing** · firewalld ativo |

## 2. Requisitos

- PHP 8.0+ com `pdo_mysql` (obrigatório), `ldap` (login AD), `curl`+`openssl`
  (GLPI/OAuth), `mbstring`.
- MySQL 5.7+ / MariaDB 10.3+.
- Apache (ou Nginx+PHP-FPM) com HTTPS.

## 3. Instalação do zero

```bash
# 1. Copiar a aplicação para o DocumentRoot
sudo mkdir -p /var/www/html/controle
sudo cp -r controle/* /var/www/html/controle/

# 2. Criar o banco e importar o esquema
mysql -u root -p < /var/www/html/controle/database.sql
#   (database.sql cria a base "controle" e o admin padrão admin/admin123)

# 3. Configurar a aplicação
sudo vi /var/www/html/controle/config/config.php
#   - db.host / db.banco / db.usuario / db.senha
#   - app.base_url = ''  (raiz do domínio)  ou  '/controle' (subpasta)
#   - app.timezone = 'America/Sao_Paulo'
#   - ldap.* (opcional; também configurável pela tela Usuários)

# 4. Permissões (dono do processo Apache)
sudo chown -R apache:apache /var/www/html/controle
sudo chmod 640 /var/www/html/controle/config/config.php
```

### VirtualHost (modelo em uso)

Arquivo `config/apache-controle.conf` do repositório (instalado em
`/etc/httpd/conf.d/sites-available/controle.conf`):

```apache
<VirtualHost *:80>
    ServerName controle.cepemar.com
    Redirect permanent / https://controle.cepemar.com/
</VirtualHost>

<VirtualHost *:443>
    ServerName controle.cepemar.com
    DocumentRoot /var/www/html/controle
    <Directory /var/www/html/controle>
        AllowOverride All
        Require all granted
    </Directory>
    SSLEngine on
    SSLCertificateFile    /etc/pki/tls/certs/cepemar.crt
    SSLCertificateKeyFile /etc/pki/tls/private/cepemar.key
    SSLProtocol           all -SSLv3 -TLSv1 -TLSv1.1
    ErrorLog  /var/log/httpd/controle.cepemar.com-ssl-error.log
    CustomLog /var/log/httpd/controle.cepemar.com-ssl-access.log combined
</VirtualHost>
```

> HSTS fica **desligado** enquanto o certificado for autoassinado (com HSTS o
> navegador não deixa o usuário prosseguir após o aviso de certificado).

Ative e recarregue:

```bash
sudo ln -s ../sites-available/controle.conf /etc/httpd/conf.d/sites-enabled/controle.conf
sudo apachectl configtest && sudo systemctl reload httpd
```

### Ajustes específicos do CentOS 7

```bash
# Firewall: liberar HTTP/HTTPS
sudo firewall-cmd --permanent --add-service=http  --add-service=https
sudo firewall-cmd --reload

# SELinux (Enforcing): permitir que o Apache faça conexões de saída
# (necessário para LDAP, GLPI e SMTP/OAuth)
sudo setsebool -P httpd_can_network_connect 1
sudo setsebool -P httpd_can_connect_ldap 1   # se usar LDAP
```

Acesse `https://controle.cepemar.com` e entre com `admin` / `admin123`
(**troque a senha** em seguida).

## 4. Configurações pós-instalação (pela interface, como admin)

- **Usuários → Integração LDAP**: host, porta, base_dn, domínio, grupos
  `Controle_ADM`/`Controle_USR`, filtro `sAMAccountName`, e **habilitar**.
- **Dispositivos → Sincronizar GLPI**: url, `app_token`, `user_token`,
  verificação SSL.
- **Configuração SMTP**: servidor SMTP ou OAuth + **e-mail de teste**.
- **Contatos**: listas Microsoft/Telefonia.

## 5. Atualização / deploy de mudanças

1. **Backup antes** (ver [08 — Backup e Contingência](08-backup-contingencia.md)):
   ```bash
   DATA=$(date +%Y%m%d_%H%M%S)
   mysqldump -u root -p controle > /root/backups/controle_$DATA.sql
   tar czf /root/backups/var-www-html-controle_$DATA.tar.gz -C /var/www/html controle
   ```
2. **Atualizar os arquivos** (git pull/rsync/cp) preservando `config/config.php`.
3. **Migrações de banco** (se houver): aplicar os arquivos novos de
   `migrations/` na ordem numérica:
   ```bash
   mysql -u root -p controle < migrations/00X_nome.sql
   ```
4. **Verificar sintaxe PHP** dos arquivos alterados: `php -l arquivo.php`.
5. `sudo chown -R apache:apache /var/www/html/controle`
6. `sudo apachectl configtest && sudo systemctl reload httpd` (só se mexeu no
   vhost; alterações em `.php` não exigem reload).
7. Testar login, um relatório e uma listagem.

> O script [`deploy_conta_telefonia.sh`](../deploy_conta_telefonia.sh) é um
> exemplo de deploy idempotente (backup da tabela, `ALTER TABLE` seguro,
> gravação com `.bak`, `php -l`). A senha do banco é passada em tempo de
> execução via `DEPLOY_DB_PASS`, nunca gravada no script.

## 6. Rollback

- **Arquivos**: restaurar o `.tar.gz` do passo 1 sobre o DocumentRoot.
- **Banco**: `mysql -u root -p controle < /root/backups/controle_<data>.sql`.
- Recarregar o Apache se necessário.
