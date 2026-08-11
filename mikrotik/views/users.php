<?php
requireAdmin();
$db = getDB();
$tab = $_GET['tab'] ?? 'local';
if (!in_array($tab, ['local', 'ldap', 'settings'])) $tab = 'local';

$localError = '';
$ldapMessage = '';
$settingsMessage = '';
$testResult = null;

// ---- Ações: Usuários Locais ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['area'] ?? '') === 'local') {
    csrfVerify();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $username = trim($_POST['username'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'standard';

        if ($username === '' || $password === '') {
            $localError = 'Usuário e senha são obrigatórios.';
        } elseif (strlen($password) < 8) {
            $localError = 'A senha deve ter pelo menos 8 caracteres.';
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO local_users (username, password_hash, full_name, enabled, role) VALUES (:u, :p, :n, 1, :r)");
                $stmt->bindValue(':u', $username);
                $stmt->bindValue(':p', password_hash($password, PASSWORD_DEFAULT));
                $stmt->bindValue(':n', $fullName);
                $stmt->bindValue(':r', $role);
                $stmt->execute();
                logActivity('users', 'Criou usuário local', "{$username} (perfil: " . ($role === 'admin' ? 'Administrador' : 'Usuário Padrão') . ")");
                header("Location: index.php?page=users&tab=local");
                exit;
            } catch (PDOException $e) {
                $localError = (strpos($e->getMessage(), 'Duplicate') !== false)
                    ? 'Já existe um usuário local com esse nome.'
                    : 'Erro ao salvar usuário: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'standard';

        $stmt = $db->prepare("SELECT username, role FROM local_users WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $target = $stmt->fetch();

        $isSelf = $target && $target['username'] === ($_SESSION['user_logged_in'] ?? null) && ($_SESSION['auth_type'] ?? '') === 'local';

        if ($isSelf && $target['role'] === 'admin' && $role !== 'admin') {
            $localError = 'Você não pode remover seu próprio perfil de Administrador.';
        } elseif ($password !== '' && strlen($password) < 8) {
            $localError = 'A senha deve ter pelo menos 8 caracteres.';
        } elseif ($password !== '') {
            $stmt = $db->prepare("UPDATE local_users SET full_name = :n, password_hash = :p, role = :r WHERE id = :id");
            $stmt->bindValue(':n', $fullName);
            $stmt->bindValue(':p', password_hash($password, PASSWORD_DEFAULT));
            $stmt->bindValue(':r', $role);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            logActivity('users', 'Editou usuário local', "{$target['username']} (perfil: " . ($role === 'admin' ? 'Administrador' : 'Usuário Padrão') . ", senha alterada)");
            header("Location: index.php?page=users&tab=local");
            exit;
        } else {
            $stmt = $db->prepare("UPDATE local_users SET full_name = :n, role = :r WHERE id = :id");
            $stmt->bindValue(':n', $fullName);
            $stmt->bindValue(':r', $role);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            logActivity('users', 'Editou usuário local', "{$target['username']} (perfil: " . ($role === 'admin' ? 'Administrador' : 'Usuário Padrão') . ")");
            header("Location: index.php?page=users&tab=local");
            exit;
        }
    } elseif ($action === 'enable' || $action === 'disable') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT username FROM local_users WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $target = $stmt->fetch();

        if ($target && $action === 'disable' && $target['username'] === ($_SESSION['user_logged_in'] ?? null) && ($_SESSION['auth_type'] ?? '') === 'local') {
            $localError = 'Você não pode desabilitar sua própria conta enquanto estiver logado com ela.';
        } elseif ($target) {
            $stmt = $db->prepare("UPDATE local_users SET enabled = :e WHERE id = :id");
            $stmt->bindValue(':e', $action === 'enable' ? 1 : 0, PDO::PARAM_INT);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            logActivity('users', $action === 'enable' ? 'Habilitou usuário local' : 'Desabilitou usuário local', $target['username']);
            header("Location: index.php?page=users&tab=local");
            exit;
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT username FROM local_users WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $target = $stmt->fetch();

        if ($target && $target['username'] === ($_SESSION['user_logged_in'] ?? null) && ($_SESSION['auth_type'] ?? '') === 'local') {
            $localError = 'Você não pode excluir sua própria conta enquanto estiver logado com ela.';
        } elseif ($target) {
            $stmt = $db->prepare("DELETE FROM local_users WHERE id = :id");
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            logActivity('users', 'Excluiu usuário local', $target['username']);
            header("Location: index.php?page=users&tab=local");
            exit;
        }
    }
}

// ---- Ação em massa: Usuários Locais ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['area'] ?? '') === 'local' && isset($_POST['bulk_action'], $_POST['ids'])) {
    csrfVerify();
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$_POST['ids']))));
    $bulkAction = $_POST['bulk_action'];

    // Nunca inclui a própria conta local logada na ação em massa (evita autobloqueio/auto-exclusão).
    $selfId = null;
    if (($_SESSION['auth_type'] ?? '') === 'local') {
        $stmt = $db->prepare("SELECT id FROM local_users WHERE username = :u");
        $stmt->bindValue(':u', $_SESSION['user_logged_in'] ?? '');
        $stmt->execute();
        $selfId = $stmt->fetchColumn() ?: null;
    }
    $skippedSelf = $selfId && in_array($selfId, $ids);
    $ids = array_values(array_diff($ids, [$selfId]));

    if (empty($ids)) {
        $localError = 'Nenhum usuário válido selecionado.';
    } elseif (in_array($bulkAction, ['enable', 'disable', 'delete'])) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $namesStmt = $db->prepare("SELECT username FROM local_users WHERE id IN ({$placeholders})");
        $namesStmt->execute($ids);
        $names = $namesStmt->fetchAll(PDO::FETCH_COLUMN);
        $suffix = $skippedSelf ? ' (sua conta foi ignorada)' : '';

        if ($bulkAction === 'delete') {
            $stmt = $db->prepare("DELETE FROM local_users WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            logActivity('users', 'Excluiu usuários locais em massa', implode(', ', $names) . $suffix);
        } else {
            $stmt = $db->prepare("UPDATE local_users SET enabled = :e WHERE id = :id");
            foreach ($ids as $id) {
                $stmt->execute([':e' => $bulkAction === 'enable' ? 1 : 0, ':id' => $id]);
            }
            logActivity('users', $bulkAction === 'enable' ? 'Habilitou usuários locais em massa' : 'Desabilitou usuários locais em massa', implode(', ', $names) . $suffix);
        }
        header("Location: index.php?page=users&tab=local");
        exit;
    }
}

