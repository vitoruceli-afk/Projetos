<?php
$db = getDB();
$routers = $db->query("SELECT id, name, location FROM routers");
if (isset($_GET['select_router'])) {
    $_SESSION['active_router'] = (int)$_GET['select_router'];
    header("Location: index.php");
    exit;
}
$active_router_id = $_SESSION['active_router'] ?? null;
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
    <title>Mikrotik Manager</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="views/theme.css">
</head>
<body>
<div class="app-shell">
    <nav class="rail" aria-label="Navegação principal">
        <div class="rail-brand">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                <circle cx="12" cy="12" r="2.1" fill="currentColor" stroke="none"/>
                <circle cx="4" cy="6" r="1.6" fill="currentColor" stroke="none"/>
                <circle cx="20" cy="6" r="1.6" fill="currentColor" stroke="none"/>
                <circle cx="4" cy="18" r="1.6" fill="currentColor" stroke="none"/>
                <circle cx="20" cy="18" r="1.6" fill="currentColor" stroke="none"/>
                <path d="M12 12L4 6M12 12L20 6M12 12L4 18M12 12L20 18"/>
            </svg>
            <span class="rail-brand-name">MIKROTIK<span>·</span>MGR</span>
        </div>

        <div class="rail-nav">
            <?php
            navLink('dashboard', $page, 'Dashboard', '<path d="M4 13l4-4 4 3 8-9M4 20h16"/>');
            navLink('hotspot', $page, 'Hotspots', '<path d="M4 9a12 12 0 0116 0M7 12.5a8 8 0 0110 0M10 16a4 4 0 014 0"/><circle cx="12" cy="19" r="1" fill="currentColor" stroke="none"/>');
            navLink('firewall', $page, 'Firewall', '<path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/>');
            navLink('diagnostics', $page, 'Diagnóstico', '<path d="M3 12h4l2-7 4 14 2-7h6"/>');
            ?>
        </div>

        <?php if ($is_admin): ?>
        <div class="rail-nav">
            <div class="rail-section-label">Administração</div>
            <?php
            navLink('routers', $page, 'Roteadores', '<rect x="4" y="3.5" width="16" height="6" rx="1"/><rect x="4" y="14.5" width="16" height="6" rx="1"/><circle cx="7.5" cy="6.5" r=".6" fill="currentColor" stroke="none"/><circle cx="7.5" cy="17.5" r=".6" fill="currentColor" stroke="none"/>');
            navLink('bypass', $page, 'Bypass', '<path d="M5 5l6 7-6 7"/><path d="M13 5l6 7-6 7"/>');
            navLink('users', $page, 'Usuários', '<circle cx="9" cy="8" r="3"/><path d="M3.5 20c0-3.5 2.5-6 5.5-6s5.5 2.5 5.5 6"/><path d="M16 8.2a3 3 0 010 5.6M19 20c0-2.8-1.6-5-3.5-5.8"/>');
            navLink('logs', $page, 'Logs', '<path d="M5 3h9l5 5v13H5z"/><path d="M9 12h6M9 16h6M9 8h3"/>');
            ?>
        </div>
        <?php endif; ?>

        <div class="rail-foot">
            <div class="rail-user"><?= htmlspecialchars($_SESSION['user_logged_in']) ?></div>
            <span class="rail-role"><?= $is_admin ? 'Administrador' : 'Usuário Padrão' ?></span>
            <a href="logout.php" class="rail-logout">Sair</a>
        </div>
    </nav>

    <div class="main-col">
        <div class="topbar">
            <form class="router-picker" method="GET" action="index.php">
                <span class="dot <?= $active_router_id ? 'online' : 'offline' ?>"></span>
                <select name="select_router" class="form-select" onchange="this.form.submit()">
                    <option value="">-- Selecione o Mikrotik --</option>
                    <?php while ($row = $routers->fetch(PDO::FETCH_ASSOC)): ?>
                        <option value="<?= $row['id'] ?>" <?= $active_router_id == $row['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($row['name'] . ' (' . $row['location'] . ')') ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </form>
        </div>

        <div class="main-content">
            <?php
            // Permite acessar Dashboard e Gerenciar Roteadores sem selecionar um MikroTik
            $pages_without_router = ['dashboard', 'routers', 'users', 'logs'];

            if (!$active_router_id && !in_array($page, $pages_without_router)) {
                echo '<div class="alert alert-warning">Por favor, selecione um Mikrotik no menu superior para gerenciar.</div>';
            } else {
                include "views/{$page}.php";
            }
            ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
