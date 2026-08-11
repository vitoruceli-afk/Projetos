<?php
requireAdmin();
set_time_limit(30);
$api = getActiveRouterAPI(5);
if (!$api) { echo "<div class='alert alert-danger'>Conexão falhou ao tentar se comunicar com o MikroTik.</div>"; return; }

// Limite de espera (segundos) especificamente para /add e /set de ip-binding: neste tipo de
// lista, o serviço de hotspot às vezes manda o início da resposta rápido e só confirma o
// resto minutos depois — mas o comando já foi aplicado no roteador nesse meio tempo. Em vez
// de travar a tela esperando essa confirmação tardia, desistimos de esperar após alguns
// segundos e avisamos o administrador para conferir a lista, em vez de fingir sucesso ou erro.
$writeMaxWait = 8;

$db = getDB();
$routerId = (int)$_SESSION['active_router'];
$routerNameStmt = $db->prepare("SELECT name FROM routers WHERE id = :r");
$routerNameStmt->bindValue(':r', $routerId, PDO::PARAM_INT);
$routerNameStmt->execute();
$routerLabel = $routerNameStmt->fetchColumn() ?: "Roteador #{$routerId}";

$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrfVerify();

    if ($_POST['action'] === 'add' || $_POST['action'] === 'update') {
        $mac = strtoupper(trim($_POST['mac_address'] ?? ''));
        $address = trim($_POST['address'] ?? '');
        $toAddress = trim($_POST['to_address'] ?? '');
        $server = trim($_POST['server'] ?? '') ?: 'all';
        $type = in_array($_POST['type'] ?? '', ['bypassed', 'blocked', 'regular'], true) ? $_POST['type'] : 'bypassed';
        $comment = trim($_POST['comment'] ?? '');
        $enabled = isset($_POST['enabled']);

        if ($mac === '' && $address === '') {
            $formError = 'Informe ao menos o endereço MAC ou o endereço IP.';
        } elseif ($mac !== '' && !preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) {
            $formError = 'Endereço MAC inválido (use o formato AA:BB:CC:DD:EE:FF).';
        } elseif ($address !== '' && !filter_var($address, FILTER_VALIDATE_IP)) {
            $formError = 'Endereço IP inválido.';
        } elseif ($toAddress !== '' && !filter_var($toAddress, FILTER_VALIDATE_IP)) {
            $formError = 'Endereço "Para IP" inválido.';
        } else {
            $params = [
                'server' => $server,
                'type' => $type,
                'comment' => $comment,
                'disabled' => $enabled ? 'no' : 'yes',
            ];
            if ($mac !== '') { $params['mac-address'] = $mac; }
            if ($address !== '') { $params['address'] = $address; }
            if ($toAddress !== '') { $params['to-address'] = $toAddress; }

            $target = $mac ?: $address;
            $isAdd = $_POST['action'] === 'add';
            if ($isAdd) {
                $result = $api->comm('/ip/hotspot/ip-binding/add', $params, $writeMaxWait);
            } else {
                $params['.id'] = $_POST['id'];
                $result = $api->comm('/ip/hotspot/ip-binding/set', $params, $writeMaxWait);
            }
            $actionLabel = $isAdd ? 'Cadastrou' : 'Editou';

            if ($api->lastReadTimedOut) {
                // Sem confirmação a tempo, mas o comando já foi enviado e costuma ser aplicado
                // no roteador mesmo assim — não é um erro conhecido, então não tratamos como falha.
                logActivity('bypass', "{$actionLabel} bypass (IP Binding) — sem confirmação a tempo", "{$routerLabel} — {$target}");
                header("Location: index.php?page=bypass&pending=1");
                exit;
            }

            $routerError = $result[0]['message'] ?? null;
            if ($routerError) {
                $formError = 'O MikroTik recusou a operação: ' . $routerError;
            } else {
                logActivity('bypass', "{$actionLabel} bypass (IP Binding)", "{$routerLabel} — {$target}");
                header("Location: index.php?page=bypass");
                exit;
            }
        }
    } elseif ($_POST['action'] === 'delete') {
        $targetId = $_POST['id'];
        $api->comm('/ip/hotspot/ip-binding/remove', ['.id' => $targetId], $writeMaxWait);
        logActivity('bypass', $api->lastReadTimedOut ? 'Excluiu bypass (IP Binding) — sem confirmação a tempo' : 'Excluiu bypass (IP Binding)', "{$routerLabel} — item {$targetId}");
        header("Location: index.php?page=bypass" . ($api->lastReadTimedOut ? '&pending=1' : ''));
        exit;
    }
}

// Busca a lista completa (usada tanto para exibir a tabela quanto para localizar o item em edição)
$api->write('/ip/hotspot/ip-binding/print');
$raw = $api->read(true, 15);
$api->disconnect();

$bindings = [];
if (!empty($raw) && is_array($raw)) {
    foreach ($raw as $item) {
        if (is_array($item) && isset($item['.id'])) {
            $bindings[] = $item;
        }
    }
}

