<?php
requireAdmin();
$db = getDB();
$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrfVerify();

    if ($_POST['action'] === 'add' || $_POST['action'] === 'update') {
        $name = trim($_POST['name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $ip = trim($_POST['ip'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $port = (int)($_POST['port'] ?? 8728);
        if ($port <= 0 || $port > 65535) $port = 8728;

        if ($name === '' || $location === '' || $username === '') {
            $formError = 'Preencha todos os campos obrigatórios.';
        } elseif (!isValidRouterHost($ip)) {
            $formError = 'Endereço IP/host inválido.';
        } elseif ($_POST['action'] === 'add' && $password === '') {
            $formError = 'Informe a senha do roteador.';
        } else {
            if ($_POST['action'] === 'add') {
                $stmt = $db->prepare("INSERT INTO routers (name, location, ip, username, password, port) VALUES (:name, :loc, :ip, :user, :pass, :port)");
                $stmt->bindValue(':name', $name);
                $stmt->bindValue(':loc', $location);
                $stmt->bindValue(':ip', $ip);
                $stmt->bindValue(':user', $username);
                $stmt->bindValue(':pass', routerEncrypt($password));
                $stmt->bindValue(':port', $port, PDO::PARAM_INT);
                $stmt->execute();
                logActivity('routers', 'Cadastrou roteador', "{$name} ({$ip}:{$port})");
            } else {
                $id = (int)($_POST['id'] ?? 0);
                if ($password !== '') {
                    $stmt = $db->prepare("UPDATE routers SET name = :name, location = :loc, ip = :ip, username = :user, password = :pass, port = :port WHERE id = :id");
                    $stmt->bindValue(':pass', routerEncrypt($password));
                } else {
                    $stmt = $db->prepare("UPDATE routers SET name = :name, location = :loc, ip = :ip, username = :user, port = :port WHERE id = :id");
                }
                $stmt->bindValue(':name', $name);
                $stmt->bindValue(':loc', $location);
                $stmt->bindValue(':ip', $ip);
                $stmt->bindValue(':user', $username);
                $stmt->bindValue(':port', $port, PDO::PARAM_INT);
                $stmt->bindValue(':id', $id, PDO::PARAM_INT);
                $stmt->execute();
                logActivity('routers', 'Editou roteador', "{$name} ({$ip}:{$port})");
            }
            header("Location: index.php?page=routers");
            exit;
        }
    } elseif ($_POST['action'] === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT name, ip FROM routers WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $target = $stmt->fetch();

        $stmt = $db->prepare("DELETE FROM routers WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        if ($target) {
            logActivity('routers', 'Excluiu roteador', "{$target['name']} ({$target['ip']})");
        }
        if (($_SESSION['active_router'] ?? null) == $id) unset($_SESSION['active_router']);
        header("Location: index.php?page=routers");
        exit;
    }
}

// Carrega roteador para edição, se solicitado
$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM routers WHERE id = :id");
    $stmt->bindValue(':id', (int)$_GET['edit'], PDO::PARAM_INT);
    $stmt->execute();
    $editing = $stmt->fetch(PDO::FETCH_ASSOC);
}

$routers = $db->query("SELECT * FROM routers ORDER BY name ASC");
?>
<h1 class="page-title">Gerenciar Equipamentos Mikrotik</h1>
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><?= $editing ? 'Editar Roteador' : 'Adicionar Novo' ?></div>
            <div class="card-body">
                <?php if ($formError): ?>
                    <div class="alert alert-danger py-2"><?= htmlspecialchars($formError) ?></div>
                <?php endif; ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'add' ?>">
                    <?php if ($editing): ?>
                        <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                    <?php endif; ?>
                    <div class="mb-2"><input type="text" name="name" class="form-control" placeholder="Nome (ex: CCR-Core)" value="<?= htmlspecialchars($editing['name'] ?? '') ?>" required></div>
                    <div class="mb-2"><input type="text" name="location" class="form-control" placeholder="Local/Campus (ex: Vitoria)" value="<?= htmlspecialchars($editing['location'] ?? '') ?>" required></div>
                    <div class="mb-2"><input type="text" name="ip" class="form-control" placeholder="Endereço IP ou host" value="<?= htmlspecialchars($editing['ip'] ?? '') ?>" required></div>
                    <div class="mb-2"><input type="number" name="port" class="form-control" placeholder="Porta da API (padrão 8728)" value="<?= htmlspecialchars($editing['port'] ?? '8728') ?>" min="1" max="65535"></div>
                    <div class="mb-2"><input type="text" name="username" class="form-control" placeholder="Usuário da API" value="<?= htmlspecialchars($editing['username'] ?? '') ?>" required></div>
                    <div class="mb-2">
                        <input type="password" name="password" class="form-control" placeholder="<?= $editing ? 'Senha (deixe em branco para manter)' : 'Senha' ?>" <?= $editing ? '' : 'required' ?>>
                    </div>
                    <button class="btn btn-outline-success w-100"><?= $editing ? 'Salvar Alterações' : 'Salvar Roteador' ?></button>
                    <?php if ($editing): ?>
                        <a href="index.php?page=routers" class="btn btn-outline-secondary w-100 mt-2">Cancelar Edição</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="table-responsive">
        <table class="table table-bordered bg-white table-actions-sticky">
            <thead class="table-dark"><tr><th>Nome</th><th>Campus/Local</th><th>IP</th><th>Porta</th><th>Usuário</th><th>Ações</th></tr></thead>
            <tbody>
                <?php while($r = $routers->fetch(PDO::FETCH_ASSOC)): ?>
                <tr>
                    <td><?= htmlspecialchars($r['name']) ?></td>
                    <td><?= htmlspecialchars($r['location']) ?></td>
                    <td class="mono"><?= htmlspecialchars($r['ip']) ?></td>
                    <td class="mono"><?= htmlspecialchars($r['port']) ?></td>
                    <td><?= htmlspecialchars($r['username']) ?></td>
                    <td>
                        <a href="index.php?page=routers&edit=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i> Editar</a>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Excluir roteador?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Remover</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
