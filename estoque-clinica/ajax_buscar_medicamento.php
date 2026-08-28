<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_logged_in'])) {
    http_response_code(401);
    echo json_encode(['found' => false, 'error' => 'Não autenticado.']);
    exit;
}

$codigo = trim($_GET['codigo'] ?? '');
if ($codigo === '') {
    echo json_encode(['found' => false, 'error' => 'Informe um código de barras.']);
    exit;
}

$db = getDB();
$medicamento = findMedicamentoByBarcode($db, $codigo);

if (!$medicamento) {
    echo json_encode(['found' => false, 'error' => 'Nenhum medicamento encontrado com este código na base da ANVISA/CMED.']);
    exit;
}

$estoqueTotal = medicamentoEstoqueTotal($db, $medicamento['id']);
$lotes = medicamentoLotesComSaldo($db, $medicamento['id']);
// Status "geral" do medicamento = do lote mais próximo de vencer (primeiro da lista, já ordenada).
$status = $lotes ? statusVencimento($lotes[0]['validade']) : null;

echo json_encode([
    'found' => true,
    'medicamento' => [
        'id' => (int)$medicamento['id'],
        'produto' => $medicamento['produto'],
        'substancia' => $medicamento['substancia'],
        'apresentacao' => $medicamento['apresentacao'],
        'laboratorio' => $medicamento['laboratorio'],
        'codigo_barras' => $codigo,
        'estoque_total' => $estoqueTotal,
        'lotes' => array_map(function ($l) {
            $st = statusVencimento($l['validade']);
            return [
                'id' => (int)$l['id'],
                'lote' => $l['lote'],
                'validade' => $l['validade'],
                'validade_br' => date('d/m/Y', strtotime($l['validade'])),
                'quantidade' => (int)$l['quantidade'],
                'status' => $st,
                'status_label' => statusVencimentoLabel($st),
            ];
        }, $lotes),
        'status' => $status,
        'status_label' => $status ? statusVencimentoLabel($status) : null,
    ],
]);
