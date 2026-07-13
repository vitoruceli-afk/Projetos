<?php
try {
    $conn = new PDO('mysql:host=localhost;dbname=manutencao_db', 'root', '');
    echo "✅ Banco de dados conectado com sucesso!";
} catch (PDOException $e) {
    echo "❌ Erro: " . $e->getMessage();
}
?>