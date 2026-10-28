<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Models\TelefoniaConta;
use App\Models\Pep;

require __DIR__ . '/../../includes/bootstrap.php';
Auth::exigirAdmin();

$id       = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editando = $id > 0;
$registro = $editando ? TelefoniaConta::buscarPorId($id) : null;

if ($editando && $registro === null) {
    Session::flash('danger', 'Conta não encontrada.');
    header('Location: ' . url('telefonia/contas/listar.php'));
    exit;
}

$peps  = Pep::listar();
$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome      = trim($_POST['nome_usuario'] ?? '');
    $telefone  = trim($_POST['telefone'] ?? '');
    $operadora = trim($_POST['operadora'] ?? '');
    $pepId     = (int) ($_POST['pep_id'] ?? 0);
    $valor     = (float) str_replace(',', '.', $_POST['valor'] ?? '0');
    $contaTelefonia = trim($_POST['conta_telefonia'] ?? '');

    if ($nome === '')     { $erros[] = 'Informe o nome do usuário.'; }
    if ($telefone === '') { $erros[] = 'Informe o número de telefone.'; }
    if ($pepId === 0)     { $erros[] = 'Selecione um PEP.'; }

    if ($erros === []) {
        if ($editando) {
            TelefoniaConta::atualizar($id, $nome, $telefone, $operadora, $pepId, $valor, $contaTelefonia);
            Session::flash('success', 'Conta atualizada.');
        } else {
            TelefoniaConta::criar($nome, $telefone, $operadora, $pepId, $valor, $contaTelefonia);
            Session::flash('success', 'Conta criada.');
        }
        header('Location: ' . url('telefonia/contas/listar.php'));
        exit;
    }
}

$contexto     = 'telefonia';
$tituloPagina = $editando ? 'Editar Conta' : 'Nova Conta';
require __DIR__ . '/../../includes/header.php';

$g = static fn(string $c) => e($_POST[$c] ?? $registro[$c] ?? '');
$pepVal = (int) ($_POST['pep_id'] ?? $registro['pep_id'] ?? 0);
?>

<h2 class="mb-4"><?= e($tituloPagina) ?></h2>

<?php if ($erros !== []): ?>
    <div class="alert alert-danger">
        <?php foreach ($erros as $erro): ?><div><?= e($erro) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($peps === []): ?>
    <div class="alert alert-warning">
        É necessário ter <a href="<?= url('peps/listar.php') ?>">PEPs</a> cadastrados.
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="POST">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Nome do Usuário</label>
                    <input type="text" name="nome_usuario" class="form-control" value="<?= $g('nome_usuario') ?>" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Telefone</label>
                    <input type="text" name="telefone" class="form-control" value="<?= $g('telefone') ?>" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Operadora</label>
                    <input type="text" name="operadora" class="form-control"
                           placeholder="Ex.: Claro, TIM, Oi..."
                           value="<?= $g('operadora') ?>">
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
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
                <div class="col-md-3 mb-3">
                    <label class="form-label">Valor (consumo do mês)</label>
                    <input type="number" step="0.01" name="valor" class="form-control"
                           value="<?= e($_POST['valor'] ?? $registro['valor'] ?? '') ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Conta Telefonia</label>
                    <input type="text" name="conta_telefonia" class="form-control" value="<?= $g('conta_telefonia') ?>">
                </div>
            </div>

            <button type="submit" class="btn btn-success">Salvar</button>
            <a href="<?= url('telefonia/contas/listar.php') ?>" class="btn btn-secondary">Voltar</a>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
