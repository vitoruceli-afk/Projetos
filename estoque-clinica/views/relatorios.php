<?php
$db = getDB();
$tab = in_array($_GET['tab'] ?? '', ['movimentacoes', 'historico', 'estoque_minimo'], true) ? $_GET['tab'] : 'estoque';
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

// Entradas/Saídas AGRUPADAS por confirmação: cada clique em "Confirmar Entrada"/"Confirmar
// Saída" na tela de Movimentação vira uma única linha aqui (com a contagem de itens e o total
// confirmado naquela ação), espelhando o que foi de fato confirmado — em vez de listar
// medicamento a medicamento.
function buscarMovimentacoesAgrupadas(PDO $db, string $tipo, string $dataInicio, string $dataFim) {
    $grupoSql = movimentacaoGrupoChaveSql('mv');
    // LEFT JOIN só bate pra confirmações novas (confirmacao_id real); entradas antigas agrupadas
    // pela chave aproximada (usuário+minuto) simplesmente ficam sem paciente_nome, o que é
    // esperado, já que esse campo nem existia quando foram feitas.
    $sql = "SELECT {$grupoSql} AS grupo_chave, MIN(mv.created_at) AS created_at, MAX(mv.usuario) AS usuario,
            COUNT(*) AS total_itens, SUM(mv.quantidade) AS total_quantidade,
            SUM(mv.quantidade * mv.valor_unitario) AS valor_total,
            MAX(p.nome_completo) AS paciente_nome
        FROM movimentacoes mv
        LEFT JOIN movimentacao_confirmacoes mc ON mc.id = mv.confirmacao_id
        LEFT JOIN pacientes p ON p.id = mc.paciente_id
        WHERE mv.tipo = :tipo AND DATE(mv.created_at) BETWEEN :di AND :df
        GROUP BY grupo_chave
        ORDER BY created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute([':tipo' => $tipo, ':di' => $dataInicio, ':df' => $dataFim]);
    return $stmt->fetchAll();
}

