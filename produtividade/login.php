<?php
require_once 'config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM local_users WHERE username = :u");
        $stmt->bindValue(':u', $username);
        $stmt->execute();
        $user = $stmt->fetch();

        if (!$user) {
            $error = "Usuário ou senha incorretos.";
        } elseif (!$user['enabled']) {
            $error = "Esta conta está desabilitada. Contate um administrador.";
        } elseif (password_verify($password, $user['password_hash'])) {
            $_SESSION['user_logged_in'] = $user['username'];
            $_SESSION['last_activity'] = time();
            header("Location: index.php");
            exit;
        } else {
            $error = "Usuário ou senha incorretos.";
        }
    } else {
        $error = "Preencha usuário e senha.";
    }
}

// Primeiro acesso: sem nenhum usuário cadastrado, cria automaticamente um admin/admin (o próprio
// formulário avisa) para não deixar a instalação sem porta de entrada.
$db = getDB();
$semUsuarios = (int)$db->query("SELECT COUNT(*) FROM local_users")->fetchColumn() === 0;
if ($semUsuarios) {
    $db->prepare("INSERT INTO local_users (username, password_hash, full_name, role) VALUES ('admin', :h, 'Administrador', 'admin')")
       ->execute([':h' => password_hash('admin', PASSWORD_DEFAULT)]);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Produtividade</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="views/theme.css?v=<?= @filemtime(__DIR__ . '/views/theme.css') ?: time() ?>">
</head>
<body>
    <div class="login-stage">
        <div class="login-card">
            <div class="brand">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                    <circle cx="12" cy="12" r="9"/>
                    <path d="M12 7v5l3.2 2" stroke-width="2"/>
                </svg>
                <span class="brand-name">PRODUTIVIDADE<span>·</span>AW</span>
            </div>
            <?php if ($semUsuarios): ?>
                <div class="alert alert-info py-2">Instalação nova: usuário <strong>admin</strong> / senha <strong>admin</strong> criado. Troque a senha em Usuários assim que entrar.</div>
            <?php endif; ?>
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
                <span>Monitoramento de Produtividade · ActivityWatch</span>
            </div>
        </div>
    </div>
</body>
</html>
