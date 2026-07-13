<?php
session_start();

// Configurações do Active Directory (LDAP)
define('LDAP_SERVER', 'ldap://faesa.br'); 
define('LDAP_DOMAIN', '@faesa.br');
define('LDAP_BASEDN', 'DC=faesa,DC=br');
define('LDAP_GROUP', 'CN=Nucleo_de_Tecnologia_da_Informação,OU=Grupos Setores,OU=Servicos,DC=faesa,DC=br');

// Configurações do Banco de Dados Local (MySQL 5.7+)
define('DB_HOST', '127.0.0.1'); // IP do servidor MySQL (ou localhost)
define('DB_NAME', 'mikrotik_manager'); // Nome do banco de dados (CRIE ESTE BANCO ANTES)
define('DB_USER', 'root'); // Seu usuário do MySQL
define('DB_PASS', ''); // Sua senha do MySQL

// Chave usada para criptografar as senhas dos roteadores salvas no banco.
// TROQUE este valor em produção e mantenha-o fora do controle de versão (ex: variável de ambiente).
define('ROUTER_ENC_KEY', 'faesa-mikrotik-manager-troque-esta-chave');

function getDB() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $db = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        
        // Criação das tabelas automaticamente caso não existam
        $db->exec("CREATE TABLE IF NOT EXISTS routers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            location VARCHAR(255) NOT NULL,
            ip VARCHAR(50) NOT NULL,
            username VARCHAR(100) NOT NULL,
            password VARCHAR(255) NOT NULL,
            port INT DEFAULT 8728
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS local_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(255) DEFAULT '',
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            role VARCHAR(20) NOT NULL DEFAULT 'standard',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        // Migração leve para bancos criados antes da coluna 'role' existir.
        try {
            $db->exec("ALTER TABLE local_users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'standard'");
        } catch (PDOException $e) {
            // coluna já existe - ignora
        }

        $db->exec("CREATE TABLE IF NOT EXISTS ldap_blocked_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            blocked_by VARCHAR(100) DEFAULT '',
            blocked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // Perfil dos usuários autenticados via LDAP. A ausência de registro para um usuário
        // significa 'admin' por padrão (todo mundo do grupo AD já tinha acesso total antes
        // deste sistema de perfis existir); um administrador precisa rebaixar explicitamente
        // quem deve virar 'standard'.
        $db->exec("CREATE TABLE IF NOT EXISTS ldap_user_roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            role VARCHAR(20) NOT NULL DEFAULT 'admin'
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS ldap_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ldap_server VARCHAR(255) NOT NULL,
            ldap_domain VARCHAR(100) NOT NULL,
            ldap_basedn VARCHAR(255) NOT NULL,
            ldap_group VARCHAR(255) NOT NULL,
            bind_username VARCHAR(255) DEFAULT '',
            bind_password VARCHAR(255) DEFAULT ''
        )");

        // Lista de permissão (allowlist): quais hotspots/regras de firewall de cada roteador
        // o perfil "Usuário Padrão" pode ver e habilitar/desabilitar. Selecionado pelo Administrador.
        $db->exec("CREATE TABLE IF NOT EXISTS rule_permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            router_id INT NOT NULL,
            rule_type VARCHAR(20) NOT NULL,
            rule_id VARCHAR(64) NOT NULL,
            granted_by VARCHAR(100) DEFAULT '',
            granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_rule_perm (router_id, rule_type, rule_id)
        )");

        return $db;
    } catch (PDOException $e) {
        die("Erro de conexão com o MySQL: " . $e->getMessage());
    }
}

// Verifica se está logado
function checkAuth() {
    if (!isset($_SESSION['user_logged_in'])) {
        header("Location: login.php");
        exit;
    }
}

// ---- Perfis de acesso (admin / standard) ----

// Resolve o perfil do usuário logado consultando a fonte correspondente ao tipo de login.
function currentUserRole() {
    $username = $_SESSION['user_logged_in'] ?? null;
    if (!$username) return 'standard';

    $db = getDB();
    if (($_SESSION['auth_type'] ?? '') === 'local') {
        $stmt = $db->prepare("SELECT role FROM local_users WHERE username = :u");
        $stmt->bindValue(':u', $username);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row['role'] ?? 'standard';
    }

    // LDAP: sem registro explícito = admin (mantém o acesso total que todo o grupo AD já tinha).
    $stmt = $db->prepare("SELECT role FROM ldap_user_roles WHERE username = :u");
    $stmt->bindValue(':u', $username);
    $stmt->execute();
    $row = $stmt->fetch();
    return $row['role'] ?? 'admin';
}

function isAdmin() {
    return currentUserRole() === 'admin';
}

// Encerra a requisição com 403 se o usuário logado não for Administrador.
function requireAdmin() {
    if (!isAdmin()) {
        http_response_code(403);
        die('Acesso restrito ao perfil Administrador.');
    }
}

// ---- Proteção CSRF ----

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

// Encerra a requisição com 403 se o token CSRF do POST não bater com o da sessão.
function csrfVerify() {
    $sent = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sent)) {
        http_response_code(403);
        die('Falha na validação de segurança (CSRF). Atualize a página e tente novamente.');
    }
}

// ---- Criptografia das senhas dos roteadores ----

function routerEncrypt($plaintext) {
    $key = hash('sha256', ROUTER_ENC_KEY, true);
    $iv = random_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $cipher = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cipher);
}

// Descriptografa; se o valor armazenado não foi gerado por routerEncrypt() (ex: senha
// legada em texto puro salva antes desta mudança), devolve o valor original como fallback.
function routerDecrypt($stored) {
    $ivLen = openssl_cipher_iv_length('aes-256-cbc');
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) <= $ivLen) {
        return $stored;
    }
    $key = hash('sha256', ROUTER_ENC_KEY, true);
    $iv = substr($raw, 0, $ivLen);
    $cipher = substr($raw, $ivLen);
    $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $plain !== false ? $plain : $stored;
}

// Aceita IPv4/IPv6 válidos ou hostnames válidos (o fsockopen aceita ambos).
function isValidRouterHost($host) {
    $host = trim((string)$host);
    if ($host === '') return false;
    if (filter_var($host, FILTER_VALIDATE_IP)) return true;
    return (bool) preg_match('/^(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))*$/', $host);
}

// ---- Configurações LDAP editáveis via tela (aba "Configurações" em Usuários) ----
// Na primeira chamada, semeia a tabela com os valores das constantes acima definidas no código.
function getLdapSettings() {
    $db = getDB();
    $row = $db->query("SELECT * FROM ldap_settings ORDER BY id ASC LIMIT 1")->fetch();
    if (!$row) {
        $stmt = $db->prepare("INSERT INTO ldap_settings (ldap_server, ldap_domain, ldap_basedn, ldap_group, bind_username, bind_password)
            VALUES (:server, :domain, :basedn, :group, '', '')");
        $stmt->bindValue(':server', LDAP_SERVER);
        $stmt->bindValue(':domain', LDAP_DOMAIN);
        $stmt->bindValue(':basedn', LDAP_BASEDN);
        $stmt->bindValue(':group', LDAP_GROUP);
        $stmt->execute();
        $row = $db->query("SELECT * FROM ldap_settings ORDER BY id ASC LIMIT 1")->fetch();
    }
    return $row;
}
?>