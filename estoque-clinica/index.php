<?php
require_once 'config.php';
checkAuth();

// Não depende de nenhum agendador do sistema: a cada requisição autenticada, checa (barato,
// um único SELECT) se está na hora de mandar a notificação diária e ainda não foi enviada hoje.
verificarEnviarNotificacaoDiaria(getDB());

$page = $_GET['page'] ?? null;
$allowed_pages = ['dashboard', 'movimentacao', 'estoque', 'relatorios', 'usuarios', 'medicamentos', 'insumos', 'pacientes', 'notificacoes', 'logs'];

// Usuário padrão acessa Dashboard, Entrada/Saída, Estoque, Relatórios, Insumos e Pacientes;
// Usuários, Medicamentos, Notificações e Log continuam restritos ao Administrador.
$admin_only_pages = ['usuarios', 'medicamentos', 'notificacoes', 'logs'];

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
