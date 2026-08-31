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
                'validade_br' => date('d/m/Y', strtotime($l['validade'])),
                'quantidade' => (int)$l['quantidade'],
                'valor_unitario' => (float)$l['valor_unitario'],
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

    // Insumo não tem uma sub-tabela de lotes como medicamento — só uma quantidade/lote/validade
    // por registro (a entrada mais recente é a que vale). Monta uma lista com esse único "lote"
    // pra reaproveitar a mesma tabela de exibição do medicamento.
    $lotes = [];
    if ($insumo['lote'] !== '' && $insumo['validade']) {
        $st = statusVencimento($insumo['validade']);
        $lotes[] = [
            'lote' => $insumo['lote'],
            'validade_br' => date('d/m/Y', strtotime($insumo['validade'])),
            'quantidade' => (int)$insumo['quantidade'],
            'valor_unitario' => (float)$insumo['valor_unitario'],
            'valor_total' => (float)$insumo['valor_unitario'] * (int)$insumo['quantidade'],
            'status' => $st,
            'status_label' => statusVencimentoLabel($st),
        ];
    }

    echo json_encode([
        'found' => true,
        'tipo' => 'insumo',
        'nome' => $insumo['nome_comercial'],
        'origem' => $insumo['marca'],
        'apresentacao' => $insumo['categoria'],
        'estoque_minimo' => (int)$insumo['estoque_minimo'],
        'lotes' => $lotes,
    ]);
}
