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
$insumo = findInsumoByBarcode($db, $codigo);

if (!$insumo) {
    echo json_encode(['found' => false, 'error' => 'Nenhum insumo cadastrado com este código de barras.']);
    exit;
}
if (!$insumo['ativo']) {
    echo json_encode(['found' => false, 'error' => 'Este insumo está inativo no catálogo.']);
    exit;
}

$estoqueTotal = insumoEstoqueTotal($db, $insumo['id']);
$proxLote = insumoProximoLote($db, $insumo['id']);
$status = $proxLote ? statusVencimento($proxLote['validade']) : null;

echo json_encode([
    'found' => true,
    'insumo' => [
        'id' => (int)$insumo['id'],
        'nome' => $insumo['nome'],
        'laboratorio' => $insumo['laboratorio_nome'],
        'composicao' => $insumo['composicao'],
        'codigo_barras' => $insumo['codigo_barras'],
        'unidade_medida' => $insumo['unidade_medida'],
        'foto_url' => insumoFotoUrl($insumo['foto']),
        'estoque_total' => $estoqueTotal,
        'lote_atual' => $proxLote ? [
            'lote' => $proxLote['lote'],
            'validade' => $proxLote['validade'],
            'validade_br' => date('d/m/Y', strtotime($proxLote['validade'])),
            'quantidade' => (int)$proxLote['quantidade'],
        ] : null,
        'status' => $status,
        'status_label' => $status ? statusVencimentoLabel($status) : null,
    ],
]);
