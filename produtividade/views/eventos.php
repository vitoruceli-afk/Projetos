<?php
$db = getDB();

$maquinaId = (int)($_GET['maquina_id'] ?? 0);
$tipo = in_array($_GET['tipo'] ?? '', ['window', 'afk', 'web'], true) ? $_GET['tipo'] : '';
// -1 é um valor especial vindo do botão "Ver eventos desta categoria" do Dashboard, quando a
// categoria clicada é "Sem categoria" (c.id vem NULL do LEFT JOIN de lá, sem id de verdade pra
// filtrar) — não dá pra distinguir "sem filtro" de "sem categoria" só com 0/vazio.
$categoriaId = (int)($_GET['categoria_id'] ?? 0);
$semCategoria = $categoriaId === -1;
$busca = trim($_GET['busca'] ?? '');
$dataIni = $_GET['data_ini'] ?? date('Y-m-d', strtotime('-1 day'));
$dataFim = $_GET['data_fim'] ?? date('Y-m-d');

$tz = new DateTimeZone('America/Sao_Paulo');
$inicioUtc = DateTime::createFromFormat('Y-m-d H:i:s', $dataIni . ' 00:00:00', $tz)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$fimUtc = DateTime::createFromFormat('Y-m-d H:i:s', $dataFim . ' 23:59:59', $tz)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

$where = ['e.ts BETWEEN :ini AND :fim'];
$params = [':ini' => $inicioUtc, ':fim' => $fimUtc];
if ($maquinaId > 0) { $where[] = 'e.maquina_id = :mid'; $params[':mid'] = $maquinaId; }
if ($tipo !== '') { $where[] = 'e.tipo = :tipo'; $params[':tipo'] = $tipo; }
if ($semCategoria) { $where[] = 'e.categoria_id IS NULL'; }
elseif ($categoriaId > 0) { $where[] = 'e.categoria_id = :cid'; $params[':cid'] = $categoriaId; }
if ($busca !== '') {
    $where[] = '(e.app LIKE :busca OR e.titulo LIKE :busca OR e.url LIKE :busca)';
    $params[':busca'] = '%' . $busca . '%';
}

// Filtros vindos dos cards do Dashboard (mesmos critérios dos KPIs de lá): "grupo" reproduz o
// recorte de cada card, e usuario/maquina_ids reproduzem o escopo da visão (usuário do Windows
// escolhido na aba Usuários; várias máquinas nas visões por setor).
$grupos = [
    'monitorado'  => 'Tempo Monitorado',
    'produtivo'   => 'Produtivo',
    'neutro'      => 'Neutro (inclui sem categoria)',
    'improdutivo' => 'Improdutivo',
    'ativo'       => 'Ativo (teclado/mouse)',
    'ausente'     => 'Ausente (AFK)',
    'bloqueada'   => 'Tela Bloqueada',
];
$grupo = isset($grupos[$_GET['grupo'] ?? '']) ? $_GET['grupo'] : '';
$usuarioFiltro = trim($_GET['usuario'] ?? '');
$maquinaIdsFiltro = array_values(array_filter(array_map('intval', explode(',', $_GET['maquina_ids'] ?? '')), fn($v) => $v > 0));
if ($maquinaIdsFiltro && $maquinaId <= 0) {
    $where[] = 'e.maquina_id IN (' . implode(',', $maquinaIdsFiltro) . ')';
}
if ($usuarioFiltro !== '') { $where[] = 'e.usuario_detectado = :usuario'; $params[':usuario'] = $usuarioFiltro; }
if ($grupo !== '') {
    if ($grupo === 'ativo' || $grupo === 'ausente') {
        $where[] = "e.tipo = 'afk'";
        $where[] = $grupo === 'ativo' ? "e.status = 'not-afk'" : "e.status = 'afk'";
    } elseif ($grupo === 'bloqueada') {
        $where[] = "e.tipo = 'window'";
        $where[] = "e.app = 'LockApp.exe'";
    } else {
        $where[] = "e.tipo = 'window'";
        $where[] = "COALESCE(e.app, '') <> 'LockApp.exe'";
    }
    if ($grupo === 'produtivo') $where[] = 'e.categoria_id IN (SELECT id FROM categorias WHERE pontuacao = 1)';
    elseif ($grupo === 'improdutivo') $where[] = 'e.categoria_id IN (SELECT id FROM categorias WHERE pontuacao = -1)';
    elseif ($grupo === 'neutro') $where[] = '(e.categoria_id IS NULL OR e.categoria_id IN (SELECT id FROM categorias WHERE pontuacao = 0))';
    // Mesmo horário comercial do Dashboard (06:00-22:30, horário de Brasília).
    $where[] = "TIME(CONVERT_TZ(e.ts, '+00:00', '-03:00')) BETWEEN '06:00:00' AND '22:30:00'";
}
$whereSql = implode(' AND ', $where);