// ---- Ações: Usuários LDAP (bloquear/desbloquear acesso) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['area'] ?? '') === 'ldap') {
    csrfVerify();
    $action = $_POST['action'] ?? '';
    $username = trim($_POST['username'] ?? '');

    if ($username !== '' && $action === 'block') {
        $stmt = $db->prepare("INSERT IGNORE INTO ldap_blocked_users (username, blocked_by) VALUES (:u, :by)");
        $stmt->bindValue(':u', $username);
        $stmt->bindValue(':by', $_SESSION['user_logged_in'] ?? '');
        $stmt->execute();
        logActivity('users', 'Bloqueou acesso de usuário LDAP', $username);
        header("Location: index.php?page=users&tab=ldap");
        exit;
    } elseif ($username !== '' && $action === 'unblock') {
        $stmt = $db->prepare("DELETE FROM ldap_blocked_users WHERE username = :u");
        $stmt->bindValue(':u', $username);
        $stmt->execute();
        logActivity('users', 'Desbloqueou acesso de usuário LDAP', $username);
        header("Location: index.php?page=users&tab=ldap");
        exit;
    } elseif ($username !== '' && in_array($action, ['make_admin', 'make_standard'])) {
        $newRole = $action === 'make_admin' ? 'admin' : 'standard';
        $isSelf = $username === ($_SESSION['user_logged_in'] ?? null) && ($_SESSION['auth_type'] ?? '') === 'ldap';

        if ($isSelf && $newRole !== 'admin') {
            $ldapMessage = 'Você não pode remover seu próprio perfil de Administrador.';
        } else {
            $stmt = $db->prepare("INSERT INTO ldap_user_roles (username, role) VALUES (:u, :r) ON DUPLICATE KEY UPDATE role = :r2");
            $stmt->bindValue(':u', $username);
            $stmt->bindValue(':r', $newRole);
            $stmt->bindValue(':r2', $newRole);
            $stmt->execute();
            logActivity('users', $newRole === 'admin' ? 'Promoveu usuário LDAP a Administrador' : 'Rebaixou usuário LDAP a Usuário Padrão', $username);
            header("Location: index.php?page=users&tab=ldap");
            exit;
        }
    }
}

