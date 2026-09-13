<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Core\Config;

/*
|--------------------------------------------------------------------------
| HEADER / LAYOUT
|--------------------------------------------------------------------------
|
| Variáveis que a página pode definir antes de incluir este header:
|   $tituloPagina  -> título da aba
|   $contexto      -> 'inicial' | 'microsoft' | 'telefonia'
|
*/

require_once __DIR__ . '/bootstrap.php';

Auth::exigirLogin();

$contexto = $contexto ?? 'inicial';
Session::set('contexto', $contexto);

$tituloPagina = $tituloPagina ?? Config::get('app.nome');
$ehAdmin      = Auth::ehAdmin();

$flashes = Session::pegarFlash();

function navLink($targetPage, $currentPage, $label, $iconPath) {
    $active = $targetPage === $currentPage ? ' is-active' : '';
    echo '<a class="rail-link' . $active . '" href="' . url($targetPage) . '">'
        . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">' . $iconPath . '</svg>'
        . htmlspecialchars($label) . '</a>';
}

// Determinar página ativa baseado no arquivo atual
$pagina_atual = basename($_SERVER['PHP_SELF'], '.php');
if (strpos($_SERVER['REQUEST_URI'], '/microsoft/') !== false) {
    $pagina_atual = 'microsoft';
} elseif (strpos($_SERVER['REQUEST_URI'], '/telefonia/') !== false) {
    $pagina_atual = 'telefonia';
} elseif (strpos($_SERVER['REQUEST_URI'], '/peps/') !== false) {
    $pagina_atual = 'peps';
} elseif (strpos($_SERVER['REQUEST_URI'], '/dispositivos/') !== false) {
    $pagina_atual = 'dispositivos';
} elseif (strpos($_SERVER['REQUEST_URI'], '/usuarios/') !== false) {
    $pagina_atual = 'usuarios';
} elseif (strpos($_SERVER['REQUEST_URI'], '/contatos/') !== false) {
    $pagina_atual = 'contatos';
} elseif (strpos($_SERVER['REQUEST_URI'], '/smtp/') !== false) {
    $pagina_atual = 'smtp';
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($tituloPagina) ?> - <?= e(Config::get('app.nome')) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= url('assets/css/theme.css?v=' . filemtime(__DIR__ . '/../assets/css/theme.css')) ?>">
    <link href="<?= url('assets/css/app.css') ?>" rel="stylesheet">
</head>
<body>
<div class="app-shell">
    <div class="rail-backdrop" id="railBackdrop"></div>
    <nav class="rail" id="rail" aria-label="Navegação principal">
        <div class="rail-brand">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                <path d="M12 2l9 5v7c0 7-6 9-9 11-3-2-9-4-9-11V7l9-5z"/>
                <path d="M12 13v5M9 15h6"/>
            </svg>
            <span class="rail-brand-name">CONTROLE<span>·</span>SISTEMA</span>
        </div>

        <div class="rail-nav">
            <?php navLink('index.php', 'index', 'Início', '<path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>'); ?>
        </div>

        <div class="rail-nav">
            <?php
            navLink('microsoft/contas/listar.php', 'microsoft', 'Microsoft', '<path d="M3 3h8v8H3zM13 3h8v8h-8zM3 13h8v8H3zM13 13h8v8h-8z"/>');
            navLink('telefonia/contas/listar.php', 'telefonia', 'Telefonia', '<path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/>');
            ?>
        </div>

        <div class="rail-nav">
            <div class="rail-section-label">Cadastros</div>
            <?php
            navLink('peps/listar.php', 'peps', 'PEPs / Projetos', '<path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>');
            navLink('dispositivos/listar.php', 'dispositivos', 'Dispositivos', '<rect x="3" y="4" width="18" height="12" rx="2"/><line x1="8" y1="20" x2="16" y2="20"/><line x1="12" y1="16" x2="12" y2="20"/>');
            ?>
        </div>

        <?php if ($ehAdmin): ?>
        <div class="rail-nav">
            <div class="rail-section-label">Administração</div>
            <?php
            navLink('usuarios/listar.php', 'usuarios', 'Usuários', '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/><path d="M12 1v6m8-4l-4 4M4 3l4 4"/>');
            navLink('contatos/listar.php', 'contatos', 'Contatos', '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>');
            navLink('smtp/index.php', 'smtp', 'SMTP', '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M4 6l8 6 8-6"/>');
            ?>
        </div>
        <?php endif; ?>

        <div class="rail-foot">
            <div class="avatar sm"><?= htmlspecialchars(userInitials(Auth::nome())) ?></div>
            <div>
                <div class="rail-user"><?= htmlspecialchars(Auth::nome()) ?></div>
                <span class="rail-role"><?= $ehAdmin ? 'Administrador' : 'Usuário' ?></span>
                <a href="<?= url('logout.php') ?>" class="rail-logout">Sair</a>
            </div>
        </div>
    </nav>

    <div class="main-col">
        <div class="topbar">
            <button type="button" class="hamburger-btn" id="railToggle" aria-label="Abrir menu" aria-expanded="false" aria-controls="rail">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
            </button>
            <div class="topbar-brand">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                    <path d="M12 2l9 5v7c0 7-6 9-9 11-3-2-9-4-9-11V7l9-5z"/>
                    <path d="M12 13v5M9 15h6"/>
                </svg>
                <span><?= e(Config::get('app.nome')) ?></span>
            </div>
            <div class="topbar-spacer"></div>
            <div class="avatar" title="<?= htmlspecialchars(Auth::nome()) ?>"><?= htmlspecialchars(userInitials(Auth::nome())) ?></div>
        </div>

        <div class="main-content">
            <?php foreach ($flashes as $msg): ?>
                <div class="alert alert-<?= e($msg['tipo']) ?> alert-dismissible fade show">
                    <?= $msg['texto'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endforeach; ?>
