<?php
// Painel simplificado para uso rápido no dia a dia: reúne, num só lugar, os hotspots e regras
// de firewall que um Administrador já liberou (mesma allowlist de rule_permissions usada nas
// telas completas de Hotspot/Firewall) — só com o comentário como título e um interruptor,
// sem os campos técnicos daquelas telas. Sempre mostra apenas o conjunto liberado, mesmo para
// Administrador, já que o propósito aqui é o atalho para os itens curados, não a lista completa.
$api = getActiveRouterAPI();
if (!$api) { echo "<div class='alert alert-danger'>Conexão falhou ao tentar se comunicar com o MikroTik.</div>"; return; }

$role = currentUserRole();
$routerId = (int)$_SESSION['active_router'];
$db = getDB();

$permStmtH = $db->prepare("SELECT rule_id FROM rule_permissions WHERE router_id = :r AND rule_type = 'hotspot'");
$permStmtH->bindValue(':r', $routerId, PDO::PARAM_INT);
$permStmtH->execute();
$permittedHotspot = array_flip($permStmtH->fetchAll(PDO::FETCH_COLUMN));

$permStmtF = $db->prepare("SELECT rule_id FROM rule_permissions WHERE router_id = :r AND rule_type = 'firewall'");
$permStmtF->bindValue(':r', $routerId, PDO::PARAM_INT);
$permStmtF->execute();
$permittedFirewall = array_flip($permStmtF->fetchAll(PDO::FETCH_COLUMN));

$routerNameStmt = $db->prepare("SELECT name FROM routers WHERE id = :r");
$routerNameStmt->bindValue(':r', $routerId, PDO::PARAM_INT);
$routerNameStmt->execute();
$routerLabel = $routerNameStmt->fetchColumn() ?: "Roteador #{$routerId}";

// Habilita/desabilita um hotspot ou uma regra de firewall a partir desta tela.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'], $_POST['action'], $_POST['kind']) && in_array($_POST['action'], ['enable', 'disable'], true)) {
    csrfVerify();
    $targetId = $_POST['id'];
    $kind = $_POST['kind'] === 'firewall' ? 'firewall' : 'hotspot';
    $permittedIds = $kind === 'firewall' ? $permittedFirewall : $permittedHotspot;

    // Usuário padrão só pode agir sobre itens explicitamente liberados pelo Administrador
    // (a tela já só exibe esses mesmos itens, mas o backend confere de novo por segurança).
    if ($role !== 'admin' && !isset($permittedIds[$targetId])) {
        http_response_code(403);
        die('Você não tem permissão para alterar este item.');
    }

    $disabled = $_POST['action'] === 'disable' ? 'yes' : 'no';
    $command = $kind === 'firewall' ? '/ip/firewall/filter/set' : '/ip/hotspot/set';
    $api->comm($command, ['.id' => $targetId, 'disabled' => $disabled]);

    $actionLabel = $_POST['action'] === 'disable' ? 'Desabilitou' : 'Habilitou';
    $itemLabel = $kind === 'firewall' ? 'regra de firewall' : 'hotspot';
    logActivity($kind, "{$actionLabel} {$itemLabel} (Ações)", "{$routerLabel} — item {$targetId}");
    header("Location: index.php?page=actions");
    exit;
}

// ---- Hotspots liberados ----
$api->write('/ip/hotspot/print');
$rawHotspot = $api->read();
$hotspots = [];
if (!empty($rawHotspot) && is_array($rawHotspot)) {
    if (isset($rawHotspot[0]) && is_array($rawHotspot[0]) && isset($rawHotspot[0]['.id']) && count($rawHotspot) === 1) {
        $hotspots = $rawHotspot;
    } else {
        foreach ($rawHotspot as $item) {
            if (is_array($item) && (isset($item['.id']) || isset($item['name']))) {
                $hotspots[] = $item;
            }
        }
    }
}
$hotspots = array_values(array_filter($hotspots, function ($h) use ($permittedHotspot) {
    $id = $h['.id'] ?? $h['name'] ?? null;
    return $id && isset($permittedHotspot[$id]);
}));

