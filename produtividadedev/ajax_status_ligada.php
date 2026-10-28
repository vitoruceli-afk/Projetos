<?php
// Testa se a máquina está ligada/alcançável na rede via ping (ICMP) — diferente de "Testar
// Conexão" (que verifica se o aw-server especificamente está respondendo na porta configurada):
// uma máquina pode estar ligada e responder ping mesmo com o ActivityWatch fechado ou o firewall
// bloqueando a porta, então os dois testes contam coisas diferentes.
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

$id = (int)($_POST['id'] ?? 0);
$db = getDB();
$stmt = $db->prepare("SELECT host FROM maquinas WHERE id = :id");
$stmt->bindValue(':id', $id, PDO::PARAM_INT);
$stmt->execute();
$maquina = $stmt->fetch();

if (!$maquina) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'erro' => 'Máquina não encontrada.']);
    exit;
}

// Sintaxe do ping difere por SO (-n/-w do Windows não existem no Linux — "-n" lá significa "saída
// numérica", nem aceita valor, e "1" sobraria como argumento extra, fazendo o comando falhar/errar
// SEMPRE independente da máquina estar ligada ou não; foi exatamente o bug visto em produção: tudo
// aparecia "Desligada" mesmo pingando de verdade). Mesmo objetivo nos dois: 1 pacote, timeout de 1s.
if (PHP_OS_FAMILY === 'Windows') {
    $cmd = 'ping -n 1 -w 1000 ' . escapeshellarg($maquina['host']);
} else {
    $cmd = 'ping -c 1 -W 1 ' . escapeshellarg($maquina['host']);
}
exec($cmd, $saida, $codigoRetorno);

echo json_encode(['ok' => true, 'ligada' => $codigoRetorno === 0]);
