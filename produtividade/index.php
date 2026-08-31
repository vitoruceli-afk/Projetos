<?php
require_once 'config.php';
checkAuth();

$page = $_GET['page'] ?? 'dashboard';
$allowed_pages = ['dashboard', 'eventos', 'maquinas', 'categorias', 'usuarios', 'logs', 'ad'];

if (!in_array($page, $allowed_pages, true)) {
    $page = 'dashboard';
}

$admin_only_pages = ['maquinas', 'categorias', 'usuarios', 'logs', 'ad'];
if (in_array($page, $admin_only_pages, true) && !isAdmin()) {
    $page = 'dashboard';
}

// Exportação CSV de eventos: precisa mandar Content-Disposition e o corpo puro, sem o HTML do
// layout em volta.
if ($page === 'eventos' && ($_GET['format'] ?? '') === 'csv') {
    require 'views/eventos.php';
    exit;
}

include 'views/layout.php';
