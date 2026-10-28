<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Models\MsConta;
use App\Models\MsLicenca;
use App\Models\Pep;

require __DIR__ . '/../../includes/bootstrap.php';
Auth::exigirAdmin();

$id       = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editando = $id > 0;
$registro = $editando ? MsConta::buscarPorId($id) : null;

if ($editando && $registro === null) {
    Session::flash('danger', 'Conta não encontrada.');
    header('Location: ' . url('microsoft/contas/listar.php'));
    exit;
}

$peps          = Pep::listar();
$licencas      = MsLicenca::listar();
$selecionadas  = $editando ? MsConta::licencasDaConta($id) : [];
$erros         = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome  = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pepId = (int) ($_POST['pep_id'] ?? 0);
    $sel   = array_map('intval', (array) ($_POST['licencas'] ?? []));

    if ($nome === '')  { $erros[] = 'Informe o nome.'; }
    if ($email === '') { $erros[] = 'Informe o email.'; }
    if ($pepId === 0)  { $erros[] = 'Selecione um PEP.'; }

    if ($erros === []) {
        if ($editando) {
            MsConta::atualizar($id, $nome, $email, $pepId, $sel);
            Session::flash('success', 'Conta atualizada.');
        } else {
            MsConta::criar($nome, $email, $pepId, $sel);
            Session::flash('success', 'Conta criada.');
        }
        header('Location: ' . url('microsoft/contas/listar.php'));
        exit;
    }
    $selecionadas = $sel;
}

$contexto     = 'microsoft';
$tituloPagina = $editando ? 'Editar Conta' : 'Nova Conta';
require __DIR__ . '/../../includes/header.php';

$nomeVal  = e($_POST['nome'] ?? $registro['nome'] ?? '');
$emailVal = e($_POST['email'] ?? $registro['email'] ?? '');
$pepVal   = (int) ($_POST['pep_id'] ?? $registro['pep_id'] ?? 0);
?>

<h2 class="mb-4"><?= e($tituloPagina) ?></h2>

<?php if ($erros !== []): ?>
    <div class="alert alert-danger">
        <?php foreach ($erros as $erro): ?><div><?= e($erro) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($peps === []): ?>
    <div class="alert alert-warning">
        Nenhum PEP cadastrado. Cadastre em
        <a href="<?= url('peps/listar.php') ?>">PEPs / Projetos</a> antes de criar contas.
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Nome</label>
                <input type="text" name="nome" class="form-control" value="<?= $nomeVal ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?= $emailVal ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">PEP / Projeto</label>
                <select name="pep_id" class="form-select" required>
                    <option value="">-- Selecione --</option>
                    <?php foreach ($peps as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $pepVal === (int) $p['id'] ? 'selected' : '' ?>>
                            <?= e($p['pep']) ?> &mdash; <?= e($p['projeto']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-4">
                <label class="form-label fw-bold">Licenças</label>
                <div class="card">
                    <div class="card-body">
                        <?php foreach ($licencas as $l): ?>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="licencas[]"
                                       value="<?= $l['id'] ?>" id="lic<?= $l['id'] ?>"
                                       <?= in_array((int) $l['id'], $selecionadas, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="lic<?= $l['id'] ?>">
                                    <strong><?= e($l['descricao']) ?></strong>
                                    &middot; <?= e($l['codigo_licenca']) ?>
                                    &middot; <?= money($l['valor']) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($licencas === []): ?>
                            <span class="text-muted">Nenhuma licença cadastrada.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-success">Salvar</button>
            <a href="<?= url('microsoft/contas/listar.php') ?>" class="btn btn-secondary">Voltar</a>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
