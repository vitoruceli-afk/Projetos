<?php
require_once 'config.php';
require_once 'RouterosAPI.php';
checkAuth();

$page = $_GET['page'] ?? 'dashboard';
$allowed_pages = ['dashboard', 'routers', 'hotspot', 'firewall', 'diagnostics', 'users'];

if (!in_array($page, $allowed_pages)) {
    $page = 'dashboard';
}

// Páginas restritas ao perfil Administrador (cadastro de roteadores e gestão de usuários/perfis).
$admin_only_pages = ['routers', 'users'];
if (in_array($page, $admin_only_pages)) {
    requireAdmin();
}

// Permite acesso ao Dashboard mesmo sem um MikroTik ativo.
// Apenas páginas que exigem conexão ativa são bloqueadas.
$pages_that_require_router = ['hotspot', 'firewall', 'diagnostics'];

if (!isset($_SESSION['active_router']) && in_array($page, $pages_that_require_router)) {
    header("Location: index.php?page=dashboard");
    exit;
}

function getActiveRouterAPI() {
    if (!isset($_SESSION['active_router'])) return null;
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM routers WHERE id = :id");
    $stmt->bindValue(':id', $_SESSION['active_router'], PDO::PARAM_INT);
    $stmt->execute();
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($res) {
        $api = new RouterosAPI();
        $api->port = (int)($res['port'] ?? 8728);
        if ($api->connect($res['ip'], $res['username'], routerDecrypt($res['password']))) {
            return $api;
        }
    }
    return null;
}

include 'views/layout.php';
?>