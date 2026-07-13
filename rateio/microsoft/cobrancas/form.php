<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Models\MsCobranca;

require __DIR__ . '/../../includes/bootstrap.php';
Auth::exigirAdmin();

$id       = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editando = $id > 0;
$registro = $editando ? MsCobranca::buscarPorId($id) : null;

if ($editando && $registro === null) {
    Session::flash('danger', 'Cobrança não encontrada.');
    header('Location: ' . url('microsoft/cobrancas/listar.php'));
    exit;
}

$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mes   = (int) ($_POST['mes'] ?? 0);
    $ano   = (int) ($_POST['ano'] ?? 0);
    $valor = (float) str_replace(',', '.', $_POST['valor_total'] ?? '0');

    if ($mes < 1 || $mes > 12) { $erros[] = 'Informe um mês válido (1 a 12).'; }
    if ($ano < 2000)           { $erros[] = 'Informe um ano válido.'; }

    if ($erros === []) {
        if ($editando) {
            MsCobranca::atualizar($id, $mes, $ano, $valor);
            Session::flash('success', 'Cobrança atualizada.');
        } else {
            MsCobranca::criar($mes, $ano, $valor);
            Session::flash('success', 'Cobrança criada.');
        }
        header('Location: ' . url('microsoft/cobrancas/listar.php'));
        exit;
    }
}

$contexto     = 'microsoft';
$tituloPagina = $editando ? 'Editar Cobrança' : 'Nova Cobrança';
require __DIR__ . '/../../includes/header.php';
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
                <label class="form-label">Mês</label>
                <input type="number" name="mes" min="1" max="12" class="form-control"
                       value="<?= e($_POST['mes'] ?? $registro['mes'] ?? '') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Ano</label>
                <input type="number" name="ano" class="form-control"
                       value="<?= e($_POST['ano'] ?? $registro['ano'] ?? date('Y')) ?>" required>
            </div>
            <div class="mb-4">
                <label class="form-label">Valor Total (boleto)</label>
                <input type="number" step="0.01" name="valor_total" class="form-control"
                       value="<?= e($_POST['valor_total'] ?? $registro['valor_total'] ?? '') ?>" required>
            </div>
            <button type="submit" class="btn btn-success">Salvar</button>
            <a href="<?= url('microsoft/cobrancas/listar.php') ?>" class="btn btn-secondary">Voltar</a>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
