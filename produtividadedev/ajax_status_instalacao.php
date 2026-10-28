<?php
require_once 'config.php';
checkAuth();
requireAdmin();
header('Content-Type: application/json; charset=utf-8');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT id, acao, status, mensagem, log, iniciado_em, finalizado_em FROM instalacoes_remotas WHERE id = :id");
$stmt->bindValue(':id', $id, PDO::PARAM_INT);
$stmt->execute();
$instalacao = $stmt->fetch();

if (!$instalacao) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'erro' => 'Instalação não encontrada.']);
    exit;
}

echo json_encode(['ok' => true] + $instalacao);
