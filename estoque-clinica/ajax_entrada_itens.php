<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_logged_in'])) {
    http_response_code(401);
    echo json_encode(['found' => false, 'error' => 'Não autenticado.']);
    exit;
}

$grupo = trim($_GET['grupo'] ?? '');
if ($grupo === '') {
    echo json_encode(['found' => false, 'error' => 'Grupo inválido.']);
    exit;
}

$db = getDB();
$grupoSql = movimentacaoGrupoChaveSql('mv');
$stmt = $db->prepare("SELECT mv.quantidade, mv.observacao, md.produto, md.laboratorio, md.apresentacao, l.lote, l.validade
    FROM movimentacoes mv
    JOIN medicamentos_anvisa md ON md.id = mv.medicamento_id
    LEFT JOIN insumo_lotes l ON l.id = mv.lote_id
    WHERE mv.tipo = 'entrada' AND {$grupoSql} = :grupo
    ORDER BY mv.id ASC");
$stmt->execute([':grupo' => $grupo]);
$itens = $stmt->fetchAll();

if (!$itens) {
    echo json_encode(['found' => false, 'error' => 'Nenhum item encontrado para esta confirmação.']);
    exit;
}

echo json_encode([
    'found' => true,
    'itens' => array_map(function ($i) {
        return [
            'produto' => $i['produto'],
            'laboratorio' => $i['laboratorio'],
            'apresentacao' => $i['apresentacao'],
            'lote' => $i['lote'],
            'validade_br' => $i['validade'] ? date('d/m/Y', strtotime($i['validade'])) : null,
            'quantidade' => (int)$i['quantidade'],
            'observacao' => $i['observacao'],
        ];
    }, $itens),
]);
