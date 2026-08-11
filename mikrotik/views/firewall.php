<?php
$api = getActiveRouterAPI();
if (!$api) { echo "<div class='alert alert-danger'>Conexão falhou ao tentar se comunicar com o MikroTik.</div>"; return; }

$role = currentUserRole();
$routerId = (int)$_SESSION['active_router'];
$db = getDB();

$permStmt = $db->prepare("SELECT rule_id FROM rule_permissions WHERE router_id = :r AND rule_type = 'firewall'");
$permStmt->bindValue(':r', $routerId, PDO::PARAM_INT);
$permStmt->execute();
$permittedIds = array_flip($permStmt->fetchAll(PDO::FETCH_COLUMN));

$routerNameStmt = $db->prepare("SELECT name FROM routers WHERE id = :r");
$routerNameStmt->bindValue(':r', $routerId, PDO::PARAM_INT);
$routerNameStmt->execute();
$routerLabel = $routerNameStmt->fetchColumn() ?: "Roteador #{$routerId}";
$bulkError = '';

// Processa a ativação/desativação da regra se houver um POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'], $_POST['action']) && in_array($_POST['action'], ['enable', 'disable'])) {
    csrfVerify();
    $targetId = $_POST['id'];

    // Usuário padrão só pode agir sobre regras explicitamente liberadas pelo Administrador.
    if ($role !== 'admin' && !isset($permittedIds[$targetId])) {
        http_response_code(403);
        die('Você não tem permissão para alterar esta regra.');
    }

    $disabled = $_POST['action'] === 'disable' ? 'yes' : 'no';
    $api->comm('/ip/firewall/filter/set', ['.id' => $targetId, 'disabled' => $disabled]);
    logActivity('firewall', $_POST['action'] === 'disable' ? 'Desabilitou regra de firewall' : 'Habilitou regra de firewall', "{$routerLabel} — regra {$targetId}");
    header("Location: index.php?page=firewall");
    exit;
}

// Concede/revoga a visibilidade desta regra para o perfil Usuário Padrão (somente Administrador)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'], $_POST['permission_action'])) {
    csrfVerify();
    requireAdmin();
    $targetId = $_POST['id'];

    if ($_POST['permission_action'] === 'grant') {
        $stmt = $db->prepare("INSERT IGNORE INTO rule_permissions (router_id, rule_type, rule_id, granted_by) VALUES (:r, 'firewall', :id, :by)");
        $stmt->bindValue(':r', $routerId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $targetId);
        $stmt->bindValue(':by', $_SESSION['user_logged_in'] ?? '');
        $stmt->execute();
        logActivity('firewall', 'Permitiu regra de firewall para Usuário Padrão', "{$routerLabel} — regra {$targetId}");
    } else {
        $stmt = $db->prepare("DELETE FROM rule_permissions WHERE router_id = :r AND rule_type = 'firewall' AND rule_id = :id");
        $stmt->bindValue(':r', $routerId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $targetId);
        $stmt->execute();
        logActivity('firewall', 'Revogou permissão de regra de firewall do Usuário Padrão', "{$routerLabel} — regra {$targetId}");
    }
    header("Location: index.php?page=firewall");
    exit;
}

// Ação em massa: habilitar/desabilitar várias regras de uma vez
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'], $_POST['ids']) && in_array($_POST['bulk_action'], ['enable', 'disable'])) {
    csrfVerify();
    $ids = array_values(array_filter((array)$_POST['ids']));

    // Usuário padrão só pode agir sobre regras explicitamente liberadas pelo Administrador.
    if ($role !== 'admin') {
        $ids = array_values(array_intersect($ids, array_keys($permittedIds)));
    }

    if (empty($ids)) {
        $bulkError = 'Nenhuma regra válida selecionada.';
    } else {
        $disabled = $_POST['bulk_action'] === 'disable' ? 'yes' : 'no';
        foreach ($ids as $targetId) {
            $api->comm('/ip/firewall/filter/set', ['.id' => $targetId, 'disabled' => $disabled]);
        }
        logActivity('firewall', $_POST['bulk_action'] === 'disable' ? 'Desabilitou regras de firewall em massa' : 'Habilitou regras de firewall em massa', "{$routerLabel} — " . count($ids) . " regra(s): " . implode(', ', $ids));
        header("Location: index.php?page=firewall");
        exit;
    }
}