// ---- Ação em massa: Usuários LDAP ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['area'] ?? '') === 'ldap' && isset($_POST['ids']) && (isset($_POST['bulk_action']) || isset($_POST['bulk_role_action']))) {
    csrfVerify();
    $usernames = array_values(array_unique(array_filter(array_map('trim', (array)$_POST['ids']))));

    // Nunca inclui o próprio usuário LDAP logado na ação em massa (evita autobloqueio/auto-rebaixamento).
    $selfUsername = ($_SESSION['auth_type'] ?? '') === 'ldap' ? ($_SESSION['user_logged_in'] ?? null) : null;
    $skippedSelf = $selfUsername && in_array($selfUsername, $usernames);
    $usernames = array_values(array_diff($usernames, [$selfUsername]));
    $suffix = $skippedSelf ? ' (sua conta foi ignorada)' : '';

    if (empty($usernames)) {
        $ldapMessage = 'Nenhum usuário válido selecionado.';
    } elseif (isset($_POST['bulk_action']) && in_array($_POST['bulk_action'], ['block', 'unblock'])) {
        if ($_POST['bulk_action'] === 'block') {
            $stmt = $db->prepare("INSERT IGNORE INTO ldap_blocked_users (username, blocked_by) VALUES (:u, :by)");
            foreach ($usernames as $u) {
                $stmt->execute([':u' => $u, ':by' => $_SESSION['user_logged_in'] ?? '']);
            }
            logActivity('users', 'Bloqueou acesso de usuários LDAP em massa', implode(', ', $usernames) . $suffix);
        } else {
            $placeholders = implode(',', array_fill(0, count($usernames), '?'));
            $stmt = $db->prepare("DELETE FROM ldap_blocked_users WHERE username IN ({$placeholders})");
            $stmt->execute($usernames);
            logActivity('users', 'Desbloqueou acesso de usuários LDAP em massa', implode(', ', $usernames) . $suffix);
        }
        header("Location: index.php?page=users&tab=ldap");
        exit;
    } elseif (isset($_POST['bulk_role_action']) && in_array($_POST['bulk_role_action'], ['make_admin', 'make_standard'])) {
        $newRole = $_POST['bulk_role_action'] === 'make_admin' ? 'admin' : 'standard';
        $stmt = $db->prepare("INSERT INTO ldap_user_roles (username, role) VALUES (:u, :r) ON DUPLICATE KEY UPDATE role = :r2");
        foreach ($usernames as $u) {
            $stmt->execute([':u' => $u, ':r' => $newRole, ':r2' => $newRole]);
        }
        logActivity('users', $newRole === 'admin' ? 'Promoveu usuários LDAP a Administrador em massa' : 'Rebaixou usuários LDAP a Usuário Padrão em massa', implode(', ', $usernames) . $suffix);
        header("Location: index.php?page=users&tab=ldap");
        exit;
    }
}