$editing = null;
if (isset($_GET['edit'])) {
    foreach ($bindings as $b) {
        if ($b['.id'] === $_GET['edit']) { $editing = $b; break; }
    }
}

// ---- Filtros (busca + tipo + status) — aplicados só à listagem; a busca do item em edição acima usa a lista completa. ----
$fSearch = trim($_GET['search'] ?? '');
$fType = $_GET['type'] ?? '';
if (!in_array($fType, ['bypassed', 'blocked', 'regular'], true)) $fType = '';
$fStatus = $_GET['status'] ?? '';
if (!in_array($fStatus, ['active', 'disabled'], true)) $fStatus = '';
$hasFilter = $fSearch !== '' || $fType !== '' || $fStatus !== '';

if ($hasFilter) {
    $bindings = array_values(array_filter($bindings, function ($b) use ($fSearch, $fType, $fStatus) {
        if ($fSearch !== '') {
            $haystack = mb_strtolower(($b['mac-address'] ?? '') . ' ' . ($b['address'] ?? '') . ' ' . ($b['comment'] ?? ''));
            if (mb_strpos($haystack, mb_strtolower($fSearch)) === false) return false;
        }
        if ($fType !== '' && ($b['type'] ?? '') !== $fType) return false;
        if ($fStatus !== '') {
            $isDisabled = in_array(strtolower((string)($b['disabled'] ?? 'no')), ['true', 'yes'], true);
            if ($fStatus === 'active' && $isDisabled) return false;
            if ($fStatus === 'disabled' && !$isDisabled) return false;
        }
        return true;
    }));
}
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Bypass (IP Bindings)</h1>
        <div class="page-sub"><?= htmlspecialchars($routerLabel) ?> · MAC/IP liberados, bloqueados ou fixados sem passar pela autenticação do Hotspot</div>
    </div>
    <span class="badge bg-light"><?= count($bindings) ?> cadastrado<?= count($bindings) === 1 ? '' : 's' ?></span>
</div>

<?php if ($formError): ?>
    <div class="alert alert-danger py-2"><?= htmlspecialchars($formError) ?></div>
<?php endif; ?>
<?php if (isset($_GET['pending'])): ?>
    <div class="alert alert-warning py-2">O MikroTik não confirmou a operação a tempo (isso pode acontecer neste roteador). O comando foi enviado — confira abaixo se o bypass aparece como esperado; se não aparecer em alguns instantes, tente novamente.</div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-header"><?= $editing ? 'Editar Bypass' : 'Novo Bypass' ?></div>
    <div class="card-body">
        <div class="alert alert-warning py-2 mb-3" style="font-size: 0.82em;">Neste MikroTik, salvar um bypass pode demorar alguns segundos para confirmar. Envie uma vez só e aguarde — clicar de novo pode criar um cadastro duplicado.</div>
        <form method="POST" id="bypass-form" onsubmit="document.getElementById('bypass-submit').disabled = true; document.getElementById('bypass-submit').textContent = 'Enviando ao MikroTik... aguarde';">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $editing ? 'update' : 'add' ?>">
            <?php if ($editing): ?>
                <input type="hidden" name="id" value="<?= htmlspecialchars($editing['.id']) ?>">
            <?php endif; ?>
            <div class="row g-2">
                <div class="col-md-3">
                    <label class="form-label">Endereço MAC</label>
                    <input type="text" name="mac_address" class="form-control mono" placeholder="AA:BB:CC:DD:EE:FF" value="<?= htmlspecialchars($editing['mac-address'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Endereço IP</label>
                    <input type="text" name="address" class="form-control mono" placeholder="10.0.0.10" value="<?= htmlspecialchars($editing['address'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Para Endereço (NAT, opcional)</label>
                    <input type="text" name="to_address" class="form-control mono" placeholder="10.0.0.254" value="<?= htmlspecialchars($editing['to-address'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Servidor Hotspot</label>
                    <input type="text" name="server" class="form-control" placeholder="all" value="<?= htmlspecialchars($editing['server'] ?? 'all') ?>">
                </div>
            </div>
            <div class="row g-2 mt-1">
                <div class="col-md-3">
                    <label class="form-label">Tipo</label>
                    <select name="type" class="form-select">
                        <?php $curType = $editing['type'] ?? 'bypassed'; ?>
                        <option value="bypassed" <?= $curType === 'bypassed' ? 'selected' : '' ?>>Bypassed (libera sem autenticar)</option>
                        <option value="blocked" <?= $curType === 'blocked' ? 'selected' : '' ?>>Blocked (bloqueia acesso)</option>
                        <option value="regular" <?= $curType === 'regular' ? 'selected' : '' ?>>Regular</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Comentário</label>
                    <input type="text" name="comment" class="form-control" placeholder="Ex: Notebook TI - Sala 3" value="<?= htmlspecialchars($editing['comment'] ?? '') ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <div class="form-check mb-2">
                        <?php $curDisabled = $editing && in_array(strtolower((string)($editing['disabled'] ?? 'no')), ['true', 'yes'], true); ?>
                        <input type="checkbox" class="form-check-input" id="bypass-enabled" name="enabled" <?= !$curDisabled ? 'checked' : '' ?>>
                        <label class="form-check-label" for="bypass-enabled">Habilitado</label>
                    </div>
                </div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button type="submit" id="bypass-submit" class="btn btn-outline-primary"><?= $editing ? 'Salvar Alterações' : 'Cadastrar' ?></button>
                <?php if ($editing): ?>
                    <a href="index.php?page=bypass" class="btn btn-outline-secondary">Cancelar Edição</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row gx-3 gy-2 align-items-end">
            <input type="hidden" name="page" value="bypass">
            <div class="col-12 col-sm-6 col-lg-4">
                <label class="form-label small text-muted mb-0">Buscar</label>
                <input type="text" name="search" class="form-control" placeholder="MAC, IP ou comentário" value="<?= htmlspecialchars($fSearch) ?>">
            </div>
            <div class="col-6 col-lg-3">
                <label class="form-label small text-muted mb-0">Tipo</label>
                <select name="type" class="form-select">
                    <option value="">Todos</option>
                    <option value="bypassed" <?= $fType === 'bypassed' ? 'selected' : '' ?>>Bypassed</option>
                    <option value="blocked" <?= $fType === 'blocked' ? 'selected' : '' ?>>Blocked</option>
                    <option value="regular" <?= $fType === 'regular' ? 'selected' : '' ?>>Regular</option>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small text-muted mb-0">Status</label>
                <select name="status" class="form-select">
                    <option value="">Todos</option>
                    <option value="active" <?= $fStatus === 'active' ? 'selected' : '' ?>>Ativo</option>
                    <option value="disabled" <?= $fStatus === 'disabled' ? 'selected' : '' ?>>Desabilitado</option>
                </select>
            </div>
            <div class="col-12 col-lg-3 d-flex gap-2">
                <button type="submit" class="btn btn-outline-primary flex-fill">Filtrar</button>
                <a href="index.php?page=bypass" class="btn btn-outline-secondary">Limpar</a>
            </div>
        </form>
    </div>
