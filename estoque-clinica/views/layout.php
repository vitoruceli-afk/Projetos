<?php
$is_admin = isAdmin();

function navLink($targetPage, $currentPage, $label, $iconPath) {
    $active = $targetPage === $currentPage ? ' is-active' : '';
    echo '<a class="rail-link' . $active . '" href="index.php?page=' . $targetPage . '">'
        . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">' . $iconPath . '</svg>'
        . htmlspecialchars($label) . '</a>';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Estoque Clínica</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="views/theme.css?v=<?= @filemtime(__DIR__ . '/theme.css') ?: time() ?>">
</head>
<body>
<div class="app-shell">
    <div class="rail-backdrop" id="railBackdrop"></div>
    <nav class="rail" id="rail" aria-label="Navegação principal">
        <div class="rail-brand">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                <path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/>
                <path d="M12 8v6M9 11h6" stroke-width="2"/>
            </svg>
            <span class="rail-brand-name">ESTOQUE<span>·</span>CLÍNICA</span>
        </div>

        <?php if ($is_admin): ?>
        <div class="rail-nav">
            <?php navLink('dashboard', $page, 'Dashboard', '<path d="M4 13l4-4 4 3 8-9M4 20h16"/>'); ?>
        </div>
        <?php endif; ?>

        <div class="rail-nav">
            <?php
            navLink('movimentacao', $page, 'Entrada / Saída', '<path d="M4 7l4-4 4 4M8 3v12M20 17l-4 4-4-4M16 21V9"/>');
            navLink('estoque', $page, 'Estoque', '<path d="M3.5 7.5l8.5-5 8.5 5-8.5 5-8.5-5z"/><path d="M3.5 7.5v9l8.5 5 8.5-5v-9"/><path d="M12 12.5v9"/>');
            navLink('relatorios', $page, 'Relatórios', '<path d="M5 3h9l5 5v13H5z"/><path d="M9 12h6M9 16h6M9 8h3"/>');
            ?>
        </div>

        <?php if ($is_admin): ?>
        <div class="rail-nav">
            <div class="rail-section-label">Cadastros</div>
            <?php
            navLink('medicamentos', $page, 'Medicamentos', '<path d="M10.5 3.5h3a2 2 0 012 2V7h-7V5.5a2 2 0 012-2z"/><rect x="4" y="7" width="16" height="13.5" rx="2"/><path d="M9 13h6M12 10v6"/>');
            navLink('insumos', $page, 'Insumos', '<path d="M20.5 7.5l-8.5-5-8.5 5 8.5 5 8.5-5z"/><path d="M3.5 7.5v9l8.5 5 8.5-5v-9"/><path d="M12 12.5v9"/>');
            navLink('pacientes', $page, 'Pacientes', '<circle cx="12" cy="8" r="3.5"/><path d="M4.5 20c0-4.14 3.36-7.5 7.5-7.5s7.5 3.36 7.5 7.5"/>');
            ?>
        </div>
        <div class="rail-nav">
            <div class="rail-section-label">Administração</div>
            <?php
            navLink('usuarios', $page, 'Usuários', '<circle cx="9" cy="8" r="3"/><path d="M3.5 20c0-3.5 2.5-6 5.5-6s5.5 2.5 5.5 6"/><path d="M16 8.2a3 3 0 010 5.6M19 20c0-2.8-1.6-5-3.5-5.8"/>');
            navLink('notificacoes', $page, 'Notificações', '<path d="M12 3.5a5.5 5.5 0 00-5.5 5.5v3.5L4.5 16h15L17.5 12.5V9A5.5 5.5 0 0012 3.5z"/><path d="M9.5 19a2.5 2.5 0 005 0"/>');
            navLink('logs', $page, 'Log', '<path d="M6 3.5h9l4 4V20a1 1 0 01-1 1H6a1 1 0 01-1-1V4.5a1 1 0 011-1z"/><path d="M15 3.5V8h4"/><path d="M8 12h8M8 15.5h8M8 8.5h3"/>');
            ?>
        </div>
        <?php endif; ?>

        <div class="rail-foot">
            <div class="avatar sm"><?= htmlspecialchars(userInitials($_SESSION['user_logged_in'])) ?></div>
            <div>
                <div class="rail-user"><?= htmlspecialchars($_SESSION['user_logged_in']) ?></div>
                <span class="rail-role"><?= $is_admin ? 'Administrador' : 'Usuário Padrão' ?></span>
                <a href="logout.php" class="rail-logout">Sair</a>
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
                    <path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/>
                    <path d="M12 8v6M9 11h6" stroke-width="2"/>
                </svg>
                <span>ESTOQUE CLÍNICA</span>
            </div>
            <div class="topbar-spacer"></div>
            <div class="avatar" title="<?= htmlspecialchars($_SESSION['user_logged_in']) ?>"><?= htmlspecialchars(userInitials($_SESSION['user_logged_in'])) ?></div>
        </div>

        <div class="main-content">
            <?php include "views/{$page}.php"; ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var rail = document.getElementById('rail');
    var backdrop = document.getElementById('railBackdrop');
    var toggle = document.getElementById('railToggle');
    if (rail && backdrop && toggle) {
        var openRail = function () {
            rail.classList.add('is-open');
            backdrop.classList.add('is-visible');
            toggle.setAttribute('aria-expanded', 'true');
        };
        var closeRail = function () {
            rail.classList.remove('is-open');
            backdrop.classList.remove('is-visible');
            toggle.setAttribute('aria-expanded', 'false');
        };
        toggle.addEventListener('click', function () {
            rail.classList.contains('is-open') ? closeRail() : openRail();
        });
        backdrop.addEventListener('click', closeRail);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeRail();
        });
        rail.querySelectorAll('a.rail-link, a.rail-logout').forEach(function (link) {
            link.addEventListener('click', closeRail);
        });
    }
})();
</script>
</body>
</html>
