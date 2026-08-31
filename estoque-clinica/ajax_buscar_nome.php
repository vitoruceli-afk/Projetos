<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_logged_in'])) {
    http_response_code(401);
    echo json_encode(['itens' => []]);
    exit;
}

$busca = trim($_GET['busca'] ?? '');
if (mb_strlen($busca) < 2) {
    echo json_encode(['itens' => []]);
    exit;
}

$db = getDB();
$itens = [];

// Busca leve (só o suficiente pra listar e deixar o operador escolher) — ao clicar num
// resultado, o front busca o detalhe completo por id via ajax_buscar_item.php.
$stmtM = $db->prepare("SELECT id, produto, substancia, laboratorio, apresentacao
    FROM medicamentos_anvisa WHERE produto LIKE :b OR substancia LIKE :b ORDER BY produto ASC LIMIT 8");
$stmtM->execute([':b' => "%{$busca}%"]);
foreach ($stmtM->fetchAll() as $m) {
    $itens[] = [
        'tipo' => 'medicamento',
        'id' => (int)$m['id'],
        'titulo' => $m['produto'],
        'subtitulo' => trim(($m['laboratorio'] ?: '') . ($m['apresentacao'] ? ' · ' . $m['apresentacao'] : ''), ' ·'),
    ];
}

$stmtI = $db->prepare("SELECT id, nome_comercial, marca, categoria
    FROM insumos WHERE nome_comercial LIKE :b OR marca LIKE :b ORDER BY nome_comercial ASC LIMIT 8");
$stmtI->execute([':b' => "%{$busca}%"]);
foreach ($stmtI->fetchAll() as $i) {
    $itens[] = [
        'tipo' => 'insumo',
        'id' => (int)$i['id'],
        'titulo' => $i['nome_comercial'],
        'subtitulo' => trim(($i['marca'] ?: '') . ($i['categoria'] ? ' · ' . $i['categoria'] : ''), ' ·'),
    ];
}

echo json_encode(['itens' => $itens]);