</div>

<?php if (empty($bindings)): ?>
    <div class="card">
        <div class="card-body text-center text-muted py-4">
            <?php if ($hasFilter): ?>
                Nenhum bypass encontrado para os filtros selecionados.
            <?php else: ?>
                Nenhum bypass cadastrado neste MikroTik.
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="entity-list">
        <?php foreach ($bindings as $b): ?>
            <?php
                $isDisabled = in_array(strtolower((string)($b['disabled'] ?? 'no')), ['true', 'yes'], true);
                $typeClass = match ($b['type'] ?? '') {
                    'bypassed' => 'tag-accept',
                    'blocked' => 'tag-drop',
                    default => 'tag-reject',
                };
                $cardTitle = $b['mac-address'] ?? $b['address'] ?? '(sem identificador)';
            ?>
            <div class="entity-card <?= $isDisabled ? 'is-disabled' : '' ?>">
                <div class="entity-card-head">
                    <div class="entity-title-wrap">
                        <div>
                            <div class="entity-title mono"><?= htmlspecialchars($cardTitle) ?></div>
                            <div class="entity-sub"><?= htmlspecialchars($b['comment'] ?: 'Sem comentário') ?></div>
                        </div>
                    </div>
                    <div class="entity-badges">
                        <span class="<?= $typeClass ?>"><?= htmlspecialchars($b['type'] ?? '-') ?></span>
                        <?php if ($isDisabled): ?>
                            <span class="badge bg-secondary">Desabilitado</span>
                        <?php else: ?>
                            <span class="badge bg-success">Ativo</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="entity-grid">
                    <div>
                        <div class="entity-field-label">MAC</div>
                        <div class="entity-field-value mono"><?= htmlspecialchars($b['mac-address'] ?? '-') ?></div>
                    </div>
                    <div>
                        <div class="entity-field-label">IP</div>
                        <div class="entity-field-value mono"><?= htmlspecialchars($b['address'] ?? '-') ?></div>
                    </div>
                    <div>
                        <div class="entity-field-label">Para IP</div>
                        <div class="entity-field-value mono"><?= htmlspecialchars($b['to-address'] ?? '-') ?></div>
                    </div>
                    <div>
                        <div class="entity-field-label">Servidor</div>
                        <div class="entity-field-value"><?= htmlspecialchars($b['server'] ?? 'all') ?></div>
                    </div>
                </div>
                <div class="entity-actions">
                    <div></div>
                    <div class="entity-actions-buttons">
                        <a href="index.php?page=bypass&edit=<?= urlencode($b['.id']) ?>" class="btn btn-sm btn-outline-primary">Editar</a>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Excluir este bypass?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= htmlspecialchars($b['.id']) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Excluir</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
