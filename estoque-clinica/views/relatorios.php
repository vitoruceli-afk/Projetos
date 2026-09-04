<?php
$db = getDB();
$tab = in_array($_GET['tab'] ?? '', ['movimentacoes', 'entradas', 'saidas', 'estoque_minimo'], true) ? $_GET['tab'] : 'estoque';
$labs = $db->query("SELECT DISTINCT laboratorio FROM medicamentos_anvisa WHERE laboratorio <> '' ORDER BY laboratorio ASC")->fetchAll(PDO::FETCH_COLUMN);

$laboratorio = trim($_GET['laboratorio'] ?? '');
$busca = trim($_GET['busca'] ?? '');

function nomeFornecedorExibicao(?string $razao, ?string $fantasia): string {
    if (!$razao) return '';
    return ($fantasia && $fantasia !== $razao) ? "{$razao} ({$fantasia})" : $razao;
}

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
    // Entrada soma valor de compra (custo investido); Saída soma valor de venda (o que foi
    // repassado na retirada) — mesmo critério do resumo financeiro na tela Movimentação.
    $colunaValor = $tipo === 'saida' ? 'valor_venda' : 'valor_unitario';
    // LEFT JOIN só bate pra confirmações novas (confirmacao_id real); entradas antigas agrupadas
    // pela chave aproximada (usuário+minuto) simplesmente ficam sem paciente_nome, o que é
    // esperado, já que esse campo nem existia quando foram feitas.
    $sql = "SELECT {$grupoSql} AS grupo_chave, MIN(mv.created_at) AS created_at, MAX(mv.usuario) AS usuario,
            COUNT(*) AS total_itens, SUM(mv.quantidade) AS total_quantidade,
            SUM(mv.quantidade * mv.{$colunaValor}) AS valor_total,
            MAX(p.nome_completo) AS paciente_nome,
            MAX(f.razao_social) AS fornecedor_razao, MAX(f.nome_fantasia) AS fornecedor_fantasia
        FROM movimentacoes mv
        LEFT JOIN movimentacao_confirmacoes mc ON mc.id = mv.confirmacao_id
        LEFT JOIN pacientes p ON p.id = mc.paciente_id
        LEFT JOIN fornecedores f ON f.id = mc.fornecedor_id
        WHERE mv.tipo = :tipo AND mv.created_at >= :di AND mv.created_at < DATE_ADD(:df, INTERVAL 1 DAY)
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
    $sql = "SELECT mv.created_at, mv.quantidade, mv.valor_unitario, mv.valor_venda, mv.usuario,
            COALESCE(md.produto, ins.nome_comercial) AS medicamento_nome,
            COALESCE(md.laboratorio, ins.marca) AS laboratorio_nome,
            l.lote AS lote,
            f.razao_social AS fornecedor_razao, f.nome_fantasia AS fornecedor_fantasia
        FROM movimentacoes mv
        LEFT JOIN medicamentos_anvisa md ON md.id = mv.medicamento_id
        LEFT JOIN insumos ins ON ins.id = mv.insumo_id
        LEFT JOIN insumo_lotes l ON l.id = mv.lote_id
        LEFT JOIN movimentacao_confirmacoes mc ON mc.id = mv.confirmacao_id
        LEFT JOIN fornecedores f ON f.id = mc.fornecedor_id
        WHERE mv.tipo = :tipo AND mv.created_at >= :di AND mv.created_at < DATE_ADD(:df, INTERVAL 1 DAY)
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
            return [$l['medicamento_nome'], $l['laboratorio_nome'], $l['codigo_ggrem'], $l['lote'], formatarValidade($l['validade']), $l['quantidade'], statusVencimentoLabel($st)];
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
            'estoque_atual' => (int)$i['estoque_atual'], 'estoque_minimo' => (int)$i['estoque_minimo'], 'unidade' => $i['unidade_medida'],
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
            l.lote AS lote, l.validade AS validade
        FROM movimentacoes mv
        LEFT JOIN medicamentos_anvisa md ON md.id = mv.medicamento_id
        LEFT JOIN insumos ins ON ins.id = mv.insumo_id
        LEFT JOIN insumo_lotes l ON l.id = mv.lote_id
        WHERE mv.created_at >= :di AND mv.created_at < DATE_ADD(:df, INTERVAL 1 DAY)";
    $params = [':di' => $dataInicio, ':df' => $dataFim];
    if ($laboratorio !== '') { $sql .= " AND md.laboratorio = :lab"; $params[':lab'] = $laboratorio; }
    if ($busca !== '') { $sql .= " AND (md.produto LIKE :b OR ins.nome_comercial LIKE :b)"; $params[':b'] = "%{$busca}%"; }
    if ($tipo !== 'todos') { $sql .= " AND mv.tipo = :tipo"; $params[':tipo'] = $tipo; }
    $sql .= " ORDER BY mv.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $linhas = $stmt->fetchAll();

    $totalMovEntradas = 0; $totalMovSaidas = 0; $valorMovimentado = 0;
    foreach ($linhas as $m) {
        if ($m['tipo'] === 'entrada') { $totalMovEntradas++; $valorBase = (float)$m['valor_unitario']; }
        else { $totalMovSaidas++; $valorBase = (float)$m['valor_venda']; }
        $valorMovimentado += $valorBase * (int)$m['quantidade'];
    }

    if (($_GET['format'] ?? '') === 'csv') {
        // Entrada usa o valor de compra (custo); Saída usa o valor de venda — a coluna "Tipo" já
        // diz qual dos dois é cada linha.
        $rows = array_map(function ($m) {
            $valorBase = $m['tipo'] === 'saida' ? (float)$m['valor_venda'] : (float)$m['valor_unitario'];
            $valorFmt = number_format($valorBase, 2, ',', '');
            $subtotal = number_format($valorBase * (int)$m['quantidade'], 2, ',', '');
            return [date('d/m/Y H:i', strtotime($m['created_at'])), $m['tipo'] === 'entrada' ? 'Entrada' : 'Saída', $m['medicamento_nome'], $m['laboratorio_nome'], $m['lote'], $m['quantidade'], $valorFmt, $subtotal, $m['usuario'], $m['observacao']];
        }, $linhas);
        csvOutput('relatorio_movimentacoes.csv', ['Data/Hora', 'Tipo', 'Medicamento', 'Laboratório', 'Lote', 'Quantidade', 'Valor (Compra/Venda)', 'Subtotal', 'Usuário', 'Observação'], $rows);
    }
} else {
    // entradas / saidas: cada uma agora é uma visão própria (cheia largura), em vez de dividir a
    // tela ao meio — dá espaço pra tabela respirar e caber mais colunas (ex: Fornecedor) sem
    // espremer. tipoMov é o valor usado nas colunas tipo/tipo de movimentacoes ('entrada'/'saida'),
    // já que o nome da aba é o plural.
    $tipoMov = $tab === 'entradas' ? 'entrada' : 'saida';
    $dataInicio = trim($_GET['data_inicio'] ?? date('Y-m-01'));
    $dataFim = trim($_GET['data_fim'] ?? date('Y-m-d'));

    $agrupadas = buscarMovimentacoesAgrupadas($db, $tipoMov, $dataInicio, $dataFim);

    if (($_GET['format'] ?? '') === 'csv') {
        $colunaValorCsv = $tipoMov === 'saida' ? 'Valor Venda' : 'Valor Compra';
        $rows = array_map(function ($m) use ($tipoMov) {
            $valorBase = $tipoMov === 'saida' ? (float)$m['valor_venda'] : (float)$m['valor_unitario'];
            $valorFmt = number_format($valorBase, 2, ',', '');
            $subtotal = number_format($valorBase * (int)$m['quantidade'], 2, ',', '');
            $linha = [date('d/m/Y', strtotime($m['created_at'])), date('H:i', strtotime($m['created_at'])), $m['medicamento_nome'], $m['laboratorio_nome'], $m['lote'], $m['quantidade'], $valorFmt, $subtotal, $m['usuario']];
            if ($tipoMov === 'entrada') $linha[] = nomeFornecedorExibicao($m['fornecedor_razao'], $m['fornecedor_fantasia']);
            return $linha;
        }, buscarMovimentacoesDetalhado($db, $tipoMov, $dataInicio, $dataFim));
        $nome = $tipoMov === 'entrada' ? 'relatorio_entradas.csv' : 'relatorio_saidas.csv';
        $header = ['Data', 'Hora', 'Medicamento', 'Laboratório', 'Lote', 'Quantidade', $colunaValorCsv, 'Subtotal', 'Usuário'];
        if ($tipoMov === 'entrada') $header[] = 'Fornecedor';
        csvOutput($nome, $header, $rows);
    }
}

