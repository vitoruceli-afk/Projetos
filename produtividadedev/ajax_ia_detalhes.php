<?php
// Detalhes de uso de uma sugestão da IA (máquina, IP, departamento e usuário que acessaram o site/aplicativo) — apoio à revisão
// humana na tela Classificação por IA. Só leitura, só administrador.
require_once 'config.php';
checkAuth();
requireAdmin();
header('Content-Type: application/json; charset=utf-8');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'erro' => 'Informe o id da sugestão.']); exit; }
try {
    echo json_encode(['ok' => true] + iaDetalhesChave(getDB(), $id), JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
