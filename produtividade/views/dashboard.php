<?php
$db = getDB();

// ---- Filtros de período (compartilhado pelas 3 visões) ----
$periodo = $_GET['periodo'] ?? '7d';
if (!in_array($periodo, ['hoje', '7d', '30d', 'custom'], true)) $periodo = '7d';

$tz = new DateTimeZone('America/Sao_Paulo');
$agoraLocal = new DateTime('now', $tz);

if ($periodo === 'custom' && !empty($_GET['data_ini']) && !empty($_GET['data_fim'])) {
    $inicioLocal = DateTime::createFromFormat('Y-m-d H:i:s', $_GET['data_ini'] . ' 00:00:00', $tz);
    $fimLocal = DateTime::createFromFormat('Y-m-d H:i:s', $_GET['data_fim'] . ' 23:59:59', $tz);
} elseif ($periodo === 'hoje') {
    $inicioLocal = (clone $agoraLocal)->setTime(0, 0, 0);
    $fimLocal = (clone $agoraLocal)->setTime(23, 59, 59);
} elseif ($periodo === '30d') {
    $inicioLocal = (clone $agoraLocal)->modify('-29 days')->setTime(0, 0, 0);
    $fimLocal = (clone $agoraLocal)->setTime(23, 59, 59);
} else {
    $inicioLocal = (clone $agoraLocal)->modify('-6 days')->setTime(0, 0, 0);
    $fimLocal = (clone $agoraLocal)->setTime(23, 59, 59);
}
// eventos.ts é gravado em UTC (vem direto do timestamp ISO8601 do ActivityWatch); as datas do
// filtro são pensadas em horário local, então convertemos as bordas para UTC na hora de consultar.
$inicioUtc = (clone $inicioLocal)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$fimUtc = (clone $fimLocal)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

$visao = $_GET['visao'] ?? 'geral';
if (!in_array($visao, ['geral', 'setor', 'maquinas_setor', 'maquina'], true)) $visao = 'geral';

