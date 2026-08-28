<?php
require_once 'config.php';
checkAuth();

$page = $_GET['page'] ?? null;
$allowed_pages = ['dashboard', 'movimentacao', 'relatorios', 'usuarios', 'medicamentos', 'insumos', 'logs'];

// Usuário padrão só enxerga Movimentação e Relatórios; qualquer outra página cai para Movimentação.
$admin_only_pages = ['dashboard', 'usuarios', 'medicamentos', 'insumos', 'logs'];

if ($page === null || !in_array($page, $allowed_pages, true)) {
    $page = isAdmin() ? 'dashboard' : 'movimentacao';
}
if (!isAdmin() && in_array($page, $admin_only_pages, true)) {
    $page = 'movimentacao';
}

// Exportação CSV: precisa mandar Content-Disposition e o corpo do arquivo puro, sem o HTML do
// layout (rail/topbar) em volta — roda a view isolada, que já finaliza com exit() nesse caso.
if (($page === 'relatorios' || $page === 'logs') && ($_GET['format'] ?? '') === 'csv') {
    require "views/{$page}.php";
    exit;
}

include 'views/layout.php';
