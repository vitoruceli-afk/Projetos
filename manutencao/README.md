# Sistema de Manutenção de Máquinas - Instruções de Instalação

## Requisitos

- **Apache 2.4+** com módulos rewrite habilitados
- **PHP 8.0+** com extensões:
  - `pdo_mysql`
  - `json`
  - `mbstring`
  - `curl` (opcional, para funcionalidades futuras)
- **MySQL 5.7+** ou **MariaDB 10.3+**

## Passo 1: Preparar o Servidor

### No Windows (com XAMPP/WAMP):
1. Coloque a pasta `manutencao` em `C:\xampp\htdocs\` (ou equivalente)
2. Certifique-se de que Apache e MySQL estão rodando
3. Acesse http://localhost/phpmyadmin/

### No Linux:
```bash
# Criar diretório do projeto
sudo mkdir -p /var/www/html/manutencao
sudo chown -R www-data:www-data /manutencao
```

## Passo 2: Criar Banco de Dados

1. Acesse o phpMyAdmin (http://localhost/phpmyadmin/)
2. Importe o arquivo `sql/schema.sql`:
   - Clique em "Importar"
   - Selecione o arquivo `sql/schema.sql`
   - Clique em "Executar"

OU execute via terminal:
```bash
mysql -u root -p < manutencao/sql/schema.sql
```

## Passo 3: Configurar Arquivo de Banco de Dados

Edite o arquivo `config/Database.php` e ajuste as credenciais:

```php
private $host = 'localhost';      // Host do MySQL
private $db_name = 'manutencao_db'; // Nome do banco
private $username = 'root';        // Usuário MySQL
private $password = '';            // Senha MySQL
```

## Passo 4: Criar Diretórios Necessários

Crie as seguintes pastas:
```bash
mkdir -p manutencao/logs
mkdir -p manutencao/public/uploads
chmod 755 manutencao/logs
chmod 755 manutencao/public/uploads
```

## Passo 5: Habilitar mod_rewrite no Apache

### Windows (XAMPP):
1. Abra `C:\xampp\apache\conf\httpd.conf`
2. Procure por `#LoadModule rewrite_module`
3. Remova o `#` para descomentar
4. Reinicie o Apache

### Linux:
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

## Passo 6: Acessar a Aplicação

Acesse via navegador:
```
http://localhost/manutencao/public/
```

### Credenciais Padrão
- **Usuário**: admin
- **Senha**: admin

## Configuração de Trocas de Senha

Após o primeiro acesso, é recomendado:
1. Ir para "Perfil" (canto superior direito)
2. Alterar a senha do admin
3. Criar usuários adicionais conforme necessário

## Estrutura de Diretórios

```
manutencao/
├── config/              # Configurações
│   ├── Config.php
│   └── Database.php
├── core/                # Classes principais
│   ├── Auth.php
│   ├── Logger.php
│   └── Permission.php
├── models/              # Modelos de dados
│   ├── User.php
│   ├── Form.php
│   ├── FormSubmission.php
│   └── ...
├── controllers/         # Controladores
│   ├── AuthController.php
│   ├── UserController.php
│   └── ...
├── views/               # Visualizações HTML
│   ├── login.php
│   ├── header.php
│   ├── footer.php
│   └── ...
├── public/              # Arquivos públicos
│   ├── index.php        # Ponto de entrada
│   ├── css/
│   │   └── style.css
│   ├── js/
│   │   └── main.js
│   ├── uploads/         # Uploads de usuários
│   └── .htaccess
├── sql/
│   └── schema.sql       # Script SQL
└── logs/                # Arquivos de log
```

## Usuários Padrão

Após a instalação, os seguintes perfis estão disponíveis:

1. **Administrador** - Acesso total ao sistema
2. **Usuário Padrão** - Acesso limitado
3. **Técnico** - Acesso para preencher formulários

## Módulos Disponíveis

### 1. Controle de Usuários
- Criar, editar e deletar usuários
- Suporte para autenticação Local e LDAP
- Gerenciar perfis de acesso

### 2. Configuração SMTP
- Configurar servidor SMTP
- Autenticação básica ou OAuth
- Testar conexão

### 3. Gerenciamento de Formulários
- Criar formulários com drag-and-drop
- Adicionar campos de vários tipos
- Organizar em categorias
- Criar seções e colunas

### 4. Preenchimento de Formulários
- Preencher formulários criados
- Anexar arquivos
- Log de alterações
- Suporta manutenção preventiva e corretiva

### 5. Gerenciamento de Setores
- Cadastrar setores/laboratórios
- Gerenciar dispositivos
- Registrar periféricos

### 6. Relatórios
- Resumo geral
- Relatórios por formulário
- Relatórios por técnico
- Relatórios por setor
- Exportar para CSV

## Troubleshooting

### Erro de conexão com banco de dados
- Verifique se MySQL está rodando
- Confirme as credenciais em `config/Database.php`
- Verifique se o banco `manutencao_db` foi criado

### Erro 404 - mod_rewrite não está habilitado
- Habilite `mod_rewrite` no Apache
- Reinicie o Apache

### Upload de arquivos não funciona
- Verifique permissões da pasta `public/uploads/`
- Aumente `upload_max_filesize` no php.ini

### Erro de permissão ao criar logs
- Certifique-se de que a pasta `logs/` existe
- Verifique permissões: `chmod 755 logs/`

## Suporte

Para dúvidas ou problemas:
1. Verifique os logs em `manutencao/logs/`
2. Verifique o browser console (F12)
3. Verifique o erro_log do PHP/Apache

## Backup e Manutenção

### Backup do Banco de Dados
```bash
mysqldump -u root -p manutencao_db > backup.sql
```

### Restaurar Backup
```bash
mysql -u root -p manutencao_db < backup.sql
```

### Limpeza de Logs
```bash
rm -f manutencao/logs/*.log
```

## Próximos Passos

1. Alterar senha padrão do admin
2. Configurar SMTP se necessário
3. Criar usuários e perfis
4. Cadastrar setores e dispositivos
5. Criar formulários para manutenção
6. Começar a usar o sistema

---

**Versão**: 1.0.0
**Data**: 2024
**Licença**: MIT