// Entradas/Saídas item a item (só para a exportação CSV — o relatório na tela mostra agrupado).
// LEFT JOIN nas duas origens possíveis (medicamento ou insumo) e COALESCE pra exibir a de qual
// delas bateu — medicamento_id/insumo_id são mutuamente exclusivos em cada linha.
function buscarMovimentacoesDetalhado(PDO $db, string $tipo, string $dataInicio, string $dataFim) {
    $sql = "SELECT mv.created_at, mv.quantidade, mv.valor_unitario, mv.usuario,
            COALESCE(md.produto, ins.nome_comercial) AS medicamento_nome,
            COALESCE(md.laboratorio, ins.marca) AS laboratorio_nome,
            COALESCE(l.lote, ins.lote) AS lote
        FROM movimentacoes mv
        LEFT JOIN medicamentos_anvisa md ON md.id = mv.medicamento_id
        LEFT JOIN insumos ins ON ins.id = mv.insumo_id
        LEFT JOIN insumo_lotes l ON l.id = mv.lote_id
        WHERE mv.tipo = :tipo AND DATE(mv.created_at) BETWEEN :di AND :df
        ORDER BY mv.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute([':tipo' => $tipo, ':di' => $dataInicio, ':df' => $dataFim]);
    return $stmt->fetchAll();
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
} elseif ($tab === 'estoque_minimo') {
    // Medicamentos e insumos juntos numa lista só, já que os dois têm o mesmo conceito de
    // "estoque mínimo" e o objetivo do relatório é mostrar quem precisa de reposição.
    $itensMinimo = [];
    foreach (medicamentosAbaixoDoMinimo($db) as $m) {
        $itensMinimo[] = [
            'tipo' => 'medicamento', 'nome' => $m['produto'], 'origem' => $m['laboratorio'],
            'estoque_atual' => (int)$m['estoque_atual'], 'estoque_minimo' => (int)$m['estoque_minimo'], 'unidade' => 'un.',
        ];
    }
    foreach (insumosAbaixoDoMinimo($db) as $i) {
        $itensMinimo[] = [
            'tipo' => 'insumo', 'nome' => $i['nome_comercial'], 'origem' => $i['marca'],
            'estoque_atual' => (int)$i['quantidade'], 'estoque_minimo' => (int)$i['estoque_minimo'], 'unidade' => $i['unidade_medida'],
        ];
    }
    if ($busca !== '') {
        $itensMinimo = array_values(array_filter($itensMinimo, function ($it) use ($busca) {
            return mb_stripos($it['nome'], $busca) !== false;
        }));
    }
    usort($itensMinimo, function ($a, $b) { return strcasecmp($a['nome'], $b['nome']); });

    if (($_GET['format'] ?? '') === 'csv') {
        $rows = array_map(function ($it) {
            return [$it['tipo'] === 'medicamento' ? 'Medicamento' : 'Insumo', $it['nome'], $it['origem'], $it['estoque_atual'], $it['estoque_minimo'], $it['unidade']];
        }, $itensMinimo);
        csvOutput('relatorio_estoque_minimo.csv', ['Tipo', 'Nome', 'Laboratório/Marca', 'Estoque Atual', 'Estoque Mínimo', 'Unidade'], $rows);
    }
} elseif ($tab === 'movimentacoes') {
    $dataInicio = trim($_GET['data_inicio'] ?? date('Y-m-01'));
    $dataFim = trim($_GET['data_fim'] ?? date('Y-m-d'));
    $tipo = in_array($_GET['tipo'] ?? '', ['entrada', 'saida']) ? $_GET['tipo'] : 'todos';

    $sql = "SELECT mv.*,
            COALESCE(md.produto, ins.nome_comercial) AS medicamento_nome,
            COALESCE(md.laboratorio, ins.marca) AS laboratorio_nome,
            md.codigo_ggrem,
            COALESCE(l.lote, ins.lote) AS lote
        FROM movimentacoes mv
        LEFT JOIN medicamentos_anvisa md ON md.id = mv.medicamento_id
        LEFT JOIN insumos ins ON ins.id = mv.insumo_id
        LEFT JOIN insumo_lotes l ON l.id = mv.lote_id
        WHERE DATE(mv.created_at) BETWEEN :di AND :df";
    $params = [':di' => $dataInicio, ':df' => $dataFim];
    if ($laboratorio !== '') { $sql .= " AND md.laboratorio = :lab"; $params[':lab'] = $laboratorio; }
    if ($busca !== '') { $sql .= " AND (md.produto LIKE :b OR ins.nome_comercial LIKE :b)"; $params[':b'] = "%{$busca}%"; }
    if ($tipo !== 'todos') { $sql .= " AND mv.tipo = :tipo"; $params[':tipo'] = $tipo; }
    $sql .= " ORDER BY mv.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $linhas = $stmt->fetchAll();

    if (($_GET['format'] ?? '') === 'csv') {
        $rows = array_map(function ($m) {
            $valorUnit = number_format((float)$m['valor_unitario'], 2, ',', '');
            $subtotal = number_format((float)$m['valor_unitario'] * (int)$m['quantidade'], 2, ',', '');
            return [date('d/m/Y H:i', strtotime($m['created_at'])), $m['tipo'] === 'entrada' ? 'Entrada' : 'Saída', $m['medicamento_nome'], $m['laboratorio_nome'], $m['lote'], $m['quantidade'], $valorUnit, $subtotal, $m['usuario'], $m['observacao']];
        }, $linhas);
        csvOutput('relatorio_movimentacoes.csv', ['Data/Hora', 'Tipo', 'Medicamento', 'Laboratório', 'Lote', 'Quantidade', 'Valor Unitário', 'Subtotal', 'Usuário', 'Observação'], $rows);
    }
} else {
    // historico: entradas e saídas, ambas agrupadas por confirmação, lado a lado.
    $dataInicio = trim($_GET['data_inicio'] ?? date('Y-m-01'));
    $dataFim = trim($_GET['data_fim'] ?? date('Y-m-d'));

    $entradasAgrupadas = buscarMovimentacoesAgrupadas($db, 'entrada', $dataInicio, $dataFim);
    $saidasAgrupadas = buscarMovimentacoesAgrupadas($db, 'saida', $dataInicio, $dataFim);

    $csvTipo = $_GET['csv_tipo'] ?? '';
    if (($_GET['format'] ?? '') === 'csv' && in_array($csvTipo, ['entrada', 'saida'], true)) {
        $rows = array_map(function ($m) {
            $valorUnit = number_format((float)$m['valor_unitario'], 2, ',', '');
            $subtotal = number_format((float)$m['valor_unitario'] * (int)$m['quantidade'], 2, ',', '');
            return [date('d/m/Y', strtotime($m['created_at'])), date('H:i', strtotime($m['created_at'])), $m['medicamento_nome'], $m['laboratorio_nome'], $m['lote'], $m['quantidade'], $valorUnit, $subtotal, $m['usuario']];
        }, buscarMovimentacoesDetalhado($db, $csvTipo, $dataInicio, $dataFim));
        $nome = $csvTipo === 'entrada' ? 'relatorio_entradas.csv' : 'relatorio_saidas.csv';
        csvOutput($nome, ['Data', 'Hora', 'Medicamento', 'Laboratório', 'Lote', 'Quantidade', 'Valor Unitário', 'Subtotal', 'Usuário'], $rows);
    }
}

