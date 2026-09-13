<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Models\Usuario;

require __DIR__ . '/../includes/bootstrap.php';
Auth::exigirAdmin();

$id      = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editando = $id > 0;
$registro = $editando ? Usuario::buscarPorId($id) : null;

if ($editando && $registro === null) {
    Session::flash('danger', 'Usuário não encontrado.');
    header('Location: ' . url('usuarios/listar.php'));
    exit;
}

$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome    = trim($_POST['nome'] ?? '');
    $login   = trim($_POST['usuario'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $perfil  = ($_POST['perfil'] ?? 'usuario') === 'admin' ? 'admin' : 'usuario';
    $senha   = (string) ($_POST['senha'] ?? '');

    if ($nome === '')  { $erros[] = 'Informe o nome.'; }
    if ($login === '') { $erros[] = 'Informe o login.'; }
    if (!$editando && $senha === '') { $erros[] = 'Informe a senha.'; }
    if (Usuario::loginEmUso($login, $editando ? $id : null)) {
        $erros[] = 'Este login já está em uso.';
    }

    if ($erros === []) {
        if ($editando) {
            Usuario::atualizar($id, $nome, $login, $email, $perfil, $senha !== '' ? $senha : null);
            Session::flash('success', 'Usuário atualizado com sucesso.');
        } else {
            Usuario::criar($nome, $login, $email, $senha, $perfil);
            Session::flash('success', 'Usuário criado com sucesso.');
        }
        header('Location: ' . url('usuarios/listar.php'));
        exit;
    }
}

$contexto     = 'inicial';
$tituloPagina = $editando ? 'Editar Usuário' : 'Novo Usuário';
require __DIR__ . '/../includes/header.php';

$v = static fn(string $campo) => e($_POST[$campo] ?? $registro[$campo] ?? '');
$perfilAtual = $_POST['perfil'] ?? $registro['perfil'] ?? 'usuario';
?>

<h2 class="mb-4"><?= e($tituloPagina) ?></h2>

<?php if ($erros !== []): ?>
    <div class="alert alert-danger">
        <?php foreach ($erros as $erro): ?>
            <div><?= e($erro) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Nome</label>
                <input type="text" name="nome" class="form-control" value="<?= $v('nome') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Login</label>
                <input type="text" name="usuario" class="form-control" value="<?= $v('usuario') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?= $v('email') ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">
                    Senha <?= $editando ? '<small class="text-muted">(deixe em branco para manter)</small>' : '' ?>
                </label>
                <input type="password" name="senha" class="form-control" <?= $editando ? '' : 'required' ?>>
            </div>
            <div class="mb-4">
                <label class="form-label">Perfil</label>
                <select name="perfil" class="form-select">
                    <option value="usuario" <?= $perfilAtual === 'usuario' ? 'selected' : '' ?>>
                        Usuário (somente leitura e relatórios)
                    </option>
                    <option value="admin" <?= $perfilAtual === 'admin' ? 'selected' : '' ?>>
                        Administrador (acesso total)
                    </option>
                </select>
            </div>
            <button type="submit" class="btn btn-success">Salvar</button>
            <a href="<?= url('usuarios/listar.php') ?>" class="btn btn-secondary">Voltar</a>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
