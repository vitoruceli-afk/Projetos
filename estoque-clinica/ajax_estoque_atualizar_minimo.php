<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_logged_in'])) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'error' => 'Não autenticado.']);
    exit;
}

csrfVerify();

$db = getDB();
$id = (int)($_POST['id'] ?? 0);
$tipo = ($_POST['tipo'] ?? '') === 'insumo' ? 'insumo' : 'medicamento';
$valorRaw = trim($_POST['valor'] ?? '');

if ($id <= 0 || $valorRaw === '' || !ctype_digit($valorRaw)) {
    echo json_encode(['sucesso' => false, 'error' => 'Informe um valor válido (número inteiro maior ou igual a zero).']);
    exit;
}
$valor = (int)$valorRaw;

if ($tipo === 'medicamento') {
    $stmt = $db->prepare("SELECT produto FROM medicamentos_anvisa WHERE id = :id");
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $nome = $stmt->fetchColumn();
    if (!$nome) {
        echo json_encode(['sucesso' => false, 'error' => 'Medicamento não encontrado.']);
        exit;
    }
    $db->prepare("UPDATE medicamentos_anvisa SET estoque_minimo = :v WHERE id = :id")->execute([':v' => $valor, ':id' => $id]);
    registrarLog('Medicamentos', 'Estoque mínimo atualizado', "medicamento: {$nome}, novo mínimo: {$valor}");
} else {
    $stmt = $db->prepare("SELECT nome_comercial FROM insumos WHERE id = :id");
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $nome = $stmt->fetchColumn();
    if (!$nome) {
        echo json_encode(['sucesso' => false, 'error' => 'Insumo não encontrado.']);
        exit;
    }
    $db->prepare("UPDATE insumos SET estoque_minimo = :v WHERE id = :id")->execute([':v' => $valor, ':id' => $id]);
    registrarLog('Insumos', 'Estoque mínimo atualizado', "insumo: {$nome}, novo mínimo: {$valor}");
}

echo json_encode(['sucesso' => true, 'valor' => $valor]);
