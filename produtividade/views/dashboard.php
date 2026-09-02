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

// A Tendência Diária tem sua própria janela de dias, independente do recorte "hoje"/período
// escolhido pros KPIs: pedido explícito pra sempre mostrar 7 dias (mesmo com "Hoje" selecionado,
// onde o recorte principal é só o dia atual) e 30 dias no período de 30 dias. "custom" mantém o
// intervalo escolhido pelo usuário, sem forçar nada.
$tendenciaDiasForcado = null;
if ($periodo === 'hoje' || $periodo === '7d') $tendenciaDiasForcado = 7;
elseif ($periodo === '30d') $tendenciaDiasForcado = 30;

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
// Faixas pedidas pro mosaico de hexágonos (Visão Setor): verde 100-67%, amarelo 66-34%, vermelho
// 33-0%. Máquina sem nenhum evento no recorte (total=0) não é "0% produtivo" de verdade — é falta
// de dado — então fica cinza, pra não parecer que a máquina esteve ligada e improdutiva o período
// inteiro.
function corHexProdutividade($temDado, $pct) {
    if (!$temDado) return '#94a2b8';
    if ($pct >= 67) return '#16a34a';
    if ($pct >= 34) return '#eab308';
    return '#dc2626';
}

function filtroNaoBloqueadoSql($aliasEventos = 'e') {
    return "AND COALESCE({$aliasEventos}.app, '') <> 'LockApp.exe'";
}