// setor_efetivo: nome ATUAL da OU vinculada (Integração > Active Directory), se houver, senão o
// texto livre gravado na máquina — nunca usar m.setor puro, ele só existe como fallback congelado.
// COALESCE final com '' (em vez de deixar NULL) mantém a mesma convenção de antes ("" = sem setor)
// usada nas comparações abaixo e no restante do arquivo.
$maquinas = $db->query("SELECT m.id, m.nome, m.ativo, COALESCE(ou.nome, NULLIF(m.setor, ''), '') AS setor
                         FROM maquinas m LEFT JOIN ad_ous ou ON ou.id = m.ou_id
                         ORDER BY m.nome ASC")->fetchAll();
$setoresExistentes = array_values(array_unique(array_filter(array_column($maquinas, 'setor'), fn($s) => $s !== '')));
sort($setoresExistentes);
$temMaquinasSemSetor = (bool)array_filter($maquinas, fn($m) => $m['setor'] === '');

function qs($extra) {
    // $_GET aqui já inclui page=dashboard (é assim que a requisição chegou nesta view), então
    // prefixar com "?page=dashboard&" de novo duplicava o parâmetro na URL gerada (inofensivo -
    // PHP fica com o último valor - mas feio e digno de corrigir já que este código foi mexido).
    return htmlspecialchars('index.php?' . http_build_query(array_merge($_GET, ['page' => 'dashboard'], $extra)));
}

// Recorte pedido explicitamente: as estatísticas do dashboard devem refletir só uso real dentro
// do expediente (06:00–22:30, horário de Brasília — offset fixo '-03:00', já usado no resto deste
// arquivo, dispensa carregar as tabelas de fuso horário do MySQL) e ignorar o tempo em que a
// máquina fica ligada mas TRANCADA. "Trancada" aqui é literal: o AW registra a tela de bloqueio do
// Windows como uma janela em foco normal (app LockApp.exe), então sem esse filtro ela conta como
// tempo "monitorado" de verdade — chegou a somar dezenas de horas por máquina.
function filtroHorarioComercialSql($aliasEventos = 'e') {
    return "AND TIME(CONVERT_TZ({$aliasEventos}.ts, '+00:00', '-03:00')) BETWEEN '06:00:00' AND '22:30:00'";
}
function filtroNaoBloqueadoSql($aliasEventos = 'e') {
    return "AND COALESCE({$aliasEventos}.app, '') <> 'LockApp.exe'";
}

// Todo o bloco de KPIs + gráficos (+ opcionalmente a tabela por máquina) — reaproveitado pela
// Visão Geral (todas as máquinas, ou uma só se selecionada) e pela Visão de uma Máquina (uma única,
// vinda do clique no nome em qualquer tabela). $maquinaIds vazio = sem restrição (todas as
// máquinas do sistema). $mostrarTabela = false quando o card de identificação da máquina, logo
// acima, já cobre a mesma informação (visão de uma única máquina) — evitaria uma tabela de 1 linha
// só repetindo o que já está no cabeçalho.
function renderPainelProdutividade(PDO $db, $inicioUtc, $fimUtc, DateTime $inicioLocal, DateTime $fimLocal, array $maquinaIds, $tituloTabela, $mostrarTabela = true) {
    $filtroMaquinaSql = '';
    $params = [':ini' => $inicioUtc, ':fim' => $fimUtc];
    if (!empty($maquinaIds)) {
        $placeholders = [];
        foreach (array_values($maquinaIds) as $i => $mid) {
            $chave = ":mid{$i}";
            $placeholders[] = $chave;
            $params[$chave] = $mid;
        }
        $filtroMaquinaSql = ' AND e.maquina_id IN (' . implode(',', $placeholders) . ')';
    }
    $filtroHorario = filtroHorarioComercialSql();
    $filtroNaoBloqueado = filtroNaoBloqueadoSql();

    // ---- KPIs: distribuição por categoria (janelas + navegador) ----
    $stmt = $db->prepare("SELECT
            c.id AS categoria_id,
            COALESCE(c.pontuacao, 99) AS pontuacao,
            c.nome AS categoria_nome,
            c.cor AS categoria_cor,
            SUM(e.duracao) AS total
        FROM eventos e
        LEFT JOIN categorias c ON c.id = e.categoria_id
        WHERE e.tipo IN ('window','web') AND e.ts BETWEEN :ini AND :fim {$filtroMaquinaSql} {$filtroHorario} {$filtroNaoBloqueado}
        GROUP BY c.id, COALESCE(c.pontuacao, 99), c.nome, c.cor
        ORDER BY total DESC");
    $stmt->execute($params);
    $porCategoria = $stmt->fetchAll();

    $totalMonitorado = 0; $produtivo = 0; $neutro = 0; $improdutivo = 0; $semCategoria = 0;
    foreach ($porCategoria as $row) {
        $totalMonitorado += $row['total'];
        if ($row['pontuacao'] == 1) $produtivo += $row['total'];
        elseif ($row['pontuacao'] == 0) $neutro += $row['total'];
        elseif ($row['pontuacao'] == -1) $improdutivo += $row['total'];
        else $semCategoria += $row['total'];
    }
    $pctProdutivo = $totalMonitorado > 0 ? round($produtivo / $totalMonitorado * 100) : 0;

    // ---- Detalhe por categoria: quais apps/sites compõem cada fatia do gráfico de pizza acima ----
    // Mesma chave de agrupamento (COALESCE ... 'Sem categoria') usada no label do gráfico, para casar
    // os dois lados na hora de montar o tooltip no JS.
    $detalheCategoria = [];
    $stmt = $db->prepare("SELECT COALESCE(c.nome, 'Sem categoria') AS categoria_key, e.app AS item, SUM(e.duracao) AS total
        FROM eventos e LEFT JOIN categorias c ON c.id = e.categoria_id
        WHERE e.tipo = 'window' AND e.app IS NOT NULL AND e.app <> '' AND e.ts BETWEEN :ini AND :fim {$filtroMaquinaSql} {$filtroHorario} {$filtroNaoBloqueado}
        GROUP BY categoria_key, item");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $detalheCategoria[$row['categoria_key']][$row['item']] = ($detalheCategoria[$row['categoria_key']][$row['item']] ?? 0) + (float)$row['total'];
    }
    $stmt = $db->prepare("SELECT COALESCE(c.nome, 'Sem categoria') AS categoria_key, e.url AS url, SUM(e.duracao) AS total
        FROM eventos e LEFT JOIN categorias c ON c.id = e.categoria_id
        WHERE e.tipo = 'web' AND e.url IS NOT NULL AND e.url <> '' AND e.ts BETWEEN :ini AND :fim {$filtroMaquinaSql} {$filtroHorario}
        GROUP BY categoria_key, url");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $host = parse_url($row['url'], PHP_URL_HOST) ?: $row['url'];
        $host = preg_replace('/^www\./', '', $host);
        $detalheCategoria[$row['categoria_key']][$host] = ($detalheCategoria[$row['categoria_key']][$host] ?? 0) + (float)$row['total'];
    }
    // Top 5 itens por categoria, já formatados como "Nome: 1h30" para exibir direto no tooltip.
    $detalheCategoriaTop = [];
    foreach ($detalheCategoria as $catKey => $itens) {
        arsort($itens);
        $top = array_slice($itens, 0, 5, true);
        $linhas = [];
        foreach ($top as $nome => $total) $linhas[] = $nome . ': ' . formatarDuracao($total);
        $detalheCategoriaTop[$catKey] = $linhas;
    }

    // ---- Detalhe por categoria, agora por título de janela/aba — usado no "explodir" (popup) do
    // gráfico de pizza ao clicar numa fatia. COALESCE encadeado: título > app > domínio da URL >
    // "(sem título)", pra sempre ter algo pra mostrar mesmo num evento sem e.titulo preenchido.
    // Também guarda o app de cada título (mostrado numa coluna própria no popup) — é o que revela,
    // por exemplo, que "Senior | Gestão de Pessoas..." roda dentro do processo genérico "WA.exe",
    // informação que o usuário precisa pra saber se a regra de categorização deve casar por
    // "Aplicativo" (WA.exe) ou por "Título da janela" (Senior).
    $detalheCategoriaTitulos = [];
    $appPorTitulo = [];
    $stmt = $db->prepare("SELECT COALESCE(c.nome, 'Sem categoria') AS categoria_key,
            COALESCE(NULLIF(e.titulo, ''), NULLIF(e.app, ''), NULLIF(e.url, ''), '(sem título)') AS item,
            e.app AS app,
            SUM(e.duracao) AS total
        FROM eventos e LEFT JOIN categorias c ON c.id = e.categoria_id
        WHERE e.tipo IN ('window', 'web') AND e.ts BETWEEN :ini AND :fim {$filtroMaquinaSql} {$filtroHorario} {$filtroNaoBloqueado}
        GROUP BY categoria_key, item, app");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $chave = $row['categoria_key'] . '|' . $row['item'];
        $detalheCategoriaTitulos[$row['categoria_key']][$row['item']] = ($detalheCategoriaTitulos[$row['categoria_key']][$row['item']] ?? 0) + (float)$row['total'];
        // Um título pode, raramente, vir de mais de um app (título genérico) — fica o app com mais
        // tempo acumulado pra esse título.
        if (!isset($appPorTitulo[$chave]) || (float)$row['total'] > $appPorTitulo[$chave]['total']) {
            $appPorTitulo[$chave] = ['app' => $row['app'] ?: '', 'total' => (float)$row['total']];
        }
    }
    foreach ($detalheCategoriaTitulos as $catKey => &$itens) {
        arsort($itens);
        $itens = array_slice($itens, 0, 20, true);
    }
    unset($itens);
    // Achata pra [categoria][titulo] => nomeDoApp, no mesmo formato simples de $detalheCategoriaTitulos,
    // só com os títulos que sobreviveram ao corte de top 20 acima.
    $detalheCategoriaApps = [];
    foreach ($detalheCategoriaTitulos as $catKey => $itens) {
        foreach ($itens as $item => $total) {
            $detalheCategoriaApps[$catKey][$item] = $appPorTitulo[$catKey . '|' . $item]['app'] ?? '';
        }
    }

    // ---- KPI: tempo ativo x ausente (bucket afk) ----
    $stmt = $db->prepare("SELECT status, SUM(duracao) AS total FROM eventos e
        WHERE tipo = 'afk' AND ts BETWEEN :ini AND :fim {$filtroMaquinaSql} {$filtroHorario}
        GROUP BY status");
    $stmt->execute($params);
    $tempoAtivo = 0; $tempoAusente = 0;
    foreach ($stmt->fetchAll() as $row) {
        if ($row['status'] === 'not-afk') $tempoAtivo = (float)$row['total'];
        elseif ($row['status'] === 'afk') $tempoAusente = (float)$row['total'];
    }

    // ---- Top 10 aplicativos ----
    $stmt = $db->prepare("SELECT app, SUM(duracao) AS total FROM eventos e
        WHERE tipo = 'window' AND app IS NOT NULL AND app <> '' AND ts BETWEEN :ini AND :fim {$filtroMaquinaSql} {$filtroHorario} {$filtroNaoBloqueado}
        GROUP BY app ORDER BY total DESC LIMIT 10");
    $stmt->execute($params);
    $topApps = $stmt->fetchAll();

    // ---- Top 10 sites (agrega por domínio; parte de um top-300 por URL para não estourar memória) ----
    $stmt = $db->prepare("SELECT url, SUM(duracao) AS total FROM eventos e
        WHERE tipo = 'web' AND url IS NOT NULL AND url <> '' AND ts BETWEEN :ini AND :fim {$filtroMaquinaSql} {$filtroHorario}
        GROUP BY url ORDER BY total DESC LIMIT 300");
    $stmt->execute($params);
    $porDominio = [];
    foreach ($stmt->fetchAll() as $row) {
        $host = parse_url($row['url'], PHP_URL_HOST) ?: $row['url'];
        $host = preg_replace('/^www\./', '', $host);
        $porDominio[$host] = ($porDominio[$host] ?? 0) + (float)$row['total'];
    }
    arsort($porDominio);
    $topSites = array_slice($porDominio, 0, 10, true);

    // ---- Tendência diária (produtivo/neutro/improdutivo) — data local via offset fixo de Brasília ----
    $stmt = $db->prepare("SELECT
            DATE(CONVERT_TZ(e.ts, '+00:00', '-03:00')) AS dia,
            COALESCE(c.pontuacao, 99) AS pontuacao,
            SUM(e.duracao) AS total
        FROM eventos e
        LEFT JOIN categorias c ON c.id = e.categoria_id
        WHERE e.tipo IN ('window','web') AND e.ts BETWEEN :ini AND :fim {$filtroMaquinaSql} {$filtroHorario} {$filtroNaoBloqueado}
        GROUP BY dia, pontuacao ORDER BY dia ASC");
    $stmt->execute($params);
    $tendenciaRaw = $stmt->fetchAll();
    $dias = [];
    $periodoDias = new DatePeriod(clone $inicioLocal, new DateInterval('P1D'), (clone $fimLocal)->modify('+1 day'));
    foreach ($periodoDias as $d) { $dias[$d->format('Y-m-d')] = ['produtivo' => 0, 'neutro' => 0, 'improdutivo' => 0]; }
    foreach ($tendenciaRaw as $row) {
        if (!isset($dias[$row['dia']])) continue;
        if ($row['pontuacao'] == 1) $dias[$row['dia']]['produtivo'] += (float)$row['total'];
        elseif ($row['pontuacao'] == -1) $dias[$row['dia']]['improdutivo'] += (float)$row['total'];
        else $dias[$row['dia']]['neutro'] += (float)$row['total'];
    }

    // ---- Tabela por máquina (só as do recorte atual — todas, ou as do setor) ----
    $porMaquina = [];
    if ($mostrarTabela) {
        $filtroMaquinasTabela = '';
        $paramsTabela = [':ini' => $inicioUtc, ':fim' => $fimUtc];
        if (!empty($maquinaIds)) {
            $placeholders = [];
            foreach (array_values($maquinaIds) as $i => $mid) {
                $chave = ":tmid{$i}";
                $placeholders[] = $chave;
                $paramsTabela[$chave] = $mid;
            }
            $filtroMaquinasTabela = 'WHERE m.id IN (' . implode(',', $placeholders) . ')';
        }
        $stmt = $db->prepare("SELECT
                m.id, m.nome, m.ativo, m.ultimo_sync_at, m.ultimo_sync_status,
                COALESCE(ou.nome, NULLIF(m.setor, '')) AS setor,
                SUM(e.duracao) AS total,
                SUM(CASE WHEN c.pontuacao = 1 THEN e.duracao ELSE 0 END) AS produtivo
            FROM maquinas m
            LEFT JOIN ad_ous ou ON ou.id = m.ou_id
            LEFT JOIN eventos e ON e.maquina_id = m.id AND e.tipo IN ('window','web') AND e.ts BETWEEN :ini AND :fim {$filtroHorario} {$filtroNaoBloqueado}
            LEFT JOIN categorias c ON c.id = e.categoria_id
            {$filtroMaquinasTabela}
            GROUP BY m.id, m.nome, m.ativo, m.ultimo_sync_at, m.ultimo_sync_status, ou.nome, m.setor
            ORDER BY total DESC");
        $stmt->execute($paramsTabela);
        $porMaquina = $stmt->fetchAll();
    }

    $idSufixo = 'p' . substr(md5(implode(',', $maquinaIds)), 0, 6);

    // Base do link "Ver eventos desta categoria" no popup — mesmo recorte de máquina(s) e período
    // desta visão; a categoria é adicionada no JS, na hora de abrir o popup (abrirModalCategoria).
    // eventos.php só filtra por UMA máquina, mas essa função nunca recebe mais de uma (visão geral
    // não filtra máquina nenhuma, visão de uma máquina passa exatamente uma).
    $eventosBaseParams = [
        'page' => 'eventos',
        'data_ini' => $inicioLocal->format('Y-m-d'),
        'data_fim' => $fimLocal->format('Y-m-d'),
    ];
    if (count($maquinaIds) === 1) $eventosBaseParams['maquina_id'] = reset($maquinaIds);
    ?>
    <div class="stat-strip">
        <div class="stat-tile">
            <div><div class="stat-label">Tempo Monitorado</div><div class="stat-value"><?= formatarDuracao($totalMonitorado) ?></div><div class="stat-note">janela + navegador</div></div>
            <div class="stat-icon blue"><i class="bi bi-clock-history"></i></div>
        </div>
        <div class="stat-tile">
            <div><div class="stat-label">Produtivo</div><div class="stat-value online-c"><?= formatarDuracao($produtivo) ?></div><div class="stat-note"><?= $pctProdutivo ?>% do tempo monitorado</div></div>
            <div class="stat-icon green"><i class="bi bi-graph-up-arrow"></i></div>
        </div>
        <div class="stat-tile">
            <div><div class="stat-label">Improdutivo</div><div class="stat-value critical-c"><?= formatarDuracao($improdutivo) ?></div><div class="stat-note">entretenimento, redes sociais...</div></div>
            <div class="stat-icon red"><i class="bi bi-graph-down-arrow"></i></div>
        </div>
        <div class="stat-tile">
            <div><div class="stat-label">Ativo x Ausente</div><div class="stat-value"><?= formatarDuracao($tempoAtivo) ?></div><div class="stat-note"><?= formatarDuracao($tempoAusente) ?> ausente (AFK)</div></div>
            <div class="stat-icon orange"><i class="bi bi-person-check"></i></div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header">Distribuição por Categoria</div>
                <div class="card-body chart-card-body">
                    <div class="chart-wrap"><canvas id="chartCategoria<?= $idSufixo ?>"></canvas></div>
                    <div class="legend-list mt-3">
                        <?php foreach ($porCategoria as $row): $pct = $totalMonitorado > 0 ? round($row['total'] / $totalMonitorado * 100, 1) : 0; $catLabel = $row['categoria_nome'] ?? 'Sem categoria'; ?>
                        <div class="legend-row">
                            <span class="cat-dot" style="background: <?= htmlspecialchars($row['categoria_cor'] ?? '#c7c5da') ?>"></span>
                            <span class="legend-name"><?= htmlspecialchars($catLabel) ?></span>
                            <span class="legend-value"><?= formatarDuracao($row['total']) ?> (<?= $pct ?>%)</span>
                        </div>
                        <?php if (!empty($detalheCategoriaTop[$catLabel])): ?>
                        <div class="legend-detail text-muted small"><?= htmlspecialchars(implode(' · ', $detalheCategoriaTop[$catLabel])) ?></div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if (empty($porCategoria)): ?><div class="text-muted small">Sem dados no período.</div><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header">Tendência Diária</div>
                <div class="card-body chart-card-body">
                    <div class="chart-wrap"><canvas id="chartTendencia<?= $idSufixo ?>"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">Top 10 Aplicativos</div>
                <div class="card-body chart-card-body">
                    <div class="chart-wrap"><canvas id="chartApps<?= $idSufixo ?>"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">Top 10 Sites</div>
                <div class="card-body chart-card-body">
                    <?php if (empty($topSites)): ?>
                        <div class="text-muted small py-4 text-center">Nenhum dado de navegador (aw-watcher-web) no período.</div>
                    <?php else: ?>
                        <div class="chart-wrap"><canvas id="chartSites<?= $idSufixo ?>"></canvas></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($mostrarTabela): ?>
    <div class="card mt-3">
        <div class="card-header"><?= htmlspecialchars($tituloTabela) ?></div>
        <div class="table-responsive">
            <table class="table table-bordered bg-white align-middle mb-0">
                <thead class="table-dark"><tr><th>Máquina</th><th>Setor</th><th>Tempo Total</th><th>% Produtivo</th><th>Última Sincronização</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($porMaquina as $m): $pct = $m['total'] > 0 ? round($m['produtivo'] / $m['total'] * 100) : 0; ?>
                    <tr>
                        <td><a href="<?= qs(['visao' => 'maquina', 'maquina_id' => $m['id']]) ?>"><?= htmlspecialchars($m['nome']) ?></a> <?php if (!$m['ativo']): ?><span class="badge bg-secondary">Inativa</span><?php endif; ?></td>
                        <td><?= $m['setor'] ? htmlspecialchars($m['setor']) : '<span class="text-muted">—</span>' ?></td>
                        <td><?= formatarDuracao($m['total'] ?: 0) ?></td>
                        <td><?= $m['total'] > 0 ? $pct . '%' : '—' ?></td>
                        <td><small class="mono text-muted"><?= $m['ultimo_sync_at'] ? htmlspecialchars($m['ultimo_sync_at']) : 'nunca' ?></small></td>
                        <td>
                            <?php if ($m['ultimo_sync_status'] === 'ok'): ?><span class="badge bg-success">OK</span>
                            <?php elseif ($m['ultimo_sync_status'] === 'parcial'): ?><span class="badge bg-warning">Parcial</span>
                            <?php elseif ($m['ultimo_sync_status'] === 'erro'): ?><span class="badge bg-danger">Erro</span>
                            <?php else: ?><span class="badge bg-secondary">—</span><?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($porMaquina)): ?><tr><td colspan="6" class="text-center text-muted py-3">Nenhuma máquina neste recorte.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="modal fade" id="modalCategoria<?= $idSufixo ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalCategoriaTitulo<?= $idSufixo ?>">Categoria</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div class="text-muted small mb-2">Use o Aplicativo ou o Título da Janela abaixo pra saber qual Campo escolher ao criar uma regra em Categorias.</div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr><th>Aplicativo</th><th>Título da Janela</th><th class="text-end">Tempo</th></tr>
                            </thead>
                            <tbody id="modalCategoriaLista<?= $idSufixo ?>"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="#" id="modalCategoriaVerEventos<?= $idSufixo ?>" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-list-ul"></i> Ver eventos desta categoria
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var corTexto = getComputedStyle(document.documentElement).getPropertyValue('--text-600').trim();
        Chart.defaults.color = corTexto || '#5f5d78';
        Chart.defaults.font.family = "'Segoe UI', sans-serif";

        var detalheCategoria<?= $idSufixo ?> = <?= json_encode($detalheCategoriaTop, JSON_UNESCAPED_UNICODE) ?>;
        var detalheTitulos<?= $idSufixo ?> = <?= json_encode($detalheCategoriaTitulos, JSON_UNESCAPED_UNICODE) ?>;
        var detalheApps<?= $idSufixo ?> = <?= json_encode($detalheCategoriaApps, JSON_UNESCAPED_UNICODE) ?>;
        var categoriasBase<?= $idSufixo ?> = {
            labels: <?= json_encode(array_map(fn($r) => $r['categoria_nome'] ?? 'Sem categoria', $porCategoria)) ?>,
            data: <?= json_encode(array_map(fn($r) => round($r['total']), $porCategoria)) ?>,
            cores: <?= json_encode(array_map(fn($r) => $r['categoria_cor'] ?? '#c7c5da', $porCategoria)) ?>,
            // -1 = "Sem categoria" (c.id vem NULL do LEFT JOIN) — eventos.php trata esse valor
            // como "categoria_id IS NULL" especialmente pra esse botão funcionar também aqui.
            ids: <?= json_encode(array_map(fn($r) => $r['categoria_id'] !== null ? (int)$r['categoria_id'] : -1, $porCategoria)) ?>
        };
        var eventosBaseParams<?= $idSufixo ?> = <?= json_encode($eventosBaseParams, JSON_UNESCAPED_UNICODE) ?>;

        function formatarDuracaoJs<?= $idSufixo ?>(segundos) {
            if (segundos < 60) return Math.round(segundos) + 's';
            var totalMin = Math.round(segundos / 60);
            var h = Math.floor(totalMin / 60), m = totalMin % 60;
            if (h > 0) return m > 0 ? (h + 'h ' + m + 'm') : (h + 'h');
            return m + 'm';
        }

        new Chart(document.getElementById('chartCategoria<?= $idSufixo ?>'), {
            type: 'doughnut',
            data: {
                labels: categoriasBase<?= $idSufixo ?>.labels.slice(),
                datasets: [{
                    data: categoriasBase<?= $idSufixo ?>.data.slice(),
                    backgroundColor: categoriasBase<?= $idSufixo ?>.cores.slice(),
                    borderWidth: 0,
                    spacing: 3,
                    borderRadius: 6,
                    hoverOffset: 6,
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                onHover: (evt, elements) => { evt.native.target.style.cursor = elements.length ? 'pointer' : 'default'; },
                onClick: (evt, elements) => {
                    if (!elements.length) return;
                    abrirModalCategoria<?= $idSufixo ?>(elements[0].index);
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => ctx.label + ': ' + Math.round(ctx.raw/60) + ' min',
                            afterLabel: (ctx) => {
                                var apps = detalheCategoria<?= $idSufixo ?>[ctx.label] || [];
                                return apps.length ? ['Principais apps/sites:', '(clique para ver a lista completa)'].concat(apps) : ['(clique para ver a lista completa)'];
                            }
                        }
                    }
                }
            }
        });

        // Abre um popup (modal Bootstrap) com a lista de títulos de janela/aba que compõem a
        // categoria clicada, em vez de mexer no gráfico - pedido explícito do usuário no lugar da
        // primeira versão, que "explodia" o próprio doughnut trocando as fatias por títulos.
        function abrirModalCategoria<?= $idSufixo ?>(idx) {
            var label = categoriasBase<?= $idSufixo ?>.labels[idx];
            var titulos = detalheTitulos<?= $idSufixo ?>[label] || {};
            var apps = detalheApps<?= $idSufixo ?>[label] || {};
            var nomes = Object.keys(titulos);

            document.getElementById('modalCategoriaTitulo<?= $idSufixo ?>').textContent = label;
            var lista = document.getElementById('modalCategoriaLista<?= $idSufixo ?>');
            lista.innerHTML = '';
            if (!nomes.length) {
                lista.innerHTML = '<tr><td colspan="3" class="text-muted text-center">Sem detalhamento disponível.</td></tr>';
            } else {
                nomes.forEach(function (nome) {
                    var tr = document.createElement('tr');

                    var tdApp = document.createElement('td');
                    tdApp.className = 'text-truncate mono small';
                    tdApp.style.maxWidth = '160px';
                    tdApp.textContent = apps[nome] || '—';
                    tdApp.title = apps[nome] || '';

                    var tdTitulo = document.createElement('td');
                    tdTitulo.className = 'text-truncate';
                    tdTitulo.style.maxWidth = '320px';
                    tdTitulo.textContent = nome;
                    tdTitulo.title = nome;

                    var tdTempo = document.createElement('td');
                    tdTempo.className = 'text-end text-nowrap';
                    tdTempo.textContent = formatarDuracaoJs<?= $idSufixo ?>(titulos[nome]);

                    tr.appendChild(tdApp);
                    tr.appendChild(tdTitulo);
                    tr.appendChild(tdTempo);
                    lista.appendChild(tr);
                });
            }

            var params = new URLSearchParams(eventosBaseParams<?= $idSufixo ?>);
            params.set('categoria_id', categoriasBase<?= $idSufixo ?>.ids[idx]);
            document.getElementById('modalCategoriaVerEventos<?= $idSufixo ?>').href = 'index.php?' + params.toString();

            // Instanciado só no clique (não no carregamento do script) pelo mesmo motivo do modal
            // de log em Máquinas: este bloco roda antes da tag <script> do bootstrap.bundle.min.js
            // no fim do layout.php, então "new bootstrap.Modal(...)" no topo do arquivo falharia.
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalCategoria<?= $idSufixo ?>')).show();
        }

        new Chart(document.getElementById('chartTendencia<?= $idSufixo ?>'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_map(fn($k) => (new DateTime($k))->format('d/m'), array_keys($dias))) ?>,
                datasets: [
                    { label: 'Produtivo', data: <?= json_encode(array_map(fn($d) => round($d['produtivo']/60), $dias)) ?>, backgroundColor: '#16a34a', stack: 's' },
                    { label: 'Neutro', data: <?= json_encode(array_map(fn($d) => round($d['neutro']/60), $dias)) ?>, backgroundColor: '#94a2b8', stack: 's' },
                    { label: 'Improdutivo', data: <?= json_encode(array_map(fn($d) => round($d['improdutivo']/60), $dias)) ?>, backgroundColor: '#dc2626', stack: 's' },
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                scales: { x: { stacked: true, grid: { display: false } }, y: { stacked: true, title: { display: true, text: 'minutos' } } },
                plugins: { legend: { position: 'bottom' } }
            }
        });

        new Chart(document.getElementById('chartApps<?= $idSufixo ?>'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_map(fn($r) => $r['app'], $topApps)) ?>,
                datasets: [{ data: <?= json_encode(array_map(fn($r) => round($r['total']/60), $topApps)) ?>, backgroundColor: '#6d4de6' }]
            },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => ctx.raw + ' min' } } },
                scales: { x: { title: { display: true, text: 'minutos' } } }
            }
        });

        <?php if (!empty($topSites)): ?>
        new Chart(document.getElementById('chartSites<?= $idSufixo ?>'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_keys($topSites)) ?>,
                datasets: [{ data: <?= json_encode(array_map(fn($v) => round($v/60), $topSites)) ?>, backgroundColor: '#12b7a8' }]
            },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => ctx.raw + ' min' } } },
                scales: { x: { title: { display: true, text: 'minutos' } } }
            }
        });
        <?php endif; ?>
    })();
    </script>
    <?php
}
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Dashboard de Produtividade</h1>
        <div class="page-sub">Dados coletados via ActivityWatch nas máquinas da rede</div>
    </div>
