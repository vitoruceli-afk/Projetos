<?php
// Iniciar session antes de qualquer coisa
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Se receber POST, processar login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Definir paths
    $base_path = dirname(__DIR__);
    
    // Carregar configurações
    require_once $base_path . '/config/Config.php';
    require_once $base_path . '/config/Database.php';
    
    // Pegue dados do formulário
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    
    // Inicializar error
    $error = '';
    
    if (empty($username) || empty($password)) {
        $error = 'Usuário e senha são obrigatórios';
    } else {
        try {
            // Conectar banco
            $db = \Config\Database::getInstance();
            
            // Buscar usuário
            $user = $db->fetch(
                "SELECT * FROM users WHERE username = :username AND status = 'active'",
                [':username' => $username]
            );
            
            if (!$user) {
                $error = 'Usuário não encontrado';
            } else {
                // Verificar senha
                if (password_verify($password, $user['password_hash'])) {
                    // Login bem sucedido!
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['user_profile_id'] = $user['profile_id'];
                    $_SESSION['full_name'] = $user['full_name'];
                    
                    // Redirecionar para dashboard
                    header('Location: index.php?page=dashboard');
                    exit();
                } else {
                    $error = 'Senha incorreta';
                }
            }
        } catch (Exception $e) {
            $error = 'Erro ao conectar: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sistema de Manutenção</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .login-container {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }

        .login-box {
            background-color: white;
            padding: 3rem;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 400px;
        }

        .login-box h1 {
            text-align: center;
            margin-bottom: 2rem;
            color: #2c3e50;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #2c3e50;
        }

        input[type="text"],
        input[type="password"],
        select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #bdc3c7;
            border-radius: 4px;
            font-family: inherit;
            font-size: 1rem;
        }

        input[type="text"]:focus,
        input[type="password"]:focus,
        select:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .alert {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
            border-left: 4px solid;
        }

        .alert-danger {
            background-color: #f8d7da;
            border-color: #e74c3c;
            color: #721c24;
        }

        button {
            width: 100%;
            padding: 0.75rem;
            background-color: #3498db;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 1.5rem;
            transition: background-color 0.3s;
        }

        button:hover {
            background-color: #2980b9;
        }

        .info {
            text-align: center;
            margin-top: 2rem;
            color: #7f8c8d;
            font-size: 0.9rem;
        }

        .info strong {
            color: #2c3e50;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <h1>🔧 Sistema de Manutenção</h1>
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="username">Usuário</label>
                    <input type="text" id="username" name="username" placeholder="Usuário ou Email" required autofocus value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="password">Senha</label>
                    <input type="password" id="password" name="password" placeholder="Senha" required>
                </div>

                <button type="submit">Entrar</button>
            </form>

            <div class="info">
                <p>Usuário padrão: <strong>admin</strong></p>
                <p>Senha: <strong>admin</strong></p>
            </div>
        </div>
    </div>
</body>
</html>