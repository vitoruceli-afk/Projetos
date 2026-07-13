<?php
// Iniciar session PRIMEIRO
session_start();

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Definir base path
define('BASE_PATH', dirname(__DIR__));

// Autoload simples
spl_autoload_register(function ($class) {
    $paths = [
        BASE_PATH . '/config/',
        BASE_PATH . '/core/',
        BASE_PATH . '/models/',
        BASE_PATH . '/controllers/'
    ];
    
    foreach ($paths as $path) {
        $file = $path . str_replace('\\', DIRECTORY_SEPARATOR, strrchr($class, '\\')) . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// Carregar configurações
require_once BASE_PATH . '/config/Config.php';
require_once BASE_PATH . '/config/Database.php';

// Inicializar timezone
\Config\Config::init();

// Pegar parâmetros
$page = isset($_GET['page']) ? preg_replace('/[^a-z0-9_-]/i', '', $_GET['page']) : 'login';
$action = isset($_GET['action']) ? preg_replace('/[^a-z0-9_-]/i', '', $_GET['action']) : 'index';

// ✅ VERIFICAR AUTENTICAÇÃO
$is_logged_in = isset($_SESSION['user_id']);

// Se não está logado, redirecionar para login
if (!$is_logged_in && $page !== 'login') {
    header('Location: ' . \Config\Config::BASE_URL . '/index.php?page=login');
    exit;
}

// Se está logado e tenta acessar login, redirecionar para dashboard
if ($is_logged_in && $page === 'login') {
    header('Location: ' . \Config\Config::BASE_URL . '/index.php?page=dashboard');
    exit;
}

// PÁGINA DE LOGIN
if ($page === 'login') {
    include BASE_PATH . '/views/login.php';
    exit;
}

// PÁGINA DE DASHBOARD
if ($page === 'dashboard') {
    $db = \Config\Database::getInstance();

    $stats = [
        'users' => (int)($db->fetch("SELECT COUNT(*) as total FROM users WHERE status = 'active'")['total'] ?? 0),
        'forms' => (int)($db->fetch("SELECT COUNT(*) as total FROM forms WHERE status = 'active'")['total'] ?? 0),
        'submissions' => (int)($db->fetch("SELECT COUNT(*) as total FROM form_submissions")['total'] ?? 0),
        'sectors' => (int)($db->fetch("SELECT COUNT(*) as total FROM sectors WHERE status = 'active'")['total'] ?? 0),
    ];

    include BASE_PATH . '/views/dashboard.php';
    exit;
}

// OUTRAS PÁGINAS (sempre após verificação de autenticação)
try {
    switch ($page) {
        case 'logout':
            session_destroy();
            header('Location: ' . \Config\Config::BASE_URL . '/index.php?page=login');
            exit;

        case 'profile':
            $controller = new \Controllers\AuthController();
            $controller->profile();
            break;

        case 'users':
            $controller = new \Controllers\UserController();
            $method = method_exists($controller, $action) ? $action : 'index';
            $controller->$method();
            break;

        case 'profiles':
            $controller = new \Controllers\ProfileController();
            $method = method_exists($controller, $action) ? $action : 'index';
            $controller->$method();
            break;

        case 'smtp':
            $controller = new \Controllers\SMTPController();
            $method = method_exists($controller, $action) ? $action : 'index';
            $controller->$method();
            break;

        case 'forms':
            $controller = new \Controllers\FormController();
            $method = method_exists($controller, $action) ? $action : 'index';
            $controller->$method();
            break;

        case 'submissions':
            $controller = new \Controllers\SubmissionController();
            $method = method_exists($controller, $action) ? $action : 'index';
            $controller->$method();
            break;

        case 'sectors':
            $controller = new \Controllers\SectorController();
            $method = method_exists($controller, $action) ? $action : 'index';
            $controller->$method();
            break;
			
		case 'devices':
            $controller = new \Controllers\DeviceController();
            $method = method_exists($controller, $action) ? $action : 'index';
            $controller->$method();
            break;

        case 'reports':
            $controller = new \Controllers\ReportController();
            $method = method_exists($controller, $action) ? $action : 'index';
            $controller->$method();
            break;

        default:
            echo "Página não encontrada!";
            break;
    }
} catch (\Exception $e) {
    error_log('Erro: ' . $e->getMessage());
    echo "Erro: " . htmlspecialchars($e->getMessage());
    exit;
}
?>