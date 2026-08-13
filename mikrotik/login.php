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
                $_SESSION['last_activity'] = time();
                logActivity('auth', 'Login realizado', 'Autenticação local');
                header("Location: index.php");
                exit;
            } else {
                $error = "Usuário ou senha incorretos.";
            }
        } else {
            // Não é um usuário local: tenta autenticar via LDAP/Active Directory.
            $settings = getLdapSettings();
            $ldap = ldapConnect($settings['ldap_server']);

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
                            $_SESSION['last_activity'] = time();
                            logActivity('auth', 'Login realizado', 'Autenticação via LDAP/AD');
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Mikrotik Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="views/theme.css">
</head>
<body>
    <div class="login-stage">
        <div class="login-card">
            <div class="login-institution">
                <img src="https://www.faesa.br/wp-content/uploads/2024/08/Camada_1.svg" alt="FAESA Centro Universitário" class="login-institution-logo">
            </div>
            <div class="brand">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                    <circle cx="12" cy="12" r="2.1" fill="currentColor" stroke="none"/>
                    <circle cx="4" cy="6" r="1.6" fill="currentColor" stroke="none"/>
                    <circle cx="20" cy="6" r="1.6" fill="currentColor" stroke="none"/>
                    <circle cx="4" cy="18" r="1.6" fill="currentColor" stroke="none"/>
                    <circle cx="20" cy="18" r="1.6" fill="currentColor" stroke="none"/>
                    <path d="M12 12L4 6M12 12L20 6M12 12L4 18M12 12L20 18"/>
                </svg>
                <span class="brand-name">MIKROTIK<span>·</span>MANAGER</span>
            </div>
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php elseif (isset($_GET['timeout'])): ?>
                <div class="alert alert-warning">Sua sessão expirou por inatividade. Faça login novamente.</div>
            <?php endif; ?>
            <form method="POST">
                <?= csrfField() ?>
                <div class="mb-3">
                    <label class="form-label">Usuário (AD ou local)</label>
                    <input type="text" name="username" class="form-control" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label">Senha</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Entrar</button>
            </form>
            <div class="login-foot">
                <span>NTI · FAESA</span>
            </div>
        </div>
    </div>
</body>
</html>