// ---- Ações: Configurações LDAP ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['area'] ?? '') === 'settings') {
    csrfVerify();
    $action = $_POST['action'] ?? '';
    $current = getLdapSettings();

    $server = trim($_POST['ldap_server'] ?? '');
    $domain = trim($_POST['ldap_domain'] ?? '');
    $basedn = trim($_POST['ldap_basedn'] ?? '');
    $group = trim($_POST['ldap_group'] ?? '');
    $bindUsername = trim($_POST['bind_username'] ?? '');
    $bindPassword = $_POST['bind_password'] ?? '';

    if ($action === 'save') {
        if ($server === '' || $domain === '' || $basedn === '' || $group === '') {
            $settingsMessage = ['type' => 'danger', 'text' => 'Servidor, domínio, base DN e grupo são obrigatórios.'];
        } else {
            if ($bindPassword !== '') {
                $stmt = $db->prepare("UPDATE ldap_settings SET ldap_server = :s, ldap_domain = :d, ldap_basedn = :b, ldap_group = :g, bind_username = :bu, bind_password = :bp WHERE id = :id");
                $stmt->bindValue(':bp', routerEncrypt($bindPassword));
            } else {
                $stmt = $db->prepare("UPDATE ldap_settings SET ldap_server = :s, ldap_domain = :d, ldap_basedn = :b, ldap_group = :g, bind_username = :bu WHERE id = :id");
            }
            $stmt->bindValue(':s', $server);
            $stmt->bindValue(':d', $domain);
            $stmt->bindValue(':b', $basedn);
            $stmt->bindValue(':g', $group);
            $stmt->bindValue(':bu', $bindUsername);
            $stmt->bindValue(':id', $current['id'], PDO::PARAM_INT);
            $stmt->execute();
            logActivity('users', 'Atualizou configurações LDAP', "Servidor: {$server}, Grupo: {$group}" . ($bindPassword !== '' ? ' (senha de serviço alterada)' : ''));
            header("Location: index.php?page=users&tab=settings");
            exit;
        }
    } elseif ($action === 'test') {
        // Testa com os valores digitados no formulário (sem salvar), usando a senha nova
        // se foi informada, ou a já salva caso o campo tenha sido deixado em branco.
        $testSettings = [
            'ldap_server' => $server !== '' ? $server : $current['ldap_server'],
            'bind_username' => $bindUsername,
            'bind_password' => $bindPassword !== '' ? routerEncrypt($bindPassword) : $current['bind_password'],
        ];
        [$ok, $msg] = ldapTestServiceBind($testSettings);
        $testResult = ['ok' => $ok, 'text' => $msg];
    }
}

// ---- Funções auxiliares de LDAP (apenas leitura / teste) ----

function ldapTestServiceBind(array $settings) {
    if (empty($settings['bind_username'])) {
        return [false, 'Nenhuma conta de serviço informada.'];
    }
    $ldap = ldapConnect($settings['ldap_server']);
    if (!$ldap) {
        return [false, 'Não foi possível conectar ao servidor LDAP.'];
    }

    $bindPass = routerDecrypt($settings['bind_password']);
    if (@ldap_bind($ldap, $settings['bind_username'], $bindPass)) {
        return [true, 'Conexão e autenticação com a conta de serviço realizadas com sucesso.'];
    }
    return [false, 'Falha ao autenticar com a conta de serviço informada.'];
}

function ldapListGroupMembers(array $settings, &$errorMsg = null) {
    if (empty($settings['bind_username'])) {
        $errorMsg = 'Configure uma conta de serviço LDAP na aba "Configurações" para listar os usuários do grupo.';
        return [];
    }

    $ldap = ldapConnect($settings['ldap_server']);
    if (!$ldap) {
        $errorMsg = 'Não foi possível conectar ao servidor LDAP.';
        return [];
    }

    $bindPass = routerDecrypt($settings['bind_password']);
    if (!@ldap_bind($ldap, $settings['bind_username'], $bindPass)) {
        $errorMsg = 'Falha ao autenticar com a conta de serviço LDAP configurada.';
        return [];
    }

    $filter = "(&(objectClass=user)(memberOf=" . $settings['ldap_group'] . "))";
    $search = @ldap_search($ldap, $settings['ldap_basedn'], $filter, ['sAMAccountName', 'displayName', 'mail']);
    if (!$search) {
        $errorMsg = 'Falha ao buscar usuários no grupo configurado.';
        return [];
    }
    $entries = @ldap_get_entries($ldap, $search);

    $users = [];
    for ($i = 0; $i < ($entries['count'] ?? 0); $i++) {
        $entry = $entries[$i];
        $username = $entry['samaccountname'][0] ?? '';
        if ($username === '') continue;
        $users[] = [
            'username' => $username,
            'name' => $entry['displayname'][0] ?? '',
            'email' => $entry['mail'][0] ?? '',
        ];
    }
    usort($users, fn($a, $b) => strcasecmp($a['name'] ?: $a['username'], $b['name'] ?: $b['username']));
    return $users;
}