$qs = $_GET;
unset($qs['format']);
$csvQs = http_build_query(array_merge($qs, ['format' => 'csv']));
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
    <li class="nav-item"><a class="nav-link <?= $tab === 'entradas' ? 'active' : '' ?>" href="index.php?page=relatorios&tab=entradas">Entradas</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'saidas' ? 'active' : '' ?>" href="index.php?page=relatorios&tab=saidas">Saídas</a></li>
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
                            <td class="mono"><?= formatarValidade($l['validade']) ?></td>
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

<?php elseif ($tab === 'entradas' || $tab === 'saidas'):
    $ehEntrada = $tab === 'entradas';
    $totalConfirmacoes = count($agrupadas);
    $totalItensSoma = array_sum(array_map(function ($m) { return (int)$m['total_itens']; }, $agrupadas));
    $totalQuantidadeSoma = array_sum(array_map(function ($m) { return (int)$m['total_quantidade']; }, $agrupadas));
    $valorTotalSoma = array_sum(array_map(function ($m) { return (float)$m['valor_total']; }, $agrupadas));
    ?>
    <form method="GET" class="entity-list-toolbar">
        <input type="hidden" name="page" value="relatorios">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
        <input type="date" name="data_inicio" class="form-control" style="max-width:170px;" value="<?= htmlspecialchars($dataInicio) ?>">
        <input type="date" name="data_fim" class="form-control" style="max-width:170px;" value="<?= htmlspecialchars($dataFim) ?>">
        <button class="btn btn-outline-primary">Filtrar</button>
        <a class="btn btn-outline-secondary ms-auto" href="?<?= htmlspecialchars($csvQs) ?>"><i class="bi bi-download"></i> Exportar CSV</a>
    </form>

    <div class="stat-strip">
        <div class="stat-tile">
            <div>
                <div class="stat-label"><?= $ehEntrada ? 'Entradas Confirmadas' : 'Saídas Confirmadas' ?></div>
                <div class="stat-value"><?= $totalConfirmacoes ?></div>
                <div class="stat-note">no período selecionado</div>
            </div>
            <div class="stat-icon <?= $ehEntrada ? 'green' : 'red' ?>">
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><?= $ehEntrada ? '<path d="M12 19V5M6 11l6-6 6 6"/>' : '<path d="M12 5v14M6 13l6 6 6-6"/>' ?></svg>
            </div>
        </div>
        <div class="stat-tile">
            <div>
                <div class="stat-label">Itens Movimentados</div>
                <div class="stat-value"><?= $totalItensSoma ?></div>
                <div class="stat-note"><?= $totalQuantidadeSoma ?> unidade(s) ao todo</div>
            </div>
            <div class="stat-icon blue"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3.5v3M16 3.5v3"/></svg></div>
        </div>
        <div class="stat-tile">
            <div>
                <div class="stat-label"><?= $ehEntrada ? 'Valor Investido' : 'Valor Retirado' ?></div>
                <div class="stat-value">R$ <?= number_format($valorTotalSoma, 2, ',', '.') ?></div>
                <div class="stat-note"><?= $ehEntrada ? 'valor de compra no período' : 'valor de venda no período' ?></div>
            </div>
            <div class="stat-icon orange"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex align-items-center">
            <span><i class="bi <?= $ehEntrada ? 'bi-arrow-down-circle text-success' : 'bi-arrow-up-circle text-danger' ?>"></i> <?= $ehEntrada ? 'Entradas do período' : 'Saídas do período' ?></span>
        </div>
        <div class="form-text px-3 pt-2">Cada linha é uma confirmação de <?= $ehEntrada ? 'entrada' : 'saída' ?> — clique em "Ver Itens" para conferir <?= $ehEntrada ? 'os medicamentos incluídos' : 'o resumo financeiro da operação' ?>.</div>
        <div class="table-responsive">
            <table class="table table-striped table-hover bg-white align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Data</th><th>Hora</th><th>Usuário</th>
                        <?php if ($ehEntrada): ?><th>Fornecedor</th><?php else: ?><th>Paciente</th><?php endif; ?>
                        <th class="text-center">Itens</th><th class="text-center">Qtd. Total</th><th class="text-end">Valor Total</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($agrupadas)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-5">Nenhuma <?= $ehEntrada ? 'entrada' : 'saída' ?> neste período.</td></tr>
                    <?php else: ?>
                        <?php foreach ($agrupadas as $m): ?>
                            <tr>
                                <td class="mono text-nowrap"><?= date('d/m/Y', strtotime($m['created_at'])) ?></td>
                                <td class="mono"><?= date('H:i', strtotime($m['created_at'])) ?></td>
                                <td><?= htmlspecialchars($m['usuario']) ?></td>
                                <?php if ($ehEntrada): ?>
                                    <td><?= htmlspecialchars(nomeFornecedorExibicao($m['fornecedor_razao'], $m['fornecedor_fantasia']) ?: '—') ?></td>
                                <?php else: ?>
                                    <td><?= htmlspecialchars($m['paciente_nome'] ?: '—') ?></td>
                                <?php endif; ?>
                                <td class="text-center"><?= (int)$m['total_itens'] ?></td>
                                <td class="text-center"><?= (int)$m['total_quantidade'] ?></td>
                                <td class="text-end mono">R$ <?= number_format((float)$m['valor_total'], 2, ',', '.') ?></td>
                                <td class="text-nowrap">
                                    <button type="button" class="btn btn-sm btn-outline-primary btn-ver-itens"
                                        data-grupo="<?= htmlspecialchars($m['grupo_chave']) ?>" data-tipo="<?= $tipoMov ?>"
                                        data-data="<?= date('d/m/Y', strtotime($m['created_at'])) ?>" data-hora="<?= date('H:i', strtotime($m['created_at'])) ?>"
                                        data-usuario="<?= htmlspecialchars($m['usuario']) ?>" data-terceiro-label="<?= $ehEntrada ? 'Fornecedor' : 'Paciente' ?>"
                                        data-terceiro="<?= htmlspecialchars($ehEntrada ? (nomeFornecedorExibicao($m['fornecedor_razao'], $m['fornecedor_fantasia']) ?: '—') : ($m['paciente_nome'] ?: '—')) ?>">
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

    <div class="modal fade" id="movItensModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="movItensModalTitle">Itens da Confirmação</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body p-4" id="movItensModalBody">
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
                var meta = {
                    data: btn.getAttribute('data-data') || '',
                    hora: btn.getAttribute('data-hora') || '',
                    usuario: btn.getAttribute('data-usuario') || '',
                    terceiroLabel: btn.getAttribute('data-terceiro-label') || '',
                    terceiro: btn.getAttribute('data-terceiro') || '',
                };
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
                        // Entrada mostra o valor de compra (custo); Saída mostra o valor de venda —
                        // mesmo critério do resumo financeiro na tela Movimentação.
                        var colunaValor = tipo === 'saida' ? 'Valor Venda' : 'Valor Compra';
                        var linhas = data.itens.map(function (i) {
                            var valor = tipo === 'saida' ? i.valor_venda : i.valor_unitario;
                            return '<tr>' +
                                '<td>' + esc(i.produto) + (i.apresentacao ? '<div class="entity-sub">' + esc(i.apresentacao) + '</div>' : '') + '</td>' +
                                '<td>' + esc(i.laboratorio || '—') + '</td>' +
                                '<td class="mono">' + esc(i.lote || '—') + '</td>' +
                                '<td class="mono">' + esc(i.validade_br || '—') + '</td>' +
                                '<td class="text-center">' + esc(i.quantidade) + '</td>' +
                                '<td class="text-end mono">' + fmtMoeda(valor) + '</td>' +
                                '<td class="text-end mono fw-bold">' + fmtMoeda(i.subtotal) + '</td>' +
                                '</tr>';
                        }).join('');

                        // Faixa de metadados (data/usuário/fornecedor-ou-paciente) reaproveitando o
                        // mesmo par label+valor dos cartões-resumo das abas Entradas/Saídas, pra dar
                        // contexto à lista de itens sem repetir a linha inteira da tabela de fora.
                        var faixaMeta = '<div class="d-flex flex-wrap gap-4 px-3 py-3 mb-3 bg-light rounded-3">' +
                                '<div><div class="stat-label">Data/Hora</div><div class="fw-bold">' + esc(meta.data) + (meta.hora ? ' às ' + esc(meta.hora) : '') + '</div></div>' +
                                '<div><div class="stat-label">Usuário</div><div class="fw-bold">' + esc(meta.usuario) + '</div></div>' +
                                (meta.terceiroLabel ? '<div><div class="stat-label">' + esc(meta.terceiroLabel) + '</div><div class="fw-bold">' + esc(meta.terceiro) + '</div></div>' : '') +
                            '</div>';

                        modalBody.innerHTML = faixaMeta +
                            '<div class="table-responsive">' +
                                '<table class="table table-striped table-hover align-middle mb-0">' +
                                    '<thead class="table-dark"><tr><th>Medicamento</th><th>Laboratório</th><th>Lote</th><th>Validade</th><th class="text-center">Qtd.</th><th class="text-end">' + colunaValor + '</th><th class="text-end">Subtotal</th></tr></thead>' +
                                    '<tbody>' + linhas + '</tbody>' +
                                '</table>' +
                            '</div>' +
                            '<div class="d-flex justify-content-between align-items-center border-top mt-3 pt-3">' +
                                '<span class="fw-bold">Resumo financeiro da operação</span>' +
                                '<span class="fw-bold fs-4">' + fmtMoeda(data.valor_total) + '</span>' +
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

    <div class="stat-strip">
        <div class="stat-tile">
            <div>
                <div class="stat-label">Movimentações</div>
                <div class="stat-value"><?= count($linhas) ?></div>
                <div class="stat-note">no período selecionado</div>
            </div>
            <div class="stat-icon blue"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M4 7l4-4 4 4M8 3v12M20 17l-4 4-4-4M16 21V9"/></svg></div>
        </div>
        <div class="stat-tile">
            <div>
                <div class="stat-label">Entradas</div>
                <div class="stat-value"><?= $totalMovEntradas ?></div>
                <div class="stat-note">confirmações no período</div>
            </div>
            <div class="stat-icon green"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M12 19V5M6 11l6-6 6 6"/></svg></div>
        </div>
        <div class="stat-tile">
            <div>
                <div class="stat-label">Saídas</div>
                <div class="stat-value"><?= $totalMovSaidas ?></div>
                <div class="stat-note">confirmações no período</div>
            </div>
            <div class="stat-icon red"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M12 5v14M6 13l6 6 6-6"/></svg></div>
        </div>
        <div class="stat-tile">
            <div>
                <div class="stat-label">Valor Movimentado</div>
                <div class="stat-value">R$ <?= number_format($valorMovimentado, 2, ',', '.') ?></div>
                <div class="stat-note">compra + venda no período</div>
            </div>
            <div class="stat-icon orange"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex align-items-center">
            <span><i class="bi bi-arrow-left-right"></i> Movimentações do período</span>
        </div>
        <div class="table-responsive">
            <table class="table table-striped table-hover bg-white align-middle mb-0">
                <thead class="table-dark">
                    <tr><th>Data/Hora</th><th>Tipo</th><th>Medicamento</th><th class="text-end text-nowrap">Valor (Compra/Venda)</th><th class="text-end">Subtotal</th><th>Usuário</th><th>Observação</th><th></th></tr>
                </thead>
                <tbody>
                    <?php if (empty($linhas)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-5">Nenhuma movimentação neste período.</td></tr>
                    <?php else: ?>
                        <?php foreach ($linhas as $m): $valorBase = $m['tipo'] === 'saida' ? (float)$m['valor_venda'] : (float)$m['valor_unitario']; $subtotal = $valorBase * (int)$m['quantidade']; ?>
                            <tr>
                                <td class="mono text-nowrap"><?= date('d/m/Y H:i', strtotime($m['created_at'])) ?></td>
                                <td><span class="badge <?= $m['tipo'] === 'entrada' ? 'bg-success' : 'bg-danger' ?>"><?= $m['tipo'] === 'entrada' ? 'Entrada' : 'Saída' ?></span></td>
                                <td><?= htmlspecialchars($m['medicamento_nome']) ?></td>
                                <td class="text-end mono text-nowrap"><?= $m['tipo'] === 'entrada' ? 'Compra' : 'Venda' ?>: R$ <?= number_format($valorBase, 2, ',', '.') ?></td>
                                <td class="text-end mono text-nowrap fw-bold">R$ <?= number_format($subtotal, 2, ',', '.') ?></td>
                                <td><?= htmlspecialchars($m['usuario']) ?></td>
                                <td><?= htmlspecialchars($m['observacao']) ?></td>
                                <td class="text-nowrap">
                                    <button type="button" class="btn btn-sm btn-outline-primary btn-mov-detalhe"
                                        data-tipo="<?= $m['tipo'] === 'entrada' ? 'Entrada' : 'Saída' ?>" data-data="<?= date('d/m/Y', strtotime($m['created_at'])) ?>" data-hora="<?= date('H:i', strtotime($m['created_at'])) ?>"
                                        data-medicamento="<?= htmlspecialchars($m['medicamento_nome']) ?>" data-laboratorio="<?= htmlspecialchars($m['laboratorio_nome'] ?: '—') ?>"
                                        data-lote="<?= htmlspecialchars($m['lote'] ?: '—') ?>" data-validade="<?= $m['validade'] ? formatarValidade($m['validade']) : '—' ?>"
                                        data-quantidade="<?= (int)$m['quantidade'] ?>" data-valor-label="<?= $m['tipo'] === 'entrada' ? 'Valor Compra' : 'Valor Venda' ?>"
                                        data-valor="R$ <?= number_format($valorBase, 2, ',', '.') ?>" data-subtotal="R$ <?= number_format($subtotal, 2, ',', '.') ?>"
                                        data-usuario="<?= htmlspecialchars($m['usuario']) ?>" data-observacao="<?= htmlspecialchars($m['observacao'] ?: '—') ?>">
                                        <i class="bi bi-info-circle"></i> Detalhes
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="modal fade" id="movDetalheModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="movDetalheModalTitle">Detalhes da Movimentação</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body p-4" id="movDetalheModalBody"></div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var modalEl = document.getElementById('movDetalheModal');
        if (!modalEl) return;
        var modalBody = document.getElementById('movDetalheModalBody');
        var modalTitle = document.getElementById('movDetalheModalTitle');
        var modal = new bootstrap.Modal(modalEl);

        function esc(s) {
            var d = document.createElement('div');
            d.textContent = (s === null || s === undefined) ? '' : String(s);
            return d.innerHTML;
        }
        function campo(rotulo, valor, mono) {
            return '<div><div class="stat-label">' + esc(rotulo) + '</div><div class="fw-bold' + (mono ? ' mono' : '') + '">' + esc(valor) + '</div></div>';
        }

        document.querySelectorAll('.btn-mov-detalhe').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var d = btn.dataset;
                modalTitle.textContent = 'Detalhes da ' + d.tipo;
                modalBody.innerHTML =
                    '<div class="d-flex flex-wrap gap-3 mb-3">' + campo('Medicamento', d.medicamento) + campo('Laboratório', d.laboratorio) + '</div>' +
                    '<div class="d-flex flex-wrap gap-4 px-3 py-3 mb-3 bg-light rounded-3">' +
                        campo('Data/Hora', d.data + ' às ' + d.hora) +
                        campo('Lote', d.lote, true) +
                        campo('Validade', d.validade, true) +
                        campo('Quantidade', d.quantidade) +
                    '</div>' +
                    '<div class="d-flex flex-wrap gap-4 mb-3">' +
                        campo(d.valorLabel, d.valor) +
                        campo('Subtotal', d.subtotal) +
                        campo('Usuário', d.usuario) +
                    '</div>' +
                    '<div class="border-top pt-3">' + campo('Observação', d.observacao) + '</div>';
                modal.show();
            });
        });
    });
    </script>
<?php endif; ?>
