<?php
require_once 'config.php';

if (isset($_SESSION['user_logged_in'])) {
    header("Location: index.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Preencha usuário e senha.';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM local_users WHERE username = :u");
        $stmt->bindValue(':u', $username);
        $stmt->execute();
        $user = $stmt->fetch();

        if (!$user) {
            $error = 'Usuário ou senha incorretos.';
        } elseif (!$user['enabled']) {
            $error = 'Esta conta está desabilitada. Contate um administrador.';
        } elseif (!password_verify($password, $user['password_hash'])) {
            $error = 'Usuário ou senha incorretos.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_logged_in'] = $user['username'];
            $_SESSION['last_activity'] = time();
            header("Location: index.php");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Estoque Clínica</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="views/theme.css?v=<?= @filemtime(__DIR__ . '/views/theme.css') ?: time() ?>">
</head>
<body>
    <div class="login-stage">
        <div class="login-card">
            <div class="brand">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                    <path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/>
                    <path d="M12 8v6M9 11h6" stroke-width="2"/>
                </svg>
                <span class="brand-name">ESTOQUE<span>·</span>CLÍNICA</span>
            </div>
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php elseif (isset($_GET['timeout'])): ?>
                <div class="alert alert-warning">Sua sessão expirou por inatividade. Faça login novamente.</div>
            <?php endif; ?>
            <form method="POST">
                <?= csrfField() ?>
                <div class="mb-3">
                    <label class="form-label">Usuário</label>
                    <input type="text" name="username" class="form-control" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label">Senha</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Entrar</button>
            </form>
            <div class="login-foot">
                <span>Controle de Estoque de Insumos</span>
            </div>
        </div>
    </div>
</body>
</html>
