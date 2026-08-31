<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_logged_in'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado.']);
    exit;
}

$busca = trim($_GET['busca'] ?? '');
if (mb_strlen($busca) < 2) {
    echo json_encode(['pacientes' => []]);
    exit;
}

$db = getDB();
$buscaCpf = preg_replace('/\D/', '', $busca);

if ($buscaCpf !== '') {
    $sql = "SELECT id, nome_completo, cpf FROM pacientes WHERE nome_completo LIKE :b OR cpf LIKE :bc ORDER BY nome_completo ASC LIMIT 8";
    $params = [':b' => "%{$busca}%", ':bc' => "%{$buscaCpf}%"];
} else {
    $sql = "SELECT id, nome_completo, cpf FROM pacientes WHERE nome_completo LIKE :b ORDER BY nome_completo ASC LIMIT 8";
    $params = [':b' => "%{$busca}%"];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$pacientes = $stmt->fetchAll();

echo json_encode([
    'pacientes' => array_map(function ($p) {
        return [
            'id' => (int)$p['id'],
            'nome_completo' => $p['nome_completo'],
            'cpf' => formatarCPF($p['cpf']),
        ];
    }, $pacientes),
]);
