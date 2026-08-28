<?php
// Buffer de saída para toda a requisição — evita "headers already sent" quando um header()
// de redirecionamento é chamado depois de algum HTML já ter sido ecoado (ver mesma decisão
// documentada em ../mikrotik/config.php).
ob_start();

date_default_timezone_set('America/Sao_Paulo');

define('SESSION_IDLE_TIMEOUT', 1800); // 30 minutos

ini_set('session.gc_maxlifetime', SESSION_IDLE_TIMEOUT + 300);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// Configurações do Banco de Dados Local (MySQL/MariaDB)
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'estoque_clinica');
define('DB_USER', 'root');
define('DB_PASS', '');

define('UPLOAD_DIR', __DIR__ . '/uploads/insumos');
define('UPLOAD_URL', 'uploads/insumos');

// Janelas de alerta de vencimento usadas no Dashboard e nos Relatórios.
define('VENCIMENTO_ALERTA_DIAS', 30);
define('VENCIMENTO_URGENTE_DIAS', 7);

function getDB() {
    // Conexão + schema são cacheados numa estática por requisição (mesmo motivo documentado
    // em ../mikrotik/config.php: evita repetir os CREATE TABLE IF NOT EXISTS em toda chamada).
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    try {
        $dsn = "mysql:host=" . DB_HOST . ";charset=utf8mb4";
        $db = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db->exec("USE `" . DB_NAME . "`");

        $db->exec("CREATE TABLE IF NOT EXISTS local_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(255) DEFAULT '',
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            role VARCHAR(20) NOT NULL DEFAULT 'usuario',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS laboratorios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(200) NOT NULL,
            cnpj VARCHAR(20) DEFAULT '',
            contato VARCHAR(150) DEFAULT '',
            telefone VARCHAR(30) DEFAULT '',
            email VARCHAR(150) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_laboratorios_nome (nome)
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS insumos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(200) NOT NULL,
            laboratorio_id INT NOT NULL,
            composicao TEXT,
            codigo_barras VARCHAR(64) NOT NULL UNIQUE,
            unidade_medida VARCHAR(30) NOT NULL DEFAULT 'unidade',
            observacoes TEXT,
            foto VARCHAR(255) DEFAULT '',
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_insumos_laboratorio (laboratorio_id),
            INDEX idx_insumos_nome (nome)
        )");

        // Um insumo pode ter vários lotes em estoque ao mesmo tempo, cada um com seu próprio
        // vencimento — é o que permite calcular "a vencer em 30/7 dias" e "vencido" por lote,
        // e dar saída seguindo o vencimento mais próximo primeiro (FIFO por validade).
        $db->exec("CREATE TABLE IF NOT EXISTS insumo_lotes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            insumo_id INT NOT NULL,
            lote VARCHAR(80) NOT NULL,
            validade DATE NOT NULL,
            quantidade INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_insumo_lote (insumo_id, lote),
            INDEX idx_lotes_validade (validade)
        )");

        // Histórico de entradas/saídas — cada linha é um movimento aplicado a um lote específico.
        $db->exec("CREATE TABLE IF NOT EXISTS movimentacoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            insumo_id INT NOT NULL,
            lote_id INT NULL,
            tipo VARCHAR(10) NOT NULL,
            quantidade INT NOT NULL,
            usuario VARCHAR(100) DEFAULT '',
            observacao VARCHAR(255) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_mov_insumo (insumo_id),
            INDEX idx_mov_created (created_at),
            INDEX idx_mov_tipo (tipo)
        )");

        // Primeiro acesso: sem nenhum usuário cadastrado ainda, semeia um Administrador padrão
        // para não deixar a aplicação sem porta de entrada.
        $count = (int)$db->query("SELECT COUNT(*) FROM local_users")->fetchColumn();
        if ($count === 0) {
            $stmt = $db->prepare("INSERT INTO local_users (username, password_hash, full_name, enabled, role) VALUES ('admin', :hash, 'Administrador', 1, 'admin')");
            $stmt->bindValue(':hash', password_hash('admin123', PASSWORD_DEFAULT));
            $stmt->execute();
        }

        $cached = $db;
        return $cached;
    } catch (PDOException $e) {
        die("Erro de conexão com o MySQL: " . $e->getMessage());
    }
}

// ---- Autenticação ----

function checkAuth() {
    if (!isset($_SESSION['user_logged_in'])) {
        header("Location: login.php");
        exit;
    }

    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT) {
        session_unset();
        session_destroy();
        header("Location: login.php?timeout=1");
        exit;
    }

    // Conta pode ter sido desabilitada por um administrador depois do login — encerra a sessão
    // imediatamente em vez de deixar a próxima requisição autenticada passar mesmo assim.
    $db = getDB();
    $stmt = $db->prepare("SELECT enabled FROM local_users WHERE username = :u");
    $stmt->bindValue(':u', $_SESSION['user_logged_in']);
    $stmt->execute();
    $enabled = $stmt->fetchColumn();
    if ($enabled === false || (int)$enabled === 0) {
        session_unset();
        session_destroy();
        header("Location: login.php");
        exit;
    }

    $_SESSION['last_activity'] = time();
}