</div>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $visao === 'geral' ? 'active' : '' ?>" href="<?= qs(['visao' => 'geral', 'maquina_id' => null, 'setor' => null]) ?>">Visão Geral</a></li>
    <li class="nav-item"><a class="nav-link <?= $visao === 'setor' ? 'active' : '' ?>" href="<?= qs(['visao' => 'setor', 'maquina_id' => null]) ?>">Setor</a></li>
    <li class="nav-item"><a class="nav-link <?= in_array($visao, ['maquinas_setor', 'maquina'], true) ? 'active' : '' ?>" href="<?= qs(['visao' => 'maquinas_setor', 'maquina_id' => null]) ?>">Máquinas por Setor</a></li>
</ul>

<div class="entity-list-toolbar">
    <span class="elt-label">Período</span>
    <div class="btn-group btn-group-sm" role="group">
        <a href="<?= qs(['periodo' => 'hoje']) ?>" class="btn btn-outline-primary <?= $periodo === 'hoje' ? 'active' : '' ?>">Hoje</a>
        <a href="<?= qs(['periodo' => '7d']) ?>" class="btn btn-outline-primary <?= $periodo === '7d' ? 'active' : '' ?>">7 dias</a>
        <a href="<?= qs(['periodo' => '30d']) ?>" class="btn btn-outline-primary <?= $periodo === '30d' ? 'active' : '' ?>">30 dias</a>
    </div>
    <form method="GET" class="d-flex align-items-center gap-2">
        <input type="hidden" name="page" value="dashboard">
        <input type="hidden" name="visao" value="<?= htmlspecialchars($visao) ?>">
        <input type="hidden" name="periodo" value="custom">
        <input type="date" name="data_ini" class="form-control form-control-sm" value="<?= htmlspecialchars($_GET['data_ini'] ?? $inicioLocal->format('Y-m-d')) ?>">
        <span class="text-muted small">até</span>
        <input type="date" name="data_fim" class="form-control form-control-sm" value="<?= htmlspecialchars($_GET['data_fim'] ?? $fimLocal->format('Y-m-d')) ?>">
        <button class="btn btn-sm btn-outline-secondary">Aplicar</button>
    </form>
    <div class="topbar-spacer"></div>

    <?php if ($visao === 'geral'): $maquinaId = (int)($_GET['maquina_id'] ?? 0); ?>
        <form method="GET" class="d-flex align-items-center gap-2">
            <input type="hidden" name="page" value="dashboard">
            <input type="hidden" name="visao" value="geral">
            <input type="hidden" name="periodo" value="<?= htmlspecialchars($periodo) ?>">
            <?php if ($periodo === 'custom'): ?>
                <input type="hidden" name="data_ini" value="<?= htmlspecialchars($inicioLocal->format('Y-m-d')) ?>">
                <input type="hidden" name="data_fim" value="<?= htmlspecialchars($fimLocal->format('Y-m-d')) ?>">
            <?php endif; ?>
            <select name="maquina_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="0">Todas as máquinas</option>
                <?php foreach ($maquinas as $m): ?>
                    <option value="<?= (int)$m['id'] ?>" <?= $maquinaId === (int)$m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php elseif ($visao === 'maquinas_setor'):
        $setorSelecionado = $_GET['setor'] ?? ($setoresExistentes[0] ?? ($temMaquinasSemSetor ? '' : null)); ?>
        <form method="GET" class="d-flex align-items-center gap-2">
            <input type="hidden" name="page" value="dashboard">
            <input type="hidden" name="visao" value="maquinas_setor">
            <input type="hidden" name="periodo" value="<?= htmlspecialchars($periodo) ?>">
            <?php if ($periodo === 'custom'): ?>
                <input type="hidden" name="data_ini" value="<?= htmlspecialchars($inicioLocal->format('Y-m-d')) ?>">
                <input type="hidden" name="data_fim" value="<?= htmlspecialchars($fimLocal->format('Y-m-d')) ?>">
            <?php endif; ?>
            <select name="setor" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ($setoresExistentes as $s): ?>
                    <option value="<?= htmlspecialchars($s) ?>" <?= $setorSelecionado === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                <?php endforeach; ?>
                <?php if ($temMaquinasSemSetor): ?>
                    <option value="" <?= $setorSelecionado === '' ? 'selected' : '' ?>>Sem setor</option>
                <?php endif; ?>
            </select>
        </form>
    <?php endif; ?>
