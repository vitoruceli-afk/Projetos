<?php
// Buffer de saída para toda a requisição: com output_buffering desligado neste servidor
// (implicit_flush=On), o HTML do layout (nav, seletor de roteadores etc.) é enviado ao
// navegador assim que é ecoado. Isso faz qualquer header() chamado depois (redirecionamentos
// após salvar/excluir) falhar com "headers already sent" — e o problema só aparece quando o
// HTML anterior cresce o bastante (ex: lista de roteadores no menu ficando maior), então pode
// funcionar por um tempo e quebrar depois sem nenhuma mudança de código. Bufferizar a resposta
// inteira aqui garante que header()/redirecionamentos sempre funcionem, em qualquer página.
ob_start();

// O date.timezone do PHP neste servidor está configurado para Europe/Berlin, mas o relógio do
// SO/MySQL (usado em CURRENT_TIMESTAMP no activity_log) reflete o horário local de Brasília. Sem
// isso, strtotime() interpretava os timestamps do banco 5h adiantado, fazendo ações recém
// registradas aparecerem como "há 5h" em vez de "agora mesmo".
date_default_timezone_set('America/Sao_Paulo');

// Tempo máximo de inatividade antes da sessão ser encerrada automaticamente.
define('SESSION_IDLE_TIMEOUT', 1800); // 30 minutos

// Cookie de sessão sem prazo fixo (expira quando o navegador é fechado por completo, não só a
// aba) + endurecido contra roubo via XSS/CSRF cross-site. gc_maxlifetime é elevado para nunca
// ser o fator limitante — quem decide o tempo de inatividade é o checkAuth() abaixo.
ini_set('session.gc_maxlifetime', SESSION_IDLE_TIMEOUT + 300);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// Configurações do Active Directory (LDAP)
define('LDAP_SERVER', 'ldap://faesa.br');
define('LDAP_DOMAIN', '@faesa.br');
define('LDAP_BASEDN', 'DC=faesa,DC=br');
define('LDAP_GROUP', 'CN=Nucleo_de_Tecnologia_da_Informação,OU=Grupos Setores,OU=Servicos,DC=faesa,DC=br');

// Timeout (segundos) para conectar/autenticar/consultar o LDAP. Sem isso, uma tentativa de
// conexão a um Domain Controller inalcançável pode travar por ~20s+ (timeout de TCP do SO)
// antes do cliente LDAP desistir e cair para outro DC resolvido pelo DNS de LDAP_SERVER.
define('LDAP_CONN_TIMEOUT', 3);

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

        // Log de auditoria: quem fez o quê, quando. Consultado na área "Logs" (Administradores).
        $db->exec("CREATE TABLE IF NOT EXISTS activity_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL,
            auth_type VARCHAR(20) DEFAULT '',
            role VARCHAR(20) DEFAULT '',
            category VARCHAR(30) NOT NULL,
            action VARCHAR(150) NOT NULL,
            details TEXT,
            ip_address VARCHAR(64) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_activity_category (category),
            INDEX idx_activity_username (username),
            INDEX idx_activity_created (created_at)
        )");

        // Registro de sessões atualmente ativas (uma linha por sessão de navegador, não por
        // usuário — a mesma pessoa logada em dois navegadores aparece como duas linhas).
        // Atualizado a cada requisição autenticada em touchActiveSession() e usado no Dashboard
        // para mostrar ao Administrador quem está de fato conectado agora.
        $db->exec("CREATE TABLE IF NOT EXISTS active_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(64) NOT NULL UNIQUE,
            username VARCHAR(100) NOT NULL,
            auth_type VARCHAR(20) DEFAULT '',
            role VARCHAR(20) DEFAULT '',
            ip_address VARCHAR(64) DEFAULT '',
            login_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_active_sessions_activity (last_activity)
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

    // Encerra a sessão se o usuário ficou inativo além do limite permitido.
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT) {
        logActivity('auth', 'Sessão expirada por inatividade');
        removeActiveSession(session_id());
        session_unset();
        session_destroy();
        header("Location: login.php?timeout=1");
        exit;
    }

    $_SESSION['last_activity'] = time();
    touchActiveSession();
}

