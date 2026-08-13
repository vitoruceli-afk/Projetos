<?php
$db = getDB();
// O seletor de roteador só lista (e só aceita trocar para) os MikroTiks liberados para este
// usuário em Usuários > Roteadores — os demais nem aparecem aqui.
$allowedRouterIdsNow = allowedRouterIds();
$routersList = empty($allowedRouterIdsNow) ? [] : (function () use ($db, $allowedRouterIdsNow) {
    $placeholders = implode(',', array_fill(0, count($allowedRouterIdsNow), '?'));
    $stmt = $db->prepare("SELECT id, name, location FROM routers WHERE id IN ({$placeholders}) ORDER BY name ASC");
    $stmt->execute($allowedRouterIdsNow);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
})();

if (isset($_GET['select_router'])) {
    $reqRouterId = (int)$_GET['select_router'];
    if (hasRouterAccess($reqRouterId)) {
        $_SESSION['active_router'] = $reqRouterId;
    }
    header("Location: index.php");
    exit;
}
$active_router_id = $_SESSION['active_router'] ?? null;
$is_admin = isAdmin();

$activeRouterLabel = null;
foreach ($routersList as $r) {
    if ($active_router_id == $r['id']) {
        $activeRouterLabel = $r['name'];
        break;
    }
}

function navLink($targetPage, $currentPage, $label, $iconPath) {
    $active = $targetPage === $currentPage ? ' is-active' : '';
    echo '<a class="rail-link' . $active . '" href="index.php?page=' . $targetPage . '">'
        . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">' . $iconPath . '</svg>'
        . htmlspecialchars($label) . '</a>';
}

// Iniciais para o avatar (ex: "vitor.uceli" -> "VU"; sem separador, pega as 2 primeiras letras).
function userInitials($username) {
    $parts = array_values(array_filter(preg_split('/[.\s_-]+/', trim((string)$username))));
    if (count($parts) >= 2) {
        return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
    }
    return strtoupper(mb_substr((string)$username, 0, 2));
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mikrotik Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="views/theme.css">
</head>
<body>
<div class="app-shell">
    <div class="rail-backdrop" id="railBackdrop"></div>
    <nav class="rail" id="rail" aria-label="Navegação principal">
        <div class="rail-institution">
            <img src="https://www.faesa.br/wp-content/uploads/2024/08/Camada_1.svg" alt="FAESA Centro Universitário" class="rail-institution-logo">
        </div>
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
            navLink('hotspot', $page, 'Autenticação', '<path d="M4 9a12 12 0 0116 0M7 12.5a8 8 0 0110 0M10 16a4 4 0 014 0"/><circle cx="12" cy="19" r="1" fill="currentColor" stroke="none"/>');
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
                    <circle cx="12" cy="12" r="2.1" fill="currentColor" stroke="none"/>
                    <circle cx="4" cy="6" r="1.6" fill="currentColor" stroke="none"/>
                    <circle cx="20" cy="6" r="1.6" fill="currentColor" stroke="none"/>
                    <circle cx="4" cy="18" r="1.6" fill="currentColor" stroke="none"/>
                    <circle cx="20" cy="18" r="1.6" fill="currentColor" stroke="none"/>
                    <path d="M12 12L4 6M12 12L20 6M12 12L4 18M12 12L20 18"/>
                </svg>
                <span>MIKROTIK MGR</span>
            </div>

            <div class="router-switch-wrap">
                <button type="button" class="router-switch" id="routerSwitchBtn" aria-haspopup="true" aria-expanded="false">
                    <span class="dot <?= $active_router_id ? 'online' : 'offline' ?>"></span>
                    <span class="rs-name"><?= $activeRouterLabel ? htmlspecialchars($activeRouterLabel) : 'Selecione o Mikrotik' ?></span>
                    <svg class="rs-chev" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
                </button>
                <div class="router-dropdown" id="routerDropdown">
                    <div class="rd-label">Selecionar Mikrotik</div>
                    <?php if (empty($routersList)): ?>
                        <div class="rd-empty">Nenhum roteador liberado para o seu usuário.</div>
                    <?php else: ?>
                        <?php foreach ($routersList as $row): ?>
                            <a class="rd-item <?= $active_router_id == $row['id'] ? 'is-current' : '' ?>" href="index.php?select_router=<?= (int)$row['id'] ?>">
                                <div>
                                    <div class="rd-item-name"><?= htmlspecialchars($row['name']) ?></div>
                                    <div class="rd-item-loc"><?= htmlspecialchars($row['location']) ?></div>
                                </div>
                                <?php if ($active_router_id == $row['id']): ?><span class="rd-item-tag">atual</span><?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="topbar-spacer"></div>
            <div class="avatar" title="<?= htmlspecialchars($_SESSION['user_logged_in']) ?>"><?= htmlspecialchars(userInitials($_SESSION['user_logged_in'])) ?></div>
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
        // Fecha o menu ao navegar para outra página no mobile (a gaveta não deve persistir aberta).
        rail.querySelectorAll('a.rail-link, a.rail-logout').forEach(function (link) {
            link.addEventListener('click', closeRail);
        });
    }

    // Seletor de Mikrotik ativo (dropdown na topbar)
    var rsBtn = document.getElementById('routerSwitchBtn');
    var rsPanel = document.getElementById('routerDropdown');
    if (rsBtn && rsPanel) {
        rsBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            var willOpen = !rsPanel.classList.contains('is-open');
            rsPanel.classList.toggle('is-open', willOpen);
            rsBtn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });
        document.addEventListener('click', function () {
            rsPanel.classList.remove('is-open');
            rsBtn.setAttribute('aria-expanded', 'false');
        });
    }
})();
</script>
</body>
</html>
