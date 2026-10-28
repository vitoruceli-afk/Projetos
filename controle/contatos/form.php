<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Models\Contato;

require __DIR__ . '/../includes/bootstrap.php';
Auth::exigirAdmin();

$id       = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editando = $id > 0;
$registro = $editando ? Contato::buscarPorId($id) : null;

if ($editando && $registro === null) {
    Session::flash('danger', 'Contato não encontrado.');
    header('Location: ' . url('contatos/listar.php'));
    exit;
}

$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome      = trim($_POST['nome'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $microsoft = isset($_POST['lista_microsoft']);
    $telefonia = isset($_POST['lista_telefonia']);

    if ($nome === '') { $erros[] = 'Informe o nome.'; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $erros[] = 'Informe um e-mail válido.'; }
    if (!$microsoft && !$telefonia) { $erros[] = 'Selecione ao menos uma lista.'; }

    if ($erros === []) {
        if ($editando) {
            Contato::atualizar($id, $nome, $email, $microsoft, $telefonia);
            Session::flash('success', 'Contato atualizado.');
        } else {
            Contato::criar($nome, $email, $microsoft, $telefonia);
            Session::flash('success', 'Contato criado.');
        }
        header('Location: ' . url('contatos/listar.php'));
        exit;
    }
}

$contexto     = 'inicial';
$tituloPagina = $editando ? 'Editar Contato' : 'Novo Contato';
require __DIR__ . '/../includes/header.php';

$nomeV  = e($_POST['nome'] ?? $registro['nome'] ?? '');
$emailV = e($_POST['email'] ?? $registro['email'] ?? '');
$msV = isset($_POST['nome']) ? isset($_POST['lista_microsoft']) : (bool) ($registro['lista_microsoft'] ?? false);
$telV = isset($_POST['nome']) ? isset($_POST['lista_telefonia']) : (bool) ($registro['lista_telefonia'] ?? false);
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
                <label class="form-label">Nome</label>
                <input type="text" name="nome" class="form-control" value="<?= $nomeV ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">E-mail</label>
                <input type="email" name="email" class="form-control" value="<?= $emailV ?>" required>
            </div>
            <div class="mb-4">
                <label class="form-label d-block">Listas</label>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" name="lista_microsoft" id="lm" <?= $msV ? 'checked' : '' ?>>
                    <label class="form-check-label" for="lm">Gerência Microsoft</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" name="lista_telefonia" id="lt" <?= $telV ? 'checked' : '' ?>>
                    <label class="form-check-label" for="lt">Gerência Telefonia</label>
                </div>
            </div>
            <button type="submit" class="btn btn-success">Salvar</button>
            <a href="<?= url('contatos/listar.php') ?>" class="btn btn-secondary">Voltar</a>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
