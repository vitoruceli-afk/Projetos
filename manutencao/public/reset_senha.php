<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(function ($class) {
    $paths = [
        BASE_PATH . '/config/',
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

echo "<h1>🔧 Resetar Senha Admin</h1>";

// Gerar novo hash para senha "admin"
$senha = "admin";
$hash_novo = password_hash($senha, PASSWORD_DEFAULT);

echo "<p>Gerando hash para senha: <strong>admin</strong></p>";
echo "<p>Hash gerado: <code>" . htmlspecialchars($hash_novo) . "</code></p>";

try {
    // Conectar ao banco
    $db = \Config\Database::getInstance();
    $conn = $db->getConnection();
    
    // Atualizar a senha
    $sql = "UPDATE users SET password_hash = ? WHERE username = 'admin'";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$hash_novo]);
    
    echo "<p style='color: green;'>✅ <strong>Senha do admin resetada com sucesso!</strong></p>";
    echo "<p>Faça login com:</p>";
    echo "<ul>";
    echo "<li><strong>Usuário:</strong> admin</li>";
    echo "<li><strong>Senha:</strong> admin</li>";
    echo "</ul>";
    
    echo "<p><a href='index.php?page=login' style='color: blue;'>Ir para o Login</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>