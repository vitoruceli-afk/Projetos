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
        // Código com a cara de unidade fracionada mas sem registro: erro específico, senão o
        // operador não sabe se digitou errado ou se a etiqueta não existe no sistema.
        $erro = validarCodigoUnidade($codigo) !== null
            ? 'Unidade ' . strtoupper(trim($codigo)) . ' não encontrada no sistema.'
            : 'Nenhum medicamento ou insumo encontrado com este código.';
        echo json_encode(['found' => false, 'error' => $erro]);
        exit;
    }
} else {
    echo json_encode(['found' => false, 'error' => 'Informe um código de barras.']);
    exit;
}

// Leitura de uma unidade fracionada: devolve tudo que o operador precisa conferir antes de dar
// baixa (produto, lote, validade, entrada de origem e status), mais os dados de utilização quando
// a unidade já foi baixada — é isso que bloqueia uma segunda saída do mesmo código.
if ($item['tipo'] === 'unidade') {
    $u = $item['dados'];
    $st = statusVencimento($u['validade']);
    $disponivel = $u['status'] === 'DISPONIVEL' && (int)$u['lote_quantidade'] > 0;

    echo json_encode([
        'found' => true,
        'tipo' => 'unidade',
        'unidade' => [
            'id' => (int)$u['id'],
            'codigo_interno' => $u['codigo_interno'],
            'prefixo' => $u['prefixo'],
            'tipo_unidade' => UNIDADE_PREFIXOS[$u['prefixo']] ?? $u['prefixo'],
            'tipo_item' => $u['medicamento_id'] ? 'medicamento' : 'insumo',
            'item_id' => (int)($u['medicamento_id'] ?: $u['insumo_id']),
            'produto' => $u['produto'],
            'origem' => $u['origem'],
            'apresentacao' => $u['apresentacao'],
            'lote_id' => (int)$u['lote_id'],
            'lote' => $u['lote'],
            'validade_br' => formatarValidade($u['validade']),
            'status_vencimento' => $st,
            'status_vencimento_label' => $st ? statusVencimentoLabel($st) : null,
            'entrada' => $u['confirmacao_id'] ? codigoReferenciaEntrada((int)$u['confirmacao_id']) : null,
            'status' => $u['status'],
            'status_label' => unidadeStatusLabel($u['status']),
            'status_badge' => unidadeStatusBadgeClass($u['status']),
            'disponivel' => $disponivel,
            'lote_quantidade' => (int)$u['lote_quantidade'],
            'valor_unitario' => (float)$u['valor_unitario'],
            'valor_venda' => (float)$u['valor_venda'],
            'utilizado_em' => $u['utilizado_em'] ? date('d/m/Y H:i', strtotime($u['utilizado_em'])) : null,
            'utilizado_por' => $u['utilizado_por'],
        ],
    ]);
} elseif ($item['tipo'] === 'medicamento') {
    $medicamento = $item['dados'];
    // Quando veio por id (sem código escaneado), usa o próprio EAN/GGREM do registro para o
    // formulário conseguir reenviar um código válido na hora de confirmar a movimentação.
    $codigoResolvido = $codigo !== '' ? $codigo : ($medicamento['ean_1'] ?: ($medicamento['ean_2'] ?: ($medicamento['ean_3'] ?: $medicamento['codigo_ggrem'])));
    $estoqueTotal = medicamentoEstoqueTotal($db, $medicamento['id']);
    $lotes = medicamentoLotesComSaldo($db, $medicamento['id']);
    // Status "geral" do medicamento = do lote mais próximo de vencer (primeiro da lista, já ordenada).
    $status = $lotes ? statusVencimento($lotes[0]['validade']) : null;
    $etiquetasPorLote = unidadesDisponiveisPorLote($db, array_column($lotes, 'id'));

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
            'lotes' => array_map(function ($l) use ($etiquetasPorLote) {
                $st = statusVencimento($l['validade']);
                return [
                    'id' => (int)$l['id'],
                    'lote' => $l['lote'],
                    'validade' => $l['validade'],
                    'validade_br' => formatarValidade($l['validade']),
                    'quantidade' => (int)$l['quantidade'],
                    'valor_unitario' => (float)$l['valor_unitario'],
                    'valor_venda' => (float)$l['valor_venda'],
                    // > 0 significa que a saída desse lote só pode ser feita lendo a etiqueta de
                    // cada unidade — a tela usa isso pra bloquear a baixa por quantidade.
                    'unidades_etiquetadas' => $etiquetasPorLote[(int)$l['id']] ?? 0,
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
    // Quando veio por id (sem código escaneado), usa o próprio EAN do registro para o formulário
    // conseguir reenviar um código válido na hora de confirmar a movimentação.
    $codigoResolvido = $codigo !== '' ? $codigo : $insumo['codigo_barras'];
    $estoqueTotal = insumoEstoqueTotal($db, $insumo['id']);
    $lotes = insumoLotesComSaldo($db, $insumo['id']);
    // Status "geral" do insumo = do lote mais próximo de vencer (primeiro da lista, já ordenada).
    $status = $lotes ? statusVencimento($lotes[0]['validade']) : null;
    $etiquetasPorLote = unidadesDisponiveisPorLote($db, array_column($lotes, 'id'));

    echo json_encode([
        'found' => true,
        'tipo' => 'insumo',
        'insumo' => [
            'id' => (int)$insumo['id'],
            'nome_comercial' => $insumo['nome_comercial'],
            'marca' => $insumo['marca'],
            'categoria' => $insumo['categoria'],
            'codigo_barras' => $codigoResolvido,
            'unidade_medida' => $insumo['unidade_medida'],
            'estoque_total' => $estoqueTotal,
            'estoque_minimo' => $insumo['estoque_minimo'] !== null ? (int)$insumo['estoque_minimo'] : null,
            'lotes' => array_map(function ($l) use ($etiquetasPorLote) {
                $st = statusVencimento($l['validade']);
                return [
                    'id' => (int)$l['id'],
                    'lote' => $l['lote'],
                    'validade' => $l['validade'],
                    'validade_br' => formatarValidade($l['validade']),
                    'quantidade' => (int)$l['quantidade'],
                    'valor_unitario' => (float)$l['valor_unitario'],
                    'valor_venda' => (float)$l['valor_venda'],
                    // > 0 significa que a saída desse lote só pode ser feita lendo a etiqueta de
                    // cada unidade — a tela usa isso pra bloquear a baixa por quantidade.
                    'unidades_etiquetadas' => $etiquetasPorLote[(int)$l['id']] ?? 0,
                    'status' => $st,
                    'status_label' => statusVencimentoLabel($st),
                ];
            }, $lotes),
            'status' => $status,
            'status_label' => $status ? statusVencimentoLabel($status) : null,
        ],
    ]);
}
