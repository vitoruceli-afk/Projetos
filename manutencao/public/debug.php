<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

define('BASE_PATH', dirname(__DIR__));

// Autoload
spl_autoload_register(function ($class) {
    $paths = [
        BASE_PATH . '/config/',
        BASE_PATH . '/core/',
        BASE_PATH . '/models/',
    ];
    
    foreach ($paths as $path) {
        $file = $path . str_replace('\\', DIRECTORY_SEPARATOR, strrchr($class, '\\')) . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

require_once BASE_PATH . '/config/Config.php';
require_once BASE_PATH . '/config/Database.php';

\Config\Config::init();

echo "<h1>🔍 Debug - Verificação de Login</h1>";

// Teste 1: Banco conectado?
echo "<h2>1. Banco de Dados</h2>";
try {
    $db = \Config\Database::getInstance();
    echo "✅ Banco de dados conectado<br>";
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "<br>";
    exit;
}

// Teste 2: Usuário admin existe?
echo "<h2>2. Usuário Admin</h2>";
$result = $db->fetch("SELECT * FROM users WHERE username = 'admin'");
if ($result) {
    echo "✅ Usuário admin encontrado<br>";
    echo "ID: " . $result['id'] . "<br>";
    echo "Username: " . $result['username'] . "<br>";
    echo "Email: " . $result['email'] . "<br>";
    echo "Status: " . $result['status'] . "<br>";
    
    // Teste a senha
    echo "<h2>3. Teste de Senha</h2>";
    $senha_teste = "admin";
    $hash = $result['password_hash'];
    
    if (password_verify($senha_teste, $hash)) {
        echo "✅ Senha 'admin' está correta!<br>";
    } else {
        echo "❌ Senha 'admin' está INCORRETA<br>";
        echo "Hash armazenado: " . $hash . "<br>";
        echo "Hash gerado: " . password_hash("admin", PASSWORD_DEFAULT) . "<br>";
    }
} else {
    echo "❌ Usuário admin NÃO encontrado!<br>";
    echo "<h3>Criar usuário agora:</h3>";
    
    $hash_correto = password_hash("admin", PASSWORD_DEFAULT);
    echo "Cole este comando no phpMyAdmin SQL:<br>";
    echo "<code>INSERT INTO users (username, email, password_hash, full_name, auth_type, profile_id, status) 
    VALUES ('admin', 'admin@manutencao.local', '$hash_correto', 'Administrador', 'local', 1, 'active');</code><br>";
}

// Teste 3: Arquivo de login existe?
echo "<h2>4. Arquivo Login</h2>";
$login_path = BASE_PATH . '/views/login.php';
if (file_exists($login_path)) {
    echo "✅ Arquivo login.php encontrado<br>";
} else {
    echo "❌ Arquivo login.php NÃO encontrado<br>";
}

// Teste 4: Controller existe?
echo "<h2>5. AuthController</h2>";
$controller_path = BASE_PATH . '/controllers/AuthController.php';
if (file_exists($controller_path)) {
    echo "✅ Arquivo AuthController.php encontrado<br>";
} else {
    echo "❌ Arquivo AuthController.php NÃO encontrado<br>";
}

echo "<hr>";
echo "<h2>Testes Concluídos</h2>";
echo "Se algum teste falhou, me avise!";
?>