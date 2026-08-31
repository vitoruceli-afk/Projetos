<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_logged_in'])) {
    http_response_code(401);
    echo json_encode(['found' => false, 'error' => 'Não autenticado.']);
    exit;
}

$db = getDB();
$pacienteId = (int)($_GET['paciente_id'] ?? 0);
if ($pacienteId <= 0) {
    echo json_encode(['found' => false, 'error' => 'Paciente inválido.']);
    exit;
}

$stmtPaciente = $db->prepare("SELECT nome_completo FROM pacientes WHERE id = :id");
$stmtPaciente->bindValue(':id', $pacienteId, PDO::PARAM_INT);
$stmtPaciente->execute();
$nomePaciente = $stmtPaciente->fetchColumn();
if (!$nomePaciente) {
    echo json_encode(['found' => false, 'error' => 'Paciente não encontrado.']);
    exit;
}

// Cada confirmação de saída com paciente_id preenchido é uma "operação" — o resumo financeiro de
// cada uma é a soma de quantidade × valor_unitario dos itens que ela gerou em movimentacoes.
$stmt = $db->prepare("SELECT mc.id AS confirmacao_id, mc.created_at, mc.usuario, mc.total_itens, mc.total_quantidade,
        COALESCE(SUM(mv.quantidade * mv.valor_unitario), 0) AS valor_total
    FROM movimentacao_confirmacoes mc
    LEFT JOIN movimentacoes mv ON mv.confirmacao_id = mc.id
    WHERE mc.tipo = 'saida' AND mc.paciente_id = :p
    GROUP BY mc.id
    ORDER BY mc.created_at DESC");
$stmt->execute([':p' => $pacienteId]);
$saidas = $stmt->fetchAll();

$valorTotalGeral = 0;
$saidasFormatadas = array_map(function ($s) use (&$valorTotalGeral) {
    $valorTotalGeral += (float)$s['valor_total'];
    return [
        'confirmacao_id' => (int)$s['confirmacao_id'],
        'data_br' => date('d/m/Y', strtotime($s['created_at'])),
        'hora' => date('H:i', strtotime($s['created_at'])),
        'usuario' => $s['usuario'],
        'total_itens' => (int)$s['total_itens'],
        'total_quantidade' => (int)$s['total_quantidade'],
        'valor_total' => (float)$s['valor_total'],
    ];
}, $saidas);

echo json_encode([
    'found' => true,
    'paciente_nome' => $nomePaciente,
    'saidas' => $saidasFormatadas,
    'valor_total_geral' => $valorTotalGeral,
]);
