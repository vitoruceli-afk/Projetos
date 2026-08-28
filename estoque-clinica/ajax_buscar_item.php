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
$item = buscarItemMovimentacao($db, $codigo);

if (!$item) {
    echo json_encode(['found' => false, 'error' => 'Nenhum medicamento ou insumo encontrado com este código.']);
    exit;
}

if ($item['tipo'] === 'medicamento') {
    $medicamento = $item['dados'];
    $estoqueTotal = medicamentoEstoqueTotal($db, $medicamento['id']);
    $lotes = medicamentoLotesComSaldo($db, $medicamento['id']);
    // Status "geral" do medicamento = do lote mais próximo de vencer (primeiro da lista, já ordenada).
    $status = $lotes ? statusVencimento($lotes[0]['validade']) : null;

    echo json_encode([
        'found' => true,
        'tipo' => 'medicamento',
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
} else {
    $insumo = $item['dados'];
    $status = $insumo['validade'] ? statusVencimento($insumo['validade']) : null;

    echo json_encode([
        'found' => true,
        'tipo' => 'insumo',
        'insumo' => [
            'id' => (int)$insumo['id'],
            'nome_comercial' => $insumo['nome_comercial'],
            'marca' => $insumo['marca'],
            'categoria' => $insumo['categoria'],
            'codigo_barras' => $codigo,
            'quantidade' => (int)$insumo['quantidade'],
            'estoque_minimo' => (int)$insumo['estoque_minimo'],
            'unidade_medida' => $insumo['unidade_medida'],
            'lote' => $insumo['lote'],
            'validade_br' => $insumo['validade'] ? date('d/m/Y', strtotime($insumo['validade'])) : null,
            'estoque_baixo' => (int)$insumo['quantidade'] <= (int)$insumo['estoque_minimo'],
            'status' => $status,
            'status_label' => $status ? statusVencimentoLabel($status) : null,
        ],
    ]);
}
