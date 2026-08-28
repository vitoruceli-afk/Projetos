<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_logged_in'])) {
    http_response_code(401);
    echo json_encode(['found' => false, 'error' => 'Não autenticado.']);
    exit;
}
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['found' => false, 'error' => 'Acesso restrito ao perfil Administrador.']);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['found' => false, 'error' => 'Medicamento inválido.']);
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT * FROM medicamentos_anvisa WHERE id = :id");
$stmt->bindValue(':id', $id, PDO::PARAM_INT);
$stmt->execute();
$med = $stmt->fetch();

if (!$med) {
    echo json_encode(['found' => false, 'error' => 'Medicamento não encontrado.']);
    exit;
}

// dados_completos guarda o CSV original inteiro (rótulo de coluna => valor), incluindo as
// faixas de preço PF/PMVG por regime tributário que não são modeladas em colunas próprias.
$dados = json_decode($med['dados_completos'] ?? '', true);
$campos = [];
if (is_array($dados)) {
    foreach ($dados as $label => $valor) {
        $campos[] = [$label, $valor];
    }
} else {
    $campos[] = ['Substância', $med['substancia']];
    $campos[] = ['Produto', $med['produto']];
}

echo json_encode(['found' => true, 'campos' => $campos]);