</div>

<?php if (empty($maquinas)): ?>
    <div class="alert alert-info">Nenhuma máquina cadastrada ainda. <?php if (isAdmin()): ?><a href="index.php?page=maquinas">Cadastre a primeira máquina</a> para começar a coletar dados do ActivityWatch.<?php endif; ?></div>
<?php endif; ?>

<?php if ($visao === 'geral'): ?>

    <?php renderPainelProdutividade($db, $inicioUtc, $fimUtc, $inicioLocal, $fimLocal, $maquinaId > 0 ? [$maquinaId] : [], 'Por Máquina'); ?>

<?php elseif ($visao === 'setor'): ?>

    <?php
    // ---- Comparativo entre setores: uma linha por setor, agregando todas as suas máquinas ----
    $stmt = $db->prepare("SELECT
            COALESCE(ou.nome, NULLIF(m.setor, ''), 'Sem setor') AS setor,
            COUNT(DISTINCT m.id) AS num_maquinas,
            SUM(e.duracao) AS total,
            SUM(CASE WHEN c.pontuacao = 1 THEN e.duracao ELSE 0 END) AS produtivo,
            SUM(CASE WHEN c.pontuacao = -1 THEN e.duracao ELSE 0 END) AS improdutivo
        FROM maquinas m
        LEFT JOIN ad_ous ou ON ou.id = m.ou_id
        LEFT JOIN eventos e ON e.maquina_id = m.id AND e.tipo IN ('window','web') AND e.ts BETWEEN :ini AND :fim " . filtroHorarioComercialSql() . " " . filtroNaoBloqueadoSql() . "
        LEFT JOIN categorias c ON c.id = e.categoria_id
        GROUP BY COALESCE(ou.nome, NULLIF(m.setor, ''), 'Sem setor')
        ORDER BY total DESC");
    $stmt->execute([':ini' => $inicioUtc, ':fim' => $fimUtc]);
    $porSetor = $stmt->fetchAll();
    ?>

    <div class="card">
        <div class="card-header">Comparativo entre Setores</div>
        <div class="row g-0">
            <div class="col-lg-7">
                <div class="table-responsive">
                    <table class="table table-bordered bg-white align-middle mb-0">
                        <thead class="table-dark"><tr><th>Setor</th><th>Máquinas</th><th>Tempo Total</th><th>Produtivo</th><th>Improdutivo</th><th>% Produtivo</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($porSetor as $s): $pct = $s['total'] > 0 ? round($s['produtivo'] / $s['total'] * 100) : 0; ?>
                            <tr>
                                <td><?= htmlspecialchars($s['setor']) ?></td>
                                <td><?= (int)$s['num_maquinas'] ?></td>
                                <td><?= formatarDuracao($s['total'] ?: 0) ?></td>
                                <td class="online-c"><?= formatarDuracao($s['produtivo'] ?: 0) ?></td>
                                <td class="critical-c"><?= formatarDuracao($s['improdutivo'] ?: 0) ?></td>
                                <td><?= $s['total'] > 0 ? $pct . '%' : '—' ?></td>
                                <td><a class="btn btn-sm btn-outline-primary" href="<?= qs(['visao' => 'maquinas_setor', 'setor' => $s['setor'] === 'Sem setor' ? '' : $s['setor']]) ?>">Ver máquinas</a></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($porSetor)): ?><tr><td colspan="7" class="text-center text-muted py-3">Nenhuma máquina cadastrada.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card-body chart-card-body">
                    <div class="chart-wrap"><canvas id="chartPorSetor"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var corTexto = getComputedStyle(document.documentElement).getPropertyValue('--text-600').trim();
        Chart.defaults.color = corTexto || '#5f5d78';
        Chart.defaults.font.family = "'Segoe UI', sans-serif";

        new Chart(document.getElementById('chartPorSetor'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_map(fn($s) => $s['setor'], $porSetor)) ?>,
                datasets: [{
                    label: '% Produtivo',
                    data: <?= json_encode(array_map(fn($s) => $s['total'] > 0 ? round($s['produtivo'] / $s['total'] * 100) : 0, $porSetor)) ?>,
                    backgroundColor: '#16a34a',
                }]
            },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => ctx.raw + '% produtivo' } } },
                scales: { x: { min: 0, max: 100, title: { display: true, text: '% produtivo' } } }
            }
        });
    })();
    </script>

