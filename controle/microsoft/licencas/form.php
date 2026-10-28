<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Models\MsLicenca;

require __DIR__ . '/../../includes/bootstrap.php';
Auth::exigirAdmin();

$id       = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editando = $id > 0;
$registro = $editando ? MsLicenca::buscarPorId($id) : null;

if ($editando && $registro === null) {
    Session::flash('danger', 'Licença não encontrada.');
    header('Location: ' . url('microsoft/licencas/listar.php'));
    exit;
}

$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codigo    = trim($_POST['codigo_licenca'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $valor     = (float) str_replace(',', '.', $_POST['valor'] ?? '0');
    $modo      = trim($_POST['modo_cobranca'] ?? '');

    if ($codigo === '')    { $erros[] = 'Informe o código.'; }
    if ($descricao === '') { $erros[] = 'Informe a descrição.'; }

    if ($erros === []) {
        if ($editando) {
            MsLicenca::atualizar($id, $codigo, $descricao, $valor, $modo);
            Session::flash('success', 'Licença atualizada.');
        } else {
            MsLicenca::criar($codigo, $descricao, $valor, $modo);
            Session::flash('success', 'Licença criada.');
        }
        header('Location: ' . url('microsoft/licencas/listar.php'));
        exit;
    }
}

$contexto     = 'microsoft';
$tituloPagina = $editando ? 'Editar Licença' : 'Nova Licença';
require __DIR__ . '/../../includes/header.php';

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
                <label class="form-label">Código</label>
                <input type="text" name="codigo_licenca" class="form-control" value="<?= $v('codigo_licenca') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Descrição</label>
                <input type="text" name="descricao" class="form-control" value="<?= $v('descricao') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Valor</label>
                <input type="number" step="0.01" name="valor" class="form-control"
                       value="<?= e($_POST['valor'] ?? $registro['valor'] ?? '') ?>" required>
            </div>
            <div class="mb-4">
                <label class="form-label">Modo de Cobrança</label>
                <input type="text" name="modo_cobranca" class="form-control" value="<?= $v('modo_cobranca') ?>">
            </div>
            <button type="submit" class="btn btn-success">Salvar</button>
            <a href="<?= url('microsoft/licencas/listar.php') ?>" class="btn btn-secondary">Voltar</a>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
