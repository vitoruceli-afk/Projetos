<?php
$api = getActiveRouterAPI();
if (!$api) { echo "<div class='alert alert-danger'>Conexão falhou ao tentar se comunicar com o MikroTik.</div>"; return; }

$role = currentUserRole();
$routerId = (int)$_SESSION['active_router'];
$db = getDB();

$permStmt = $db->prepare("SELECT rule_id FROM rule_permissions WHERE router_id = :r AND rule_type = 'hotspot'");
$permStmt->bindValue(':r', $routerId, PDO::PARAM_INT);
$permStmt->execute();
$permittedIds = array_flip($permStmt->fetchAll(PDO::FETCH_COLUMN));

$routerNameStmt = $db->prepare("SELECT name FROM routers WHERE id = :r");
$routerNameStmt->bindValue(':r', $routerId, PDO::PARAM_INT);
$routerNameStmt->execute();
$routerLabel = $routerNameStmt->fetchColumn() ?: "Roteador #{$routerId}";
$bulkError = '';

// Processa a ativação/desativação do Hotspot Server se houver um POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'], $_POST['action']) && in_array($_POST['action'], ['enable', 'disable'])) {
    csrfVerify();
    $targetId = $_POST['id'];

    // Usuário padrão só pode agir sobre itens explicitamente liberados pelo Administrador.
    if ($role !== 'admin' && !isset($permittedIds[$targetId])) {
        http_response_code(403);
        die('Você não tem permissão para alterar este item.');
    }

    $disabled = $_POST['action'] === 'disable' ? 'yes' : 'no';
    // Garante o comando correto de alteração mapeando o ID interno (.id)
    $api->comm('/ip/hotspot/set', ['.id' => $targetId, 'disabled' => $disabled]);
    logActivity('hotspot', $_POST['action'] === 'disable' ? 'Desabilitou hotspot' : 'Habilitou hotspot', "{$routerLabel} — item {$targetId}");
    header("Location: index.php?page=hotspot");
    exit;
}

// Concede/revoga a visibilidade deste hotspot para o perfil Usuário Padrão (somente Administrador)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'], $_POST['permission_action'])) {
    csrfVerify();
    requireAdmin();
    $targetId = $_POST['id'];

    if ($_POST['permission_action'] === 'grant') {
        $stmt = $db->prepare("INSERT IGNORE INTO rule_permissions (router_id, rule_type, rule_id, granted_by) VALUES (:r, 'hotspot', :id, :by)");
        $stmt->bindValue(':r', $routerId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $targetId);
        $stmt->bindValue(':by', $_SESSION['user_logged_in'] ?? '');
        $stmt->execute();
        logActivity('hotspot', 'Permitiu hotspot para Usuário Padrão', "{$routerLabel} — item {$targetId}");
    } else {
        $stmt = $db->prepare("DELETE FROM rule_permissions WHERE router_id = :r AND rule_type = 'hotspot' AND rule_id = :id");
        $stmt->bindValue(':r', $routerId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $targetId);
        $stmt->execute();
        logActivity('hotspot', 'Revogou permissão de hotspot do Usuário Padrão', "{$routerLabel} — item {$targetId}");
    }
    header("Location: index.php?page=hotspot");
    exit;
}

// Ação em massa: habilitar/desabilitar vários servidores de uma vez
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'], $_POST['ids']) && in_array($_POST['bulk_action'], ['enable', 'disable'])) {
    csrfVerify();
    $ids = array_values(array_filter((array)$_POST['ids']));

    // Usuário padrão só pode agir sobre itens explicitamente liberados pelo Administrador.
    if ($role !== 'admin') {
        $ids = array_values(array_intersect($ids, array_keys($permittedIds)));
    }

    if (empty($ids)) {
        $bulkError = 'Nenhum item válido selecionado.';
    } else {
        $disabled = $_POST['bulk_action'] === 'disable' ? 'yes' : 'no';
        foreach ($ids as $targetId) {
            $api->comm('/ip/hotspot/set', ['.id' => $targetId, 'disabled' => $disabled]);
        }
        logActivity('hotspot', $_POST['bulk_action'] === 'disable' ? 'Desabilitou hotspots em massa' : 'Habilitou hotspots em massa', "{$routerLabel} — " . count($ids) . " item(ns): " . implode(', ', $ids));
        header("Location: index.php?page=hotspot");
        exit;
    }
}