// A busca no AD (ldapListGroupMembers) é a operação mais lenta desta tela. Como toda ação
// (bloquear, promover, ação em massa, etc.) redireciona de volta para esta mesma aba, sem cache
// cada clique refaria a busca inteira no LDAP. Guarda o resultado na sessão por um tempo curto;
// ?refresh=1 força buscar de novo. O cache é automaticamente invalidado se as configurações LDAP mudarem.
function ldapListGroupMembersCached(array $settings, &$errorMsg = null, $ttlSeconds = 120) {
    $hash = md5(json_encode($settings));
    $cache = $_SESSION['ldap_members_cache'] ?? null;

    if (!isset($_GET['refresh']) && $cache && $cache['hash'] === $hash && (time() - $cache['time']) < $ttlSeconds) {
        $errorMsg = $cache['error'];
        return $cache['data'];
    }

    $users = ldapListGroupMembers($settings, $errorMsg);
    $_SESSION['ldap_members_cache'] = ['hash' => $hash, 'time' => time(), 'data' => $users, 'error' => $errorMsg];
    return $users;
}

// Carrega roteador em edição (usuários locais)
$editing = null;
if ($tab === 'local' && isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM local_users WHERE id = :id");
    $stmt->bindValue(':id', (int)$_GET['edit'], PDO::PARAM_INT);
    $stmt->execute();
    $editing = $stmt->fetch();
}

$ldapSettings = getLdapSettings();
?>
<h1 class="page-title mb-3">Gerenciamento de Usuários</h1>

<ul class="nav nav-tabs mb-4">
    <li class="nav-item"><a class="nav-link <?= $tab === 'local' ? 'active' : '' ?>" href="index.php?page=users&tab=local">Usuários Locais</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'ldap' ? 'active' : '' ?>" href="index.php?page=users&tab=ldap">Usuários LDAP / AD</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'settings' ? 'active' : '' ?>" href="index.php?page=users&tab=settings">Configurações LDAP</a></li>
</ul>

