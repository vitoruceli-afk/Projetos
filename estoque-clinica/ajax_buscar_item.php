<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_logged_in'])) {
    http_response_code(401);
    echo json_encode(['found' => false, 'error' => 'Não autenticado.']);
    exit;
}

$db = getDB();

// Duas formas de encontrar o item: pelo código de barras lido/digitado (fluxo normal de scan) ou
// direto por id+tipo (usado pela busca por nome, quando o resultado escolhido não tem EAN
// cadastrado — insumo sem código de barras, por exemplo).
$codigo = trim($_GET['codigo'] ?? '');
$id = (int)($_GET['id'] ?? 0);
$tipoParam = ($_GET['tipo'] ?? '') === 'insumo' ? 'insumo' : (($_GET['tipo'] ?? '') === 'medicamento' ? 'medicamento' : '');

if ($id > 0 && $tipoParam !== '') {
    if ($tipoParam === 'medicamento') {
        $stmt = $db->prepare("SELECT * FROM medicamentos_anvisa WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $dados = $stmt->fetch();
        $item = $dados ? ['tipo' => 'medicamento', 'dados' => $dados] : null;
    } else {
        $stmt = $db->prepare("SELECT * FROM insumos WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $dados = $stmt->fetch();
        $item = $dados ? ['tipo' => 'insumo', 'dados' => $dados] : null;
    }
    if (!$item) {
        echo json_encode(['found' => false, 'error' => 'Item não encontrado.']);
        exit;
    }
} elseif ($codigo !== '') {
    $item = buscarItemMovimentacao($db, $codigo);
    if (!$item) {
        echo json_encode(['found' => false, 'error' => 'Nenhum medicamento ou insumo encontrado com este código.']);
        exit;
    }
} else {
    echo json_encode(['found' => false, 'error' => 'Informe um código de barras.']);
    exit;
}

if ($item['tipo'] === 'medicamento') {
    $medicamento = $item['dados'];
    // Quando veio por id (sem código escaneado), usa o próprio EAN/GGREM do registro para o
    // formulário conseguir reenviar um código válido na hora de confirmar a movimentação.
    $codigoResolvido = $codigo !== '' ? $codigo : ($medicamento['ean_1'] ?: ($medicamento['ean_2'] ?: ($medicamento['ean_3'] ?: $medicamento['codigo_ggrem'])));
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
            'codigo_barras' => $codigoResolvido,
            'estoque_total' => $estoqueTotal,
            'estoque_minimo' => $medicamento['estoque_minimo'] !== null ? (int)$medicamento['estoque_minimo'] : null,
            'lotes' => array_map(function ($l) {
                $st = statusVencimento($l['validade']);
                return [
                    'id' => (int)$l['id'],
                    'lote' => $l['lote'],
                    'validade' => $l['validade'],
                    'validade_br' => date('d/m/Y', strtotime($l['validade'])),
                    'quantidade' => (int)$l['quantidade'],
                    'valor_unitario' => (float)$l['valor_unitario'],
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
    $codigoResolvido = $codigo !== '' ? $codigo : $insumo['codigo_barras'];
    $status = $insumo['validade'] ? statusVencimento($insumo['validade']) : null;

    echo json_encode([
        'found' => true,
        'tipo' => 'insumo',
        'insumo' => [
            'id' => (int)$insumo['id'],
            'nome_comercial' => $insumo['nome_comercial'],
            'marca' => $insumo['marca'],
            'categoria' => $insumo['categoria'],
            'codigo_barras' => $codigoResolvido,
            'quantidade' => (int)$insumo['quantidade'],
            'estoque_minimo' => (int)$insumo['estoque_minimo'],
            'unidade_medida' => $insumo['unidade_medida'],
            'lote' => $insumo['lote'],
            'validade_br' => $insumo['validade'] ? date('d/m/Y', strtotime($insumo['validade'])) : null,
            'valor_unitario' => (float)$insumo['valor_unitario'],
            'estoque_baixo' => (int)$insumo['quantidade'] <= (int)$insumo['estoque_minimo'],
            'status' => $status,
            'status_label' => $status ? statusVencimentoLabel($status) : null,
        ],
    ]);
}
