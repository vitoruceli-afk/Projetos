<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $error = '';

    if ($username && $password) {
        $db = getDB();

        // Primeiro tenta autenticar como usuário local (cadastrado em Usuários > Usuários Locais).
        $stmt = $db->prepare("SELECT * FROM local_users WHERE username = :u");
        $stmt->bindValue(':u', $username);
        $stmt->execute();
        $localUser = $stmt->fetch();

        if ($localUser) {
            if (!$localUser['enabled']) {
                $error = "Esta conta local está desabilitada. Contate um administrador.";
            } elseif (password_verify($password, $localUser['password_hash'])) {
                $_SESSION['user_logged_in'] = $localUser['username'];
                $_SESSION['auth_type'] = 'local';
                header("Location: index.php");
                exit;
            } else {
                $error = "Usuário ou senha incorretos.";
            }
        } else {
            // Não é um usuário local: tenta autenticar via LDAP/Active Directory.
            $settings = getLdapSettings();
            $ldap = @ldap_connect($settings['ldap_server']);
            @ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
            @ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);

            if ($ldap) {
                $bind = @ldap_bind($ldap, $username . $settings['ldap_domain'], $password);
                if ($bind) {
                    // Autenticado, agora verificar pertencimento ao grupo usando filtro LDAP avançado
                    $safeUsername = ldap_escape($username, '', LDAP_ESCAPE_FILTER);
                    $filter = "(&(objectClass=user)(sAMAccountName=" . $safeUsername . ")(memberOf=" . $settings['ldap_group'] . "))";
                    $search = @ldap_search($ldap, $settings['ldap_basedn'], $filter);
                    $entries = @ldap_get_entries($ldap, $search);

                    if ($entries && $entries['count'] > 0) {
                        $blockStmt = $db->prepare("SELECT 1 FROM ldap_blocked_users WHERE username = :u");
                        $blockStmt->bindValue(':u', $username);
                        $blockStmt->execute();

                        if ($blockStmt->fetch()) {
                            $error = "Seu acesso a esta aplicação foi bloqueado por um administrador.";
                        } else {
                            $_SESSION['user_logged_in'] = $username;
                            $_SESSION['auth_type'] = 'ldap';
                            header("Location: index.php");
                            exit;
                        }
                    } else {
                        $error = "Acesso negado: Usuário não pertence ao grupo de segurança autorizado.";
                    }
                } else {
                    $error = "Usuário ou senha incorretos.";
                }
            } else {
                $error = "Não foi possível conectar ao servidor LDAP.";
            }
        }
    } else {
        $error = "Preencha usuário e senha.";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Login - Mikrotik Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; display: flex; align-items: center; justify-content: center; height: 100vh; }
        .login-card { width: 100%; max-width: 400px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); border-radius: 8px; background: #fff; }
    </style>
</head>
<body>
    <div class="login-card">
        <h3 class="text-center mb-4">Mikrotik Manager</h3>
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <?= csrfField() ?>
            <div class="mb-3">
                <label>Usuário (AD ou local)</label>
                <input type="text" name="username" class="form-control" required autofocus>
            </div>
            <div class="mb-3">
                <label>Senha</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Entrar</button>
        </form>
    </div>
</body>
</html>