// Consulta o perfil sempre no banco (não na sessão) para que uma mudança de perfil feita por um
// administrador tenha efeito imediato, mesmo que o usuário afetado já esteja com sessão aberta.
function currentUserRole() {
    $username = $_SESSION['user_logged_in'] ?? null;
    if (!$username) return 'usuario';
    static $cachedRole = null;
    static $cachedUser = null;
    if ($cachedUser === $username && $cachedRole !== null) {
        return $cachedRole;
    }
    $db = getDB();
    $stmt = $db->prepare("SELECT role FROM local_users WHERE username = :u");
    $stmt->bindValue(':u', $username);
    $stmt->execute();
    $role = $stmt->fetchColumn();
    $cachedUser = $username;
    $cachedRole = $role !== false ? $role : 'usuario';
    return $cachedRole;
}

function isAdmin() {
    return currentUserRole() === 'admin';
}

function requireAdmin() {
    if (!isAdmin()) {
        http_response_code(403);
        die('Acesso restrito ao perfil Administrador.');
    }
}

function userInitials($username) {
    $parts = array_values(array_filter(preg_split('/[.\s_-]+/', trim((string)$username))));
    if (count($parts) >= 2) {
        return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
    }
    return strtoupper(mb_substr((string)$username, 0, 2));
}

// ---- CSRF ----

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

function csrfVerify() {
    $sent = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sent)) {
        http_response_code(403);
        die('Falha na validação de segurança (CSRF). Atualize a página e tente novamente.');
    }
}

// ---- Regras de estoque / vencimento ----

// Soma de todos os lotes com saldo do insumo.
function insumoEstoqueTotal(PDO $db, $insumoId) {
    $stmt = $db->prepare("SELECT COALESCE(SUM(quantidade), 0) FROM insumo_lotes WHERE insumo_id = :id AND quantidade > 0");
    $stmt->bindValue(':id', $insumoId, PDO::PARAM_INT);
    $stmt->execute();
    return (int)$stmt->fetchColumn();
}

// Lote com saldo cujo vencimento é o mais próximo (usado para dar saída por FIFO de validade
// e para exibir o resumo ao ler o código de barras).
function insumoProximoLote(PDO $db, $insumoId) {
    $stmt = $db->prepare("SELECT * FROM insumo_lotes WHERE insumo_id = :id AND quantidade > 0 ORDER BY validade ASC LIMIT 1");
    $stmt->bindValue(':id', $insumoId, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetch() ?: null;
}

// 'vencido' | 'urgente' (<= 7 dias) | 'alerta' (<= 30 dias) | 'ok' | null (sem lote com saldo)
function statusVencimento($validade) {
    if (!$validade) return null;
    $hoje = new DateTime('today');
    $venc = new DateTime($validade);
    $dias = (int)$hoje->diff($venc)->format('%r%a');
    if ($dias < 0) return 'vencido';
    if ($dias <= VENCIMENTO_URGENTE_DIAS) return 'urgente';
    if ($dias <= VENCIMENTO_ALERTA_DIAS) return 'alerta';
    return 'ok';
}

function statusVencimentoLabel($status) {
    return match ($status) {
        'vencido' => 'Vencido',
        'urgente' => 'Vence em até 7 dias',
        'alerta' => 'Vence em até 30 dias',
        'ok' => 'Dentro da validade',
        default => 'Sem estoque',
    };
}

function statusVencimentoBadgeClass($status) {
    return match ($status) {
        'vencido' => 'bg-danger',
        'urgente' => 'bg-warning text-dark',
        'alerta' => 'bg-info text-dark',
        'ok' => 'bg-success',
        default => 'bg-secondary',
    };
}

function findInsumoByBarcode(PDO $db, $codigo) {
    $stmt = $db->prepare("SELECT i.*, l.nome AS laboratorio_nome FROM insumos i
        LEFT JOIN laboratorios l ON l.id = i.laboratorio_id
        WHERE i.codigo_barras = :c LIMIT 1");
    $stmt->bindValue(':c', $codigo);
    $stmt->execute();
    return $stmt->fetch() ?: null;
}

// Salva a foto enviada em uploads/insumos com um nome único; devolve o nome do arquivo salvo
// (ou '' se nenhum arquivo válido foi enviado). Lança RuntimeException em caso de erro real de upload.
function salvarFotoInsumo(array $file) {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Falha ao enviar a foto (código ' . $file['error'] . ').');
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = mime_content_type($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Formato de imagem não suportado. Use JPG, PNG, WEBP ou GIF.');
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('A foto deve ter no máximo 5MB.');
    }

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $filename)) {
        throw new RuntimeException('Não foi possível salvar a foto enviada.');
    }
    return $filename;
}

function insumoFotoUrl($foto) {
    if (empty($foto)) return null;
    return UPLOAD_URL . '/' . rawurlencode($foto);
}