// ---- Regras de firewall liberadas ----
$api->write('/ip/firewall/filter/print');
$rawFirewall = $api->read();
$api->disconnect();
$firewallRules = is_array($rawFirewall) ? $rawFirewall : [];
$firewallRules = array_values(array_filter($firewallRules, function ($r) use ($permittedFirewall) {
    return is_array($r) && isset($r['.id']) && isset($permittedFirewall[$r['.id']]);
}));
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Ações</h1>
        <div class="page-sub"><?= htmlspecialchars($routerLabel) ?> · Itens liberados por um Administrador para uso rápido</div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header">Autenticação</div>
    <div class="card-body">
        <?php if (empty($hotspots)): ?>
            <p class="text-muted mb-0">Nenhum hotspot liberado para uso rápido<?= $role === 'admin' ? '. Libere a visibilidade em "Hotspot".' : ' ainda. Peça a um administrador para liberar.' ?></p>
        <?php else: ?>
            <div class="toggle-list">
                <?php foreach ($hotspots as $h): ?>
                    <?php
                        $internal_id = $h['.id'] ?? $h['name'] ?? null;
                        if (!$internal_id) continue;
                        $isDisabled = isset($h['disabled']) && in_array(strtolower((string)$h['disabled']), ['true', 'yes'], true);
                        $title = trim($h['comment'] ?? '') !== '' ? $h['comment'] : ($h['name'] ?? $internal_id);
                    ?>
                    <div class="toggle-row <?= $isDisabled ? 'is-disabled' : '' ?>">
                        <span class="toggle-row-title"><?= htmlspecialchars($title) ?></span>
                        <form method="POST" class="m-0">
                            <?= csrfField() ?>
                            <input type="hidden" name="kind" value="hotspot">
                            <input type="hidden" name="id" value="<?= htmlspecialchars($internal_id) ?>">
                            <input type="hidden" name="action" value="<?= $isDisabled ? 'enable' : 'disable' ?>">
                            <button type="submit" class="toggle-switch-btn" title="<?= $isDisabled ? 'Habilitar' : 'Desabilitar' ?>" aria-pressed="<?= $isDisabled ? 'false' : 'true' ?>">
                                <span class="mini-switch <?= $isDisabled ? '' : 'is-on' ?>"></span>
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">Bloqueios</div>
    <div class="card-body">
        <?php if (empty($firewallRules)): ?>
            <p class="text-muted mb-0">Nenhuma regra de firewall liberada para uso rápido<?= $role === 'admin' ? '. Libere a visibilidade em "Firewall".' : ' ainda. Peça a um administrador para liberar.' ?></p>
        <?php else: ?>
            <div class="toggle-list toggle-list-striped">
                <?php foreach ($firewallRules as $r): ?>
                    <?php
                        $isDisabled = isset($r['disabled']) && in_array(strtolower((string)$r['disabled']), ['true', 'yes'], true);
                        $title = trim($r['comment'] ?? '') !== '' ? $r['comment'] : ('ID ' . $r['.id']);
                    ?>
                    <div class="toggle-row <?= $isDisabled ? 'is-disabled' : '' ?>">
                        <span class="toggle-row-title"><?= htmlspecialchars($title) ?></span>
                        <form method="POST" class="m-0">
                            <?= csrfField() ?>
                            <input type="hidden" name="kind" value="firewall">
                            <input type="hidden" name="id" value="<?= htmlspecialchars($r['.id']) ?>">
                            <input type="hidden" name="action" value="<?= $isDisabled ? 'enable' : 'disable' ?>">
                            <button type="submit" class="toggle-switch-btn" title="<?= $isDisabled ? 'Habilitar' : 'Desabilitar' ?>" aria-pressed="<?= $isDisabled ? 'false' : 'true' ?>">
                                <span class="mini-switch <?= $isDisabled ? '' : 'is-on' ?>"></span>
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
