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
if (!in_array($visao, ['geral', 'setor', 'maquinas_setor', 'maquina', 'usuarios', 'usuario_detalhe'], true)) $visao = 'geral';

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

// Quem usou esta máquina no período (aba Usuários "explodida" para dentro da Visão Máquina e da
// Visão Máquinas por Setor) — quebra o mesmo tempo monitorado/produtivo do painel abaixo por
// usuário do Windows detectado pelo aw-watcher-currentuser, em vez de só pelo "Usuário Responsável"
// estático do cadastro (que é só o último detectado, não reflete máquinas usadas por turnos/
// pessoas diferentes). eventos.usuario_detectado já vem pré-calculado da sincronização (ver
// atualizarUsuarioDetectadoEventos() em aw_client.php) — ver comentário em renderPainelProdutividade
// sobre por que isso é pré-calculado em vez de cruzado em tempo de consulta.
function renderTabelaUsuariosMaquina(PDO $db, $inicioUtc, $fimUtc, $maquinaId) {
    $filtroHorario = filtroHorarioComercialSql();
    $filtroNaoBloqueado = filtroNaoBloqueadoSql();
    $stmt = $db->prepare("SELECT e.usuario_detectado AS usuario,
               SUM(e.duracao) AS total,
               SUM(CASE WHEN c.pontuacao = 1 THEN e.duracao ELSE 0 END) AS produtivo
        FROM eventos e
        LEFT JOIN categorias c ON c.id = e.categoria_id
        WHERE e.maquina_id = :maquina_id AND e.tipo IN ('window','web') AND e.ts BETWEEN :ini AND :fim
              AND e.usuario_detectado IS NOT NULL AND e.usuario_detectado <> '' {$filtroHorario} {$filtroNaoBloqueado}
        GROUP BY e.usuario_detectado
        ORDER BY total DESC");
    $stmt->execute([':maquina_id' => $maquinaId, ':ini' => $inicioUtc, ':fim' => $fimUtc]);
    $porUsuario = $stmt->fetchAll();
    ?>
    <div class="card mb-3">
        <div class="card-header">Usuários desta Máquina no Período</div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered bg-white align-middle mb-0">
                <thead class="table-light"><tr><th>Usuário</th><th>Tempo Monitorado</th><th>Produtivo</th><th>% Produtivo</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($porUsuario as $u): $pct = $u['total'] > 0 ? round($u['produtivo'] / $u['total'] * 100) : 0; ?>
                    <tr>
                        <td><?= htmlspecialchars($u['usuario']) ?></td>
                        <td><?= formatarDuracao($u['total'] ?: 0) ?></td>
                        <td class="online-c"><?= formatarDuracao($u['produtivo'] ?: 0) ?></td>
                        <td><?= $u['total'] > 0 ? $pct . '%' : '—' ?></td>
                        <td><a class="btn btn-sm btn-outline-primary" href="<?= qs(['visao' => 'usuario_detalhe', 'maquina_id' => null, 'setor' => null, 'usuario' => $u['usuario']]) ?>">Ver usuário</a></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($porUsuario)): ?><tr><td colspan="5" class="text-center text-muted py-2">Nenhum usuário detectado nesta máquina no período (o agente aw-watcher-currentuser pode não estar instalado nela).</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

// Cor determinística a partir de um texto (nome de app, domínio...) — mesmo texto sempre cai na
// mesma cor, sem precisar de uma tabela de cores cadastrada (ao contrário das categorias, que têm
// cor própria escolhida pelo admin). Usado nas raias "Aplicações"/"Documentos" da Linha do Tempo,
// onde cada app/site distinto precisa ser visualmente diferenciável dos vizinhos.
function corPorTexto($texto) {
    if ($texto === null || $texto === '') return '#c7c5da';
    $matiz = crc32($texto) % 360;
    return "hsl({$matiz}, 55%, 52%)";
}

// Nome "amigável" de um executável pra exibição (ex: "EXCEL.EXE" -> "Excel") — puramente cosmético,
// não afeta a categorização em nada (classificarEvento() continua comparando contra o nome REAL do
// processo, não este). Comparação sem ".exe"/case de propósito, pra casar "EXCEL.EXE", "Excel.exe"
// e "excel" com a mesma entrada. Executável sem entrada aqui continua aparecendo com o nome original
// reportado pelo Windows — a lista não precisa ser exaustiva, só cobre os mais comuns vistos nas
// máquinas monitoradas (mesmos apps já referenciados nas regras padrão de Categorias, ver getDB()).
function nomeAmigavelApp($exe) {
    static $mapa = null;
    if ($mapa === null) {
        $mapa = [
            // Microsoft Office
            'winword' => 'Word', 'excel' => 'Excel', 'powerpnt' => 'PowerPoint',
            'outlook' => 'Outlook', 'olk' => 'Outlook', 'onenote' => 'OneNote',
            'msaccess' => 'Access', 'mspub' => 'Publisher', 'teams' => 'Microsoft Teams',
            'ms-teams' => 'Microsoft Teams',
            'wa' => 'Senior (Gestão de Pessoas)',
            // Power BI
            'pbidesktop' => 'Power BI Desktop',
            // Navegadores
            'chrome' => 'Google Chrome', 'msedge' => 'Microsoft Edge',
            'firefox' => 'Mozilla Firefox', 'brave' => 'Brave',
            // Sistema/Windows
            'explorer' => 'Explorador de Arquivos', 'notepad' => 'Bloco de Notas',
            'notepad++' => 'Notepad++', 'taskmgr' => 'Gerenciador de Tarefas',
            'mmc' => 'Console de Gerenciamento (MMC)', 'lockapp' => 'Tela de Bloqueio',
            'cmd' => 'Prompt de Comando', 'powershell' => 'PowerShell',
            // Comunicação/entretenimento
            'whatsapp.root' => 'WhatsApp', 'spotify' => 'Spotify', 'steam' => 'Steam',
            // Suporte/TI
            'mstsc' => 'Conexão de Área de Trabalho Remota', 'putty' => 'PuTTY',
            'winbox64' => 'WinBox (Mikrotik)', 'vncviewer' => 'VNC Viewer',
            'winvnc' => 'Ultra VNC',
            'anydesk' => 'AnyDesk', 'webphone' => 'WebPhone',
            // Desenvolvimento
            'code' => 'Visual Studio Code', 'phpstorm64' => 'PhpStorm', 'phpstorm' => 'PhpStorm',
            'idea64' => 'IntelliJ IDEA', 'idea' => 'IntelliJ IDEA',
            'datagrip64' => 'DataGrip', 'datagrip' => 'DataGrip',
            'sublime_text' => 'Sublime Text', 'mysqlworkbench' => 'MySQL Workbench',
            'devenv' => 'Visual Studio',
        ];
    }
    $chave = strtolower(preg_replace('/\.exe$/i', '', trim((string)$exe)));
    return $mapa[$chave] ?? $exe;
}

// Mapa id => {nome, cor} de todas as categorias — usado pelas raias Aplicações/Documentos da Linha
// do Tempo pra colorir cada bloco pela CATEGORIA do evento (mesma cor cadastrada em Categorias, já
// usada no resto do dashboard), em vez de uma cor por app/título distinto (pedido explícito do
// usuário — antes usava corPorTexto() acima, uma cor "aleatória" por item, sem relação com
// produtivo/neutro/improdutivo). '#c7c5da' é o cinza padrão pra "Sem categoria", mesmo valor usado
// em renderPainelProdutividade.
function categoriasPorId(PDO $db) {
    $mapa = [];
    foreach ($db->query('SELECT id, nome, cor FROM categorias') as $row) {
        $mapa[(int)$row['id']] = ['nome' => $row['nome'], 'cor' => $row['cor'] ?: '#c7c5da'];
    }
    return $mapa;
}

// Fragmento WHERE + params pro filtro de máquina/usuário compartilhado pelas consultas da Linha do
// Tempo abaixo (evento cru, contagem e bins) — só um lugar pra manter os dois em sincronia.
function filtroMaquinaUsuarioSql($maquinaId, $usuarioFiltro, array &$params) {
    $sql = '';
    if ($maquinaId !== null) { $sql .= ' AND maquina_id = :maquina_id'; $params[':maquina_id'] = $maquinaId; }
    if ($usuarioFiltro !== null && $usuarioFiltro !== '') { $sql .= ' AND usuario_detectado = :usuario'; $params[':usuario'] = $usuarioFiltro; }
    return $sql;
}

// Busca os eventos de um tipo (afk/window/web) do período, restrito a uma máquina e/ou a um
// usuário detectado — as três raias da Linha do Tempo abaixo usam esta mesma função, só trocando o
// tipo e o mapeamento rótulo/cor.
function eventosDoPeriodo(PDO $db, $tipo, $periodoIniUtc, $periodoFimUtc, $maquinaId, $usuarioFiltro) {
    $params = [':tipo' => $tipo, ':ini' => $periodoIniUtc, ':fim' => $periodoFimUtc];
    $filtro = filtroMaquinaUsuarioSql($maquinaId, $usuarioFiltro, $params);
    $stmt = $db->prepare("SELECT ts, duracao, app, titulo, url, status, categoria_id FROM eventos WHERE tipo = :tipo AND ts BETWEEN :ini AND :fim {$filtro} ORDER BY ts ASC");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Só conta quantos eventos um tipo teria no período (sem trazer as linhas) — usado pra decidir,
// ANTES de buscar qualquer coisa, se o período tem eventos de menos pra renderizar um por um (ver
// segmentosLinhaDoTempo) ou de mais, precisando agregar em baldes (ver binsLinhaDoTempo abaixo).
function contarEventosPeriodo(PDO $db, $tipoSql, $filtroExtra, array $params) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM eventos WHERE {$tipoSql} AND ts BETWEEN :ini AND :fim {$filtroExtra}");
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

// Funde eventos CONSECUTIVOS com o mesmo rótulo (mesmo app, mesmo título...) num único segmento —
// reduz o ruído de trocas rápidas entre as MESMAS janelas (Alt+Tab, notificação, etc.) quando o
// período ainda vai ser renderizado evento a evento (ver decisão em renderLinhaDoTempo: períodos
// muito grandes usam binsLinhaDoTempo() abaixo em vez disso, já que a maioria dos eventos numa
// máquina ativa em 7-30 dias são títulos genuinamente diferentes, não repetições — o merge sozinho
// não dá conta desse caso). Só funde quando o intervalo até o próximo evento do mesmo rótulo é
// curto (≤ $gapMaxSeg); uma pausa maior é tratada como uma sessão de uso realmente separada.
function mesclarEventosConsecutivos(array $linhas, callable $rotuloFn, $gapMaxSeg = 300) {
    usort($linhas, fn($a, $b) => $a['ts'] <=> $b['ts']);
    $out = [];
    $atual = null; $atualRotulo = null; $atualIniEpoch = null; $atualFimEpoch = null;
    foreach ($linhas as $l) {
        $rotulo = $rotuloFn($l);
        $iniEpoch = (new DateTime($l['ts'], new DateTimeZone('UTC')))->getTimestamp();
        $fimEpoch = $iniEpoch + (float)$l['duracao'];
        if ($atual !== null && $rotulo === $atualRotulo && ($iniEpoch - $atualFimEpoch) <= $gapMaxSeg) {
            if ($fimEpoch > $atualFimEpoch) $atualFimEpoch = $fimEpoch;
            $atual['duracao'] = $atualFimEpoch - $atualIniEpoch;
        } else {
            if ($atual !== null) $out[] = $atual;
            $atual = $l;
            $atualRotulo = $rotulo;
            $atualIniEpoch = $iniEpoch;
            $atualFimEpoch = $fimEpoch;
        }
    }
    if ($atual !== null) $out[] = $atual;
    return $out;
}

// Visão agregada em "baldes" de tempo fixo — usada no lugar dos segmentos por evento quando o
// período tem eventos demais pra renderizar um por um (ex: uma máquina muito ativa passa de 14 mil
// trocas de janela em 30 dias — testado direto: virou 22 mil <div>s e ~6MB de HTML numa página só,
// mesmo já com mesclarEventosConsecutivos(), porque a maioria são títulos genuinamente diferentes,
// não repetições). Cada balde vira UM segmento colorido pelo rótulo que mais ocupou aquele
// intervalo (o "dominante"); a contagem de baldes é fixa ($numBins), então o tamanho da página não
// cresce junto com o volume de dados. $rotuloSql já entra pronto (COALESCE/NULLIF montado pelo
// chamador) porque cada raia agrupa por uma coluna diferente (app x título).
function binsLinhaDoTempo(PDO $db, $tipoSql, $rotuloSql, $filtroExtra, array $params,
                           $periodoIniUtc, $faixaTotalMin, callable $corFn, $numBins = 300,
                           $detalheSql = null, callable $detalheFmt = null) {
    $binWidthMin = max(1, $faixaTotalMin / $numBins);
    $params[':p_ini'] = $periodoIniUtc;
    $params[':bin_largura'] = $binWidthMin;
    // $detalheSql (opcional): o que o tooltip deve mostrar (app em Aplicações, título da janela em
    // Documentos). Cor e legenda continuam vindo de $rotuloSql (a categoria); o tooltip mostra o
    // detalhe que mais ocupou o intervalo dentro da categoria dominante.
    $detalheSelect = $detalheSql !== null ? "{$detalheSql} AS detalhe," : "'' AS detalhe,";
    // LEFT JOIN categorias sempre presente (mesmo pras raias que não usam — afk/sessão não têm
    // categoria_id, o join só fica vazio/ignorado pra elas) pra $rotuloSql poder referenciar
    // c.nome/c.cor quando for o caso (Aplicações/Documentos, coloridas por categoria).
    $stmt = $db->prepare("SELECT
            FLOOR(TIMESTAMPDIFF(SECOND, :p_ini, eventos.ts) / 60 / :bin_largura) AS bin,
            {$rotuloSql} AS rotulo,
            {$detalheSelect}
            SUM(eventos.duracao) AS total
        FROM eventos
        LEFT JOIN categorias c ON c.id = eventos.categoria_id
        WHERE {$tipoSql} AND eventos.ts BETWEEN :ini AND :fim {$filtroExtra}
        GROUP BY bin, rotulo, detalhe");
    $stmt->execute($params);

    $porBin = [];
    $detalhesBin = [];
    foreach ($stmt->fetchAll() as $row) {
        $b = (int)$row['bin'];
        $t = (float)$row['total'];
        $porBin[$b][$row['rotulo']] = ($porBin[$b][$row['rotulo']] ?? 0) + $t;
        if ($row['detalhe'] !== '' && $row['detalhe'] !== null) {
            $d = $detalheFmt !== null ? $detalheFmt($row['detalhe']) : $row['detalhe'];
            $detalhesBin[$b][$row['rotulo']][$d] = ($detalhesBin[$b][$row['rotulo']][$d] ?? 0) + $t;
        }
    }
    foreach ($porBin as $b => $porRotulo) {
        $lista = [];
        foreach ($porRotulo as $rotulo => $total) $lista[] = ['rotulo' => $rotulo, 'total' => $total];
        $porBin[$b] = $lista;
    }

    $out = [];
    foreach ($porBin as $bin => $itens) {
        usort($itens, fn($a, $b) => $b['total'] <=> $a['total']);
        $dominante = $itens[0];
        $somaBin = array_sum(array_column($itens, 'total'));
        $outros = count($itens) - 1;
        // Detalhe predominante dentro da categoria dominante (app/título), pro tooltip.
        $detalheTxt = $dominante['rotulo'];
        $detalhesDaCategoria = $detalhesBin[$bin][$dominante['rotulo']] ?? [];
        if ($detalhesDaCategoria) {
            arsort($detalhesDaCategoria);
            $detalheTxt = array_key_first($detalhesDaCategoria);
            $outros = max(0, count($detalhesBin[$bin] ?? []) > 0 ? array_sum(array_map('count', $detalhesBin[$bin])) - 1 : 0);
        }
        $minIni = max(0, min($faixaTotalMin, $bin * $binWidthMin));
        $minFim = max($minIni, min($faixaTotalMin, ($bin + 1) * $binWidthMin));
        $out[] = [
            'minIni' => round($minIni, 3),
            'minFim' => round($minFim, 3),
            'left' => round($minIni / $faixaTotalMin * 100, 3),
            'width' => max(round(($minFim - $minIni) / $faixaTotalMin * 100, 3), 0.15),
            'cor' => $corFn($dominante['rotulo']),
            'rotulo' => $dominante['rotulo'],
            'titulo' => $detalheTxt . ($outros > 0 ? ' (predominante, +' . $outros . ' outro(s) no intervalo)' : '') . ' — ' . formatarDuracao($somaBin),
        ];
    }
    return $out;
}

// Converte uma lista de eventos (já filtrada por tipo/período) em segmentos posicionáveis na Linha
// do Tempo: left/width em % do PERÍODO INTEIRO selecionado (não só um dia — pode ser 7/30 dias ou um
// intervalo personalizado), prontos pra virar `style` de um <div> absoluto; data-ini/data-fim (em
// minutos desde o início do período) também vão junto pra o JS conseguir recalcular a posição ao dar
// zoom num trecho, sem precisar buscar os dados de novo. $rotuloFn decide o AGRUPAMENTO (cor +
// legenda — ex: o domínio, não a página inteira, senão cada URL distinta viraria uma cor/legenda
// própria); $detalheFn (opcional) decide o texto do tooltip de CADA bloco, que pode ser mais
// específico que o rótulo — cai no próprio rótulo quando omitido.
function segmentosLinhaDoTempo(array $linhas, DateTime $periodoIniLocal, $faixaTotalMin, DateTimeZone $tz, callable $rotuloFn, callable $corFn, callable $detalheFn = null) {
    $multiDia = $faixaTotalMin > 1440;
    $out = [];
    foreach ($linhas as $l) {
        $tsLocal = (new DateTime($l['ts'], new DateTimeZone('UTC')))->setTimezone($tz);
        $minInicio = max(0, min($faixaTotalMin, ($tsLocal->getTimestamp() - $periodoIniLocal->getTimestamp()) / 60));
        $minFim = max($minInicio, min($faixaTotalMin, $minInicio + (float)$l['duracao'] / 60));
        $rotulo = $rotuloFn($l);
        $detalhe = $detalheFn !== null ? $detalheFn($l) : $rotulo;
        $momento = $multiDia ? $tsLocal->format('d/m H:i') : $tsLocal->format('H:i');
        $out[] = [
            'minIni' => round($minInicio, 3),
            'minFim' => round($minFim, 3),
            'left' => round($minInicio / $faixaTotalMin * 100, 3),
            'width' => max(round(($minFim - $minInicio) / $faixaTotalMin * 100, 3), 0.15),
            'cor' => $corFn($l),
            'rotulo' => $rotulo,
            // detalhe/momento ficam à parte (não só já embutidos no título) pra
            // estenderAteProximoOuAgora() conseguir remontar o tooltip com a duração nova depois de
            // esticar o segmento, sem precisar re-parsear a string.
            'detalhe' => $detalhe,
            'momento' => $momento,
            'titulo' => $detalhe . ' — ' . $momento . ' (' . formatarDuracao((float)$l['duracao']) . ')',
        ];
    }
    return $out;
}

// Estende cada segmento até o início do próximo (ou até agora/fim do período, se for o último) —
// pedido explícito pra eliminar espaços vazios nas raias Uso do Dispositivo e Sessão: um evento
// pontual como "Login" ou um estado como "Ativo" deve continuar valendo até a próxima mudança de
// estado, não só durar o instante/duração exata que o evento relatou. $agoraMin já vem limitado ao
// fim do período (ver chamada) — olhando pro futuro só faz sentido quando o período inclui "agora"
// (ex: período "Hoje"); num período todo no passado, o último segmento estica só até o fim do
// período mesmo. Só usado nessas duas raias de propósito — em Aplicações/Documentos um espaço vazio
// é informação real (nenhuma janela em foco), não uma lacuna de monitoramento.
function estenderAteProximoOuAgora(array $segmentos, $faixaTotalMin, $agoraMin) {
    $n = count($segmentos);
    $limite = max(0, min($faixaTotalMin, $agoraMin));
    for ($i = 0; $i < $n; $i++) {
        $novoFim = ($i + 1 < $n) ? $segmentos[$i + 1]['minIni'] : $limite;
        if ($novoFim > $segmentos[$i]['minFim']) {
            $segmentos[$i]['minFim'] = $novoFim;
            $segmentos[$i]['width'] = max(round(($novoFim - $segmentos[$i]['minIni']) / $faixaTotalMin * 100, 3), 0.15);
            $duracaoSegundos = max(0, ($novoFim - $segmentos[$i]['minIni']) * 60);
            $segmentos[$i]['titulo'] = $segmentos[$i]['detalhe'] . ' — ' . $segmentos[$i]['momento'] . ' (' . formatarDuracao($duracaoSegundos) . ')';
        }
    }
    return $segmentos;
}

// Legenda de uma raia: um item por rótulo distinto (app/domínio), cor igual à dos blocos que ele
// representa, ordenado pelo tempo total ocupado (soma da largura dos segmentos) — pra quem usou o
// app/site por mais tempo aparecer primeiro. $limite corta a lista (dias muito variados podem ter
// dezenas de apps/sites distintos, o que viraria uma parede de legenda) e o restante vira só uma
// contagem "+N outros".
function legendaLinhaDoTempo(array $segs, $limite = 18) {
    $porRotulo = [];
    foreach ($segs as $s) {
        if (!isset($porRotulo[$s['rotulo']])) $porRotulo[$s['rotulo']] = ['cor' => $s['cor'], 'total' => 0.0];
        $porRotulo[$s['rotulo']]['total'] += $s['width'];
    }
    uasort($porRotulo, fn($a, $b) => $b['total'] <=> $a['total']);
    $restantes = max(0, count($porRotulo) - $limite);
    return ['itens' => array_slice($porRotulo, 0, $limite, true), 'restantes' => $restantes];
}

// Linha do Tempo estilo "Rize"/RescueTime: 3 raias (Uso do Dispositivo, Aplicações, Documentos)
// mostrando blocos coloridos ao longo do PERÍODO SELECIONADO no topo do Dashboard (Hoje/7 dias/30
// dias/personalizado — o mesmo $inicioLocal/$fimLocal usado pelos KPIs) — antes ficava presa a UM
// dia com navegação própria (setas ← →), só que aí trocar o período no topo não mudava nada aqui,
// o que confundiu quem esperava ver o período inteiro refletido na linha do tempo. Pra continuar
// legível/rápida mesmo num período de 30 dias, o usuário pode arrastar sobre a linha do tempo pra
// "explodir" um trecho específico com mais detalhe (ver script no fim da função) em vez de depender
// só do zoom fixo de um dia. "Aplicações" e "Documentos" descrevem a MESMA atividade (eventos tipo
// 'window', foco de janela) por dois ângulos diferentes: "Aplicações" agrupa pelo app (e.app — ex:
// "chrome.exe"); "Documentos" agrupa pelo título da janela (e.titulo — ex: "Relatório.docx - Word",
// "Caixa de Entrada - Gmail — Google Chrome"), que é o que de fato diz qual documento/página estava
// aberta ali. Eventos tipo 'web' (abas do navegador, só existem com a extensão do ActivityWatch
// instalada — nenhuma máquina tem isso hoje) entram também em "Documentos", com o título da aba no
// lugar do título da janela e a URL completa no tooltip, quando existirem. $maquinaId e
// $usuarioFiltro: pelo menos um dos dois deve vir preenchido (a Visão Máquina passa só maquinaId; a
// Visão Usuário passa só usuarioFiltro, já que uma pessoa pode ter usado mais de uma máquina).
function renderLinhaDoTempo(PDO $db, DateTime $periodoIniLocal, DateTime $periodoFimLocal, $maquinaId = null, $usuarioFiltro = null) {
    $tz = new DateTimeZone('America/Sao_Paulo');
    $periodoIniUtc = (clone $periodoIniLocal)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $periodoFimUtc = (clone $periodoFimLocal)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $faixaTotalMin = ($periodoFimLocal->getTimestamp() - $periodoIniLocal->getTimestamp()) / 60;
    // Minuto "agora" dentro da escala do período — usado só por estenderAteProximoOuAgora() pra
    // saber até onde esticar o último segmento de Uso do Dispositivo/Sessão. Num período todo no
    // passado isso dá um valor maior que $faixaTotalMin, e a própria função já limita ao fim do
    // período nesse caso (ver comentário nela).
    $agoraMin = ((new DateTime('now', $tz))->getTimestamp() - $periodoIniLocal->getTimestamp()) / 60;

    // Acima deste número de eventos no período, troca de segmento-por-evento pra bins agregados
    // (ver binsLinhaDoTempo) — testado direto: uma máquina muito ativa em 30 dias chega a 14 mil
    // eventos só de janela, o que virou 22 mil <div>s / ~6MB de HTML sem esse corte (mesmo já com
    // mesclarEventosConsecutivos). Um dia só normalmente fica bem abaixo disso.
    $LIMITE_EVENTOS_DETALHADOS = 1500;
    // Vira true se QUALQUER raia cair no modo agregado — usado só pra avisar o usuário no texto da
    // barra de ferramentas (ver abaixo) que o zoom por arrasto aqui amplia os blocos já calculados,
    // não busca detalhe evento-a-evento novo (isso exigiria ida ao servidor a cada arrasto, o que
    // esta implementação propositalmente evita pra manter a interação instantânea).
    $usouAgregado = false;

    // ---- Uso do Dispositivo (afk) ----
    $paramsAfk = [':ini' => $periodoIniUtc, ':fim' => $periodoFimUtc];
    $filtroAfk = filtroMaquinaUsuarioSql($maquinaId, $usuarioFiltro, $paramsAfk);
    $afkRotulo = fn($l) => $l['status'] === 'not-afk' ? 'Ativo' : 'Ausente';
    if (contarEventosPeriodo($db, "tipo = 'afk'", $filtroAfk, $paramsAfk) > $LIMITE_EVENTOS_DETALHADOS) {
        $segAfk = binsLinhaDoTempo($db, "tipo = 'afk'", "IF(status = 'not-afk', 'Ativo', 'Ausente')", $filtroAfk, $paramsAfk,
            $periodoIniUtc, $faixaTotalMin, fn($r) => $r === 'Ativo' ? '#178ec8' : '#dfe2ec');
        $usouAgregado = true;
    } else {
        $afk = mesclarEventosConsecutivos(
            eventosDoPeriodo($db, 'afk', $periodoIniUtc, $periodoFimUtc, $maquinaId, $usuarioFiltro), $afkRotulo);
        $segAfk = estenderAteProximoOuAgora(
            segmentosLinhaDoTempo($afk, $periodoIniLocal, $faixaTotalMin, $tz, $afkRotulo,
                fn($l) => $l['status'] === 'not-afk' ? '#178ec8' : '#dfe2ec'),
            $faixaTotalMin, $agoraMin);
    }

    // ---- Sessão (login/bloqueio/desbloqueio, ver Agentes/aw-watcher-sessionevents) — marcos
    // pontuais (duração zero) em vez de blocos contínuos, por isso ficam numa raia própria em vez
    // de misturados em "Uso do Dispositivo": o AFK (acima) só enxerga tempo sem teclado/mouse, não
    // sabe se a tela estava DE FATO bloqueada — pedido explícito pra estatística mais fiel.
    $sessaoCores = ['login' => '#16a34a', 'lock' => '#dc2626', 'unlock' => '#178ec8'];
    $sessaoRotulos = ['login' => 'Login', 'lock' => 'Bloqueio', 'unlock' => 'Desbloqueio'];
    $sessaoRotulo = fn($l) => $sessaoRotulos[$l['status']] ?? ($l['status'] ?: '(evento desconhecido)');
    $paramsSessao = [':ini' => $periodoIniUtc, ':fim' => $periodoFimUtc];
    $filtroSessao = filtroMaquinaUsuarioSql($maquinaId, $usuarioFiltro, $paramsSessao);
    if (contarEventosPeriodo($db, "tipo = 'sessao'", $filtroSessao, $paramsSessao) > $LIMITE_EVENTOS_DETALHADOS) {
        $segSessao = binsLinhaDoTempo($db, "tipo = 'sessao'", "COALESCE(NULLIF(status,''), '(evento desconhecido)')", $filtroSessao, $paramsSessao,
            $periodoIniUtc, $faixaTotalMin, fn($r) => $sessaoCores[$r] ?? corPorTexto($r));
        $usouAgregado = true;
    } else {
        $sessao = eventosDoPeriodo($db, 'sessao', $periodoIniUtc, $periodoFimUtc, $maquinaId, $usuarioFiltro);
        $segSessao = estenderAteProximoOuAgora(
            segmentosLinhaDoTempo($sessao, $periodoIniLocal, $faixaTotalMin, $tz,
                $sessaoRotulo, fn($l) => $sessaoCores[$l['status']] ?? corPorTexto((string)$l['status'])),
            $faixaTotalMin, $agoraMin);
    }

    // Aplicações/Documentos coloridas pela CATEGORIA do evento (mesma cor cadastrada em Categorias,
    // já usada no resto do dashboard) em vez de uma cor por app/título distinto — pedido explícito
    // do usuário. $nomeCategoria/$corCategoria resolvem pelo categoria_id do evento (modo detalhado,
    // evento a evento); $corPorNomeCategoria resolve pelo NOME (modo agregado/bins, onde o SQL já
    // devolve o nome da categoria dominante do balde como "rótulo" — ver uso abaixo).
    $categoriasMapa = categoriasPorId($db);
    $nomeCategoria = fn($categoriaId) => $categoriaId !== null && isset($categoriasMapa[(int)$categoriaId])
        ? $categoriasMapa[(int)$categoriaId]['nome'] : 'Sem categoria';
    $corCategoria = fn($categoriaId) => $categoriaId !== null && isset($categoriasMapa[(int)$categoriaId])
        ? $categoriasMapa[(int)$categoriaId]['cor'] : '#c7c5da';
    $corPorNomeCategoria = function ($nome) use ($categoriasMapa) {
        foreach ($categoriasMapa as $cat) { if ($cat['nome'] === $nome) return $cat['cor']; }
        return '#c7c5da';
    };

    // ---- Aplicações (window, sem LockApp.exe — mesmo critério de "uso real" dos KPIs do resto do
    // dashboard, ver filtroNaoBloqueadoSql()). $appRotulo (nome do app) continua decidindo só o
    // MERGE de eventos consecutivos (mesmo app = funde) — a cor/legenda usam a categoria, não o app.
    $paramsApps = [':ini' => $periodoIniUtc, ':fim' => $periodoFimUtc];
    $filtroApps = filtroMaquinaUsuarioSql($maquinaId, $usuarioFiltro, $paramsApps) . " AND COALESCE(app,'') <> 'LockApp.exe'";
    $appRotulo = fn($l) => $l['app'] ? nomeAmigavelApp($l['app']) : '(sem aplicativo)';
    $janelas = null;
    if (contarEventosPeriodo($db, "tipo = 'window'", $filtroApps, $paramsApps) > $LIMITE_EVENTOS_DETALHADOS) {
        // Cor/legenda pela categoria; o tooltip mostra o APLICATIVO em uso.
        $segApps = binsLinhaDoTempo($db, "tipo = 'window'", "COALESCE(c.nome, 'Sem categoria')", $filtroApps, $paramsApps,
            $periodoIniUtc, $faixaTotalMin, $corPorNomeCategoria, 300,
            "COALESCE(NULLIF(eventos.app, ''), '(sem aplicativo)')", fn($app) => $app === '(sem aplicativo)' ? $app : nomeAmigavelApp($app));
        $usouAgregado = true;
    } else {
        $janelas = array_filter(eventosDoPeriodo($db, 'window', $periodoIniUtc, $periodoFimUtc, $maquinaId, $usuarioFiltro),
            fn($l) => ($l['app'] ?? '') !== 'LockApp.exe');
        $segApps = segmentosLinhaDoTempo(mesclarEventosConsecutivos($janelas, $appRotulo), $periodoIniLocal, $faixaTotalMin, $tz,
            fn($l) => $nomeCategoria($l['categoria_id']), fn($l) => $corCategoria($l['categoria_id']), $appRotulo);
    }

    // ---- Documentos (window + web, agrupado por título) — $docLabel decide o MERGE (mesmo título =
    // funde) e o tooltip (via $docDetalhe, abaixo); cor/legenda usam a categoria, igual Aplicações. ----
    $paramsDocs = [':ini' => $periodoIniUtc, ':fim' => $periodoFimUtc];
    $filtroDocs = filtroMaquinaUsuarioSql($maquinaId, $usuarioFiltro, $paramsDocs) . " AND (tipo <> 'window' OR COALESCE(app,'') <> 'LockApp.exe')";
    $tipoDocsSql = "tipo IN ('window','web')";
    $docLabel = fn($l) => $l['titulo'] ?: ($l['app'] ?: '(sem título)');
    $docDetalhe = function ($l) {
        $titulo = $l['titulo'] ?: '(sem título)';
        // Tooltip de Documentos = título da janela (a URL, só se for aba do navegador).
        if (!empty($l['url'])) return $titulo . ' — ' . $l['url'];
        if ($titulo === '(sem título)' && !empty($l['app'])) return nomeAmigavelApp($l['app']);
        return $titulo;
    };
    if (contarEventosPeriodo($db, $tipoDocsSql, $filtroDocs, $paramsDocs) > $LIMITE_EVENTOS_DETALHADOS) {
        // Cor/legenda pela categoria; o tooltip mostra o TÍTULO DA JANELA (ou da aba) em uso.
        $segDocs = binsLinhaDoTempo($db, $tipoDocsSql, "COALESCE(c.nome, 'Sem categoria')", $filtroDocs, $paramsDocs,
            $periodoIniUtc, $faixaTotalMin, $corPorNomeCategoria, 300,
            "COALESCE(NULLIF(eventos.titulo, ''), NULLIF(eventos.app, ''), '(sem título)')");
        $usouAgregado = true;
    } else {
        $janelasDocs = $janelas ?? array_filter(eventosDoPeriodo($db, 'window', $periodoIniUtc, $periodoFimUtc, $maquinaId, $usuarioFiltro),
            fn($l) => ($l['app'] ?? '') !== 'LockApp.exe');
        $web = eventosDoPeriodo($db, 'web', $periodoIniUtc, $periodoFimUtc, $maquinaId, $usuarioFiltro);
        $docs = array_merge($janelasDocs, $web);
        usort($docs, fn($a, $b) => $a['ts'] <=> $b['ts']);
        $segDocs = segmentosLinhaDoTempo(mesclarEventosConsecutivos($docs, $docLabel), $periodoIniLocal, $faixaTotalMin, $tz,
            fn($l) => $nomeCategoria($l['categoria_id']), fn($l) => $corCategoria($l['categoria_id']), $docDetalhe);
    }

    $raias = ['Uso do Dispositivo' => $segAfk, 'Sessão' => $segSessao, 'Aplicações' => $segApps, 'Documentos' => $segDocs];
    // Uso do Dispositivo e Sessão têm estados fixos (não um app/domínio por linha) — legenda fixa em
    // vez de calculada a partir dos dados, pra sempre explicar as cores mesmo num período sem todos
    // os estados presentes.
    $legendas = [
        'Uso do Dispositivo' => ['itens' => ['Ativo' => ['cor' => '#178ec8', 'total' => 1], 'Ausente' => ['cor' => '#dfe2ec', 'total' => 1]], 'restantes' => 0],
        'Sessão' => ['itens' => [
            'Login' => ['cor' => $sessaoCores['login'], 'total' => 1],
            'Bloqueio' => ['cor' => $sessaoCores['lock'], 'total' => 1],
            'Desbloqueio' => ['cor' => $sessaoCores['unlock'], 'total' => 1],
        ], 'restantes' => 0],
        'Aplicações' => legendaLinhaDoTempo($segApps),
        'Documentos' => legendaLinhaDoTempo($segDocs),
    ];
    // Sufixo pra não colidir IDs se esta função algum dia for chamada mais de uma vez na mesma
    // página (hoje não é o caso, mas o resto do arquivo segue esse padrão pros gráficos também).
    $tlId = 'tl' . substr(md5(($maquinaId ?? '') . '|' . ($usuarioFiltro ?? '') . '|' . $periodoIniLocal->format('Y-m-d')), 0, 6);
    $rotuloPeriodo = $periodoIniLocal->format('d/m/Y') === $periodoFimLocal->format('d/m/Y')
        ? $periodoIniLocal->format('d/m/Y')
        : $periodoIniLocal->format('d/m/Y') . ' – ' . $periodoFimLocal->format('d/m/Y');
    ?>
    <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span>Linha do Tempo</span>
            <span class="mono small text-muted"><?= htmlspecialchars($rotuloPeriodo) ?></span>
        </div>
        <div class="card-body">
            <?php if ($usouAgregado): ?>
            <div class="alert alert-light border py-1 px-2 small text-muted mb-2">Período longo — Aplicações/Documentos mostram uma visão agregada (a cor predominante de cada intervalo), não evento a evento. Dê zoom pra ampliar; pra ver blocos exatos, escolha um período mais curto (ex: "Hoje").</div>
            <?php endif; ?>
            <div class="timeline-toolbar">
                <span class="text-muted small" id="<?= $tlId ?>_info">Arraste sobre a linha do tempo pra dar zoom num período</span>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="<?= $tlId ?>_reset" hidden>Ver período inteiro</button>
            </div>
            <div class="timeline-wrap">
                <div class="timeline-ticks" id="<?= $tlId ?>_ticks"></div>
                <div class="timeline-body" id="<?= $tlId ?>_body">
                    <?php foreach ($raias as $rotulo => $segs): ?>
                    <div class="timeline-row">
                        <div class="timeline-label"><?= htmlspecialchars($rotulo) ?></div>
                        <div class="timeline-track">
                            <?php foreach ($segs as $s): ?>
                            <div class="timeline-seg" data-ini="<?= $s['minIni'] ?>" data-fim="<?= $s['minFim'] ?>" style="left:<?= $s['left'] ?>%; width:<?= $s['width'] ?>%; background:<?= $s['cor'] ?>;" title="<?= htmlspecialchars($s['titulo']) ?>"></div>
                            <?php endforeach; ?>
                            <?php if (empty($segs)): ?><span class="timeline-empty">Sem dados neste período</span><?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="timeline-legendas mt-2">
                <?php foreach ($raias as $rotulo => $segs): ?>
                <div class="timeline-legend">
                    <div class="timeline-legend-titulo"><?= htmlspecialchars($rotulo) ?></div>
                    <div class="timeline-legend-itens">
                        <?php foreach ($legendas[$rotulo]['itens'] as $nome => $info): ?>
                        <span class="timeline-legend-item"><span class="timeline-legend-dot" style="background:<?= $info['cor'] ?>;"></span><?= htmlspecialchars($nome) ?></span>
                        <?php endforeach; ?>
                        <?php if ($legendas[$rotulo]['restantes'] > 0): ?><span class="timeline-legend-item text-muted">+<?= $legendas[$rotulo]['restantes'] ?> outro(s)</span><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <script>
    (function () {
        // Zoom por arrasto: seleciona um período na Linha do Tempo pra "explodir" (ver com mais
        // detalhe) — pedido explícito do usuário, com print de referência de um seletor de período.
        // Em vez de um overlay fixo por cima das raias (que bloquearia o title/tooltip nativo de
        // cada bloco ao passar o mouse), os listeners ficam no container inteiro e só desenham a
        // caixa de seleção quando o mouse de fato se MOVE depois do mousedown — um clique parado
        // continua livre pra só mostrar o tooltip do bloco, sem iniciar seleção nenhuma.
        var body = document.getElementById('<?= $tlId ?>_body');
        var ticksEl = document.getElementById('<?= $tlId ?>_ticks');
        var infoEl = document.getElementById('<?= $tlId ?>_info');
        var resetBtn = document.getElementById('<?= $tlId ?>_reset');
        var trackRef = body.querySelector('.timeline-track');
        var segs = body.querySelectorAll('.timeline-seg');
        if (!trackRef) return;

        // Início do período em epoch ms (horário local) — só pra formatar as marcas de hora/data
        // dos ticks a partir de um minuto absoluto; toda a matemática de posicionamento usa só
        // minutos (0..FAIXA_TOTAL), sem depender de fuso nenhum.
        var PERIODO_INICIO_MS = <?= $periodoIniLocal->getTimestamp() * 1000 ?>;
        var FAIXA_TOTAL = <?= round($faixaTotalMin, 3) ?>;
        var zoomIni = 0, zoomFim = FAIXA_TOTAL;
        var textoPadrao = infoEl.textContent;

        function formatarMin(min, comData) {
            var dt = new Date(PERIODO_INICIO_MS + min * 60000);
            var hh = ('0' + dt.getHours()).slice(-2), mm = ('0' + dt.getMinutes()).slice(-2);
            if (comData) {
                var dd = ('0' + dt.getDate()).slice(-2), mo = ('0' + (dt.getMonth() + 1)).slice(-2);
                return dd + '/' + mo + ' ' + hh + ':' + mm;
            }
            return hh + ':' + mm;
        }

        // Passo dos ticks (em minutos) escolhido pela faixa visível no momento — de 5 em 5 minutos
        // bem no zoom máximo até de 2 em 2 dias pra um período de 30 dias inteiro.
        function passoTicks(faixaMin) {
            if (faixaMin <= 30) return 5;
            if (faixaMin <= 90) return 15;
            if (faixaMin <= 180) return 30;
            if (faixaMin <= 480) return 60;
            if (faixaMin <= 1440) return 120;
            if (faixaMin <= 4320) return 360;
            if (faixaMin <= 10080) return 1440;
            return 2880;
        }

        function redesenharTicks() {
            var faixa = zoomFim - zoomIni;
            var passo = passoTicks(faixa);
            var inicio = Math.ceil(zoomIni / passo) * passo;
            var comData = passo >= 1440 || FAIXA_TOTAL > 1440;
            var html = '';
            for (var m = inicio; m <= zoomFim; m += passo) {
                html += '<span style="left:' + ((m - zoomIni) / faixa * 100).toFixed(3) + '%;">' + formatarMin(m, comData) + '</span>';
            }
            ticksEl.innerHTML = html;
        }

        function aplicarZoom(ini, fim) {
            zoomIni = Math.max(0, ini);
            zoomFim = Math.min(FAIXA_TOTAL, fim);
            var faixa = zoomFim - zoomIni;
            segs.forEach(function (seg) {
                var si = parseFloat(seg.dataset.ini), sf = parseFloat(seg.dataset.fim);
                seg.style.left = ((si - zoomIni) / faixa * 100) + '%';
                seg.style.width = Math.max((sf - si) / faixa * 100, 0.15) + '%';
            });
            redesenharTicks();
            var zoomado = zoomIni > 0 || zoomFim < FAIXA_TOTAL;
            resetBtn.hidden = !zoomado;
            infoEl.textContent = zoomado
                ? ('Mostrando ' + formatarMin(zoomIni, true) + ' – ' + formatarMin(zoomFim, true) + ' · arraste de novo pra refinar')
                : textoPadrao;
        }

        resetBtn.addEventListener('click', function () { aplicarZoom(0, FAIXA_TOTAL); });

        function xParaMin(clientX) {
            var rect = trackRef.getBoundingClientRect();
            var pct = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
            return zoomIni + pct * (zoomFim - zoomIni);
        }

        var arrastando = false, moveu = false, startX = 0, caixa = null;
        body.addEventListener('mousedown', function (ev) {
            if (ev.button !== 0) return;
            arrastando = true; moveu = false; startX = ev.clientX;
        });
        window.addEventListener('mousemove', function (ev) {
            if (!arrastando) return;
            if (!moveu && Math.abs(ev.clientX - startX) < 4) return;
            if (!moveu) {
                moveu = true;
                caixa = document.createElement('div');
                caixa.className = 'timeline-selection-box';
                body.appendChild(caixa);
            }
            var trackRect = trackRef.getBoundingClientRect();
            var bodyRect = body.getBoundingClientRect();
            var x1 = Math.max(trackRect.left, Math.min(trackRect.right, startX));
            var x2 = Math.max(trackRect.left, Math.min(trackRect.right, ev.clientX));
            var esq = Math.min(x1, x2) - bodyRect.left;
            var dir = Math.max(x1, x2) - bodyRect.left;
            caixa.style.left = esq + 'px';
            caixa.style.width = (dir - esq) + 'px';
        });
        window.addEventListener('mouseup', function (ev) {
            if (!arrastando) return;
            arrastando = false;
            if (moveu && caixa) {
                var m1 = xParaMin(startX), m2 = xParaMin(ev.clientX);
                body.removeChild(caixa);
                caixa = null;
                var ini = Math.min(m1, m2), fim = Math.max(m1, m2);
                if (fim - ini >= 2) aplicarZoom(ini, fim);
            }
            moveu = false;
        });

        redesenharTicks();
    })();
    </script>
    <?php
}

// Todo o bloco de KPIs + gráficos — reaproveitado pela Visão Geral (todas as máquinas, ou uma só
// se selecionada), pela Visão de uma Máquina e pela Visão Máquinas por Setor (ambas uma única
// máquina, vinda do clique num hexágono ou numa tabela). $maquinaIds vazio = sem restrição (todas
// as máquinas do sistema).
function renderPainelProdutividade(PDO $db, $inicioUtc, $fimUtc, DateTime $inicioLocal, DateTime $fimLocal, array $maquinaIds, $exibirTendenciaPontos = false, $tendenciaDiasForcado = null, $usuarioFiltro = null, callable $entreStatsECharts = null, $mapaCalor = false) {
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
    // Visão "por usuário da máquina" (aba Usuários): restringe as mesmas consultas de window/web/afk
    // abaixo ao usuário do Windows escolhido, usando eventos.usuario_detectado — já pré-calculado na
    // sincronização por atualizarUsuarioDetectadoEventos() (ver aw_client.php) a partir do bucket do
    // aw-watcher-currentuser. Comparar isso em tempo de consulta (em vez de cruzar com os heartbeats
    // 'usuario' aqui, via JOIN de intervalo) é o que faz esta view abrir rápido — a versão com JOIN
    // de intervalo chegou a levar 7-10s numa base com só ~50 mil eventos, porque o otimizador do
    // MariaDB 10.4 não usa índice nesse tipo de junção por faixa correlacionada. Anexado ao mesmo
    // $filtroMaquinaSql (em vez de repetir a condição em cada consulta) porque essa variável já é
    // interpolada em todas as consultas abaixo que filtram window/web/afk.
    if ($usuarioFiltro !== null && $usuarioFiltro !== '') {
        $filtroMaquinaSql .= ' AND e.usuario_detectado = :usuario_filtro';
        $params[':usuario_filtro'] = $usuarioFiltro;
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

    // ---- Top 10 — aplicativos por padrão; a caixa de seleção no card troca pra mostrar os 10
    // títulos de janela mais vistos de UM app específico, escolhido pelo usuário (pedido explícito).
    // $todosApps traz TODOS os apps do período (não só 10) pra popular essa caixa de seleção —
    // $topApps continua sendo só os 10 primeiros, pro gráfico no modo padrão.
    $stmt = $db->prepare("SELECT app, SUM(duracao) AS total FROM eventos e
        WHERE tipo = 'window' AND app IS NOT NULL AND app <> '' AND ts BETWEEN :ini AND :fim {$filtroMaquinaSql} {$filtroHorario} {$filtroNaoBloqueado}
        GROUP BY app ORDER BY total DESC");
    $stmt->execute($params);
    $todosApps = $stmt->fetchAll();
    $topApps = array_slice($todosApps, 0, 10);

    // Cor de cada app = cor da categoria que mais contribuiu pro tempo dele (um app pode, raramente,
    // ter migrado de categoria ao longo do tempo se as regras mudaram — fica a que tem mais tempo
    // acumulado). Sem categoria cai no cinza padrão, igual ao resto do dashboard. Calculado pra
    // TODOS os apps (não só os 10 do gráfico padrão) porque a caixa de seleção deixa escolher
    // qualquer um, e as barras de título reaproveitam a cor do próprio app selecionado.
    $corPorApp = [];
    if (!empty($todosApps)) {
        $stmt = $db->prepare("SELECT e.app, c.cor AS categoria_cor, SUM(e.duracao) AS total
            FROM eventos e LEFT JOIN categorias c ON c.id = e.categoria_id
            WHERE e.tipo = 'window' AND e.app IS NOT NULL AND e.app <> '' AND e.ts BETWEEN :ini AND :fim {$filtroMaquinaSql} {$filtroHorario} {$filtroNaoBloqueado}
            GROUP BY e.app, c.id, c.cor");
        $stmt->execute($params);
        $melhorPorApp = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!isset($melhorPorApp[$row['app']]) || (float)$row['total'] > $melhorPorApp[$row['app']]['total']) {
                $melhorPorApp[$row['app']] = ['cor' => $row['categoria_cor'] ?? '#c7c5da', 'total' => (float)$row['total']];
            }
        }
        foreach ($todosApps as $r) {
            $corPorApp[$r['app']] = $melhorPorApp[$r['app']]['cor'] ?? '#c7c5da';
        }
    }

    // Top 10 títulos de janela de CADA app (não só os que aparecem no Top 10 padrão) — mesmo padrão
    // de agregação de $detalheCategoriaTitulos mais abaixo, só que agrupando por app em vez de
    // categoria. $dadosTopPorApp já sai pronto no formato que o gráfico Chart.js espera (labels +
    // dados em minutos), indexado por app, pra trocar o gráfico inteiro no cliente sem ida ao
    // servidor quando a caixa de seleção muda.
    $titulosPorApp = [];
    $stmt = $db->prepare("SELECT app, COALESCE(NULLIF(titulo, ''), '(sem título)') AS item, SUM(duracao) AS total
        FROM eventos e
        WHERE tipo = 'window' AND app IS NOT NULL AND app <> '' AND ts BETWEEN :ini AND :fim {$filtroMaquinaSql} {$filtroHorario} {$filtroNaoBloqueado}
        GROUP BY app, item");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $titulosPorApp[$row['app']][$row['item']] = ($titulosPorApp[$row['app']][$row['item']] ?? 0) + (float)$row['total'];
    }
    $dadosTopPorApp = [];
    foreach ($todosApps as $r) {
        $app = $r['app'];
        $titulos = $titulosPorApp[$app] ?? [];
        arsort($titulos);
        $titulos = array_slice($titulos, 0, 10, true);
        $dadosTopPorApp[$app] = [
            'labels' => array_keys($titulos),
            'data' => array_values(array_map(fn($t) => round($t / 60), $titulos)),
            'cor' => $corPorApp[$app] ?? '#c7c5da',
        ];
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
    if (!empty($maquinaIds) || ($usuarioFiltro !== null && $usuarioFiltro !== '')) {
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
    // DatePeriod lança "Recurrence count must be greater than 0" se o 3º argumento (nº de
    // repetições após a 1ª data) for 0 — acontece quando a tendência cobre um único dia (ex.:
    // período customizado com data inicial = data final), daí o caso precisa ser tratado à parte.
    if ($numDiasTendencia <= 1) {
        $dias[$tendenciaInicioLocal->format('Y-m-d')] = ['produtivo' => 0, 'neutro' => 0, 'improdutivo' => 0];
    } else {
        $periodoDias = new DatePeriod(clone $tendenciaInicioLocal, new DateInterval('P1D'), $numDiasTendencia - 1);
        foreach ($periodoDias as $d) { $dias[$d->format('Y-m-d')] = ['produtivo' => 0, 'neutro' => 0, 'improdutivo' => 0]; }
    }
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
            foreach ($porHora as $h => $produtivoHora) {
                $pontosLabels[] = sprintf('%02dh', $h);
                $pontosValores[] = $produtivoHora;
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


    $idSufixo = 'p' . substr(md5(implode(',', $maquinaIds) . '|' . ($usuarioFiltro ?? '')), 0, 6);

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
    // Cards clicáveis: abrem o Explorador de Eventos com o mesmo recorte do card (grupo) e o mesmo
    // escopo da visão (usuário do Windows e/ou várias máquinas).
    $eventosCardBase = $eventosBaseParams;
    if (count($maquinaIds) > 1) $eventosCardBase['maquina_ids'] = implode(',', $maquinaIds);
    if ($usuarioFiltro !== null && $usuarioFiltro !== '') $eventosCardBase['usuario'] = $usuarioFiltro;
    $linkCard = fn($grupo) => 'index.php?' . http_build_query($eventosCardBase + ['grupo' => $grupo]);
    ?>
    <div class="stat-strip stat-strip-5">
        <a class="stat-tile" href="<?= htmlspecialchars($linkCard('monitorado')) ?>" title="Ver os eventos deste card">
            <div><div class="stat-label">Tempo Monitorado</div><div class="stat-value"><?= formatarDuracao($totalMonitorado) ?></div><div class="stat-note">janela + navegador</div></div>
            <div class="stat-icon blue"><i class="bi bi-clock-history"></i></div>
        </a>
        <a class="stat-tile" href="<?= htmlspecialchars($linkCard('produtivo')) ?>" title="Ver os eventos deste card">
            <div><div class="stat-label">Produtivo</div><div class="stat-value online-c"><?= formatarDuracao($produtivo) ?></div><div class="stat-note"><?= $pctProdutivo ?>% do tempo monitorado</div></div>
            <div class="stat-icon green"><i class="bi bi-graph-up-arrow"></i></div>
        </a>
        <a class="stat-tile" href="<?= htmlspecialchars($linkCard('neutro')) ?>" title="Ver os eventos deste card">
            <div><div class="stat-label">Neutro</div><div class="stat-value"><?= formatarDuracao($neutro + $semCategoria) ?></div><div class="stat-note"><?= $totalMonitorado > 0 ? round(($neutro + $semCategoria) / $totalMonitorado * 100) : 0 ?>% do tempo monitorado<?= $semCategoria > 0 ? ' · inclui sem categoria: ' . formatarDuracao($semCategoria) : '' ?></div></div>
            <div class="stat-icon gray"><i class="bi bi-dash-circle"></i></div>
        </a>
        <a class="stat-tile" href="<?= htmlspecialchars($linkCard('improdutivo')) ?>" title="Ver os eventos deste card">
            <div><div class="stat-label">Improdutivo</div><div class="stat-value critical-c"><?= formatarDuracao($improdutivo) ?></div><div class="stat-note"><?= $totalMonitorado > 0 ? round($improdutivo / $totalMonitorado * 100) : 0 ?>% do tempo monitorado</div></div>
            <div class="stat-icon red"><i class="bi bi-graph-down-arrow"></i></div>
        </a>
        <a class="stat-tile" href="<?= htmlspecialchars($linkCard('ativo')) ?>" title="Ver os eventos deste card">
            <div><div class="stat-label">Ativo x Ausente</div><div class="stat-value"><?= formatarDuracao($tempoAtivo) ?></div><div class="stat-note"><?= formatarDuracao($tempoAusente) ?> ausente (AFK) · base: teclado/mouse, independe do monitorado</div></div>
            <div class="stat-icon orange"><i class="bi bi-person-check"></i></div>
        </a>
    </div>

    <?php if ($entreStatsECharts !== null) $entreStatsECharts(); ?>

    <?php if ($mapaCalor && $exibirTendenciaPontos): ?>
    <div class="row g-3 mb-1">
        <div class="col-12"><?php renderMapaCalorProdutividade($db, $inicioLocal, $fimLocal, $maquinaIds ?: null, $usuarioFiltro); ?></div>
    </div>
    <?php endif; ?>

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
                <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <span>Top 10</span>
                    <select id="seletorTopApp<?= $idSufixo ?>" class="form-select form-select-sm" style="max-width: 220px;">
                        <option value="">Aplicativos</option>
                        <?php foreach ($todosApps as $r): ?>
                        <option value="<?= htmlspecialchars($r['app']) ?>"><?= htmlspecialchars(nomeAmigavelApp($r['app'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="card-body chart-card-body">
                    <div class="chart-wrap" style="height: 270px;"><canvas id="chartApps<?= $idSufixo ?>"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($mapaCalor && !$exibirTendenciaPontos): ?>
    <div class="row g-3 mt-1">
        <div class="col-12"><?php renderMapaCalorProdutividade($db, $inicioLocal, $fimLocal, $maquinaIds ?: null, $usuarioFiltro); ?></div>
    </div>
    <?php renderRankings($db, $inicioUtc, $fimUtc, null); ?>
    <?php endif; ?>

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

        // Dados por app (10 títulos de janela mais vistos de cada um, já em minutos) pra trocar o
        // gráfico no cliente quando a caixa de seleção do card "Top 10" muda, sem ida ao servidor —
        // mesmo princípio de detalheCategoria<?= $idSufixo ?> acima.
        var dadosTopPorApp<?= $idSufixo ?> = <?= json_encode($dadosTopPorApp, JSON_UNESCAPED_UNICODE) ?>;
        var topAppsPadrao<?= $idSufixo ?> = {
            labels: <?= json_encode(array_map(fn($r) => nomeAmigavelApp($r['app']), $topApps), JSON_UNESCAPED_UNICODE) ?>,
            data: <?= json_encode(array_map(fn($r) => round($r['total']/60), $topApps)) ?>,
            cores: <?= json_encode(array_map(fn($r) => $corPorApp[$r['app']] ?? '#c7c5da', $topApps)) ?>,
        };

        var chartApps<?= $idSufixo ?> = new Chart(document.getElementById('chartApps<?= $idSufixo ?>'), {
            type: 'bar',
            data: {
                labels: topAppsPadrao<?= $idSufixo ?>.labels,
                datasets: [{
                    data: topAppsPadrao<?= $idSufixo ?>.data,
                    backgroundColor: topAppsPadrao<?= $idSufixo ?>.cores,
                }]
            },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => ctx.raw + ' min' } } },
                scales: { x: { title: { display: true, text: 'minutos' } } }
            }
        });

        document.getElementById('seletorTopApp<?= $idSufixo ?>').addEventListener('change', function () {
            var app = this.value;
            var dados = app && dadosTopPorApp<?= $idSufixo ?>[app]
                ? dadosTopPorApp<?= $idSufixo ?>[app]
                : null;
            if (dados) {
                chartApps<?= $idSufixo ?>.data.labels = dados.labels;
                chartApps<?= $idSufixo ?>.data.datasets[0].data = dados.data;
                chartApps<?= $idSufixo ?>.data.datasets[0].backgroundColor = dados.labels.map(() => dados.cor);
            } else {
                chartApps<?= $idSufixo ?>.data.labels = topAppsPadrao<?= $idSufixo ?>.labels;
                chartApps<?= $idSufixo ?>.data.datasets[0].data = topAppsPadrao<?= $idSufixo ?>.data;
                chartApps<?= $idSufixo ?>.data.datasets[0].backgroundColor = topAppsPadrao<?= $idSufixo ?>.cores;
            }
            chartApps<?= $idSufixo ?>.update();
        });

    })();
    </script>
    <?php
}

// ---- Cards de ranking (Visão Geral / Máquinas por Setor) ----
// Agrega produtividade por colaborador (usuário do Windows detectado), departamento (setor/OU) ou
// máquina, no mesmo recorte de horário/bloqueio dos KPIs. $maquinaIds restringe às máquinas dadas
// (null = todas). Devolve [chave => ['total','produtivo','improdutivo','maquinas'(lista de nomes)]].
function agregarRanking(PDO $db, $iniUtc, $fimUtc, $dimensao, ?array $maquinaIds = null) {
    if ($maquinaIds !== null && empty($maquinaIds)) return [];
    $chaveSql = [
        'usuario' => "e.usuario_detectado",
        'setor'   => "COALESCE(ou.nome, NULLIF(m.setor, ''), 'Sem setor')",
        'maquina' => "m.nome",
    ][$dimensao];
    $filtroIds = $maquinaIds !== null ? ' AND e.maquina_id IN (' . implode(',', array_map('intval', $maquinaIds)) . ')' : '';
    $filtroUsuario = $dimensao === 'usuario' ? " AND e.usuario_detectado IS NOT NULL AND e.usuario_detectado <> ''" : '';
    $stmt = $db->prepare("SELECT {$chaveSql} AS chave, m.nome AS maquina,
            SUM(e.duracao) AS total,
            SUM(CASE WHEN c.pontuacao = 1 THEN e.duracao ELSE 0 END) AS produtivo,
            SUM(CASE WHEN c.pontuacao = -1 THEN e.duracao ELSE 0 END) AS improdutivo
        FROM eventos e
        JOIN maquinas m ON m.id = e.maquina_id
        LEFT JOIN ad_ous ou ON ou.id = m.ou_id
        LEFT JOIN categorias c ON c.id = e.categoria_id
        WHERE e.tipo IN ('window','web') AND e.ts BETWEEN :ini AND :fim {$filtroIds} {$filtroUsuario} " . filtroHorarioComercialSql() . " " . filtroNaoBloqueadoSql() . "
        GROUP BY chave, m.nome");
    $stmt->execute([':ini' => $iniUtc, ':fim' => $fimUtc]);
    $dados = [];
    foreach ($stmt->fetchAll() as $r) {
        $k = $r['chave'];
        if ($k === null || $k === '') continue;
        if (!isset($dados[$k])) $dados[$k] = ['total' => 0.0, 'produtivo' => 0.0, 'improdutivo' => 0.0, 'maquinas' => []];
        $dados[$k]['total'] += (float)$r['total'];
        $dados[$k]['produtivo'] += (float)$r['produtivo'];
        $dados[$k]['improdutivo'] += (float)$r['improdutivo'];
        $dados[$k]['maquinas'][$r['maquina']] = true;
    }
    foreach ($dados as &$d) $d['maquinas'] = array_keys($d['maquinas']);
    unset($d);
    return $dados;
}

function formatarPctRanking($v) { return number_format($v, 1, ',', '.') . '%'; }

// Um card "Ranking por atividade produtiva/improdutiva": duas abas (Colaboradores + Departamentos
// ou Máquinas), ordenado pelo % do tempo monitorado, com variação (em pontos percentuais) contra o
// período anterior de mesma duração. Paginado no cliente, 5 por página.
// $abas: [['rotulo' => ..., 'itens' => [['nome','sub','pct','delta'], ...]], ...]
function renderCardRanking($titulo, $chaveMetrica, array $abas, $uid) {
    $altaEhBoa = $chaveMetrica === 'produtivo';
    ?>
    <div class="rank-card" data-rank="<?= $uid ?>">
        <h3 class="rank-title"><?= htmlspecialchars($titulo) ?></h3>
        <div class="rank-tabs">
            <?php foreach ($abas as $i => $aba): ?>
                <button type="button" class="rank-tab <?= $i === 0 ? 'active' : '' ?>" data-tab="<?= $i ?>"><?= htmlspecialchars($aba['rotulo']) ?></button>
            <?php endforeach; ?>
        </div>
        <?php foreach ($abas as $i => $aba): ?>
        <div class="rank-pane" data-pane="<?= $i ?>" <?= $i === 0 ? '' : 'hidden' ?>>
            <div class="rank-head"><span><?= htmlspecialchars($aba['rotulo'] === 'Colaboradores' ? 'Colaborador' : rtrim($aba['rotulo'], 's')) ?></span><span>Valor <i class="bi bi-arrow-down"></i></span></div>
            <?php if (empty($aba['itens'])): ?>
                <div class="rank-empty">Sem dados no período.</div>
            <?php else: foreach ($aba['itens'] as $it):
                $delta = $it['delta'];
                $bom = $delta === null ? null : (($delta >= 0) === $altaEhBoa);
                $iniciais = mb_strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N}]/u', '', $it['nome']), 0, 2));
                ?>
                <div class="rank-row">
                    <span class="rank-avatar" style="background: <?= htmlspecialchars(corPorTexto($it['nome'])) ?>"><?= htmlspecialchars($iniciais) ?></span>
                    <span class="rank-info"><span class="rank-name"><?= htmlspecialchars($it['nome']) ?></span><span class="rank-sub"><?= htmlspecialchars($it['sub']) ?></span></span>
                    <span class="rank-val"><?= formatarPctRanking($it['pct']) ?></span>
                    <?php if ($delta === null): ?><span class="rank-delta">—</span>
                    <?php else: ?><span class="rank-delta <?= $bom ? 'bom' : 'ruim' ?>"><?= ($delta >= 0 ? '+' : '−') . number_format(abs($delta), 1, ',', '.') ?>%</span><?php endif; ?>
                </div>
            <?php endforeach; endif; ?>
            <div class="rank-pager"></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
}

// Ranking de eventos TOP 10: aplicativos (janela ativa) e sites (navegador) com mais tempo no período.
function renderCardRankingEventos(PDO $db, $iniUtc, $fimUtc, ?array $maquinaIds, $linkVerTodos) {
    $filtroIds = ($maquinaIds !== null && !empty($maquinaIds)) ? ' AND e.maquina_id IN (' . implode(',', array_map('intval', $maquinaIds)) . ')' : '';
    $stmt = $db->prepare("SELECT e.tipo,
            CASE WHEN e.tipo = 'web' THEN SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(e.url, '://', -1), '/', 1), '?', 1) ELSE e.app END AS item,
            COALESCE(c.nome, 'Sem categoria') AS categoria, SUM(e.duracao) AS total
        FROM eventos e LEFT JOIN categorias c ON c.id = e.categoria_id
        WHERE e.tipo IN ('window','web') AND e.ts BETWEEN :ini AND :fim {$filtroIds} " . filtroHorarioComercialSql() . " " . filtroNaoBloqueadoSql() . "
        GROUP BY e.tipo, item, categoria
        HAVING item IS NOT NULL AND item <> ''");
    $stmt->execute([':ini' => $iniUtc, ':fim' => $fimUtc]);
    $itens = [];
    foreach ($stmt->fetchAll() as $r) {
        $nome = $r['tipo'] === 'web' ? preg_replace('/^www\./', '', $r['item']) : nomeAmigavelApp($r['item']);
        $k = $r['tipo'] . '|' . $nome;
        if (!isset($itens[$k])) $itens[$k] = ['nome' => $nome, 'tipo' => $r['tipo'], 'total' => 0.0, 'cats' => []];
        $itens[$k]['total'] += (float)$r['total'];
        $itens[$k]['cats'][$r['categoria']] = ($itens[$k]['cats'][$r['categoria']] ?? 0) + (float)$r['total'];
    }
    uasort($itens, fn($a, $b) => $b['total'] <=> $a['total']);
    $itens = array_slice($itens, 0, 10);
    ?>
    <div class="rank-card">
        <div class="rank-title-row">
            <h3 class="rank-title">Ranking de eventos <span class="rank-top">TOP 10</span></h3>
            <a href="<?= htmlspecialchars($linkVerTodos) ?>" class="rank-all">Ver todos</a>
        </div>
        <div class="rank-pane" style="border-top: 1px solid var(--line); border-radius: 8px;">
            <div class="rank-head"><span>Evento</span><span>Valor <i class="bi bi-arrow-down"></i></span></div>
            <?php if (empty($itens)): ?>
                <div class="rank-empty">Sem dados no período.</div>
            <?php else: foreach ($itens as $it): arsort($it['cats']); $cat = array_key_first($it['cats']); ?>
                <div class="rank-row">
                    <span class="rank-info"><span class="rank-name"><?= htmlspecialchars($it['nome']) ?></span><span class="rank-sub"><?= htmlspecialchars($cat) ?> · <?= $it['tipo'] === 'web' ? 'Site' : 'Aplicativo' ?></span></span>
                    <span class="rank-val"><?= formatarDuracao($it['total']) ?></span>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
    <?php
}

// Mapa de calor de produtividade: uma linha por dia do período, uma célula por hora (horário de
// Brasília), colorida pelo % do tempo monitorado daquela hora que foi produtivo. Mesmo recorte de
// horário comercial/bloqueio dos KPIs. $maquinaIds vazio/null = todas as máquinas.
function renderMapaCalorProdutividade(PDO $db, DateTime $inicioLocal, DateTime $fimLocal, ?array $maquinaIds = null, $usuarioFiltro = null) {
    $tzBr = new DateTimeZone('America/Sao_Paulo');
    $ini = (clone $inicioLocal)->setTimezone($tzBr)->setTime(0, 0, 0);
    $fim = (clone $fimLocal)->setTimezone($tzBr)->setTime(0, 0, 0);
    $maxDias = 62;
    if ($ini->diff($fim)->days + 1 > $maxDias) $ini = (clone $fim)->modify('-' . ($maxDias - 1) . ' days');
    $iniUtc = (clone $ini)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $fimUtc = (clone $fim)->setTime(23, 59, 59)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $filtroIds = !empty($maquinaIds) ? ' AND e.maquina_id IN (' . implode(',', array_map('intval', $maquinaIds)) . ')' : '';
    $paramsMapa = [];
    if ($usuarioFiltro !== null && $usuarioFiltro !== '') {
        $filtroIds .= ' AND e.usuario_detectado = :usuario_mapa';
        $paramsMapa[':usuario_mapa'] = $usuarioFiltro;
    }

    $stmt = $db->prepare("SELECT DATE(CONVERT_TZ(e.ts, '+00:00', '-03:00')) AS dia,
            HOUR(CONVERT_TZ(e.ts, '+00:00', '-03:00')) AS hora,
            SUM(e.duracao) AS total,
            SUM(CASE WHEN c.pontuacao = 1 THEN e.duracao ELSE 0 END) AS produtivo
        FROM eventos e LEFT JOIN categorias c ON c.id = e.categoria_id
        WHERE e.tipo IN ('window','web') AND e.ts BETWEEN :ini AND :fim {$filtroIds} " . filtroHorarioComercialSql() . " " . filtroNaoBloqueadoSql() . "
        GROUP BY dia, hora");
    $stmt->execute([':ini' => $iniUtc, ':fim' => $fimUtc] + $paramsMapa);
    $celulas = [];
    foreach ($stmt->fetchAll() as $r) {
        $celulas[$r['dia']][(int)$r['hora']] = ['total' => (float)$r['total'], 'produtivo' => (float)$r['produtivo']];
    }

    $meses = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    $nivel = function ($pct) {
        if ($pct === null || $pct <= 0) return 0;   // sem atividade produtiva
        if ($pct >= 75) return 5;                   // muito alta
        if ($pct >= 55) return 4;                   // alta
        if ($pct >= 35) return 3;                   // média
        if ($pct >= 15) return 2;                   // baixa
        return 1;                                   // muito baixa
    };
    $nomes = [5 => 'Muito alta', 4 => 'Alta', 3 => 'Média', 2 => 'Baixa', 1 => 'Muito baixa', 0 => 'Sem atividade produtiva'];

    $linhas = [];
    for ($d = clone $ini; $d <= $fim; $d->modify('+1 day')) {
        $linhas[] = ['chave' => $d->format('Y-m-d'), 'rotulo' => (int)$d->format('j') . ' de ' . $meses[(int)$d->format('n') - 1]];
    }

    // Período com mais de um dia: em vez de uma linha por dia, uma única linha com a MÉDIA por hora
    // sobre os dias de produção efetiva — dias sem nenhum tempo monitorado (fins de semana,
    // feriados, máquinas desligadas) ficam FORA do divisor, em vez de entrarem como zero e
    // puxarem a média pra baixo. Dentro de um dia efetivo, hora sem dados conta como 0%.
    $mediaDias = null;
    if (count($linhas) > 1) {
        $efetivos = [];
        foreach ($linhas as $l) {
            $somaDia = 0.0;
            foreach ($celulas[$l['chave']] ?? [] as $v) $somaDia += $v['total'];
            if ($somaDia > 0) $efetivos[] = $l['chave'];
        }
        $nEfetivos = count($efetivos);
        $mediaCelulas = [];
        for ($h = 0; $h < 24; $h++) {
            $somaPct = 0.0; $somaProd = 0.0; $somaTot = 0.0;
            foreach ($efetivos as $dia) {
                $v = $celulas[$dia][$h] ?? null;
                if ($v && $v['total'] > 0) {
                    $somaPct += $v['produtivo'] / $v['total'] * 100;
                    $somaProd += $v['produtivo'];
                    $somaTot += $v['total'];
                }
            }
            if ($nEfetivos > 0 && $somaTot > 0) {
                $mediaCelulas[$h] = ['total' => $somaTot / $nEfetivos, 'produtivo' => $somaProd / $nEfetivos, 'pct' => $somaPct / $nEfetivos];
            }
        }
        $mediaDias = ['efetivos' => $nEfetivos, 'ignorados' => count($linhas) - $nEfetivos];
        $linhas = [['chave' => 'media', 'rotulo' => 'Média do período']];
        $celulas = ['media' => $mediaCelulas];
    }

    $melhor = null;
    foreach ($celulas[$linhas[0]['chave']] ?? [] as $h => $v) {
        if ($v['total'] <= 0) continue;
        $pct = $v['pct'] ?? ($v['produtivo'] / $v['total'] * 100);
        if ($pct > 0 && ($melhor === null || $pct > $melhor['pct'])) $melhor = ['pct' => $pct, 'hora' => $h];
    }
    ?>
    <div class="card hm-card">
        <div class="card-header">Mapa de calor de produtividade</div>
        <div class="card-body">
            <div class="hm-legend">
                <?php foreach ([5, 4, 3, 2, 1, 0] as $n): ?>
                    <span><i class="hm-dot hm-<?= $n ?>"></i><?= $nomes[$n] ?></span>
                <?php endforeach; ?>
            </div>
            <div class="hm-scroll">
                <?php foreach ($linhas as $l): ?>
                <div class="hm-row">
                    <div class="hm-day"><?= htmlspecialchars($l['rotulo']) ?></div>
                    <div class="hm-cells">
                        <?php for ($h = 0; $h < 24; $h++):
                            $v = $celulas[$l['chave']][$h] ?? null;
                            $pct = ($v && $v['total'] > 0) ? ($v['pct'] ?? $v['produtivo'] / $v['total'] * 100) : null;
                            $n = $nivel($pct);
                            $faixa = sprintf('%02d:00–%02d:00', $h, $h + 1);
                            $tip = $l['rotulo'] . ' · ' . $faixa . ' · ' . ($pct === null ? 'sem dados'
                                : formatarPctRanking($pct) . ' produtivo (' . formatarDuracao($v['produtivo']) . ' de ' . formatarDuracao($v['total']) . ($mediaDias ? ' por dia útil, em média' : '') . ')');
                            ?>
                            <div class="hm-cell hm-<?= $n ?>" title="<?= htmlspecialchars($tip) ?>"><span><?= sprintf('%02d:00', $h) ?></span><span><?= sprintf('%02d:00', $h + 1) ?></span></div>
                        <?php endfor; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="hm-best">
                <?php if ($melhor): ?>
                    Hora mais produtiva<?= $mediaDias ? ' (média)' : '' ?>: <strong><?= sprintf('%02d:00 – %02d:00', $melhor['hora'], $melhor['hora'] + 1) ?> — <?= formatarPctRanking($melhor['pct']) ?></strong>
                <?php else: ?>
                    Sem atividade produtiva no período.
                <?php endif; ?>
                <?php if ($mediaDias): ?>
                    <div class="text-muted mt-1">Média sobre <?= (int)$mediaDias['efetivos'] ?> dia(s) com atividade; <?= (int)$mediaDias['ignorados'] ?> dia(s) sem atividade (fins de semana, feriados) desconsiderado(s) do cálculo.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

// Linha com os 3 cards. $maquinaIds = null na Visão Geral (todas as máquinas); lista de ids na
// visão Máquinas por Setor (só as do setor, e a 2ª aba vira "Máquinas" em vez de "Departamentos").
function renderRankings(PDO $db, $iniUtc, $fimUtc, ?array $maquinaIds = null) {
    $ini = new DateTime($iniUtc, new DateTimeZone('UTC'));
    $fim = new DateTime($fimUtc, new DateTimeZone('UTC'));
    $duracao = $fim->getTimestamp() - $ini->getTimestamp() + 1;
    $antFim = (clone $ini)->modify('-1 second')->format('Y-m-d H:i:s');
    $antIni = (clone $ini)->modify('-' . $duracao . ' seconds')->format('Y-m-d H:i:s');

    $dimensao2 = $maquinaIds === null ? 'setor' : 'maquina';
    $rotulo2 = $maquinaIds === null ? 'Departamentos' : 'Máquinas';

    $atual = ['usuario' => agregarRanking($db, $iniUtc, $fimUtc, 'usuario', $maquinaIds), $dimensao2 => agregarRanking($db, $iniUtc, $fimUtc, $dimensao2, $maquinaIds)];
    $anterior = ['usuario' => agregarRanking($db, $antIni, $antFim, 'usuario', $maquinaIds), $dimensao2 => agregarRanking($db, $antIni, $antFim, $dimensao2, $maquinaIds)];

    $montar = function ($metrica, $dim) use ($atual, $anterior) {
        $itens = [];
        foreach ($atual[$dim] as $nome => $d) {
            if ($d['total'] <= 0) continue;
            $pct = $d[$metrica] / $d['total'] * 100;
            $ant = $anterior[$dim][$nome] ?? null;
            $delta = ($ant && $ant['total'] > 0) ? $pct - ($ant[$metrica] / $ant['total'] * 100) : null;
            if ($dim === 'usuario') {
                $partes = explode('\\', $nome);
                $exibir = end($partes);
                $sub = count($partes) > 1 ? $partes[0] . ' · ' . implode(', ', $d['maquinas']) : implode(', ', $d['maquinas']);
            } elseif ($dim === 'setor') {
                $exibir = $nome;
                $sub = count($d['maquinas']) . ' máquina(s)';
            } else {
                $exibir = $nome;
                $sub = 'Máquina';
            }
            $itens[] = ['nome' => $exibir, 'sub' => $sub, 'pct' => $pct, 'delta' => $delta];
        }
        usort($itens, fn($a, $b) => $b['pct'] <=> $a['pct']);
        return $itens;
    };

    $uid = 'rk' . substr(md5(uniqid('', true)), 0, 6);
    $tzBr = new DateTimeZone('America/Sao_Paulo');
    $ver = 'index.php?' . http_build_query(array_filter([
        'page' => 'eventos', 'grupo' => 'monitorado',
        'data_ini' => (clone $ini)->setTimezone($tzBr)->format('Y-m-d'),
        'data_fim' => (clone $fim)->setTimezone($tzBr)->format('Y-m-d'),
        'maquina_ids' => $maquinaIds ? implode(',', $maquinaIds) : null,
    ]));
    ?>
    <div class="rank-grid" id="<?= $uid ?>">
        <?php
        renderCardRanking('Ranking por atividade produtiva', 'produtivo', [
            ['rotulo' => 'Colaboradores', 'itens' => $montar('produtivo', 'usuario')],
            ['rotulo' => $rotulo2, 'itens' => $montar('produtivo', $dimensao2)],
        ], $uid . 'a');
        renderCardRanking('Ranking por atividade improdutiva', 'improdutivo', [
            ['rotulo' => 'Colaboradores', 'itens' => $montar('improdutivo', 'usuario')],
            ['rotulo' => $rotulo2, 'itens' => $montar('improdutivo', $dimensao2)],
        ], $uid . 'b');
        renderCardRankingEventos($db, $iniUtc, $fimUtc, $maquinaIds, $ver);
        ?>
    </div>
    <script>
    (function () {
        var POR_PAGINA = 10;
        document.querySelectorAll('#<?= $uid ?> [data-rank]').forEach(function (card) {
            card.querySelectorAll('.rank-pane').forEach(function (pane) {
                var linhas = pane.querySelectorAll('.rank-row');
                var pager = pane.querySelector('.rank-pager');
                var total = Math.max(1, Math.ceil(linhas.length / POR_PAGINA));
                function ir(p) {
                    p = Math.min(Math.max(1, p), total);
                    linhas.forEach(function (l, i) { l.hidden = Math.floor(i / POR_PAGINA) + 1 !== p; });
                    pager.innerHTML = '';
                    if (total <= 1) return;
                    function botao(txt, alvo, ativo, desab) {
                        var b = document.createElement('button');
                        b.type = 'button'; b.textContent = txt;
                        if (ativo) b.className = 'active';
                        b.disabled = !!desab;
                        b.addEventListener('click', function () { ir(alvo); });
                        pager.appendChild(b);
                    }
                    botao('«', 1, false, p === 1);
                    botao('‹', p - 1, false, p === 1);
                    for (var n = Math.max(1, p - 2); n <= Math.min(total, p + 2); n++) botao(String(n), n, n === p, false);
                    botao('›', p + 1, false, p === total);
                    botao('»', total, false, p === total);
                }
                ir(1);
            });
            card.querySelectorAll('.rank-tab').forEach(function (tab) {
                tab.addEventListener('click', function () {
                    card.querySelectorAll('.rank-tab').forEach(function (t) { t.classList.toggle('active', t === tab); });
                    card.querySelectorAll('.rank-pane').forEach(function (p) { p.hidden = p.getAttribute('data-pane') !== tab.getAttribute('data-tab'); });
                });
            });
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
    <li class="nav-item"><a class="nav-link <?= $visao === 'setor' ? 'active' : '' ?>" href="<?= qs(['visao' => 'setor', 'maquina_id' => null]) ?>">Setores</a></li>
    <li class="nav-item"><a class="nav-link <?= in_array($visao, ['maquinas_setor', 'maquina'], true) ? 'active' : '' ?>" href="<?= qs(['visao' => 'maquinas_setor', 'maquina_id' => null]) ?>">Máquinas por Setor</a></li>
    <li class="nav-item"><a class="nav-link <?= in_array($visao, ['usuarios', 'usuario_detalhe'], true) ? 'active' : '' ?>" href="<?= qs(['visao' => 'usuarios', 'usuario' => null]) ?>">Usuários</a></li>
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

    <?php renderPainelProdutividade($db, $inicioUtc, $fimUtc, $inicioLocal, $fimLocal, [], false, $tendenciaDiasForcado, null, null, true); ?>

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
    // Setor selecionado no Comparativo (clique na linha): os cards de ranking abaixo passam a
    // mostrar só as máquinas dele. Só vale se o nome existir na lista atual.
    $setorSel = trim($_GET['setor_sel'] ?? '');
    if ($setorSel !== '' && !in_array($setorSel, array_column($porSetor, 'setor'), true)) $setorSel = '';
    $idsSetorSel = null;
    if ($setorSel !== '') {
        $stmt = $db->prepare("SELECT m.id FROM maquinas m LEFT JOIN ad_ous ou ON ou.id = m.ou_id
            WHERE COALESCE(ou.nome, NULLIF(m.setor, ''), 'Sem setor') = :s");
        $stmt->execute([':s' => $setorSel]);
        $idsSetorSel = array_map('intval', array_column($stmt->fetchAll(), 'id'));
    }

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
                    <?php foreach ($porSetor as $s): $pct = $s['total'] > 0 ? round($s['produtivo'] / $s['total'] * 100) : 0;
                        $selecionado = $setorSel === $s['setor'];
                        $hrefSel = qs(['setor_sel' => $selecionado ? null : $s['setor']]); ?>
                    <tr class="<?= $selecionado ? 'table-success' : '' ?>" style="cursor: pointer" title="<?= $selecionado ? 'Clique para limpar a seleção' : 'Clique para ver os rankings deste setor' ?>"
                        onclick="if (!event.target.closest('a')) location.href = this.dataset.href" data-href="<?= $hrefSel ?>">
                        <td><?= $selecionado ? '<i class="bi bi-check-circle-fill text-success me-1"></i>' : '' ?><?= htmlspecialchars($s['setor']) ?></td>
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
        <div class="col-12">
            <div class="card mt-3">
                <div class="card-header">Produtividade por Setor</div>
                <div class="card-body">
                    <?php renderHexGrid($produtividadePorSetor, fn($setorNome) => qs(['visao' => 'maquinas_setor', 'setor' => $setorNome === 'Sem setor' ? '' : $setorNome])); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-3"><?php renderMapaCalorProdutividade($db, $inicioLocal, $fimLocal, $idsSetorSel); ?></div>

    <div class="d-flex align-items-center gap-2 mt-3 text-muted small">
        <?php if ($setorSel !== ''): ?>
            Rankings do setor <strong><?= htmlspecialchars($setorSel) ?></strong>
            <a href="<?= qs(['setor_sel' => null]) ?>" class="btn btn-sm btn-outline-secondary py-0">Ver todos os setores</a>
        <?php else: ?>
            Rankings de todos os setores — clique numa linha do Comparativo para filtrar por setor.
        <?php endif; ?>
    </div>
    <?php renderRankings($db, $inicioUtc, $fimUtc, $idsSetorSel); ?>

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
            renderTabelaUsuariosMaquina($db, $inicioUtc, $fimUtc, $maquinaAtual['id']);
            renderPainelProdutividade($db, $inicioUtc, $fimUtc, $inicioLocal, $fimLocal, [$maquinaAtual['id']], true, $tendenciaDiasForcado, null,
                fn() => renderLinhaDoTempo($db, $inicioLocal, $fimLocal, $maquinaAtual['id']), true);
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
        renderTabelaUsuariosMaquina($db, $inicioUtc, $fimUtc, $maquinaAtual['id']);
        renderPainelProdutividade($db, $inicioUtc, $fimUtc, $inicioLocal, $fimLocal, [$maquinaAtual['id']], true, $tendenciaDiasForcado, null,
            fn() => renderLinhaDoTempo($db, $inicioLocal, $fimLocal, $maquinaAtual['id']));
    }

elseif ($visao === 'usuarios'):
    // Comparativo entre usuários do Windows detectados nas máquinas (aw-watcher-currentuser) —
    // mesma estrutura da Visão Setores, mas agrupando por pessoa em vez de setor. Um usuário que
    // loga em mais de uma máquina aparece uma única vez aqui, com o tempo somado de todas elas.
    // eventos.usuario_detectado já vem pré-calculado da sincronização (ver comentário em
    // renderPainelProdutividade sobre por que isso não é cruzado em tempo de consulta).
    $stmt = $db->prepare("SELECT e.usuario_detectado AS usuario,
               COUNT(DISTINCT e.maquina_id) AS num_maquinas,
               SUM(e.duracao) AS total,
               SUM(CASE WHEN c.pontuacao = 1 THEN e.duracao ELSE 0 END) AS produtivo,
               SUM(CASE WHEN c.pontuacao = -1 THEN e.duracao ELSE 0 END) AS improdutivo
        FROM eventos e
        LEFT JOIN categorias c ON c.id = e.categoria_id
        WHERE e.tipo IN ('window','web') AND e.ts BETWEEN :ini AND :fim
              AND e.usuario_detectado IS NOT NULL AND e.usuario_detectado <> '' " . filtroHorarioComercialSql() . " " . filtroNaoBloqueadoSql() . "
        GROUP BY e.usuario_detectado
        ORDER BY total DESC");
    $stmt->execute([':ini' => $inicioUtc, ':fim' => $fimUtc]);
    $porUsuario = $stmt->fetchAll();

    $produtividadePorUsuario = array_map(fn($u) => [
        'id' => $u['usuario'],
        'nome' => $u['usuario'],
        'total' => $u['total'],
        'produtivo' => $u['produtivo'],
    ], $porUsuario);
    ?>

    <div class="card">
        <div class="card-header">Comparativo entre Usuários</div>
        <div class="table-responsive">
            <table class="table table-bordered bg-white align-middle mb-0">
                <thead class="table-dark"><tr><th>Usuário</th><th>Máquinas</th><th>Tempo Total</th><th>Produtivo</th><th>Improdutivo</th><th>% Produtivo</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($porUsuario as $u): $pct = $u['total'] > 0 ? round($u['produtivo'] / $u['total'] * 100) : 0; ?>
                    <tr>
                        <td><?= htmlspecialchars($u['usuario']) ?></td>
                        <td><?= (int)$u['num_maquinas'] ?></td>
                        <td><?= formatarDuracao($u['total'] ?: 0) ?></td>
                        <td class="online-c"><?= formatarDuracao($u['produtivo'] ?: 0) ?></td>
                        <td class="critical-c"><?= formatarDuracao($u['improdutivo'] ?: 0) ?></td>
                        <td><?= $u['total'] > 0 ? $pct . '%' : '—' ?></td>
                        <td><a class="btn btn-sm btn-outline-primary" href="<?= qs(['visao' => 'usuario_detalhe', 'usuario' => $u['usuario']]) ?>">Ver detalhes</a></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($porUsuario)): ?><tr><td colspan="7" class="text-center text-muted py-3">Nenhum usuário detectado ainda — instale o agente aw-watcher-currentuser nas máquinas em <a href="index.php?page=maquinas">Máquinas</a>.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-6">
            <div class="card mt-3">
                <div class="card-header">Produtividade por Usuário</div>
                <div class="card-body">
                    <?php renderHexGrid($produtividadePorUsuario, fn($usuarioNome) => qs(['visao' => 'usuario_detalhe', 'usuario' => $usuarioNome])); ?>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($visao === 'usuario_detalhe'):
    $usuarioSelecionado = trim($_GET['usuario'] ?? '');

    if ($usuarioSelecionado === '') {
        echo '<div class="alert alert-info">Selecione um usuário na aba <a href="' . qs(['visao' => 'usuarios']) . '">Usuários</a>.</div>';
    } else {
        // Máquinas onde este usuário foi detectado no período — só para o card de identificação
        // abaixo (o painel de KPIs/gráficos em si não fica restrito a nenhuma máquina específica).
        $stmt = $db->prepare("SELECT DISTINCT m.nome
                               FROM eventos e JOIN maquinas m ON m.id = e.maquina_id
                               WHERE e.tipo IN ('window','web') AND e.usuario_detectado = :u
                                 AND e.ts BETWEEN :ini AND :fim
                               ORDER BY m.nome ASC");
        $stmt->execute([':u' => $usuarioSelecionado, ':ini' => $inicioUtc, ':fim' => $fimUtc]);
        $maquinasDoUsuario = array_column($stmt->fetchAll(), 'nome');
        ?>
        <div class="card mb-3">
            <div class="card-body d-flex flex-wrap align-items-center gap-3">
                <div class="flex-grow-1">
                    <h2 class="h5 mb-0"><?= htmlspecialchars($usuarioSelecionado) ?></h2>
                    <div class="text-muted small mt-1">
                        <?= $maquinasDoUsuario ? 'Máquinas no período: ' . htmlspecialchars(implode(', ', $maquinasDoUsuario)) : 'Nenhuma máquina no período selecionado.' ?>
                    </div>
                </div>
                <a href="<?= qs(['visao' => 'usuarios', 'usuario' => null]) ?>" class="btn btn-outline-secondary btn-sm">← Voltar</a>
            </div>
        </div>
        <?php
        renderPainelProdutividade($db, $inicioUtc, $fimUtc, $inicioLocal, $fimLocal, [], true, $tendenciaDiasForcado, $usuarioSelecionado,
            fn() => renderLinhaDoTempo($db, $inicioLocal, $fimLocal, null, $usuarioSelecionado), true);
    }
endif; ?>