<?php if ($tab === 'local'): ?>

    <div class="row">
        <div class="col-md-4">
            <div class="card">
                <div class="card-header"><?= $editing ? 'Editar Usuário Local' : 'Novo Usuário Local' ?></div>
                <div class="card-body">
                    <?php if ($localError): ?>
                        <div class="alert alert-danger py-2"><?= htmlspecialchars($localError) ?></div>
                    <?php endif; ?>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="area" value="local">
                        <input type="hidden" name="action" value="<?= $editing ? 'update' : 'add' ?>">
                        <?php if ($editing): ?>
                            <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                            <div class="mb-2">
                                <label class="form-label small text-muted mb-0">Usuário</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($editing['username']) ?>" disabled>
                            </div>
                        <?php else: ?>
                            <div class="mb-2"><input type="text" name="username" class="form-control" placeholder="Usuário" required></div>
                        <?php endif; ?>
                        <div class="mb-2"><input type="text" name="full_name" class="form-control" placeholder="Nome completo" value="<?= htmlspecialchars($editing['full_name'] ?? '') ?>"></div>
                        <div class="mb-2">
                            <label class="form-label small text-muted mb-0">Perfil</label>
                            <select name="role" class="form-select">
                                <option value="standard" <?= (($editing['role'] ?? 'standard') === 'standard') ? 'selected' : '' ?>>Usuário Padrão</option>
                                <option value="admin" <?= (($editing['role'] ?? '') === 'admin') ? 'selected' : '' ?>>Administrador</option>
                            </select>
                        </div>
                        <div class="mb-2">
                            <input type="password" name="password" class="form-control" placeholder="<?= $editing ? 'Senha (deixe em branco para manter)' : 'Senha (mín. 8 caracteres)' ?>" <?= $editing ? '' : 'required' ?>>
                        </div>
                        <button class="btn btn-outline-success w-100"><?= $editing ? 'Salvar Alterações' : 'Criar Usuário' ?></button>
                        <?php if ($editing): ?>
                            <a href="index.php?page=users&tab=local" class="btn btn-outline-secondary w-100 mt-2">Cancelar Edição</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <form method="POST" id="bulkLocalUsersForm">
                <?= csrfField() ?>
                <input type="hidden" name="area" value="local">
            </form>
            <div class="card mb-2">
                <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
                    <span class="text-muted small me-2"><i class="bi bi-check2-square"></i> Ações em massa:</span>
                    <button type="submit" form="bulkLocalUsersForm" name="bulk_action" value="enable" class="btn btn-sm btn-outline-success">Habilitar Selecionados</button>
                    <button type="submit" form="bulkLocalUsersForm" name="bulk_action" value="disable" class="btn btn-sm btn-outline-warning">Desabilitar Selecionados</button>
                    <button type="submit" form="bulkLocalUsersForm" name="bulk_action" value="delete" class="btn btn-sm btn-outline-danger" onclick="return confirm('Excluir todos os usuários locais selecionados?');">Excluir Selecionados</button>
                </div>
            </div>
            <div class="table-responsive">
            <table class="table table-bordered bg-white align-middle table-actions-sticky">
                <thead class="table-dark">
                    <tr><th style="width:32px;"><input type="checkbox" class="form-check-input" id="selectAllLocalUsers"></th><th>Usuário</th><th>Nome</th><th>Perfil</th><th>Status</th><th>Criado em</th><th class="text-center">Ações</th></tr>
                </thead>
                <tbody>
                    <?php
                    $localUsers = $db->query("SELECT * FROM local_users ORDER BY username ASC");
                    $count = 0;
                    while ($u = $localUsers->fetch()):
                        $count++;
                        $isSelf = $u['username'] === ($_SESSION['user_logged_in'] ?? null) && ($_SESSION['auth_type'] ?? '') === 'local';
                    ?>
                    <tr>
                        <td><input type="checkbox" class="form-check-input local-user-check" name="ids[]" value="<?= (int)$u['id'] ?>" form="bulkLocalUsersForm" <?= $isSelf ? 'disabled' : '' ?>></td>
                        <td><?= htmlspecialchars($u['username']) ?> <?php if ($isSelf): ?><span class="badge bg-info">Você</span><?php endif; ?></td>
                        <td><?= htmlspecialchars($u['full_name']) ?></td>
                        <td>
                            <?php if ($u['role'] === 'admin'): ?>
                                <span class="badge bg-warning text-dark">Administrador</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Usuário Padrão</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($u['enabled']): ?>
                                <span class="badge bg-success">Habilitado</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Desabilitado</span>
                            <?php endif; ?>
                        </td>
                        <td><small class="mono text-muted"><?= htmlspecialchars($u['created_at']) ?></small></td>
                        <td class="text-center text-nowrap">
                            <a href="index.php?page=users&tab=local&edit=<?= (int)$u['id'] ?>" class="btn btn-sm btn-outline-primary" title="Editar"><i class="bi bi-pencil"></i></a>
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="area" value="local">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <?php if ($u['enabled']): ?>
                                    <input type="hidden" name="action" value="disable">
                                    <button class="btn btn-sm btn-outline-warning" title="Desabilitar" <?= $isSelf ? 'disabled' : '' ?>><i class="bi bi-slash-circle"></i></button>
                                <?php else: ?>
                                    <input type="hidden" name="action" value="enable">
                                    <button class="btn btn-sm btn-outline-success" title="Habilitar"><i class="bi bi-check-circle"></i></button>
                                <?php endif; ?>
                            </form>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Excluir este usuário local?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="area" value="local">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Excluir" <?= $isSelf ? 'disabled' : '' ?>><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    <?php if ($count === 0): ?>
                        <tr><td colspan="7" class="text-center text-muted py-3">Nenhum usuário local cadastrado.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
            <script>
            document.getElementById('selectAllLocalUsers')?.addEventListener('change', function () {
                document.querySelectorAll('.local-user-check:not(:disabled)').forEach(function (cb) { cb.checked = this.checked; }, this);
            });
            </script>
        </div>
    </div>

