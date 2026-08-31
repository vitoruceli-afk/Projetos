<?php
requireAdmin();
$db = getDB();
$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrfVerify();
    $action = $_POST['action'];

    if ($action === 'add') {
        $username = trim($_POST['username'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'usuario';

        if ($username === '' || $password === '') {
            $formError = 'Usuário e senha são obrigatórios.';
        } elseif (strlen($password) < 8) {
            $formError = 'A senha deve ter pelo menos 8 caracteres.';
        } else {
            try {
                $db->prepare("INSERT INTO local_users (username, password_hash, full_name, enabled, role) VALUES (:u, :p, :n, 1, :r)")
                   ->execute([':u' => $username, ':p' => password_hash($password, PASSWORD_DEFAULT), ':n' => $fullName, ':r' => $role]);
                header("Location: index.php?page=usuarios");
                exit;
            } catch (PDOException $e) {
                $formError = (strpos($e->getMessage(), 'Duplicate') !== false) ? 'Já existe um usuário com esse nome.' : 'Erro ao salvar: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'usuario';

        $stmt = $db->prepare("SELECT username, role FROM local_users WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $target = $stmt->fetch();
        $isSelf = $target && $target['username'] === $_SESSION['user_logged_in'];

        if ($isSelf && $target['role'] === 'admin' && $role !== 'admin') {
            $formError = 'Você não pode remover seu próprio perfil de Administrador.';
        } elseif ($password !== '' && strlen($password) < 8) {
            $formError = 'A senha deve ter pelo menos 8 caracteres.';
        } else {
            if ($password !== '') {
                $db->prepare("UPDATE local_users SET full_name = :n, password_hash = :p, role = :r WHERE id = :id")
                   ->execute([':n' => $fullName, ':p' => password_hash($password, PASSWORD_DEFAULT), ':r' => $role, ':id' => $id]);
            } else {
                $db->prepare("UPDATE local_users SET full_name = :n, role = :r WHERE id = :id")
                   ->execute([':n' => $fullName, ':r' => $role, ':id' => $id]);
            }
            header("Location: index.php?page=usuarios");
            exit;
        }
    } elseif ($action === 'enable' || $action === 'disable') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT username FROM local_users WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $target = $stmt->fetch();

        if ($target && $action === 'disable' && $target['username'] === $_SESSION['user_logged_in']) {
            $formError = 'Você não pode desabilitar sua própria conta enquanto estiver logado com ela.';
        } elseif ($target) {
            $db->prepare("UPDATE local_users SET enabled = :e WHERE id = :id")->execute([':e' => $action === 'enable' ? 1 : 0, ':id' => $id]);
            header("Location: index.php?page=usuarios");
            exit;
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT username FROM local_users WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $target = $stmt->fetch();

        if ($target && $target['username'] === $_SESSION['user_logged_in']) {
            $formError = 'Você não pode excluir sua própria conta enquanto estiver logado com ela.';
        } elseif ($target) {
            $db->prepare("DELETE FROM local_users WHERE id = :id")->execute([':id' => $id]);
            header("Location: index.php?page=usuarios");
            exit;
        }
    }
}

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM local_users WHERE id = :id");
    $stmt->bindValue(':id', (int)$_GET['edit'], PDO::PARAM_INT);
    $stmt->execute();
    $editing = $stmt->fetch();
}

$users = $db->query("SELECT * FROM local_users ORDER BY username ASC");
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Usuários</h1>
        <div class="page-sub">Administrador tem acesso total; Usuário Padrão só acessa o Dashboard e o Explorador de Eventos</div>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><?= $editing ? 'Editar Usuário' : 'Novo Usuário' ?></div>
            <div class="card-body">
                <?php if ($formError): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($formError) ?></div><?php endif; ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'add' ?>">
                    <?php if ($editing): ?>
                        <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                        <div class="mb-2">
                            <label class="form-label">Usuário</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($editing['username']) ?>" disabled>
                        </div>
                    <?php else: ?>
                        <div class="mb-2">
                            <label class="form-label">Usuário</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                    <?php endif; ?>
                    <div class="mb-2">
                        <label class="form-label">Nome completo</label>
                        <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($editing['full_name'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Perfil</label>
                        <select name="role" class="form-select">
                            <option value="usuario" <?= (($editing['role'] ?? 'usuario') === 'usuario') ? 'selected' : '' ?>>Usuário Padrão</option>
                            <option value="admin" <?= (($editing['role'] ?? '') === 'admin') ? 'selected' : '' ?>>Administrador</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Senha</label>
                        <input type="password" name="password" class="form-control" placeholder="<?= $editing ? 'Deixe em branco para manter' : 'Mínimo 8 caracteres' ?>" <?= $editing ? '' : 'required' ?>>
                    </div>
                    <button class="btn btn-outline-success w-100"><?= $editing ? 'Salvar Alterações' : 'Criar Usuário' ?></button>
                    <?php if ($editing): ?><a href="index.php?page=usuarios" class="btn btn-outline-secondary w-100 mt-2">Cancelar Edição</a><?php endif; ?>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="table-responsive">
        <table class="table table-bordered bg-white align-middle table-actions-sticky">
            <thead class="table-dark"><tr><th>Usuário</th><th>Nome</th><th>Perfil</th><th>Origem</th><th>Status</th><th>Criado em</th><th>Ações</th></tr></thead>
            <tbody>
                <?php $count = 0; while ($u = $users->fetch()): $count++; $isSelf = $u['username'] === $_SESSION['user_logged_in']; ?>
                <tr>
                    <td><?= htmlspecialchars($u['username']) ?> <?php if ($isSelf): ?><span class="badge bg-info">Você</span><?php endif; ?></td>
                    <td><?= htmlspecialchars($u['full_name']) ?></td>
                    <td><?= $u['role'] === 'admin' ? '<span class="badge bg-warning text-dark">Administrador</span>' : '<span class="badge bg-secondary">Usuário Padrão</span>' ?></td>
                    <td><?= ($u['origem'] ?? 'local') === 'ad' ? '<span class="badge bg-info">Active Directory</span>' : '<span class="badge bg-light">Local</span>' ?></td>
                    <td><?= $u['enabled'] ? '<span class="badge bg-success">Habilitado</span>' : '<span class="badge bg-danger">Desabilitado</span>' ?></td>
                    <td><small class="mono text-muted"><?= htmlspecialchars($u['created_at']) ?></small></td>
                    <td class="text-nowrap">
                        <a href="index.php?page=usuarios&edit=<?= (int)$u['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form method="POST" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                            <?php if ($u['enabled']): ?>
                                <input type="hidden" name="action" value="disable">
                                <button class="btn btn-sm btn-outline-warning" <?= $isSelf ? 'disabled' : '' ?>><i class="bi bi-slash-circle"></i></button>
                            <?php else: ?>
                                <input type="hidden" name="action" value="enable">
                                <button class="btn btn-sm btn-outline-success"><i class="bi bi-check-circle"></i></button>
                            <?php endif; ?>
                        </form>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Excluir este usuário?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" <?= $isSelf ? 'disabled' : '' ?>><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php if ($count === 0): ?><tr><td colspan="7" class="text-center text-muted py-3">Nenhum usuário cadastrado.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