// Ação em massa: conceder/revogar visibilidade de várias regras para o Usuário Padrão (Administrador)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_permission_action'], $_POST['ids'])) {
    csrfVerify();
    requireAdmin();
    $ids = array_values(array_filter((array)$_POST['ids']));

    if (empty($ids)) {
        $bulkError = 'Nenhuma regra válida selecionada.';
    } else {
        if ($_POST['bulk_permission_action'] === 'grant') {
            $stmt = $db->prepare("INSERT IGNORE INTO rule_permissions (router_id, rule_type, rule_id, granted_by) VALUES (:r, 'firewall', :id, :by)");
            foreach ($ids as $targetId) {
                $stmt->execute([':r' => $routerId, ':id' => $targetId, ':by' => $_SESSION['user_logged_in'] ?? '']);
            }
            logActivity('firewall', 'Permitiu regras de firewall em massa para Usuário Padrão', "{$routerLabel} — " . count($ids) . " regra(s): " . implode(', ', $ids));
        } else {
            $stmt = $db->prepare("DELETE FROM rule_permissions WHERE router_id = :r AND rule_type = 'firewall' AND rule_id = :id");
            foreach ($ids as $targetId) {
                $stmt->execute([':r' => $routerId, ':id' => $targetId]);
            }
            logActivity('firewall', 'Revogou permissão em massa de regras de firewall do Usuário Padrão', "{$routerLabel} — " . count($ids) . " regra(s): " . implode(', ', $ids));
        }
        header("Location: index.php?page=firewall");
        exit;
    }
}

// CORREÇÃO: Removido o filtro inválido e aplicada a chamada correta para ler todo o buffer
$api->write('/ip/firewall/filter/print');
$rules = $api->read();
$api->disconnect();

if (!is_array($rules)) {
    $rules = [];
}