<?php elseif ($visao === 'maquinas_setor'):
    if ($setorSelecionado === null) {
        echo '<div class="alert alert-info">Nenhum setor cadastrado ainda. Defina o setor de uma máquina em <a href="index.php?page=maquinas">Máquinas</a>, ou importe máquinas por OU em <a href="index.php?page=ad">Active Directory</a>.</div>';
    } else {
        $idsDoSetor = array_column(array_filter($maquinas, fn($m) => $m['setor'] === $setorSelecionado), 'id');
        $tituloSetor = $setorSelecionado === '' ? 'Sem setor' : $setorSelecionado;

        // Aqui é só um diretório — nome clicável leva pro dashboard completo de UMA máquina
        // (visão "maquina"). As estatísticas em si não aparecem aqui de propósito: antes esta
        // mesma visão já mostrava o painel inteiro agregando todas as máquinas do setor de uma vez
        // (isso virou a visão "Setor", com o comparativo entre setores); esta ficou só para navegar
        // até uma máquina específica.
        $placeholders = [];
        $paramsLista = [];
        foreach (array_values($idsDoSetor) as $i => $mid) { $chave = ":id{$i}"; $placeholders[] = $chave; $paramsLista[$chave] = $mid; }
        $listaMaquinas = [];
        if (!empty($placeholders)) {
            $stmt = $db->prepare("SELECT id, nome, host, porta, ativo, usuario_responsavel, ultimo_sync_at, ultimo_sync_status, ultimo_erro
                FROM maquinas WHERE id IN (" . implode(',', $placeholders) . ") ORDER BY nome ASC");
            $stmt->execute($paramsLista);
            $listaMaquinas = $stmt->fetchAll();
        }
        ?>
        <div class="card">
            <div class="card-header">Máquinas do setor "<?= htmlspecialchars($tituloSetor) ?>"</div>
            <div class="table-responsive">
                <table class="table table-bordered bg-white align-middle mb-0">
                    <thead class="table-dark"><tr><th>Máquina</th><th>Usuário Responsável</th><th>Host</th><th>Última Sincronização</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($listaMaquinas as $m): ?>
                        <tr>
                            <td>
                                <a href="<?= qs(['visao' => 'maquina', 'maquina_id' => $m['id']]) ?>"><?= htmlspecialchars($m['nome']) ?></a>
                                <?php if (!$m['ativo']): ?><span class="badge bg-secondary">Inativa</span><?php endif; ?>
                            </td>
                            <td><?= $m['usuario_responsavel'] ? htmlspecialchars($m['usuario_responsavel']) : '<span class="text-muted">—</span>' ?></td>
                            <td><code><?= htmlspecialchars($m['host']) ?>:<?= (int)$m['porta'] ?></code></td>
                            <td><small class="mono text-muted"><?= $m['ultimo_sync_at'] ? htmlspecialchars($m['ultimo_sync_at']) : 'nunca' ?></small></td>
                            <td>
                                <?php if ($m['ultimo_sync_status'] === 'ok'): ?><span class="badge bg-success">OK</span>
                                <?php elseif ($m['ultimo_sync_status'] === 'parcial'): ?><span class="badge bg-warning" title="<?= htmlspecialchars($m['ultimo_erro'] ?? '') ?>">Parcial</span>
                                <?php elseif ($m['ultimo_sync_status'] === 'erro'): ?><span class="badge bg-danger" title="<?= htmlspecialchars($m['ultimo_erro'] ?? '') ?>">Erro</span>
                                <?php else: ?><span class="badge bg-secondary">—</span><?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($listaMaquinas)): ?><tr><td colspan="5" class="text-center text-muted py-3">Nenhuma máquina neste setor.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

elseif ($visao === 'maquina'):
    $maquinaId = (int)($_GET['maquina_id'] ?? 0);
    $stmt = $db->prepare("SELECT m.*, COALESCE(ou.nome, NULLIF(m.setor, '')) AS setor_efetivo
                           FROM maquinas m LEFT JOIN ad_ous ou ON ou.id = m.ou_id
                           WHERE m.id = :id");
    $stmt->execute([':id' => $maquinaId]);
    $maquinaAtual = $stmt->fetch();

    if (!$maquinaAtual) {
        echo '<div class="alert alert-danger">Máquina não encontrada.</div>';
    } else {
        ?>
        <div class="card mb-3">
            <div class="card-body d-flex flex-wrap align-items-center gap-3">
                <div class="flex-grow-1">
                    <div class="d-flex align-items-center gap-2">
                        <h2 class="h5 mb-0"><?= htmlspecialchars($maquinaAtual['nome']) ?></h2>
                        <?php if (!$maquinaAtual['ativo']): ?><span class="badge bg-secondary">Inativa</span><?php endif; ?>
                        <?php if ($maquinaAtual['ultimo_sync_status'] === 'ok'): ?><span class="badge bg-success">Sincronização OK</span>
                        <?php elseif ($maquinaAtual['ultimo_sync_status'] === 'parcial'): ?><span class="badge bg-warning">Parcial</span>
                        <?php elseif ($maquinaAtual['ultimo_sync_status'] === 'erro'): ?><span class="badge bg-danger">Erro de sincronização</span>
                        <?php endif; ?>
                    </div>
                    <div class="entity-grid mt-2" style="grid-template-columns: repeat(3, minmax(0,1fr));">
                        <div><div class="entity-field-label">Host</div><div class="entity-field-value"><code><?= htmlspecialchars($maquinaAtual['host']) ?>:<?= (int)$maquinaAtual['porta'] ?></code></div></div>
                        <div><div class="entity-field-label">Setor</div><div class="entity-field-value"><?= $maquinaAtual['setor_efetivo'] ? htmlspecialchars($maquinaAtual['setor_efetivo']) : '—' ?></div></div>
                        <div><div class="entity-field-label">Usuário Responsável</div><div class="entity-field-value"><?= $maquinaAtual['usuario_responsavel'] ? htmlspecialchars($maquinaAtual['usuario_responsavel']) : '—' ?></div></div>
                        <div><div class="entity-field-label">aw-server</div><div class="entity-field-value"><?= $maquinaAtual['aw_hostname'] ? htmlspecialchars($maquinaAtual['aw_hostname']) . ' (' . htmlspecialchars($maquinaAtual['aw_versao']) . ')' : '—' ?></div></div>
                        <div><div class="entity-field-label">Última Sincronização</div><div class="entity-field-value mono"><?= $maquinaAtual['ultimo_sync_at'] ? htmlspecialchars($maquinaAtual['ultimo_sync_at']) : 'nunca' ?></div></div>
                    </div>
                </div>
                <?php if (isAdmin()): ?><a href="index.php?page=maquinas&edit=<?= (int)$maquinaAtual['id'] ?>" class="btn btn-outline-primary btn-sm">Editar Máquina</a><?php endif; ?>
                <a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm">← Voltar</a>
            </div>
        </div>
        <?php
        renderPainelProdutividade($db, $inicioUtc, $fimUtc, $inicioLocal, $fimLocal, [$maquinaAtual['id']], '', false);
    }
endif; ?>
