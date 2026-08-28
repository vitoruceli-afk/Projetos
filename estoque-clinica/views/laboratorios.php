<?php
requireAdmin();
$db = getDB();
$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrfVerify();

    if ($_POST['action'] === 'add' || $_POST['action'] === 'update') {
        $nome = trim($_POST['nome'] ?? '');
        $cnpj = trim($_POST['cnpj'] ?? '');
        $contato = trim($_POST['contato'] ?? '');
        $telefone = trim($_POST['telefone'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if ($nome === '') {
            $formError = 'Informe o nome do laboratório/fabricante.';
        } else {
            if ($_POST['action'] === 'add') {
                $stmt = $db->prepare("INSERT INTO laboratorios (nome, cnpj, contato, telefone, email) VALUES (:n, :c, :ct, :t, :e)");
            } else {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $db->prepare("UPDATE laboratorios SET nome = :n, cnpj = :c, contato = :ct, telefone = :t, email = :e WHERE id = :id");
                $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            }
            $stmt->bindValue(':n', $nome);
            $stmt->bindValue(':c', $cnpj);
            $stmt->bindValue(':ct', $contato);
            $stmt->bindValue(':t', $telefone);
            $stmt->bindValue(':e', $email);
            $stmt->execute();
            header("Location: index.php?page=laboratorios");
            exit;
        }
    } elseif ($_POST['action'] === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $inUse = $db->prepare("SELECT COUNT(*) FROM insumos WHERE laboratorio_id = :id");
        $inUse->bindValue(':id', $id, PDO::PARAM_INT);
        $inUse->execute();
        if ((int)$inUse->fetchColumn() > 0) {
            $formError = 'Não é possível excluir: existem insumos cadastrados para este laboratório.';
        } else {
            $stmt = $db->prepare("DELETE FROM laboratorios WHERE id = :id");
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            header("Location: index.php?page=laboratorios");
            exit;
        }
    }
}

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM laboratorios WHERE id = :id");
    $stmt->bindValue(':id', (int)$_GET['edit'], PDO::PARAM_INT);
    $stmt->execute();
    $editing = $stmt->fetch();
}

$labs = $db->query("SELECT l.*, (SELECT COUNT(*) FROM insumos i WHERE i.laboratorio_id = l.id) AS total_insumos FROM laboratorios l ORDER BY l.nome ASC");
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Laboratórios / Fabricantes</h1>
        <div class="page-sub">Cadastro de laboratórios e fabricantes dos insumos</div>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><?= $editing ? 'Editar Laboratório' : 'Novo Laboratório' ?></div>
            <div class="card-body">
                <?php if ($formError): ?>
                    <div class="alert alert-danger py-2"><?= htmlspecialchars($formError) ?></div>
                <?php endif; ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'add' ?>">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
                    <div class="mb-2"><input type="text" name="nome" class="form-control" placeholder="Nome do laboratório/fabricante" value="<?= htmlspecialchars($editing['nome'] ?? '') ?>" required></div>
                    <div class="mb-2"><input type="text" name="cnpj" class="form-control" placeholder="CNPJ" value="<?= htmlspecialchars($editing['cnpj'] ?? '') ?>"></div>
                    <div class="mb-2"><input type="text" name="contato" class="form-control" placeholder="Nome do contato" value="<?= htmlspecialchars($editing['contato'] ?? '') ?>"></div>
                    <div class="mb-2"><input type="text" name="telefone" class="form-control" placeholder="Telefone" value="<?= htmlspecialchars($editing['telefone'] ?? '') ?>"></div>
                    <div class="mb-2"><input type="email" name="email" class="form-control" placeholder="E-mail" value="<?= htmlspecialchars($editing['email'] ?? '') ?>"></div>
                    <button class="btn btn-outline-success w-100"><?= $editing ? 'Salvar Alterações' : 'Salvar Laboratório' ?></button>
                    <?php if ($editing): ?>
                        <a href="index.php?page=laboratorios" class="btn btn-outline-secondary w-100 mt-2">Cancelar Edição</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="table-responsive">
        <table class="table table-bordered bg-white table-actions-sticky">
            <thead class="table-dark"><tr><th>Nome</th><th>CNPJ</th><th>Contato</th><th>Telefone</th><th class="text-center">Insumos</th><th>Ações</th></tr></thead>
            <tbody>
                <?php $count = 0; while ($l = $labs->fetch()): $count++; ?>
                <tr>
                    <td><?= htmlspecialchars($l['nome']) ?></td>
                    <td class="mono"><?= htmlspecialchars($l['cnpj']) ?></td>
                    <td><?= htmlspecialchars($l['contato']) ?></td>
                    <td class="mono"><?= htmlspecialchars($l['telefone']) ?></td>
                    <td class="text-center"><span class="badge bg-light"><?= (int)$l['total_insumos'] ?></span></td>
                    <td class="text-nowrap">
                        <a href="index.php?page=laboratorios&edit=<?= (int)$l['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Excluir este laboratório?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" <?= $l['total_insumos'] > 0 ? 'disabled title="Existem insumos vinculados"' : '' ?>><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php if ($count === 0): ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">Nenhum laboratório cadastrado.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
