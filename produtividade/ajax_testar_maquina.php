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

$host = trim($_POST['host'] ?? '');
$porta = (int)($_POST['porta'] ?? AW_PORTA_PADRAO);

if ($host === '') {
    echo json_encode(['ok' => false, 'erro' => 'Host não informado.']);
    exit;
}

$resultado = awTestarConexao(['host' => $host, 'porta' => $porta ?: AW_PORTA_PADRAO]);
echo json_encode($resultado);
