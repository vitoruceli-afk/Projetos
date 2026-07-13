<?php
echo "Teste 1: PHP está funcionando<br>";

// Teste 2: Autoload
if (file_exists(__DIR__ . '/../config/Config.php')) {
    echo "✅ Arquivo Config.php encontrado<br>";
    require __DIR__ . '/../config/Config.php';
    echo "✅ Config.php carregado<br>";
} else {
    echo "❌ Arquivo Config.php não encontrado<br>";
}

// Teste 3: Database
if (file_exists(__DIR__ . '/../config/Database.php')) {
    echo "✅ Arquivo Database.php encontrado<br>";
} else {
    echo "❌ Arquivo Database.php não encontrado<br>";
}

echo "Teste concluído!";
?>