// ---- Sessões ativas (visão do Administrador no Dashboard) ----

// Marca a sessão atual como ativa agora (chamado em toda requisição autenticada) e aproveita
// para descartar sessões que já passaram do mesmo limite de inatividade do checkAuth(), para a
// lista nunca mostrar sessões "fantasma" que na prática já foram encerradas.
function touchActiveSession() {
    if (empty($_SESSION['user_logged_in'])) return;
    try {
        $db = getDB();
        $cutoff = date('Y-m-d H:i:s', time() - SESSION_IDLE_TIMEOUT);
        $stmt = $db->prepare("DELETE FROM active_sessions WHERE last_activity < :cutoff");
        $stmt->bindValue(':cutoff', $cutoff);
        $stmt->execute();

        $stmt = $db->prepare("INSERT INTO active_sessions (session_id, username, auth_type, role, ip_address, last_activity)
            VALUES (:sid, :u, :at, :r, :ip, NOW())
            ON DUPLICATE KEY UPDATE username = :u2, auth_type = :at2, role = :r2, ip_address = :ip2, last_activity = NOW()");
        $u = $_SESSION['user_logged_in'];
        $at = $_SESSION['auth_type'] ?? '';
        $r = currentUserRole();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $stmt->bindValue(':sid', session_id());
        $stmt->bindValue(':u', $u);
        $stmt->bindValue(':u2', $u);
        $stmt->bindValue(':at', $at);
        $stmt->bindValue(':at2', $at);
        $stmt->bindValue(':r', $r);
        $stmt->bindValue(':r2', $r);
        $stmt->bindValue(':ip', $ip);
        $stmt->bindValue(':ip2', $ip);
        $stmt->execute();
    } catch (PDOException $e) {
        // Nunca deve impedir o uso normal da aplicação por causa disso.
    }
}

function removeActiveSession($sessionId) {
    try {
        $db = getDB();
        $stmt = $db->prepare("DELETE FROM active_sessions WHERE session_id = :sid");
        $stmt->bindValue(':sid', $sessionId);
        $stmt->execute();
    } catch (PDOException $e) {
        // intencionalmente silencioso
    }
}

// Sessões dentro da janela de SESSION_IDLE_TIMEOUT, mais recentes primeiro.
function getActiveSessions() {
    try {
        $db = getDB();
        $cutoff = date('Y-m-d H:i:s', time() - SESSION_IDLE_TIMEOUT);
        $stmt = $db->prepare("SELECT * FROM active_sessions WHERE last_activity >= :cutoff ORDER BY last_activity DESC");
        $stmt->bindValue(':cutoff', $cutoff);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
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

// ---- Log de auditoria ----

// Categorias válidas: 'auth', 'hotspot', 'firewall', 'routers', 'users'.
// $action é um texto curto já pronto para exibição (ex: "Habilitou hotspot").
// Uma falha ao gravar o log nunca deve impedir a ação principal do usuário.
function logActivity($category, $action, $details = '') {
    try {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO activity_log (username, auth_type, role, category, action, details, ip_address)
            VALUES (:u, :at, :r, :c, :a, :d, :ip)");
        $stmt->bindValue(':u', $_SESSION['user_logged_in'] ?? 'desconhecido');
        $stmt->bindValue(':at', $_SESSION['auth_type'] ?? '');
        $stmt->bindValue(':r', currentUserRole());
        $stmt->bindValue(':c', $category);
        $stmt->bindValue(':a', $action);
        $stmt->bindValue(':d', $details);
        $stmt->bindValue(':ip', $_SERVER['REMOTE_ADDR'] ?? '');
        $stmt->execute();
    } catch (PDOException $e) {
        // intencionalmente silencioso
    }
}

// Abre uma conexão LDAP já com protocolo v3, referrals desligados e timeout curto.
// Centraliza essa configuração para que todo ponto que fala com o AD tenha o mesmo limite de tempo.
function ldapConnect($server) {
    $ldap = @ldap_connect($server);
    if (!$ldap) return false;
    @ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
    @ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
    @ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, LDAP_CONN_TIMEOUT);
    @ldap_set_option($ldap, LDAP_OPT_TIMEOUT, LDAP_CONN_TIMEOUT);
    return $ldap;
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