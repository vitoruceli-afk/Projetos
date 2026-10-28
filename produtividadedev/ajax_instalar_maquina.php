<?php
require_once 'config.php';
checkAuth();
requireAdmin();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'erro' => 'Método não permitido.']);
    exit;
}
csrfVerify();

$db = getDB();
$id = (int)($_POST['id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM maquinas WHERE id = :id");
$stmt->bindValue(':id', $id, PDO::PARAM_INT);
$stmt->execute();
$maquina = $stmt->fetch();

if (!$maquina) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'erro' => 'Máquina não encontrada.']);
    exit;
}

$config = getInstalacaoConfig($db);
if (empty($config['admin_usuario'])) {
    echo json_encode(['ok' => false, 'erro' => 'Configure a conta de administrador em "Instalação Remota" antes de instalar.']);
    exit;
}

$acao = $_POST['acao'] ?? 'instalar';
if (!in_array($acao, ['instalar', 'atualizar', 'desinstalar'], true)) $acao = 'instalar';

$instalacaoId = dispararInstalacaoRemota($db, $maquina, $acao);
echo json_encode(['ok' => true, 'instalacao_id' => $instalacaoId]);
