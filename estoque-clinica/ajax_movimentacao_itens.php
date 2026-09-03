<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_logged_in'])) {
    http_response_code(401);
    echo json_encode(['found' => false, 'error' => 'Não autenticado.']);
    exit;
}

$grupo = trim($_GET['grupo'] ?? '');
$tipo = ($_GET['tipo'] ?? '') === 'saida' ? 'saida' : 'entrada';
if ($grupo === '') {
    echo json_encode(['found' => false, 'error' => 'Grupo inválido.']);
    exit;
}

$db = getDB();
$grupoSql = movimentacaoGrupoChaveSql('mv');
// LEFT JOIN nas duas origens possíveis (medicamento ou insumo — mv.medicamento_id/insumo_id são
// mutuamente exclusivos) e COALESCE pra exibir o nome de qual delas bateu. Lote/validade vêm
// sempre de insumo_lotes (l), que agora guarda o lote tanto de medicamento quanto de insumo.
$stmt = $db->prepare("SELECT mv.quantidade, mv.valor_unitario, mv.valor_venda, mv.observacao,
        COALESCE(md.produto, ins.nome_comercial) AS produto,
        COALESCE(md.laboratorio, ins.marca) AS laboratorio,
        COALESCE(md.apresentacao, ins.categoria) AS apresentacao,
        l.lote AS lote,
        l.validade AS validade
    FROM movimentacoes mv
    LEFT JOIN medicamentos_anvisa md ON md.id = mv.medicamento_id
    LEFT JOIN insumos ins ON ins.id = mv.insumo_id
    LEFT JOIN insumo_lotes l ON l.id = mv.lote_id
    WHERE mv.tipo = :tipo AND {$grupoSql} = :grupo
    ORDER BY mv.id ASC");
$stmt->execute([':tipo' => $tipo, ':grupo' => $grupo]);
$itens = $stmt->fetchAll();

if (!$itens) {
    echo json_encode(['found' => false, 'error' => 'Nenhum item encontrado para esta confirmação.']);
    exit;
}

// Saída é sobre o valor de venda (o que foi repassado na retirada); Entrada continua sobre o
// valor de compra (o custo investido) — mesmo critério do resumo financeiro na tela Movimentação.
$valorTotal = 0;
$itensSaida = array_map(function ($i) use (&$valorTotal, $tipo) {
    $valorBase = $tipo === 'saida' ? (float)$i['valor_venda'] : (float)$i['valor_unitario'];
    $subtotal = $valorBase * (int)$i['quantidade'];
    $valorTotal += $subtotal;
    return [
        'produto' => $i['produto'],
        'laboratorio' => $i['laboratorio'],
        'apresentacao' => $i['apresentacao'],
        'lote' => $i['lote'],
        'validade_br' => formatarValidade($i['validade']),
        'quantidade' => (int)$i['quantidade'],
        'valor_unitario' => (float)$i['valor_unitario'],
        'valor_venda' => (float)$i['valor_venda'],
        'subtotal' => $subtotal,
        'observacao' => $i['observacao'],
    ];
}, $itens);

echo json_encode([
    'found' => true,
    'itens' => $itensSaida,
    'valor_total' => $valorTotal,
]);
