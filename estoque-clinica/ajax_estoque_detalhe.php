<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_logged_in'])) {
    http_response_code(401);
    echo json_encode(['found' => false, 'error' => 'Não autenticado.']);
    exit;
}

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$tipo = ($_GET['tipo'] ?? '') === 'insumo' ? 'insumo' : 'medicamento';

if ($id <= 0) {
    echo json_encode(['found' => false, 'error' => 'Item inválido.']);
    exit;
}

if ($tipo === 'medicamento') {
    $stmt = $db->prepare("SELECT * FROM medicamentos_anvisa WHERE id = :id");
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $medicamento = $stmt->fetch();
    if (!$medicamento) {
        echo json_encode(['found' => false, 'error' => 'Medicamento não encontrado.']);
        exit;
    }

    // Todos os lotes JÁ cadastrados pra esse medicamento (inclusive com saldo zerado — "cadastrados"
    // é diferente de "com saldo"), do vencimento mais próximo pro mais distante.
    $stmtLotes = $db->prepare("SELECT * FROM insumo_lotes WHERE medicamento_id = :id ORDER BY validade ASC");
    $stmtLotes->bindValue(':id', $id, PDO::PARAM_INT);
    $stmtLotes->execute();
    $lotes = $stmtLotes->fetchAll();

    echo json_encode([
        'found' => true,
        'tipo' => 'medicamento',
        'nome' => $medicamento['produto'],
        'origem' => $medicamento['laboratorio'],
        'apresentacao' => $medicamento['apresentacao'],
        'estoque_minimo' => $medicamento['estoque_minimo'] !== null ? (int)$medicamento['estoque_minimo'] : null,
        'lotes' => array_map(function ($l) {
            $st = statusVencimento($l['validade']);
            return [
                'lote' => $l['lote'],
                'validade_br' => formatarValidade($l['validade']),
                'quantidade' => (int)$l['quantidade'],
                'valor_unitario' => (float)$l['valor_unitario'],
                'valor_venda' => (float)$l['valor_venda'],
                'valor_total' => (float)$l['valor_unitario'] * (int)$l['quantidade'],
                'status' => $st,
                'status_label' => statusVencimentoLabel($st),
            ];
        }, $lotes),
    ]);
} else {
    $stmt = $db->prepare("SELECT * FROM insumos WHERE id = :id");
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $insumo = $stmt->fetch();
    if (!$insumo) {
        echo json_encode(['found' => false, 'error' => 'Insumo não encontrado.']);
        exit;
    }

    // Todos os lotes JÁ cadastrados pra esse insumo (inclusive com saldo zerado), do vencimento
    // mais próximo pro mais distante — igual ao ramo de medicamento acima.
    $stmtLotes = $db->prepare("SELECT * FROM insumo_lotes WHERE insumo_id = :id ORDER BY validade ASC");
    $stmtLotes->bindValue(':id', $id, PDO::PARAM_INT);
    $stmtLotes->execute();
    $lotes = $stmtLotes->fetchAll();

    echo json_encode([
        'found' => true,
        'tipo' => 'insumo',
        'nome' => $insumo['nome_comercial'],
        'origem' => $insumo['marca'],
        'apresentacao' => $insumo['categoria'],
        'estoque_minimo' => $insumo['estoque_minimo'] !== null ? (int)$insumo['estoque_minimo'] : null,
        'lotes' => array_map(function ($l) {
            $st = statusVencimento($l['validade']);
            return [
                'lote' => $l['lote'],
                'validade_br' => formatarValidade($l['validade']),
                'quantidade' => (int)$l['quantidade'],
                'valor_unitario' => (float)$l['valor_unitario'],
                'valor_venda' => (float)$l['valor_venda'],
                'valor_total' => (float)$l['valor_unitario'] * (int)$l['quantidade'],
                'status' => $st,
                'status_label' => statusVencimentoLabel($st),
            ];
        }, $lotes),
    ]);
}
