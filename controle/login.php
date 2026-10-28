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
    <link rel="stylesheet" href="<?= url('assets/css/theme.css') ?>">
</head>
<body>
<div class="login-stage">
    <div class="login-card">
        <div class="brand">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                <path d="M12 2l9 5v7c0 7-6 9-9 11-3-2-9-4-9-11V7l9-5z"/>
                <path d="M12 13v5M9 15h6"/>
            </svg>
            <span class="brand-name"><?= e(Config::get('app.nome')) ?></span>
        </div>

        <?php if ($erro !== null): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?= e($erro) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
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

        <div class="login-foot">
            <span>Autenticação Local ou LDAP/Active Directory</span>
            <span><?= date('Y') ?></span>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
