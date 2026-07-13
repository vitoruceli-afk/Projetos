<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Models\Pep;

require __DIR__ . '/../includes/bootstrap.php';
Auth::exigirAdmin();

$id       = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editando = $id > 0;
$registro = $editando ? Pep::buscarPorId($id) : null;

if ($editando && $registro === null) {
    Session::flash('danger', 'PEP não encontrado.');
    header('Location: ' . url('peps/listar.php'));
    exit;
}

$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pep     = trim($_POST['pep'] ?? '');
    $projeto = trim($_POST['projeto'] ?? '');

    if ($pep === '')     { $erros[] = 'Informe o PEP.'; }
    if ($projeto === '') { $erros[] = 'Informe o nome do projeto.'; }
    if (Pep::pepEmUso($pep, $editando ? $id : null)) {
        $erros[] = 'Este PEP já está cadastrado.';
    }

    if ($erros === []) {
        if ($editando) {
            Pep::atualizar($id, $pep, $projeto);
            Session::flash('success', 'PEP atualizado.');
        } else {
            Pep::criar($pep, $projeto);
            Session::flash('success', 'PEP cadastrado.');
        }
        header('Location: ' . url('peps/listar.php'));
        exit;
    }
}

$contexto     = 'inicial';
$tituloPagina = $editando ? 'Editar PEP' : 'Novo PEP';
require __DIR__ . '/../includes/header.php';

$v = static fn(string $c) => e($_POST[$c] ?? $registro[$c] ?? '');
?>

<h2 class="mb-4"><?= e($tituloPagina) ?></h2>

<?php if ($erros !== []): ?>
    <div class="alert alert-danger">
        <?php foreach ($erros as $erro): ?><div><?= e($erro) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="POST">
            <div class="mb-3">
                <label class="form-label">PEP</label>
                <input type="text" name="pep" class="form-control" value="<?= $v('pep') ?>" required>
            </div>
            <div class="mb-4">
                <label class="form-label">Nome do Projeto</label>
                <input type="text" name="projeto" class="form-control" value="<?= $v('projeto') ?>" required>
            </div>
            <button type="submit" class="btn btn-success">Salvar</button>
            <a href="<?= url('peps/listar.php') ?>" class="btn btn-secondary">Voltar</a>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