<?php elseif ($tab === 'ldap'): ?>

    <?php
    $ldapError = null;
    $ldapUsers = ldapListGroupMembersCached($ldapSettings, $ldapError);
    $cacheInfo = $_SESSION['ldap_members_cache'] ?? null;

    $blockedRows = $db->query("SELECT username FROM ldap_blocked_users")->fetchAll(PDO::FETCH_COLUMN);
    $blockedSet = array_flip($blockedRows);

    $roleRows = $db->query("SELECT username, role FROM ldap_user_roles")->fetchAll();
    $roleMap = [];
    foreach ($roleRows as $r) { $roleMap[$r['username']] = $r['role']; }
    ?>

    <?php if ($ldapError): ?>
        <div class="alert alert-warning"><?= htmlspecialchars($ldapError) ?></div>
    <?php endif; ?>
    <?php if ($ldapMessage): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($ldapMessage) ?></div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <p class="text-muted mb-0">Usuários que pertencem ao grupo AD configurado (<code><?= htmlspecialchars($ldapSettings['ldap_group']) ?></code>). Usuários ainda não classificados aqui entram como <strong>Administrador</strong> por padrão. Bloquear/rebaixar aqui não altera a conta no Active Directory — apenas afeta o acesso a esta aplicação.</p>
    </div>
    <div class="d-flex justify-content-end align-items-center mb-2">
        <?php if ($cacheInfo): ?>
            <span class="text-muted small me-2">Lista obtida do AD há <?= max(0, time() - $cacheInfo['time']) ?>s</span>
        <?php endif; ?>
        <a href="index.php?page=users&tab=ldap&refresh=1" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-clockwise"></i> Atualizar Lista</a>
    </div>

    <form method="POST" id="bulkLdapUsersForm">
        <?= csrfField() ?>
        <input type="hidden" name="area" value="ldap">
    </form>
    <?php if (!empty($ldapUsers)): ?>
    <div class="card mb-2">
        <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
            <span class="text-muted small me-2"><i class="bi bi-check2-square"></i> Ações em massa:</span>
            <button type="submit" form="bulkLdapUsersForm" name="bulk_action" value="block" class="btn btn-sm btn-outline-danger">Bloquear Selecionados</button>
            <button type="submit" form="bulkLdapUsersForm" name="bulk_action" value="unblock" class="btn btn-sm btn-outline-success">Desbloquear Selecionados</button>
            <span class="vr mx-1"></span>
            <button type="submit" form="bulkLdapUsersForm" name="bulk_role_action" value="make_admin" class="btn btn-sm btn-outline-warning">Tornar Admin (Selecionados)</button>
            <button type="submit" form="bulkLdapUsersForm" name="bulk_role_action" value="make_standard" class="btn btn-sm btn-outline-secondary">Tornar Padrão (Selecionados)</button>
        </div>
    </div>
    <?php endif; ?>

    <div class="table-responsive">
    <table class="table table-bordered bg-white align-middle table-actions-sticky">
        <thead class="table-dark">
            <tr><th style="width:32px;"><input type="checkbox" class="form-check-input" id="selectAllLdapUsers"></th><th>Usuário</th><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Status</th><th class="text-center">Ações</th></tr>
        </thead>
        <tbody>
            <?php if (empty($ldapUsers)): ?>
                <tr><td colspan="7" class="text-center text-muted py-3">Nenhum usuário encontrado (ou conta de serviço não configurada).</td></tr>
            <?php else: ?>
                <?php foreach ($ldapUsers as $u): ?>
                    <?php
                        $isBlocked = isset($blockedSet[$u['username']]);
                        $role = $roleMap[$u['username']] ?? 'admin';
                        $isSelf = $u['username'] === ($_SESSION['user_logged_in'] ?? null) && ($_SESSION['auth_type'] ?? '') === 'ldap';
                    ?>
                    <tr class="<?= $isBlocked ? 'table-light text-muted' : '' ?>">
                        <td><input type="checkbox" class="form-check-input ldap-user-check" name="ids[]" value="<?= htmlspecialchars($u['username']) ?>" form="bulkLdapUsersForm" <?= $isSelf ? 'disabled' : '' ?>></td>
                        <td><?= htmlspecialchars($u['username']) ?> <?php if ($isSelf): ?><span class="badge bg-info">Você</span><?php endif; ?></td>
                        <td><?= htmlspecialchars($u['name']) ?></td>
                        <td><?= htmlspecialchars($u['email']) ?></td>
                        <td>
                            <?php if ($role === 'admin'): ?>
                                <span class="badge bg-warning text-dark">Administrador</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Usuário Padrão</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isBlocked): ?>
                                <span class="badge bg-danger">Bloqueado</span>
                            <?php else: ?>
                                <span class="badge bg-success">Permitido</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center text-nowrap">
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="area" value="ldap">
                                <input type="hidden" name="username" value="<?= htmlspecialchars($u['username']) ?>">
                                <?php if ($role === 'admin'): ?>
                                    <input type="hidden" name="action" value="make_standard">
                                    <button class="btn btn-sm btn-outline-secondary" title="Tornar Usuário Padrão" <?= $isSelf ? 'disabled' : '' ?>>Tornar Padrão</button>
                                <?php else: ?>
                                    <input type="hidden" name="action" value="make_admin">
                                    <button class="btn btn-sm btn-outline-warning" title="Tornar Administrador">Tornar Admin</button>
                                <?php endif; ?>
                            </form>
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="area" value="ldap">
                                <input type="hidden" name="username" value="<?= htmlspecialchars($u['username']) ?>">
                                <?php if ($isBlocked): ?>
                                    <input type="hidden" name="action" value="unblock">
                                    <button class="btn btn-sm btn-outline-success">Desbloquear</button>
                                <?php else: ?>
                                    <input type="hidden" name="action" value="block">
                                    <button class="btn btn-sm btn-outline-danger">Bloquear</button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
    <script>
    document.getElementById('selectAllLdapUsers')?.addEventListener('change', function () {
        document.querySelectorAll('.ldap-user-check:not(:disabled)').forEach(function (cb) { cb.checked = this.checked; }, this);
    });
    </script>

