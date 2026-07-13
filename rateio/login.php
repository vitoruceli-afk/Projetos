<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;

require __DIR__ . '/includes/bootstrap.php';

if (Auth::logado()) {
    header('Location: ' . url('index.php'));
    exit;
}

$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $senha = (string) ($_POST['senha'] ?? '');

    if (Auth::tentar($login, $senha)) {
        header('Location: ' . url('index.php'));
        exit;
    }

    $erro = 'Usuário ou senha inválidos.';
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= e(Config::get('app.nome')) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container">
    <div class="row justify-content-center align-items-center vh-100">
        <div class="col-md-4">
            <div class="card shadow">
                <div class="card-header bg-dark text-white">
                    <h4 class="mb-0">
                        <i class="bi bi-pie-chart-fill"></i>
                        <?= e(Config::get('app.nome')) ?>
                    </h4>
                </div>
                <div class="card-body">

                    <?php if ($erro !== null): ?>
                        <div class="alert alert-danger"><?= e($erro) ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Usuário ou Email</label>
                            <input type="text" name="login" class="form-control" required autofocus>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Senha</label>
                            <input type="password" name="senha" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Entrar</button>
                    </form>

                </div>
            </div>
            <p class="text-center text-muted small mt-3">
                Autenticação local ou LDAP/Active Directory
            </p>
        </div>
    </div>
</div>
</body>
</html>
