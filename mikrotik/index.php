<?php
require_once 'config.php';
require_once 'RouterosAPI.php';
checkAuth();

$page = $_GET['page'] ?? 'dashboard';
$allowed_pages = ['dashboard', 'routers', 'hotspot', 'firewall', 'diagnostics', 'users', 'logs', 'bypass'];

if (!in_array($page, $allowed_pages)) {
    $page = 'dashboard';
}

// Páginas restritas ao perfil Administrador (cadastro de roteadores, gestão de usuários/perfis, logs e bypass).
$admin_only_pages = ['routers', 'users', 'logs', 'bypass'];
if (in_array($page, $admin_only_pages)) {
    requireAdmin();
}

// Permite acesso ao Dashboard mesmo sem um MikroTik ativo.
// Apenas páginas que exigem conexão ativa são bloqueadas.
$pages_that_require_router = ['hotspot', 'firewall', 'diagnostics', 'bypass'];

if (!isset($_SESSION['active_router']) && in_array($page, $pages_that_require_router)) {
    header("Location: index.php?page=dashboard");
    exit;
}

// $timeout (segundos) sobrescreve o padrão da classe (2s) quando a página sabe que vai
// executar uma operação que pode legitimamente demorar mais no roteador (ex: /add em
// listas grandes, onde o serviço de hotspot pode levar dezenas de segundos para responder).
function getActiveRouterAPI($timeout = null) {
    if (!isset($_SESSION['active_router'])) return null;
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM routers WHERE id = :id");
    $stmt->bindValue(':id', $_SESSION['active_router'], PDO::PARAM_INT);
    $stmt->execute();
    $res = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($res) {
        $api = new RouterosAPI();
        $api->port = (int)($res['port'] ?? 8728);
        if ($timeout !== null) { $api->timeout = $timeout; }
        if ($api->connect($res['ip'], $res['username'], routerDecrypt($res['password']))) {
            return $api;
        }
    }
    return null;
}

include 'views/layout.php';
?>