$qs = $_GET;
unset($qs['format'], $qs['csv_tipo']);
$csvQs = http_build_query(array_merge($qs, ['format' => 'csv']));
$csvEntradasQs = http_build_query(array_merge($qs, ['format' => 'csv', 'csv_tipo' => 'entrada']));
$csvSaidasQs = http_build_query(array_merge($qs, ['format' => 'csv', 'csv_tipo' => 'saida']));
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Relatórios</h1>
        <div class="page-sub">Consulte o estoque por vencimento, o histórico de entradas/saídas ou as movimentações completas</div>
    </div>
</div>

<ul class="nav nav-tabs mb-4">
    <li class="nav-item"><a class="nav-link <?= $tab === 'estoque' ? 'active' : '' ?>" href="index.php?page=relatorios&tab=estoque">Estoque por Vencimento</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'estoque_minimo' ? 'active' : '' ?>" href="index.php?page=relatorios&tab=estoque_minimo">Estoque Mínimo</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'historico' ? 'active' : '' ?>" href="index.php?page=relatorios&tab=historico">Entradas e Saídas</a></li>
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

<?php elseif ($tab === 'estoque_minimo'): ?>
    <form method="GET" class="entity-list-toolbar">
        <input type="hidden" name="page" value="relatorios">
        <input type="hidden" name="tab" value="estoque_minimo">
        <input type="text" name="busca" class="form-control" style="max-width:240px;" placeholder="Buscar por nome..." value="<?= htmlspecialchars($busca) ?>">
        <button class="btn btn-outline-primary">Filtrar</button>
        <a class="btn btn-outline-secondary ms-auto" href="?<?= htmlspecialchars($csvQs) ?>"><i class="bi bi-download"></i> Exportar CSV</a>
    </form>

    <div class="table-responsive">
        <table class="table table-striped table-hover bg-white align-middle">
            <thead class="table-dark">
                <tr><th>Tipo</th><th>Nome</th><th>Laboratório/Marca</th><th class="text-center">Estoque Atual</th><th class="text-center">Estoque Mínimo</th></tr>
            </thead>
            <tbody>
                <?php if (empty($itensMinimo)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">Nenhum medicamento ou insumo atingiu o estoque mínimo.</td></tr>
                <?php else: ?>
                    <?php foreach ($itensMinimo as $it): ?>
                        <tr class="<?= $it['estoque_atual'] <= 0 ? 'table-danger' : 'table-warning' ?>">
                            <td><span class="badge <?= $it['tipo'] === 'medicamento' ? 'bg-info text-dark' : 'bg-secondary' ?>"><?= $it['tipo'] === 'medicamento' ? 'Medicamento' : 'Insumo' ?></span></td>
                            <td><?= htmlspecialchars($it['nome']) ?></td>
                            <td><?= htmlspecialchars($it['origem'] ?: '—') ?></td>
                            <td class="text-center"><?= $it['estoque_atual'] ?> <?= htmlspecialchars($it['unidade']) ?></td>
                            <td class="text-center"><?= $it['estoque_minimo'] ?> <?= htmlspecialchars($it['unidade']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab === 'historico'): ?>
    <form method="GET" class="entity-list-toolbar">
        <input type="hidden" name="page" value="relatorios">
        <input type="hidden" name="tab" value="historico">
        <input type="date" name="data_inicio" class="form-control" style="max-width:160px;" value="<?= htmlspecialchars($dataInicio) ?>">
        <input type="date" name="data_fim" class="form-control" style="max-width:160px;" value="<?= htmlspecialchars($dataFim) ?>">
        <button class="btn btn-outline-primary">Filtrar</button>
    </form>

    <?php
    $valorTotalEntradas = array_sum(array_map(function ($e) { return (float)$e['valor_total']; }, $entradasAgrupadas));
    $valorTotalSaidas = array_sum(array_map(function ($s) { return (float)$s['valor_total']; }, $saidasAgrupadas));
    ?>
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-arrow-down-circle text-success"></i> Entradas</span>
                    <a class="small fw-bold" href="?<?= htmlspecialchars($csvEntradasQs) ?>"><i class="bi bi-download"></i> Exportar CSV</a>
                </div>
                <div class="form-text px-3 pt-2">Cada linha é uma confirmação de entrada — clique em "Ver Itens" para conferir os medicamentos incluídos.</div>
                <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-light">
                    <span class="small text-muted">Valor total investido no período</span>
                    <span class="fw-bold">R$ <?= number_format($valorTotalEntradas, 2, ',', '.') ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped table-hover bg-white align-middle mb-0">
                        <thead class="table-dark">
                            <tr><th>Data</th><th>Hora</th><th>Usuário</th><th class="text-center">Itens</th><th class="text-center">Qtd. Total</th><th class="text-end">Valor Total</th><th></th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($entradasAgrupadas)): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">Nenhuma entrada neste período.</td></tr>
                            <?php else: ?>
                                <?php foreach ($entradasAgrupadas as $e): ?>
                                    <tr>
                                        <td class="mono text-nowrap"><?= date('d/m/Y', strtotime($e['created_at'])) ?></td>
                                        <td class="mono"><?= date('H:i', strtotime($e['created_at'])) ?></td>
                                        <td><?= htmlspecialchars($e['usuario']) ?></td>
                                        <td class="text-center"><?= (int)$e['total_itens'] ?></td>
                                        <td class="text-center"><?= (int)$e['total_quantidade'] ?></td>
                                        <td class="text-end mono">R$ <?= number_format((float)$e['valor_total'], 2, ',', '.') ?></td>
                                        <td class="text-nowrap">
                                            <button type="button" class="btn btn-sm btn-outline-primary btn-ver-itens" data-grupo="<?= htmlspecialchars($e['grupo_chave']) ?>" data-tipo="entrada">
                                                <i class="bi bi-list-ul"></i> Ver Itens
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-arrow-up-circle text-danger"></i> Saídas</span>
                    <a class="small fw-bold" href="?<?= htmlspecialchars($csvSaidasQs) ?>"><i class="bi bi-download"></i> Exportar CSV</a>
                </div>
                <div class="form-text px-3 pt-2">Cada linha é uma confirmação de saída — clique em "Ver Itens" para conferir o resumo financeiro da operação.</div>
                <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-light">
                    <span class="small text-muted">Valor total retirado no período</span>
                    <span class="fw-bold">R$ <?= number_format($valorTotalSaidas, 2, ',', '.') ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped table-hover bg-white align-middle mb-0">
                        <thead class="table-dark">
                            <tr><th>Data</th><th>Hora</th><th>Usuário</th><th>Paciente</th><th class="text-center">Itens</th><th class="text-center">Qtd. Total</th><th class="text-end">Valor Total</th><th></th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($saidasAgrupadas)): ?>
                                <tr><td colspan="8" class="text-center text-muted py-4">Nenhuma saída neste período.</td></tr>
                            <?php else: ?>
                                <?php foreach ($saidasAgrupadas as $s): ?>
                                    <tr>
                                        <td class="mono text-nowrap"><?= date('d/m/Y', strtotime($s['created_at'])) ?></td>
                                        <td class="mono"><?= date('H:i', strtotime($s['created_at'])) ?></td>
                                        <td><?= htmlspecialchars($s['usuario']) ?></td>
                                        <td><?= htmlspecialchars($s['paciente_nome'] ?: '—') ?></td>
                                        <td class="text-center"><?= (int)$s['total_itens'] ?></td>
                                        <td class="text-center"><?= (int)$s['total_quantidade'] ?></td>
                                        <td class="text-end mono">R$ <?= number_format((float)$s['valor_total'], 2, ',', '.') ?></td>
                                        <td class="text-nowrap">
                                            <button type="button" class="btn btn-sm btn-outline-primary btn-ver-itens" data-grupo="<?= htmlspecialchars($s['grupo_chave']) ?>" data-tipo="saida">
                                                <i class="bi bi-list-ul"></i> Ver Itens
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="movItensModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="movItensModalTitle">Itens da Confirmação</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body" id="movItensModalBody">
                    <div class="text-muted small">Carregando...</div>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var modalEl = document.getElementById('movItensModal');
        if (!modalEl) return;
        var modalBody = document.getElementById('movItensModalBody');
        var modalTitle = document.getElementById('movItensModalTitle');
        var modal = new bootstrap.Modal(modalEl);

        function esc(s) {
            var d = document.createElement('div');
            d.textContent = (s === null || s === undefined) ? '' : String(s);
            return d.innerHTML;
        }

        document.querySelectorAll('.btn-ver-itens').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var grupo = btn.getAttribute('data-grupo');
                var tipo = btn.getAttribute('data-tipo');
                modalTitle.textContent = tipo === 'entrada' ? 'Itens da Entrada' : 'Itens da Saída';
                modalBody.innerHTML = '<div class="text-muted small">Carregando...</div>';
                modal.show();

                fetch('ajax_movimentacao_itens.php?grupo=' + encodeURIComponent(grupo) + '&tipo=' + encodeURIComponent(tipo))
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.found) {
                            modalBody.innerHTML = '<div class="alert alert-danger mb-0">' + esc(data.error) + '</div>';
                            return;
                        }
                        function fmtMoeda(v) {
                            return 'R$ ' + (Number(v) || 0).toFixed(2).replace('.', ',');
                        }
                        var linhas = data.itens.map(function (i) {
                            return '<tr>' +
                                '<td>' + esc(i.produto) + (i.apresentacao ? '<div class="entity-sub">' + esc(i.apresentacao) + '</div>' : '') + '</td>' +
                                '<td>' + esc(i.laboratorio || '—') + '</td>' +
                                '<td class="mono">' + esc(i.lote || '—') + '</td>' +
                                '<td class="mono">' + esc(i.validade_br || '—') + '</td>' +
                                '<td class="text-center">' + esc(i.quantidade) + '</td>' +
                                '<td class="text-end mono">' + fmtMoeda(i.valor_unitario) + '</td>' +
                                '<td class="text-end mono">' + fmtMoeda(i.subtotal) + '</td>' +
                                '</tr>';
                        }).join('');
                        modalBody.innerHTML = '<div class="table-responsive"><table class="table table-sm table-striped mb-0">' +
                            '<thead><tr><th>Medicamento</th><th>Laboratório</th><th>Lote</th><th>Validade</th><th class="text-center">Qtd.</th><th class="text-end">Valor Unit.</th><th class="text-end">Subtotal</th></tr></thead>' +
                            '<tbody>' + linhas + '</tbody></table></div>' +
                            '<div class="d-flex justify-content-between align-items-center border-top mt-2 pt-2">' +
                                '<span class="fw-bold">Resumo financeiro da operação</span>' +
                                '<span class="fw-bold fs-5">' + fmtMoeda(data.valor_total) + '</span>' +
                            '</div>';
                    })
                    .catch(function () {
                        modalBody.innerHTML = '<div class="alert alert-danger mb-0">Erro ao carregar os itens.</div>';
                    });
            });
        });
    });
    </script>

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
                <tr><th>Data/Hora</th><th>Tipo</th><th>Medicamento</th><th>Laboratório</th><th>Lote</th><th class="text-center">Qtd.</th><th class="text-end">Valor Unit.</th><th class="text-end">Subtotal</th><th>Usuário</th><th>Observação</th></tr>
            </thead>
            <tbody>
                <?php if (empty($linhas)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">Nenhuma movimentação neste período.</td></tr>
                <?php else: ?>
                    <?php foreach ($linhas as $m): ?>
                        <tr>
                            <td class="mono text-nowrap"><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></td>
                            <td><span class="badge <?= $m['tipo'] === 'entrada' ? 'bg-success' : 'bg-danger' ?>"><?= $m['tipo'] === 'entrada' ? 'Entrada' : 'Saída' ?></span></td>
                            <td><?= htmlspecialchars($m['medicamento_nome']) ?></td>
                            <td><?= htmlspecialchars($m['laboratorio_nome'] ?: '—') ?></td>
                            <td class="mono"><?= htmlspecialchars($m['lote'] ?: '—') ?></td>
                            <td class="text-center"><?= (int)$m['quantidade'] ?></td>
                            <td class="text-end mono">R$ <?= number_format((float)$m['valor_unitario'], 2, ',', '.') ?></td>
                            <td class="text-end mono">R$ <?= number_format((float)$m['valor_unitario'] * (int)$m['quantidade'], 2, ',', '.') ?></td>
                            <td><?= htmlspecialchars($m['usuario']) ?></td>
                            <td><?= htmlspecialchars($m['observacao']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
