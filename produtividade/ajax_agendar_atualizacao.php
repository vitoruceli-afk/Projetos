<?php
// Agenda (ou cancela) a atualização do agente de uma máquina para "a próxima vez que ela estiver
// comunicável": só marca maquinas.atualizar_pendente_em; quem dispara de fato a atualização é
// dispararAtualizacaoAgendada() (aw_client.php), no fim da próxima sincronização bem-sucedida.
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
$agendar = ($_POST['agendar'] ?? '1') === '1';

$stmt = $db->prepare("SELECT id FROM maquinas WHERE id = :id");
$stmt->execute([':id' => $id]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'erro' => 'Máquina não encontrada.']);
    exit;
}

if ($agendar) {
    $config = getInstalacaoConfig($db);
    if (empty($config['admin_usuario'])) {
        echo json_encode(['ok' => false, 'erro' => 'Configure a conta de administrador em "Instalação Remota" antes de agendar.']);
        exit;
    }
}

$db->prepare("UPDATE maquinas SET atualizar_pendente_em = :t WHERE id = :id")
   ->execute([':t' => $agendar ? date('Y-m-d H:i:s') : null, ':id' => $id]);

echo json_encode(['ok' => true, 'agendada' => $agendar]);