// Tempo total/produtivo por máquina (ativas), no mesmo recorte de horário/bloqueio do resto do
// dashboard — usado pelo mosaico de hexágonos, tanto o da Visão Setor (todas as máquinas ativas)
// quanto o da Visão Máquinas por Setor ($maquinaIdsFiltro = só as do setor escolhido).
function calcularHexProdutividade(PDO $db, $inicioUtc, $fimUtc, ?array $maquinaIdsFiltro = null) {
    $params = [':ini' => $inicioUtc, ':fim' => $fimUtc];
    $filtroIds = '';
    if ($maquinaIdsFiltro !== null) {
        if (empty($maquinaIdsFiltro)) return [];
        $placeholders = [];
        foreach (array_values($maquinaIdsFiltro) as $i => $mid) {
            $chave = ":hid{$i}";
            $placeholders[] = $chave;
            $params[$chave] = $mid;
        }
        $filtroIds = 'AND m.id IN (' . implode(',', $placeholders) . ')';
    }
    $stmt = $db->prepare("SELECT m.id, m.nome,
            SUM(e.duracao) AS total,
            SUM(CASE WHEN c.pontuacao = 1 THEN e.duracao ELSE 0 END) AS produtivo
        FROM maquinas m
        LEFT JOIN eventos e ON e.maquina_id = m.id AND e.tipo IN ('window','web') AND e.ts BETWEEN :ini AND :fim " . filtroHorarioComercialSql() . " " . filtroNaoBloqueadoSql() . "
        LEFT JOIN categorias c ON c.id = e.categoria_id
        WHERE m.ativo = 1 {$filtroIds}
        GROUP BY m.id, m.nome
        ORDER BY m.nome ASC");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Desenha o mosaico de hexágonos a partir do resultado de calcularHexProdutividade(). $linkFn
// recebe o id da máquina e devolve a URL do hexágono; $maquinaSelecionadaId (opcional) marca um
// hexágono como "ativo" — usado na Visão Máquinas por Setor pra indicar qual máquina está sendo
// exibida embaixo no momento.
function renderHexGrid(array $maquinasComTempo, callable $linkFn, $maquinaSelecionadaId = null) {
    $porLinha = max(1, (int)ceil(count($maquinasComTempo) / 2));
    $linhas = array_chunk($maquinasComTempo, $porLinha);
    ?>
    <div class="hex-grid">
        <?php foreach ($linhas as $li => $linha): ?>
        <div class="hex-row <?= $li % 2 === 1 ? 'hex-row-offset' : '' ?>">
            <?php foreach ($linha as $m):
                $temDado = $m['total'] > 0;
                $pct = $temDado ? round($m['produtivo'] / $m['total'] * 100) : 0;
                $cor = corHexProdutividade($temDado, $pct);
                $ativa = $maquinaSelecionadaId !== null && (int)$m['id'] === (int)$maquinaSelecionadaId;
            ?>
            <a href="<?= $linkFn($m['id']) ?>"
               class="hex-tile<?= $ativa ? ' hex-tile-ativa' : '' ?>"
               style="background: <?= $cor ?>;"
               title="<?= htmlspecialchars($m['nome']) ?> — <?= $temDado ? $pct . '% produtivo' : 'sem dados no período' ?>">
                <span class="hex-nome"><?= htmlspecialchars($m['nome']) ?></span>
                <span class="hex-pct"><?= $temDado ? $pct . '%' : '—' ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <?php if (empty($maquinasComTempo)): ?><div class="text-muted text-center py-3">Nenhum dado para exibir.</div><?php endif; ?>
    </div>
    <?php
}

// Card de identificação/resumo de uma máquina — reaproveitado pela Visão Máquina (standalone) e
// pela Visão Máquinas por Setor (embutido acima do mosaico + painel). $mostrarVoltar desliga o
// botão "Voltar" nesse segundo caso: lá a navegação principal é clicar noutro hexágono, não voltar.
function renderCardInfoMaquina($maquinaAtual, $mostrarVoltar = true) {
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
            <?php if ($mostrarVoltar): ?><a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm">← Voltar</a><?php endif; ?>
        </div>
    </div>
    <?php
}

// Todo o bloco de KPIs + gráficos — reaproveitado pela Visão Geral (todas as máquinas, ou uma só
// se selecionada), pela Visão de uma Máquina e pela Visão Máquinas por Setor (ambas uma única
// máquina, vinda do clique num hexágono ou numa tabela). $maquinaIds vazio = sem restrição (todas
// as máquinas do sistema).
function renderPainelProdutividade(PDO $db, $inicioUtc, $fimUtc, DateTime $inicioLocal, DateTime $fimLocal, array $maquinaIds, $exibirTendenciaPontos = false, $tendenciaDiasForcado = null) {
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

    // Cor de cada barra = cor da categoria que mais contribuiu pro tempo daquele app (um app pode,
    // raramente, ter migrado de categoria ao longo do tempo se as regras mudaram — fica a que tem
    // mais tempo acumulado). Sem categoria cai no cinza padrão, igual ao resto do dashboard.
    $corPorApp = [];
    if (!empty($topApps)) {
        $placeholders = [];
        $paramsApps = $params;
        foreach (array_values(array_column($topApps, 'app')) as $i => $appNome) {
            $chave = ":app{$i}";
            $placeholders[] = $chave;
            $paramsApps[$chave] = $appNome;
        }
        $stmt = $db->prepare("SELECT e.app, c.cor AS categoria_cor, SUM(e.duracao) AS total
            FROM eventos e LEFT JOIN categorias c ON c.id = e.categoria_id
            WHERE e.tipo = 'window' AND e.app IN (" . implode(',', $placeholders) . ") AND e.ts BETWEEN :ini AND :fim {$filtroMaquinaSql} {$filtroHorario} {$filtroNaoBloqueado}
            GROUP BY e.app, c.id, c.cor");
        $stmt->execute($paramsApps);
        $melhorPorApp = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!isset($melhorPorApp[$row['app']]) || (float)$row['total'] > $melhorPorApp[$row['app']]['total']) {
                $melhorPorApp[$row['app']] = ['cor' => $row['categoria_cor'] ?? '#c7c5da', 'total' => (float)$row['total']];
            }
        }
        foreach ($topApps as $r) {
            $corPorApp[$r['app']] = $melhorPorApp[$r['app']]['cor'] ?? '#c7c5da';
        }
    }

    // ---- Tendência diária (produtivo/neutro/improdutivo) — data local via offset fixo de Brasília.
    // Janela própria (independente do recorte "hoje"/período dos KPIs acima): sempre 7 ou 30 dias
    // quando $tendenciaDiasForcado vem preenchido (ver comentário na origem, no topo do arquivo) —
    // "Hoje" continua filtrando só o dia atual nos KPIs, mas a tendência mostra os últimos 7 dias.
    $tendenciaFimLocal = clone $fimLocal;
    $tendenciaInicioLocal = $tendenciaDiasForcado !== null
        ? (clone $tendenciaFimLocal)->modify('-' . ($tendenciaDiasForcado - 1) . ' days')->setTime(0, 0, 0)
        : clone $inicioLocal;
    $paramsTendencia = [
        ':ini' => (clone $tendenciaInicioLocal)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ':fim' => (clone $tendenciaFimLocal)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
    ];
    if (!empty($maquinaIds)) {
        foreach ($params as $chave => $valor) {
            if ($chave !== ':ini' && $chave !== ':fim') $paramsTendencia[$chave] = $valor;
        }
    }
    $stmt = $db->prepare("SELECT
            DATE(CONVERT_TZ(e.ts, '+00:00', '-03:00')) AS dia,
            COALESCE(c.pontuacao, 99) AS pontuacao,
            SUM(e.duracao) AS total
        FROM eventos e
        LEFT JOIN categorias c ON c.id = e.categoria_id
        WHERE e.tipo IN ('window','web') AND e.ts BETWEEN :ini AND :fim {$filtroMaquinaSql} {$filtroHorario} {$filtroNaoBloqueado}
        GROUP BY dia, pontuacao ORDER BY dia ASC");
    $stmt->execute($paramsTendencia);
    $tendenciaRaw = $stmt->fetchAll();
    $dias = [];
    // Forma "recorrências" (nº de repetições após o 1º dia) em vez de passar uma data final pro
    // DatePeriod: como $tendenciaFimLocal carrega hora 23:59:59, somar "+1 dia" pra tentar usá-la
    // como limite exclusivo virava meia-noite do dia SEGUINTE ao último — ainda menor que
    // 23:59:59 daquele dia, então o DatePeriod incluía um dia a mais (8 em vez de 7, 31 em vez de
    // 30). Calculando o nº exato de dias primeiro, esse problema de horário não entra em jogo.
    $numDiasTendencia = (int)$tendenciaInicioLocal->diff($tendenciaFimLocal)->days + 1;
    $periodoDias = new DatePeriod(clone $tendenciaInicioLocal, new DateInterval('P1D'), max(0, $numDiasTendencia - 1));
    foreach ($periodoDias as $d) { $dias[$d->format('Y-m-d')] = ['produtivo' => 0, 'neutro' => 0, 'improdutivo' => 0]; }
    foreach ($tendenciaRaw as $row) {
        if (!isset($dias[$row['dia']])) continue;
        if ($row['pontuacao'] == 1) $dias[$row['dia']]['produtivo'] += (float)$row['total'];
        elseif ($row['pontuacao'] == -1) $dias[$row['dia']]['improdutivo'] += (float)$row['total'];
        else $dias[$row['dia']]['neutro'] += (float)$row['total'];
    }

    // ---- Tendência de produtividade em "gráfico de pontos" (só na visão de uma máquina) — se o
    // recorte é um único dia, hora a hora; se abrange vários dias, reaproveita $dias (dia a dia).
    $pontosLabels = [];
    $pontosValores = [];
    $pontosFimDeSemana = [];
    $pontosPorHora = $inicioLocal->format('Y-m-d') === $fimLocal->format('Y-m-d');
    if ($exibirTendenciaPontos) {
        if ($pontosPorHora) {
            $stmt = $db->prepare("SELECT
                    HOUR(CONVERT_TZ(e.ts, '+00:00', '-03:00')) AS hora,
                    SUM(CASE WHEN c.pontuacao = 1 THEN e.duracao ELSE 0 END) AS produtivo
                FROM eventos e
                LEFT JOIN categorias c ON c.id = e.categoria_id
                WHERE e.tipo IN ('window','web') AND e.ts BETWEEN :ini AND :fim {$filtroMaquinaSql} {$filtroHorario} {$filtroNaoBloqueado}
                GROUP BY hora");
            $stmt->execute($params);
            $porHora = array_fill(6, 17, 0.0); // 06h .. 22h (janela comercial)
            foreach ($stmt->fetchAll() as $row) {
                $h = (int)$row['hora'];
                if (isset($porHora[$h])) $porHora[$h] = (float)$row['produtivo'];
            }
            foreach ($porHora as $h => $produtivo) {
                $pontosLabels[] = sprintf('%02dh', $h);
                $pontosValores[] = $produtivo;
            }
        } else {
            foreach ($dias as $dia => $valores) {
                $dataObj = new DateTime($dia);
                $pontosLabels[] = $dataObj->format('d/m');
                $pontosValores[] = $valores['produtivo'];
                $pontosFimDeSemana[] = in_array($dataObj->format('N'), ['6', '7'], true);
            }
        }
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

    <?php if ($exibirTendenciaPontos): ?>
    <div class="row g-3">
        <div class="col-12">
            <div class="card">
                <div class="card-header">Produtividade <?= $pontosPorHora ? 'por Hora' : 'por Dia' ?></div>
                <div class="card-body chart-card-body">
                    <div class="chart-wrap" style="height: 130px;"><canvas id="chartPontos<?= $idSufixo ?>"></canvas></div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-3 mt-1">
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header">Distribuição por Categoria</div>
                <div class="card-body chart-card-body d-flex align-items-center gap-3">
                    <div class="chart-wrap" style="height: 270px; flex: 0 0 45%;"><canvas id="chartCategoria<?= $idSufixo ?>"></canvas></div>
                    <div class="legend-list flex-grow-1" style="min-width: 0;">
                        <?php foreach ($porCategoria as $row): $catLabel = $row['categoria_nome'] ?? 'Sem categoria'; ?>
                        <div class="legend-row">
                            <span class="cat-dot" style="background: <?= htmlspecialchars($row['categoria_cor'] ?? '#c7c5da') ?>"></span>
                            <span class="legend-name"><?= htmlspecialchars($catLabel) ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($porCategoria)): ?><div class="text-muted small">Sem dados no período.</div><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header">Top 10 Aplicativos</div>
                <div class="card-body chart-card-body">
                    <div class="chart-wrap" style="height: 270px;"><canvas id="chartApps<?= $idSufixo ?>"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-12">
            <div class="card">
                <div class="card-header">Tendência Diária</div>
                <div class="card-body chart-card-body">
                    <div class="chart-wrap"><canvas id="chartTendencia<?= $idSufixo ?>"></canvas></div>
                </div>
            </div>
        </div>
    </div>

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
                    { label: 'Produtivo', data: <?= json_encode(array_values(array_map(fn($d) => round($d['produtivo']/60), $dias))) ?>, backgroundColor: '#16a34a', stack: 's' },
                    { label: 'Neutro', data: <?= json_encode(array_values(array_map(fn($d) => round($d['neutro']/60), $dias))) ?>, backgroundColor: '#94a2b8', stack: 's' },
                    { label: 'Improdutivo', data: <?= json_encode(array_values(array_map(fn($d) => round($d['improdutivo']/60), $dias))) ?>, backgroundColor: '#dc2626', stack: 's' },
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                scales: {
                    // autoSkip: false — pedido explícito pra sempre mostrar TODOS os dias no eixo
                    // (o padrão do Chart.js pula rótulos quando não cabem, o que sumia com dias no
                    // recorte de 30); maxRotation permite girar o texto "dd/mm" pra caber sem cortar.
                    x: { stacked: true, grid: { display: false }, ticks: { autoSkip: false, maxRotation: 60, minRotation: 0 } },
                    y: { stacked: true, title: { display: true, text: 'minutos' } }
                },
                plugins: { legend: { position: 'bottom' } }
            }
        });

        <?php if ($exibirTendenciaPontos): ?>
        // Gráfico de linha com marcadores (estilo "Hours tracked" do RescueTime/afins pedido pelo
        // usuário) só na visão de uma máquina: hora a hora se o recorte é um único dia, dia a dia
        // se abrange vários dias (mesmos dados de $dias, já calculados acima pra Tendência Diária).
        new Chart(document.getElementById('chartPontos<?= $idSufixo ?>'), {
            type: 'line',
            data: {
                labels: <?= json_encode($pontosLabels) ?>,
                datasets: [{
                    label: 'Produtivo',
                    data: <?= json_encode(array_map(fn($v) => round($v / 3600, 2), $pontosValores)) ?>,
                    borderColor: '#16a34a',
                    backgroundColor: '#16a34a',
                    pointBackgroundColor: '#16a34a',
                    pointBorderColor: '#fff',
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    borderWidth: 2,
                    tension: 0.3,
                    fill: false,
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (ctx) => 'Produtivo: ' + ctx.raw + 'h' } }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            <?php if (!$pontosPorHora): ?>
                            color: (ctx) => <?= json_encode($pontosFimDeSemana) ?>[ctx.index] ? '#dc2626' : undefined,
                            <?php endif; ?>
                        }
                    },
                    y: { beginAtZero: true, title: { display: true, text: 'horas produtivas' } }
                }
            }
        });
        <?php endif; ?>

        new Chart(document.getElementById('chartApps<?= $idSufixo ?>'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_map(fn($r) => $r['app'], $topApps)) ?>,
                datasets: [{
                    data: <?= json_encode(array_map(fn($r) => round($r['total']/60), $topApps)) ?>,
                    backgroundColor: <?= json_encode(array_map(fn($r) => $corPorApp[$r['app']] ?? '#c7c5da', $topApps)) ?>,
                }]
            },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => ctx.raw + ' min' } } },
                scales: { x: { title: { display: true, text: 'minutos' } } }
            }
        });

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

    <?php if ($visao === 'maquinas_setor'):
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

    <?php renderPainelProdutividade($db, $inicioUtc, $fimUtc, $inicioLocal, $fimLocal, [], false, $tendenciaDiasForcado); ?>

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

    // ---- Produtividade por setor (mosaico de hexágonos) — reaproveita $porSetor (a mesma agregação
    // da tabela acima); renderHexGrid() só espera itens com 'id'/'nome'/'total'/'produtivo', então
    // usamos o próprio nome do setor como "id" (não há um id numérico de setor de verdade — setor
    // aqui é texto livre ou nome de OU, ver comentário no início do arquivo).
    $produtividadePorSetor = array_map(fn($s) => [
        'id' => $s['setor'],
        'nome' => $s['setor'],
        'total' => $s['total'],
        'produtivo' => $s['produtivo'],
    ], $porSetor);
    ?>

    <div class="card">
        <div class="card-header">Comparativo entre Setores</div>
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

    <div class="row">
        <div class="col-lg-6">
            <div class="card mt-3">
                <div class="card-header">Produtividade por Setor</div>
                <div class="card-body">
                    <?php renderHexGrid($produtividadePorSetor, fn($setorNome) => qs(['visao' => 'maquinas_setor', 'setor' => $setorNome === 'Sem setor' ? '' : $setorNome])); ?>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($visao === 'maquinas_setor'):
    if ($setorSelecionado === null) {
        echo '<div class="alert alert-info">Nenhum setor cadastrado ainda. Defina o setor de uma máquina em <a href="index.php?page=maquinas">Máquinas</a>, ou importe máquinas por OU em <a href="index.php?page=ad">Active Directory</a>.</div>';
    } else {
        $idsDoSetor = array_column(array_filter($maquinas, fn($m) => $m['setor'] === $setorSelecionado), 'id');
        $tituloSetor = $setorSelecionado === '' ? 'Sem setor' : $setorSelecionado;

        // Mudança de comportamento pedida: em vez de uma lista de máquinas pra escolher e navegar
        // pra visão "maquina" separada, este mosaico FICA na própria tela, e o painel completo de
        // uma máquina (mesmo usado na visão "maquina") aparece logo abaixo dele — clicar noutro
        // hexágono só troca o "maquina_id" na URL, recarregando com os dados da máquina clicada.
        $produtividadeSetor = calcularHexProdutividade($db, $inicioUtc, $fimUtc, $idsDoSetor);

        $maquinaIdSel = (int)($_GET['maquina_id'] ?? 0);
        if (!in_array($maquinaIdSel, array_map('intval', $idsDoSetor), true)) {
            // Sem seleção válida (primeira visita a este setor, ou id de outro setor) — cai na
            // primeira máquina ativa da lista, pra nunca mostrar a tela "vazia".
            $maquinaIdSel = !empty($produtividadeSetor) ? (int)$produtividadeSetor[0]['id'] : 0;
        }

        $maquinaAtual = null;
        if ($maquinaIdSel > 0) {
            $stmt = $db->prepare("SELECT m.*, COALESCE(ou.nome, NULLIF(m.setor, '')) AS setor_efetivo
                                   FROM maquinas m LEFT JOIN ad_ous ou ON ou.id = m.ou_id
                                   WHERE m.id = :id");
            $stmt->execute([':id' => $maquinaIdSel]);
            $maquinaAtual = $stmt->fetch();
        }
        ?>
        <div class="card mb-3">
            <div class="card-header">Máquinas do setor "<?= htmlspecialchars($tituloSetor) ?>"</div>
            <div class="card-body">
                <?php renderHexGrid($produtividadeSetor, fn($id) => qs(['visao' => 'maquinas_setor', 'setor' => $setorSelecionado, 'maquina_id' => $id]), $maquinaIdSel); ?>
            </div>
        </div>

        <?php if (!$maquinaAtual): ?>
            <div class="alert alert-info">Nenhuma máquina ativa neste setor.</div>
        <?php else: ?>
            <?php
            renderCardInfoMaquina($maquinaAtual, false);
            renderPainelProdutividade($db, $inicioUtc, $fimUtc, $inicioLocal, $fimLocal, [$maquinaAtual['id']], true, $tendenciaDiasForcado);
            ?>
        <?php endif;
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
        renderCardInfoMaquina($maquinaAtual, true);
        renderPainelProdutividade($db, $inicioUtc, $fimUtc, $inicioLocal, $fimLocal, [$maquinaAtual['id']], true, $tendenciaDiasForcado);
    }
endif; ?>