<?php elseif ($tab === 'settings'): ?>

    <?php if ($settingsMessage): ?>
        <div class="alert alert-<?= $settingsMessage['type'] ?>"><?= htmlspecialchars($settingsMessage['text']) ?></div>
    <?php endif; ?>
    <?php if ($testResult): ?>
        <div class="alert alert-<?= $testResult['ok'] ? 'success' : 'danger' ?>"><?= htmlspecialchars($testResult['text']) ?></div>
    <?php endif; ?>

    <div class="card" style="max-width: 640px;">
        <div class="card-header">Configurações de Conexão LDAP / Active Directory</div>
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="area" value="settings">
                <div class="mb-2">
                    <label class="form-label small text-muted mb-0">Servidor LDAP</label>
                    <input type="text" name="ldap_server" class="form-control" value="<?= htmlspecialchars($ldapSettings['ldap_server']) ?>" placeholder="ldap://faesa.br" required>
                </div>
                <div class="mb-2">
                    <label class="form-label small text-muted mb-0">Sufixo de domínio (usado no login: usuario + sufixo)</label>
                    <input type="text" name="ldap_domain" class="form-control" value="<?= htmlspecialchars($ldapSettings['ldap_domain']) ?>" placeholder="@faesa.br" required>
                </div>
                <div class="mb-2">
                    <label class="form-label small text-muted mb-0">Base DN</label>
                    <input type="text" name="ldap_basedn" class="form-control" value="<?= htmlspecialchars($ldapSettings['ldap_basedn']) ?>" placeholder="DC=faesa,DC=br" required>
                </div>
                <div class="mb-2">
                    <label class="form-label small text-muted mb-0">DN do grupo autorizado</label>
                    <input type="text" name="ldap_group" class="form-control" value="<?= htmlspecialchars($ldapSettings['ldap_group']) ?>" required>
                </div>
                <hr>
                <p class="text-muted small">Conta de serviço usada apenas para <strong>listar</strong> os usuários do grupo na aba "Usuários LDAP / AD" (não é necessária para o login normal).</p>
                <div class="mb-2">
                    <label class="form-label small text-muted mb-0">Usuário de serviço (ex: svc_ldap@faesa.br ou DN completo)</label>
                    <input type="text" name="bind_username" class="form-control" value="<?= htmlspecialchars($ldapSettings['bind_username']) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label small text-muted mb-0">Senha de serviço</label>
                    <input type="password" name="bind_password" class="form-control" placeholder="Deixe em branco para manter a senha atual">
                </div>
                <button type="submit" name="action" value="save" class="btn btn-outline-success">Salvar Configurações</button>
                <button type="submit" name="action" value="test" class="btn btn-outline-secondary">Testar Conta de Serviço</button>
            </form>
        </div>
    </div>

<?php endif; ?>
