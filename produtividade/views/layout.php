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
    <title>Produtividade · ActivityWatch</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="views/theme.css?v=<?= @filemtime(__DIR__ . '/theme.css') ?: time() ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
</head>
<body>
<div class="app-shell">
    <div class="rail-backdrop" id="railBackdrop"></div>
    <nav class="rail" id="rail" aria-label="Navegação principal">
        <div class="rail-brand">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                <circle cx="12" cy="12" r="9"/>
                <path d="M12 7v5l3.2 2" stroke-width="2"/>
            </svg>
            <span class="rail-brand-name">PRODUTIVIDADE<span>·</span>AW</span>
        </div>

        <div class="rail-nav">
            <?php
            navLink('dashboard', $page, 'Dashboard', '<path d="M4 13l4-4 4 3 8-9M4 20h16"/>');
            navLink('eventos', $page, 'Eventos', '<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3.5v3M16 3.5v3"/>');
            ?>
        </div>

        <?php if ($is_admin): ?>
        <div class="rail-nav">
            <div class="rail-section-label">Administração</div>
            <?php
            navLink('maquinas', $page, 'Máquinas', '<rect x="3" y="4" width="18" height="12" rx="1.5"/><path d="M8 20h8M12 16v4"/>');
            navLink('categorias', $page, 'Categorias', '<path d="M4 6h6M4 12h6M4 18h6"/><circle cx="17" cy="6" r="2.3"/><circle cx="17" cy="12" r="2.3"/><circle cx="17" cy="18" r="2.3"/>');
            navLink('usuarios', $page, 'Usuários', '<circle cx="9" cy="8" r="3"/><path d="M3.5 20c0-3.5 2.5-6 5.5-6s5.5 2.5 5.5 6"/><path d="M16 8.2a3 3 0 010 5.6M19 20c0-2.8-1.6-5-3.5-5.8"/>');
            navLink('ad', $page, 'Active Directory', '<rect x="4" y="3.5" width="16" height="6" rx="1.5"/><rect x="4" y="14.5" width="16" height="6" rx="1.5"/><circle cx="8" cy="6.5" r=".6" fill="currentColor" stroke="none"/><circle cx="8" cy="17.5" r=".6" fill="currentColor" stroke="none"/>');
            navLink('logs', $page, 'Logs de Sincronização', '<path d="M5 3h9l5 5v13H5z"/><path d="M9 12h6M9 16h6M9 8h3"/>');
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
                    <circle cx="12" cy="12" r="9"/>
                    <path d="M12 7v5l3.2 2" stroke-width="2"/>
                </svg>
                <span>PRODUTIVIDADE AW</span>
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
