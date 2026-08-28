<?php
$db = getDB();
$tab = ($_GET['tab'] ?? 'estoque') === 'movimentacoes' ? 'movimentacoes' : 'estoque';
$labs = $db->query("SELECT DISTINCT laboratorio FROM medicamentos_anvisa WHERE laboratorio <> '' ORDER BY laboratorio ASC")->fetchAll(PDO::FETCH_COLUMN);

$laboratorio = trim($_GET['laboratorio'] ?? '');
$busca = trim($_GET['busca'] ?? '');

function csvOutput($filename, $header, $rows) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM p/ acentuação abrir corretamente no Excel
    fputcsv($out, $header, ';');
    foreach ($rows as $r) fputcsv($out, $r, ';');
    fclose($out);
    exit;
}

if ($tab === 'estoque') {
    $status = $_GET['status'] ?? 'todos';
    $sql = "SELECT l.lote, l.validade, l.quantidade, md.produto AS medicamento_nome, md.laboratorio AS laboratorio_nome, md.codigo_ggrem
        FROM insumo_lotes l
        JOIN medicamentos_anvisa md ON md.id = l.medicamento_id
        WHERE l.quantidade > 0";
    $params = [];
    if ($laboratorio !== '') { $sql .= " AND md.laboratorio = :lab"; $params[':lab'] = $laboratorio; }
    if ($busca !== '') { $sql .= " AND md.produto LIKE :b"; $params[':b'] = "%{$busca}%"; }

    $hoje = date('Y-m-d');
    $em30 = date('Y-m-d', strtotime('+' . VENCIMENTO_ALERTA_DIAS . ' days'));
    $em7 = date('Y-m-d', strtotime('+' . VENCIMENTO_URGENTE_DIAS . ' days'));
    if ($status === 'vencido') { $sql .= " AND l.validade < :hoje"; $params[':hoje'] = $hoje; }
    elseif ($status === 'urgente') { $sql .= " AND l.validade >= :hoje AND l.validade <= :em7"; $params[':hoje'] = $hoje; $params[':em7'] = $em7; }
    elseif ($status === 'alerta') { $sql .= " AND l.validade >= :hoje AND l.validade <= :em30"; $params[':hoje'] = $hoje; $params[':em30'] = $em30; }

    $sql .= " ORDER BY l.validade ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $linhas = $stmt->fetchAll();

    if (($_GET['format'] ?? '') === 'csv') {
        $rows = array_map(function ($l) {
            $st = statusVencimento($l['validade']);
            return [$l['medicamento_nome'], $l['laboratorio_nome'], $l['codigo_ggrem'], $l['lote'], date('d/m/Y', strtotime($l['validade'])), $l['quantidade'], statusVencimentoLabel($st)];
        }, $linhas);
        csvOutput('relatorio_estoque.csv', ['Medicamento', 'Laboratório', 'Código GGREM', 'Lote', 'Validade', 'Quantidade', 'Status'], $rows);
    }
} else {
    $dataInicio = trim($_GET['data_inicio'] ?? date('Y-m-01'));
    $dataFim = trim($_GET['data_fim'] ?? date('Y-m-d'));
    $tipo = in_array($_GET['tipo'] ?? '', ['entrada', 'saida']) ? $_GET['tipo'] : 'todos';

    $sql = "SELECT mv.*, md.produto AS medicamento_nome, md.laboratorio AS laboratorio_nome, md.codigo_ggrem, l.lote
        FROM movimentacoes mv
        JOIN medicamentos_anvisa md ON md.id = mv.medicamento_id
        LEFT JOIN insumo_lotes l ON l.id = mv.lote_id
        WHERE DATE(mv.created_at) BETWEEN :di AND :df";
    $params = [':di' => $dataInicio, ':df' => $dataFim];
    if ($laboratorio !== '') { $sql .= " AND md.laboratorio = :lab"; $params[':lab'] = $laboratorio; }
    if ($busca !== '') { $sql .= " AND md.produto LIKE :b"; $params[':b'] = "%{$busca}%"; }
    if ($tipo !== 'todos') { $sql .= " AND mv.tipo = :tipo"; $params[':tipo'] = $tipo; }
    $sql .= " ORDER BY mv.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $linhas = $stmt->fetchAll();

    if (($_GET['format'] ?? '') === 'csv') {
        $rows = array_map(function ($m) {
            return [date('d/m/Y H:i', strtotime($m['created_at'])), $m['tipo'] === 'entrada' ? 'Entrada' : 'Saída', $m['medicamento_nome'], $m['laboratorio_nome'], $m['lote'], $m['quantidade'], $m['usuario'], $m['observacao']];
        }, $linhas);
        csvOutput('relatorio_movimentacoes.csv', ['Data/Hora', 'Tipo', 'Medicamento', 'Laboratório', 'Lote', 'Quantidade', 'Usuário', 'Observação'], $rows);
    }
}

$qs = $_GET;
unset($qs['format']);
$csvQs = http_build_query(array_merge($qs, ['format' => 'csv']));
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Relatórios</h1>
        <div class="page-sub">Consulte o estoque por vencimento ou o histórico de movimentações</div>
    </div>
</div>

<ul class="nav nav-tabs mb-4">
    <li class="nav-item"><a class="nav-link <?= $tab === 'estoque' ? 'active' : '' ?>" href="index.php?page=relatorios&tab=estoque">Estoque por Vencimento</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'movimentacoes' ? 'active' : '' ?>" href="index.php?page=relatorios&tab=movimentacoes">Movimentações</a></li>
</ul>