// Ação em massa: conceder/revogar visibilidade de vários servidores para o Usuário Padrão (Administrador)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_permission_action'], $_POST['ids'])) {
    csrfVerify();
    requireAdmin();
    $ids = array_values(array_filter((array)$_POST['ids']));

    if (empty($ids)) {
        $bulkError = 'Nenhum item válido selecionado.';
    } else {
        if ($_POST['bulk_permission_action'] === 'grant') {
            $stmt = $db->prepare("INSERT IGNORE INTO rule_permissions (router_id, rule_type, rule_id, granted_by) VALUES (:r, 'hotspot', :id, :by)");
            foreach ($ids as $targetId) {
                $stmt->execute([':r' => $routerId, ':id' => $targetId, ':by' => $_SESSION['user_logged_in'] ?? '']);
            }
            logActivity('hotspot', 'Permitiu hotspots em massa para Usuário Padrão', "{$routerLabel} — " . count($ids) . " item(ns): " . implode(', ', $ids));
        } else {
            $stmt = $db->prepare("DELETE FROM rule_permissions WHERE router_id = :r AND rule_type = 'hotspot' AND rule_id = :id");
            foreach ($ids as $targetId) {
                $stmt->execute([':r' => $routerId, ':id' => $targetId]);
            }
            logActivity('hotspot', 'Revogou permissão em massa de hotspots do Usuário Padrão', "{$routerLabel} — " . count($ids) . " item(ns): " . implode(', ', $ids));
        }
        header("Location: index.php?page=hotspot");
        exit;
    }
}

// Executa a requisição limpa do comando print
$api->write('/ip/hotspot/print');
$raw_response = $api->read();
$api->disconnect();

$hotspots_clean = [];

// Trata o encapsulamento do array retornado pelo par write/read da API MikroTik
if (!empty($raw_response) && is_array($raw_response)) {
    // Se a API aninhou todas as respostas dentro da primeira posição do array
    if (isset($raw_response[0]) && is_array($raw_response[0]) && isset($raw_response[0]['.id']) && count($raw_response) === 1) {
        $hotspots_clean = $raw_response;
    } else {
        // Coleta todos os itens válidos que contenham as propriedades do Hotspot Server
        foreach ($raw_response as $item) {
            if (is_array($item) && (isset($item['.id']) || isset($item['name']))) {
                $hotspots_clean[] = $item;
            }
        }
    }
}

