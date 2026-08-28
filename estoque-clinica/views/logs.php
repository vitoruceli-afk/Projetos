<?php
requireAdmin();
$db = getDB();

$categoria = trim($_GET['categoria'] ?? '');
$usuarioFiltro = trim($_GET['usuario'] ?? '');
$dataInicio = trim($_GET['data_inicio'] ?? date('Y-m-d', strtotime('-7 days')));
$dataFim = trim($_GET['data_fim'] ?? date('Y-m-d'));
$busca = trim($_GET['busca'] ?? '');

$usuarios = $db->query("SELECT DISTINCT usuario FROM logs WHERE usuario <> '' ORDER BY usuario ASC")->fetchAll(PDO::FETCH_COLUMN);

$where = "WHERE DATE(created_at) BETWEEN :di AND :df";
$params = [':di' => $dataInicio, ':df' => $dataFim];
if ($categoria !== '' && in_array($categoria, LOG_CATEGORIAS, true)) {
    $where .= " AND categoria = :cat";
    $params[':cat'] = $categoria;
}
if ($usuarioFiltro !== '') {
    $where .= " AND usuario = :usr";
    $params[':usr'] = $usuarioFiltro;
}
if ($busca !== '') {
    $where .= " AND (acao LIKE :b OR detalhes LIKE :b)";
    $params[':b'] = "%{$busca}%";
}

function csvOutputLog($filename, $header, $rows) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header, ';');
    foreach ($rows as $r) fputcsv($out, $r, ';');
    fclose($out);
    exit;
}

if (($_GET['format'] ?? '') === 'csv') {
    $stmt = $db->prepare("SELECT * FROM logs {$where} ORDER BY created_at DESC");
    $stmt->execute($params);
    $todas = $stmt->fetchAll();
    $rows = array_map(function ($l) {
        return [date('d/m/Y', strtotime($l['created_at'])), date('H:i:s', strtotime($l['created_at'])), $l['categoria'], $l['acao'], $l['usuario'], $l['detalhes']];
    }, $todas);
    csvOutputLog('log_auditoria.csv', ['Data', 'Hora', 'Categoria', 'Ação', 'Usuário', 'Detalhes'], $rows);
}

$porPagina = 40;
$paginaAtual = max(1, (int)($_GET['pagina'] ?? 1));

$totalResultados = (int)(function () use ($db, $where, $params) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM logs {$where}");
    $stmt->execute($params);
    return $stmt->fetchColumn();
})();
$totalPaginas = max(1, (int)ceil($totalResultados / $porPagina));
$paginaAtual = min($paginaAtual, $totalPaginas);
$offset = ($paginaAtual - 1) * $porPagina;

$stmt = $db->prepare("SELECT * FROM logs {$where} ORDER BY created_at DESC LIMIT {$porPagina} OFFSET {$offset}");
$stmt->execute($params);
$logs = $stmt->fetchAll();

function logCategoriaBadgeClass($categoria) {
    return match ($categoria) {
        'Autenticação' => 'bg-info text-dark',
        'Usuários' => 'bg-warning text-dark',
        'Medicamentos' => 'bg-secondary',
        'Movimentação' => 'bg-success',
        default => 'bg-light',
    };
}

function logQueryString(array $overrides = []) {
    $qs = array_merge(['page' => 'logs'], $_GET, $overrides);
    unset($qs['format']);
    return htmlspecialchars(http_build_query($qs));
}

$qs = $_GET;
unset($qs['format']);
$csvQs = http_build_query(array_merge($qs, ['format' => 'csv']));
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Log de Auditoria</h1>
        <div class="page-sub">Ações executadas na aplicação — autenticação, usuários, medicamentos e movimentação</div>
    </div>
</div>

<form method="GET" class="entity-list-toolbar">
    <input type="hidden" name="page" value="logs">
    <select name="categoria" class="form-select" style="max-width:180px;">
        <option value="">Todas as categorias</option>
        <?php foreach (LOG_CATEGORIAS as $cat): ?>
            <option value="<?= htmlspecialchars($cat) ?>" <?= $categoria === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="usuario" class="form-select" style="max-width:180px;">
        <option value="">Todos os usuários</option>
        <?php foreach ($usuarios as $u): ?>
            <option value="<?= htmlspecialchars($u) ?>" <?= $usuarioFiltro === $u ? 'selected' : '' ?>><?= htmlspecialchars($u) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="date" name="data_inicio" class="form-control" style="max-width:160px;" value="<?= htmlspecialchars($dataInicio) ?>">
    <input type="date" name="data_fim" class="form-control" style="max-width:160px;" value="<?= htmlspecialchars($dataFim) ?>">
    <input type="text" name="busca" class="form-control" style="max-width:200px;" placeholder="Buscar na ação/detalhes..." value="<?= htmlspecialchars($busca) ?>">
    <button class="btn btn-outline-primary">Filtrar</button>
    <a class="btn btn-outline-secondary ms-auto" href="?<?= htmlspecialchars($csvQs) ?>"><i class="bi bi-download"></i> Exportar CSV</a>
</form>

<div class="table-responsive">
    <table class="table table-striped table-hover bg-white align-middle">
        <thead class="table-dark">
            <tr><th>Data</th><th>Hora</th><th>Categoria</th><th>Ação</th><th>Usuário</th><th>Detalhes</th></tr>
        </thead>
        <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Nenhum registro para este filtro.</td></tr>
            <?php else: ?>
                <?php foreach ($logs as $l): ?>
                    <tr>
                        <td class="mono text-nowrap"><?= date('d/m/Y', strtotime($l['created_at'])) ?></td>
                        <td class="mono"><?= date('H:i:s', strtotime($l['created_at'])) ?></td>
                        <td><span class="badge <?= logCategoriaBadgeClass($l['categoria']) ?>"><?= htmlspecialchars($l['categoria']) ?></span></td>
                        <td><?= htmlspecialchars($l['acao']) ?></td>
                        <td><?= htmlspecialchars($l['usuario'] ?: '—') ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($l['detalhes']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPaginas > 1): ?>
    <nav>
        <ul class="pagination">
            <li class="page-item <?= $paginaAtual <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= logQueryString(['pagina' => $paginaAtual - 1]) ?>">Anterior</a>
            </li>
            <li class="page-item disabled"><span class="page-link"><?= $paginaAtual ?> / <?= $totalPaginas ?></span></li>
            <li class="page-item <?= $paginaAtual >= $totalPaginas ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= logQueryString(['pagina' => $paginaAtual + 1]) ?>">Próxima</a>
            </li>
        </ul>
    </nav>
<?php endif; ?>