<?php if ($tab === 'estoque'): ?>
    <form method="GET" class="entity-list-toolbar">
        <input type="hidden" name="page" value="relatorios">
        <input type="hidden" name="tab" value="estoque">
        <select name="laboratorio" class="form-select" style="max-width:220px;">
            <option value="">Todos os laboratórios</option>
            <?php foreach ($labs as $lab): ?>
                <option value="<?= htmlspecialchars($lab) ?>" <?= $laboratorio === $lab ? 'selected' : '' ?>><?= htmlspecialchars($lab) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" class="form-select" style="max-width:220px;">
            <option value="todos" <?= $status === 'todos' ? 'selected' : '' ?>>Todos os medicamentos</option>
            <option value="alerta" <?= $status === 'alerta' ? 'selected' : '' ?>>A vencer em 30 dias</option>
            <option value="urgente" <?= $status === 'urgente' ? 'selected' : '' ?>>A vencer em 7 dias</option>
            <option value="vencido" <?= $status === 'vencido' ? 'selected' : '' ?>>Vencidos</option>
        </select>
        <input type="text" name="busca" class="form-control" style="max-width:220px;" placeholder="Buscar medicamento..." value="<?= htmlspecialchars($busca) ?>">
        <button class="btn btn-outline-primary">Filtrar</button>
        <a class="btn btn-outline-secondary ms-auto" href="?<?= htmlspecialchars($csvQs) ?>"><i class="bi bi-download"></i> Exportar CSV</a>
    </form>

    <div class="table-responsive">
        <table class="table table-striped table-hover bg-white align-middle">
            <thead class="table-dark">
                <tr><th>Medicamento</th><th>Laboratório</th><th>Lote</th><th>Validade</th><th class="text-center">Qtd.</th><th class="text-center">Status</th></tr>
            </thead>
            <tbody>
                <?php if (empty($linhas)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Nenhum resultado para este filtro.</td></tr>
                <?php else: ?>
                    <?php foreach ($linhas as $l): $st = statusVencimento($l['validade']); ?>
                        <tr class="<?= $st === 'vencido' ? 'table-danger' : ($st === 'urgente' ? 'table-warning' : '') ?>">
                            <td><?= htmlspecialchars($l['medicamento_nome']) ?></td>
                            <td><?= htmlspecialchars($l['laboratorio_nome'] ?: '—') ?></td>
                            <td class="mono"><?= htmlspecialchars($l['lote']) ?></td>
                            <td class="mono"><?= date('d/m/Y', strtotime($l['validade'])) ?></td>
                            <td class="text-center"><?= (int)$l['quantidade'] ?></td>
                            <td class="text-center"><span class="badge <?= statusVencimentoBadgeClass($st) ?>"><?= statusVencimentoLabel($st) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php else: ?>
    <form method="GET" class="entity-list-toolbar">
        <input type="hidden" name="page" value="relatorios">
        <input type="hidden" name="tab" value="movimentacoes">
        <select name="tipo" class="form-select" style="max-width:160px;">
            <option value="todos" <?= $tipo === 'todos' ? 'selected' : '' ?>>Entradas e Saídas</option>
            <option value="entrada" <?= $tipo === 'entrada' ? 'selected' : '' ?>>Somente Entradas</option>
            <option value="saida" <?= $tipo === 'saida' ? 'selected' : '' ?>>Somente Saídas</option>
        </select>
        <select name="laboratorio" class="form-select" style="max-width:200px;">
            <option value="">Todos os laboratórios</option>
            <?php foreach ($labs as $lab): ?>
                <option value="<?= htmlspecialchars($lab) ?>" <?= $laboratorio === $lab ? 'selected' : '' ?>><?= htmlspecialchars($lab) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="data_inicio" class="form-control" style="max-width:160px;" value="<?= htmlspecialchars($dataInicio) ?>">
        <input type="date" name="data_fim" class="form-control" style="max-width:160px;" value="<?= htmlspecialchars($dataFim) ?>">
        <input type="text" name="busca" class="form-control" style="max-width:180px;" placeholder="Buscar medicamento..." value="<?= htmlspecialchars($busca) ?>">
        <button class="btn btn-outline-primary">Filtrar</button>
        <a class="btn btn-outline-secondary ms-auto" href="?<?= htmlspecialchars($csvQs) ?>"><i class="bi bi-download"></i> Exportar CSV</a>
    </form>

    <div class="table-responsive">
        <table class="table table-striped table-hover bg-white align-middle">
            <thead class="table-dark">
                <tr><th>Data/Hora</th><th>Tipo</th><th>Medicamento</th><th>Laboratório</th><th>Lote</th><th class="text-center">Qtd.</th><th>Usuário</th><th>Observação</th></tr>
            </thead>
            <tbody>
                <?php if (empty($linhas)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Nenhuma movimentação neste período.</td></tr>
                <?php else: ?>
                    <?php foreach ($linhas as $m): ?>
                        <tr>
                            <td class="mono text-nowrap"><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></td>
                            <td><span class="badge <?= $m['tipo'] === 'entrada' ? 'bg-success' : 'bg-danger' ?>"><?= $m['tipo'] === 'entrada' ? 'Entrada' : 'Saída' ?></span></td>
                            <td><?= htmlspecialchars($m['medicamento_nome']) ?></td>
                            <td><?= htmlspecialchars($m['laboratorio_nome'] ?: '—') ?></td>
                            <td class="mono"><?= htmlspecialchars($m['lote'] ?: '—') ?></td>
                            <td class="text-center"><?= (int)$m['quantidade'] ?></td>
                            <td><?= htmlspecialchars($m['usuario']) ?></td>
                            <td><?= htmlspecialchars($m['observacao']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
