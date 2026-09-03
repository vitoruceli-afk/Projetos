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
    echo json_encode(['fornecedores' => []]);
    exit;
}

$db = getDB();
$buscaCnpj = preg_replace('/\D/', '', $busca);

if ($buscaCnpj !== '') {
    $sql = "SELECT id, razao_social, nome_fantasia, cnpj FROM fornecedores
        WHERE razao_social LIKE :b OR nome_fantasia LIKE :b OR cnpj LIKE :bc ORDER BY razao_social ASC LIMIT 8";
    $params = [':b' => "%{$busca}%", ':bc' => "%{$buscaCnpj}%"];
} else {
    $sql = "SELECT id, razao_social, nome_fantasia, cnpj FROM fornecedores
        WHERE razao_social LIKE :b OR nome_fantasia LIKE :b ORDER BY razao_social ASC LIMIT 8";
    $params = [':b' => "%{$busca}%"];
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$fornecedores = $stmt->fetchAll();

echo json_encode([
    'fornecedores' => array_map(function ($f) {
        return [
            'id' => (int)$f['id'],
            'razao_social' => $f['razao_social'],
            'nome_fantasia' => $f['nome_fantasia'],
            'cnpj' => formatarCNPJ($f['cnpj']),
        ];
    }, $fornecedores),
]);