// Usuário padrão só enxerga os hotspots liberados pelo Administrador.
if ($role !== 'admin') {
    $hotspots_clean = array_values(array_filter($hotspots_clean, function ($h) use ($permittedIds) {
        $id = $h['.id'] ?? $h['name'] ?? null;
        return $id && isset($permittedIds[$id]);
    }));
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="page-title">Gerenciamento de Hotspot (Servers)</h1>
    <span class="badge bg-secondary">Total: <?= count($hotspots_clean) ?> servidores</span>
</div>

<?php if ($role !== 'admin'): ?>
    <div class="alert alert-info py-2">Exibindo apenas os servidores liberados pelo Administrador para o seu perfil.</div>
<?php endif; ?>

<?php if ($bulkError): ?>
    <div class="alert alert-danger py-2"><?= htmlspecialchars($bulkError) ?></div>
<?php endif; ?>

<form method="POST" id="bulkHotspotForm">
    <?= csrfField() ?>
</form>

<?php if (!empty($hotspots_clean)): ?>
<div class="card mb-3">
    <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
        <span class="text-muted small me-2"><i class="bi bi-check2-square"></i> Ações em massa:</span>
        <button type="submit" form="bulkHotspotForm" name="bulk_action" value="enable" class="btn btn-sm btn-success" onclick="return confirm('Habilitar todos os itens selecionados?');">Habilitar Selecionados</button>
        <button type="submit" form="bulkHotspotForm" name="bulk_action" value="disable" class="btn btn-sm btn-warning" onclick="return confirm('Desabilitar todos os itens selecionados?');">Desabilitar Selecionados</button>
        <?php if ($role === 'admin'): ?>
            <span class="vr mx-1"></span>
            <button type="submit" form="bulkHotspotForm" name="bulk_permission_action" value="grant" class="btn btn-sm btn-outline-primary">Permitir p/ Padrão</button>
            <button type="submit" form="bulkHotspotForm" name="bulk_permission_action" value="revoke" class="btn btn-sm btn-outline-secondary">Revogar de Padrão</button>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<table class="table table-striped table-hover bg-white shadow-sm mt-3 align-middle" style="font-size: 0.9rem;">
    <thead class="table-dark">
        <tr>
            <th style="width: 36px;"><input type="checkbox" class="form-check-input" id="selectAllHotspot"></th>
            <th>Servidor</th>
            <th>Interface</th>
            <th>Endereço Pool</th>
            <th>Profile</th>
            <th>Status</th>
            <?php if ($role === 'admin'): ?><th class="text-center" style="width: 150px;">Visível p/ Padrão</th><?php endif; ?>
            <th class="text-center" style="width: 130px;">Ações</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($hotspots_clean)): ?>
            <tr>
                <td colspan="<?= $role === 'admin' ? 8 : 7 ?>" class="text-center text-muted py-4">Nenhum Hotspot Server configurado<?= $role === 'admin' ? ' neste MikroTik.' : ' liberado para o seu perfil.' ?></td>
            </tr>
        <?php else: ?>
            <?php foreach ($hotspots_clean as $h): ?>
                <?php
                    // Fallback de ID para garantir que o formulário de POST consiga ativar/desativar a linha
                    $internal_id = $h['.id'] ?? $h['name'] ?? null;
                    if (!$internal_id) continue;

                    $isDisabled = isset($h['disabled']) && in_array(strtolower((string)$h['disabled']), ['true', 'yes']);
                    $isPermitted = isset($permittedIds[$internal_id]);

                    // Tratamento visual das flags especiais mostradas no terminal (ex: I = Invalid)
                    $isInvalid = isset($h['invalid']) && $h['invalid'] === 'true';
                ?>
                <tr class="<?= $isDisabled ? 'table-light text-muted' : '' ?>">
                    <td><input type="checkbox" class="form-check-input hotspot-row-check" name="ids[]" value="<?= htmlspecialchars($internal_id) ?>" form="bulkHotspotForm"></td>
                    <td>
                        <strong><?= htmlspecialchars($h['name'] ?? '-') ?></strong>
                        <?php if ($isInvalid): ?>
                            <span class="badge bg-dark text-warning ms-1" style="font-size: 0.65rem;" title="Invalid Setup">I</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($h['interface'] ?? '-') ?></span></td>
                    <td><code><?= htmlspecialchars($h['address-pool'] ?? 'Qualquer (None)') ?></code></td>
                    <td><?= htmlspecialchars($h['profile'] ?? '-') ?></td>

                    <td>
                        <?php if ($isDisabled): ?>
                            <span class="badge bg-danger">Desabilitado</span>
                        <?php else: ?>
                            <span class="badge bg-success">Habilitado</span>
                        <?php endif; ?>
                    </td>

                    <?php if ($role === 'admin'): ?>
                    <td class="text-center">
                        <form method="POST" class="m-0">
                            <?= csrfField() ?>
                            <input type="hidden" name="id" value="<?= htmlspecialchars($internal_id) ?>">
                            <?php if ($isPermitted): ?>
                                <input type="hidden" name="permission_action" value="revoke">
                                <button type="submit" class="btn btn-sm btn-outline-secondary w-100 py-1" style="font-size: 0.75rem;">Revogar</button>
                            <?php else: ?>
                                <input type="hidden" name="permission_action" value="grant">
                                <button type="submit" class="btn btn-sm btn-outline-primary w-100 py-1" style="font-size: 0.75rem;">Permitir</button>
                            <?php endif; ?>
                        </form>
                    </td>
                    <?php endif; ?>

                    <td class="text-center">
                        <form method="POST" class="m-0" onsubmit="return confirm('Alterar o status deste servidor Hotspot?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="id" value="<?= htmlspecialchars($internal_id) ?>">

                            <?php if ($isDisabled): ?>
                                <input type="hidden" name="action" value="enable">
                                <button type="submit" class="btn btn-sm btn-success w-100 py-1" style="font-size: 0.8rem;">Habilitar</button>
                            <?php else: ?>
                                <input type="hidden" name="action" value="disable">
                                <button type="submit" class="btn btn-sm btn-warning w-100 py-1" style="font-size: 0.8rem;">Desabilitar</button>
                            <?php endif; ?>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>
<script>
document.getElementById('selectAllHotspot')?.addEventListener('change', function () {
    document.querySelectorAll('.hotspot-row-check').forEach(function (cb) { cb.checked = this.checked; }, this);
});
</script>