// Usuário padrão só enxerga as regras liberadas pelo Administrador.
if ($role !== 'admin') {
    $rules = array_values(array_filter($rules, function ($r) use ($permittedIds) {
        return is_array($r) && isset($r['.id']) && isset($permittedIds[$r['.id']]);
    }));
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="page-title">Regras de Firewall Filter (Completo)</h1>
    <span class="badge bg-secondary">Total: <?= count($rules) ?> regras</span>
</div>

<?php if ($role !== 'admin'): ?>
    <div class="alert alert-info py-2">Exibindo apenas as regras liberadas pelo Administrador para o seu perfil.</div>
<?php endif; ?>

<?php if ($bulkError): ?>
    <div class="alert alert-danger py-2"><?= htmlspecialchars($bulkError) ?></div>
<?php endif; ?>

<form method="POST" id="bulkFirewallForm">
    <?= csrfField() ?>
</form>

<?php if (!empty($rules)): ?>
<div class="entity-list-toolbar">
    <input type="checkbox" class="form-check-input" id="selectAllFirewall" title="Selecionar todas">
    <span class="elt-label"><i class="bi bi-check2-square"></i> Ações em massa</span>
    <button type="submit" form="bulkFirewallForm" name="bulk_action" value="enable" class="btn btn-sm btn-outline-success" onclick="return confirm('Habilitar todas as regras selecionadas?');">Habilitar Selecionadas</button>
    <button type="submit" form="bulkFirewallForm" name="bulk_action" value="disable" class="btn btn-sm btn-outline-danger" onclick="return confirm('Desabilitar todas as regras selecionadas?');">Desabilitar Selecionadas</button>
    <?php if ($role === 'admin'): ?>
        <span class="vr mx-1"></span>
        <button type="submit" form="bulkFirewallForm" name="bulk_permission_action" value="grant" class="btn btn-sm btn-outline-primary">Permitir p/ Padrão</button>
        <button type="submit" form="bulkFirewallForm" name="bulk_permission_action" value="revoke" class="btn btn-sm btn-outline-secondary">Revogar de Padrão</button>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (empty($rules)): ?>
    <div class="card">
        <div class="card-body text-center text-muted py-4">Nenhuma regra de firewall filter encontrada<?= $role === 'admin' ? '.' : ' liberada para o seu perfil.' ?></div>
    </div>
<?php else: ?>
    <div class="entity-list">
        <?php foreach ($rules as $r): ?>
            <?php
                // Ignora blocos de resposta da API que não sejam dados de regras válidas (como mensagens de !done)
                if (!is_array($r) || !isset($r['.id'])) continue;

                $isDisabled = isset($r['disabled']) && in_array(strtolower((string)$r['disabled']), ['true', 'yes']);
                $isPermitted = isset($permittedIds[$r['.id']]);

                $actionClass = match($r['action'] ?? '') {
                    'accept' => 'tag-accept',
                    'drop' => 'tag-drop',
                    'reject' => 'tag-reject',
                    default => 'badge bg-info'
                };
            ?>
            <div class="entity-card <?= $isDisabled ? 'is-disabled' : '' ?>">
                <div class="entity-card-head">
                    <div class="entity-title-wrap">
                        <input type="checkbox" class="form-check-input entity-check firewall-row-check" name="ids[]" value="<?= htmlspecialchars($r['.id']) ?>" form="bulkFirewallForm">
                        <div>
                            <div class="entity-title">
                                <?php if (!empty($r['comment'])): ?>
                                    <?= htmlspecialchars($r['comment']) ?>
                                <?php else: ?>
                                    <span class="text-muted" style="font-style: italic; font-weight: 400;">(Sem comentário / Sem nome)</span>
                                <?php endif; ?>
                            </div>
                            <div class="entity-sub mono">ID <?= htmlspecialchars($r['.id']) ?></div>
                        </div>
                    </div>
                    <div class="entity-badges">
                        <span class="badge bg-secondary"><?= htmlspecialchars($r['chain'] ?? '-') ?></span>
                        <span class="<?= $actionClass ?>"><?= htmlspecialchars($r['action'] ?? 'unknown') ?></span>
                        <?php if ($isDisabled): ?>
                            <span class="badge bg-secondary">Desabilitada</span>
                        <?php else: ?>
                            <span class="badge bg-success">Ativa</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="entity-grid">
                    <div>
                        <div class="entity-field-label">Src. Address</div>
                        <div class="entity-field-value mono"><?= htmlspecialchars($r['src-address'] ?? 'Qualquer (0.0.0.0/0)') ?></div>
                    </div>
                    <div>
                        <div class="entity-field-label">Dst. Address</div>
                        <div class="entity-field-value mono"><?= htmlspecialchars($r['dst-address'] ?? 'Qualquer (0.0.0.0/0)') ?></div>
                    </div>
                    <div>
                        <div class="entity-field-label">Protocolo</div>
                        <div class="entity-field-value text-uppercase"><?= htmlspecialchars($r['protocol'] ?? 'todos') ?></div>
                    </div>
                    <div>
                        <div class="entity-field-label">Dst. Port</div>
                        <div class="entity-field-value mono"><?= htmlspecialchars($r['dst-port'] ?? '-') ?></div>
                    </div>
                    <div class="full">
                        <div class="entity-field-label">Interfaces (in / out)</div>
                        <div class="entity-field-value mono"><?= htmlspecialchars($r['in-interface'] ?? $r['in-interface-list'] ?? 'any') ?> / <?= htmlspecialchars($r['out-interface'] ?? $r['out-interface-list'] ?? 'any') ?></div>
                    </div>
                </div>

                <?php if ($role === 'admin'): ?>
                <div class="entity-visible-row">
                    <span><?= $isPermitted ? 'Visível para Usuário Padrão' : 'Oculta para Usuário Padrão' ?></span>
                    <form method="POST" class="m-0">
                        <?= csrfField() ?>
                        <input type="hidden" name="id" value="<?= htmlspecialchars($r['.id']) ?>">
                        <?php if ($isPermitted): ?>
                            <input type="hidden" name="permission_action" value="revoke">
                            <button type="submit" class="btn btn-sm btn-outline-secondary py-1" style="font-size: 0.75rem;">Revogar</button>
                        <?php else: ?>
                            <input type="hidden" name="permission_action" value="grant">
                            <button type="submit" class="btn btn-sm btn-outline-primary py-1" style="font-size: 0.75rem;">Permitir</button>
                        <?php endif; ?>
                    </form>
                </div>
                <?php endif; ?>

                <div class="entity-actions">
                    <div></div>
                    <div class="entity-actions-buttons">
                        <form method="POST" class="m-0">
                            <?= csrfField() ?>
                            <input type="hidden" name="id" value="<?= htmlspecialchars($r['.id']) ?>">
                            <?php if ($isDisabled): ?>
                                <input type="hidden" name="action" value="enable">
                                <button class="btn btn-sm btn-outline-success" title="Habilitar Regra"><i class="bi bi-play-fill"></i> Habilitar</button>
                            <?php else: ?>
                                <input type="hidden" name="action" value="disable">
                                <button class="btn btn-sm btn-outline-danger" title="Desabilitar Regra"><i class="bi bi-pause-fill"></i> Desabilitar</button>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<script>
document.getElementById('selectAllFirewall')?.addEventListener('change', function () {
    document.querySelectorAll('.firewall-row-check').forEach(function (cb) { cb.checked = this.checked; }, this);
});
</script>