if (($_GET['format'] ?? '') === 'csv') {
    requireAdmin();
    $stmt = $db->prepare("SELECT e.ts, m.nome AS maquina, e.usuario_detectado AS usuario, e.tipo, e.app, e.titulo, e.url, e.status, e.duracao, c.nome AS categoria
        FROM eventos e JOIN maquinas m ON m.id = e.maquina_id LEFT JOIN categorias c ON c.id = e.categoria_id
        WHERE {$whereSql} ORDER BY e.ts DESC LIMIT 50000");
    $stmt->execute($params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="eventos_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // BOM para o Excel abrir acentuação corretamente
    fputcsv($out, ['Data/Hora (local)', 'Máquina', 'Usuário', 'Tipo', 'Aplicativo', 'Título', 'URL', 'Status AFK', 'Duração (s)', 'Categoria'], ';');
    while ($row = $stmt->fetch()) {
        $tsLocal = (new DateTime($row['ts'], new DateTimeZone('UTC')))->setTimezone($tz)->format('d/m/Y H:i:s');
        fputcsv($out, [$tsLocal, $row['maquina'], $row['usuario'], $row['tipo'], $row['app'], $row['titulo'], $row['url'], $row['status'], $row['duracao'], $row['categoria']], ';');
    }
    fclose($out);
    return;
}

$porPagina = 50;
$pagina = max(1, (int)($_GET['pg'] ?? 1));
$offset = ($pagina - 1) * $porPagina;

$totalStmt = $db->prepare("SELECT COUNT(*) FROM eventos e WHERE {$whereSql}");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();

$stmt = $db->prepare("SELECT e.ts, m.nome AS maquina, e.usuario_detectado AS usuario, e.tipo, e.app, e.titulo, e.url, e.status, e.duracao, c.nome AS categoria, c.cor AS categoria_cor
    FROM eventos e JOIN maquinas m ON m.id = e.maquina_id LEFT JOIN categorias c ON c.id = e.categoria_id
    WHERE {$whereSql} ORDER BY e.ts DESC LIMIT {$porPagina} OFFSET {$offset}");
$stmt->execute($params);
$eventos = $stmt->fetchAll();

$maquinas = $db->query("SELECT id, nome FROM maquinas ORDER BY nome ASC")->fetchAll();
$categorias = $db->query("SELECT id, nome FROM categorias ORDER BY ordem ASC, nome ASC")->fetchAll();
$totalPaginas = max(1, (int)ceil($total / $porPagina));

function qsEventos($extra) {
    $params = array_merge($_GET, $extra);
    unset($params['format']);
    return htmlspecialchars('index.php?' . http_build_query($params));
}
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Explorador de Eventos</h1>
        <div class="page-sub"><?= number_format($total, 0, ',', '.') ?> evento(s) encontrado(s)<?php if ($grupo !== ''): ?> · card: <strong><?= htmlspecialchars($grupos[$grupo]) ?></strong>
            <?php if ($usuarioFiltro !== ''): ?> · usuário: <?= htmlspecialchars($usuarioFiltro) ?><?php endif; ?>
            · <a href="<?= qsEventos(['grupo' => null, 'usuario' => null, 'maquina_ids' => null, 'pg' => null]) ?>">limpar filtro do card</a><?php endif; ?></div>
    </div>
    <?php if (isAdmin()): ?>
        <a href="<?= qsEventos(['format' => 'csv', 'page' => 'eventos']) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download"></i> Exportar CSV (até 50.000 linhas)</a>
    <?php endif; ?>
</div>

<form method="GET" class="entity-list-toolbar">
    <input type="hidden" name="page" value="eventos">
    <?php if ($usuarioFiltro !== ''): ?><input type="hidden" name="usuario" value="<?= htmlspecialchars($usuarioFiltro) ?>"><?php endif; ?>
    <?php if ($maquinaIdsFiltro): ?><input type="hidden" name="maquina_ids" value="<?= htmlspecialchars(implode(',', $maquinaIdsFiltro)) ?>"><?php endif; ?>
    <?php if ($grupo !== ''): ?><input type="hidden" name="grupo" value="<?= htmlspecialchars($grupo) ?>"><?php endif; ?>
    <select name="maquina_id" class="form-select form-select-sm" style="max-width: 200px">
        <option value="0">Todas as máquinas</option>
        <?php foreach ($maquinas as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $maquinaId === (int)$m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['nome']) ?></option><?php endforeach; ?>
    </select>
    <select name="tipo" class="form-select form-select-sm" style="max-width: 160px">
        <option value="">Todos os tipos</option>
        <option value="window" <?= $tipo === 'window' ? 'selected' : '' ?>>Janela ativa</option>
        <option value="web" <?= $tipo === 'web' ? 'selected' : '' ?>>Navegador</option>
        <option value="afk" <?= $tipo === 'afk' ? 'selected' : '' ?>>AFK</option>
    </select>
    <select name="categoria_id" class="form-select form-select-sm" style="max-width: 200px">
        <option value="0">Todas as categorias</option>
        <option value="-1" <?= $semCategoria ? 'selected' : '' ?>>Sem categoria</option>
        <?php foreach ($categorias as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (!$semCategoria && $categoriaId === (int)$c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['nome']) ?></option><?php endforeach; ?>
    </select>
    <input type="text" name="busca" class="form-control form-control-sm" placeholder="Buscar app/título/URL..." value="<?= htmlspecialchars($busca) ?>" style="max-width: 220px">
    <input type="date" name="data_ini" class="form-control form-control-sm" value="<?= htmlspecialchars($dataIni) ?>">
    <span class="text-muted small">até</span>
    <input type="date" name="data_fim" class="form-control form-control-sm" value="<?= htmlspecialchars($dataFim) ?>">
    <button class="btn btn-sm btn-outline-primary">Filtrar</button>
</form>

<div class="table-responsive">
    <table class="table table-bordered bg-white align-middle table-sm">
        <thead class="table-dark"><tr><th>Data/Hora</th><th>Máquina</th><th>Usuário</th><th>Tipo</th><th>Aplicativo / URL</th><th>Título</th><th>Duração</th><th>Categoria</th></tr></thead>
        <tbody>
            <?php foreach ($eventos as $e): $tsLocal = (new DateTime($e['ts'], new DateTimeZone('UTC')))->setTimezone($tz); ?>
            <tr>
                <td class="text-nowrap"><small class="mono"><?= $tsLocal->format('d/m/Y H:i:s') ?></small></td>
                <td><?= htmlspecialchars($e['maquina']) ?></td>
                <td class="text-nowrap"><?= $e['usuario'] ? htmlspecialchars($e['usuario']) : '<span class="text-muted">—</span>' ?></td>
                <td>
                    <?php if ($e['tipo'] === 'window'): ?><span class="badge bg-info">Janela</span>
                    <?php elseif ($e['tipo'] === 'web'): ?><span class="badge bg-dark">Navegador</span>
                    <?php else: ?><span class="badge bg-secondary"><?= $e['status'] === 'afk' ? 'AFK' : 'Ativo' ?></span><?php endif; ?>
                </td>
                <?php $origem = $e['tipo'] === 'web' ? ($e['url'] ?: '—') : ($e['app'] ?: '—'); ?>
                <td class="text-truncate" style="max-width: 260px" title="<?= htmlspecialchars($origem) ?>"><?= htmlspecialchars($origem) ?></td>
                <td class="text-truncate" style="max-width: 260px" title="<?= htmlspecialchars($e['titulo'] ?? '') ?>"><?= htmlspecialchars($e['titulo'] ?? '—') ?></td>
                <td class="text-nowrap"><?= formatarDuracao($e['duracao']) ?></td>
                <td>
                    <?php if ($e['categoria']): ?>
                        <span class="cat-dot" style="background: <?= htmlspecialchars($e['categoria_cor']) ?>"></span> <?= htmlspecialchars($e['categoria']) ?>
                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($eventos)): ?><tr><td colspan="8" class="text-center text-muted py-3">Nenhum evento encontrado com esses filtros.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPaginas > 1): ?>
<nav>
    <ul class="pagination pagination-sm">
        <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= qsEventos(['pg' => max(1, $pagina - 1)]) ?>">Anterior</a></li>
        <li class="page-item disabled"><span class="page-link">Página <?= $pagina ?> de <?= $totalPaginas ?></span></li>
        <li class="page-item <?= $pagina >= $totalPaginas ? 'disabled' : '' ?>"><a class="page-link" href="<?= qsEventos(['pg' => min($totalPaginas, $pagina + 1)]) ?>">Próxima</a></li>
    </ul>
</nav>
<?php endif; ?>
