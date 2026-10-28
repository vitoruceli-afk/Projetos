<?php
// Classificação por IA — configuração, fila de revisão, classificações aprovadas (alterar/excluir), avaliação e monitoramento.
// A IA só sugere; nada vira classificação sem a aprovação do administrador (modo "revisão") ou sem passar nas travas do modo "auto".
requireAdmin();
$db = getDB();
$usuarioAtual = $_SESSION['user_logged_in'] ?? 'admin';
$aba = in_array($_GET['aba'] ?? '', ['visao', 'revisao', 'aprovadas', 'avaliacao', 'config'], true) ? $_GET['aba'] : 'visao';

function iaRedirecionar($aba, $msg, $tipo = 'success') {
    $_SESSION['ia_flash'] = [$tipo, $msg];
    header('Location: index.php?page=ia&aba=' . $aba);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrfVerify();
    $acao = $_POST['action'];
    $cfg = iaConfig($db);
    try {
        if ($acao === 'salvar_config') {
            $modo = in_array($_POST['modo'] ?? '', IA_MODOS, true) ? $_POST['modo'] : 'desligado';
            $url = trim($_POST['ollama_url'] ?? '');
            if (!preg_match('#^https?://[A-Za-z0-9.\-]+(:\d+)?$#', $url)) iaRedirecionar('config', 'Endereço do serviço inválido (use http://host:porta).', 'danger');
            $db->prepare("UPDATE ia_config SET modo = :m, ollama_url = :u, modelo = :mod, timeout_s = :t, max_tentativas = :mt, lote_por_execucao = :l,
                          limite_segundos = :ls, enviar_titulo = :et, dias_descoberta = :dd, politica = :pol WHERE id = 1")
               ->execute([':m' => $modo, ':u' => $url, ':mod' => mb_substr(trim($_POST['modelo'] ?? 'qwen3:4b'), 0, 80),
                          ':t' => max(5, min(600, (int)($_POST['timeout_s'] ?? 90))), ':mt' => max(1, min(10, (int)($_POST['max_tentativas'] ?? 3))),
                          ':l' => max(1, min(50, (int)($_POST['lote_por_execucao'] ?? 5))), ':ls' => max(10, min(55, (int)($_POST['limite_segundos'] ?? 50))),
                          ':et' => !empty($_POST['enviar_titulo']) ? 1 : 0, ':dd' => max(1, min(90, (int)($_POST['dias_descoberta'] ?? 30))),
                          ':pol' => trim($_POST['politica'] ?? '') === '' || trim($_POST['politica']) === iaPoliticaPadrao() ? null : mb_substr(trim($_POST['politica']), 0, 3000)]);
            iaRedirecionar('config', 'Configuração salva (modo: ' . $modo . ').');
        } elseif ($acao === 'testar') {
            $st = iaStatusServico($cfg);
            iaRedirecionar('config', ($st['ok'] ? 'Conexão OK. ' : 'Falha: ') . $st['mensagem'] . (!empty($st['modelos']) ? ' Modelos: ' . implode(', ', $st['modelos']) . '.' : ''), $st['ok'] ? 'success' : 'danger');
        } elseif ($acao === 'executar_agora') {
            if ($cfg['modo'] === 'desligado') iaRedirecionar('visao', 'A IA está desligada (modo "desligado"). Ligue em Configuração.', 'warning');
            set_time_limit(180);
            $novas = iaDescobrirChaves($db, $cfg, true);
            $res = iaProcessarLote($db, $cfg);
            iaRedirecionar('visao', "Ciclo executado: {$novas} chave(s) nova(s) na fila, " . count($res) . ' processada(s).');
        } elseif ($acao === 'aprovar') {
            $n = iaAplicarSugestao($db, (int)($_POST['id'] ?? 0), (int)($_POST['categoria_id'] ?? 0), $usuarioAtual, $cfg);
            iaRedirecionar('revisao', "Classificação aprovada e gravada como regra. {$n} evento(s) sem categoria foram classificados.");
        } elseif ($acao === 'rejeitar') {
            iaRejeitarSugestao($db, (int)($_POST['id'] ?? 0), $usuarioAtual);
            iaRedirecionar('revisao', 'Sugestão rejeitada. A chave não volta para a fila.');
        } elseif ($acao === 'alterar') {
            $n = iaAlterarClassificacao($db, (int)($_POST['id'] ?? 0), (int)($_POST['categoria_id'] ?? 0), $usuarioAtual);
            iaRedirecionar('aprovadas', "Categoria alterada. {$n} evento(s) acompanharam a mudança.");
        } elseif ($acao === 'excluir') {
            $n = iaExcluirClassificacao($db, (int)($_POST['id'] ?? 0), $usuarioAtual);
            iaRedirecionar('aprovadas', "Classificação excluída. {$n} evento(s) voltaram ao que as demais regras decidem.");
        } elseif (in_array($acao, ['lote_aprovar', 'lote_aprovar_cat', 'lote_rejeitar', 'lote_alterar', 'lote_excluir'], true)) {
            set_time_limit(600);
            $ids = $_POST['ids'] ?? [];
            $catLote = (int)($_POST['categoria_lote'] ?? 0);
            if ($acao === 'lote_aprovar') { $r = iaLoteAprovar($db, $ids, $usuarioAtual, $cfg); $aba = 'revisao'; $verbo = 'aprovada(s)'; }
            elseif ($acao === 'lote_aprovar_cat') { if ($catLote <= 0) throw new RuntimeException('Escolha a categoria.'); $r = iaLoteAprovar($db, $ids, $usuarioAtual, $cfg, $catLote); $aba = 'revisao'; $verbo = 'aprovada(s)'; }
            elseif ($acao === 'lote_rejeitar') { $r = iaLoteRejeitar($db, $ids, $usuarioAtual); $aba = 'revisao'; $verbo = 'rejeitada(s)'; }
            elseif ($acao === 'lote_alterar') { if ($catLote <= 0) throw new RuntimeException('Escolha a categoria.'); $r = iaLoteAlterar($db, $ids, $catLote, $usuarioAtual); $aba = 'aprovadas'; $verbo = 'alterada(s)'; }
            else { $r = iaLoteExcluir($db, $ids, $usuarioAtual); $aba = 'aprovadas'; $verbo = 'excluída(s)'; }
            $msg = "{$r['ok']} {$verbo}" . ($r['eventos'] ? ", {$r['eventos']} evento(s) atualizado(s)" : '') . '.';
            if ($r['falhas']) {
                $msg .= ' ' . count($r['falhas']) . ' não processada(s): ' . implode('; ', array_map(fn($x) => "{$x[0]} ({$x[1]})", array_slice($r['falhas'], 0, 3))) . (count($r['falhas']) > 3 ? '…' : '');
            }
            iaRedirecionar($aba, $msg, $r['falhas'] ? ($r['ok'] ? 'warning' : 'danger') : 'success');
        } elseif ($acao === 'reanalisar') {
            iaRedirecionar('revisao', iaReanalisarSugestoes($db) . ' sugestão(ões) voltaram para a fila e serão refeitas com o prompt/política atuais (decisões já tomadas não são tocadas).');
        } elseif ($acao === 'reprocessar_falhas') {
            iaRedirecionar('visao', iaReprocessarFalhas($db) . ' item(ns) voltaram para a fila.');
        } elseif ($acao === 'iniciar_avaliacao') {
            iaIniciarAvaliacaoEmSegundoPlano(max(1, min(100, (int)($_POST['limite'] ?? 15))));
            iaRedirecionar('avaliacao', 'Avaliação iniciada em segundo plano (cerca de 6 s por chave). Atualize esta página em alguns minutos.');
        }
    } catch (RuntimeException $e) {
        iaRedirecionar($aba, $e->getMessage(), 'danger');
    }
}

$flash = $_SESSION['ia_flash'] ?? null; unset($_SESSION['ia_flash']);
$cfg = iaConfig($db);
$m = iaMetricas($db);
$categorias = $db->query("SELECT id, nome, cor, pontuacao FROM categorias ORDER BY ordem, nome")->fetchAll(PDO::FETCH_ASSOC);
$catPorPont = [];
foreach ($categorias as $c) $catPorPont[(int)$c['pontuacao']] = $catPorPont[(int)$c['pontuacao']] ?? $c;
$catPorId = array_column($categorias, null, 'id');
$rotuloModo = ['desligado' => 'Desligado', 'observacao' => 'Observação (só sugere, não aplica)', 'revisao' => 'Revisão (aplica após aprovação)', 'auto' => 'Automático (aplica o que passa nas travas)'];
$corModo = ['desligado' => 'secondary', 'observacao' => 'info', 'revisao' => 'primary', 'auto' => 'warning'];

function iaBadgeCat($nomeIa) {
    global $catPorPont;
    $p = IA_CATEGORIAS[$nomeIa] ?? null;
    $c = $p !== null ? ($catPorPont[$p] ?? null) : null;
    $cor = $c['cor'] ?? '#94a2b8';
    return '<span class="badge" style="background:' . htmlspecialchars($cor) . '">' . htmlspecialchars($nomeIa) . '</span>';
}
function iaSelectCategoria($categorias, $sel) {
    $h = '<select name="categoria_id" class="form-select form-select-sm" style="min-width:150px">';
    foreach ($categorias as $c) $h .= '<option value="' . (int)$c['id'] . '"' . ((int)$c['id'] === (int)$sel ? ' selected' : '') . '>' . htmlspecialchars($c['nome']) . '</option>';
    return $h . '</select>';
}
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Classificação por IA</h1>
        <div class="page-sub">Sugestões de categoria geradas por um modelo local (Ollama + <?= htmlspecialchars($cfg['modelo']) ?>), sem enviar dados para fora. Nada vira classificação sem a sua aprovação.</div>
    </div>
    <div>
        <span class="badge bg-<?= $corModo[$cfg['modo']] ?? 'secondary' ?> me-2"><?= htmlspecialchars($rotuloModo[$cfg['modo']] ?? $cfg['modo']) ?></span>
    </div>
</div>

<?php if ($flash): ?><div class="alert alert-<?= htmlspecialchars($flash[0]) ?>"><?= htmlspecialchars($flash[1]) ?></div><?php endif; ?>

<ul class="nav nav-tabs mb-3">
    <?php foreach (['visao' => 'Visão geral', 'revisao' => 'Fila de revisão (' . $m['fila_revisao'] . ')', 'aprovadas' => 'Classificações da IA (' . $m['regras_ia'] . ')', 'avaliacao' => 'Avaliação', 'config' => 'Configuração'] as $k => $rot): ?>
        <li class="nav-item"><a class="nav-link <?= $aba === $k ? 'active' : '' ?>" href="index.php?page=ia&aba=<?= $k ?>"><?= htmlspecialchars($rot) ?></a></li>
    <?php endforeach; ?>
</ul>

<?php if ($aba === 'visao'):
    $st = $cfg['modo'] === 'desligado' ? null : iaStatusServico($cfg);
    $erros = $db->query("SELECT id, tipo, valor, estado, tentativas, ultimo_erro, proxima_tentativa FROM ia_sugestoes WHERE estado IN ('erro', 'falha') ORDER BY atualizado_em DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    $logs = $db->query("SELECT l.ts, l.resultado, l.duracao_ms, l.mensagem, s.valor FROM ia_log l LEFT JOIN ia_sugestoes s ON s.id = l.sugestao_id ORDER BY l.id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    $cards = [
        ['Aguardando classificação', $m['aguardando'], 'chaves na fila da IA', 'secondary'],
        ['Na fila de revisão', $m['fila_revisao'], 'sugestões aguardando você', 'primary'],
        ['Regras do administrador', $m['regras_manuais'], 'classificações manuais (regras)', 'success'],
        ['Classificações da IA', $m['regras_ia'], $m['eventos_por_ia'] . ' evento(s) classificados por elas', 'info'],
        ['Rejeitadas', $m['estados']['rejeitada'] ?? 0, 'chaves descartadas por você', 'secondary'],
        ['Falhas de processamento', $m['falhas'] + ($m['estados']['erro'] ?? 0), $m['falhas'] . ' esgotaram as tentativas', $m['falhas'] > 0 ? 'danger' : 'secondary'],
    ]; ?>
    <div class="row g-3 mb-3">
        <?php foreach ($cards as [$tit, $val, $sub, $cor]): ?>
        <div class="col-md-4 col-xl-2"><div class="card h-100"><div class="card-body">
            <div class="text-muted small"><?= htmlspecialchars($tit) ?></div>
            <div class="fs-3 fw-bold text-<?= $cor ?>"><?= (int)$val ?></div>
            <div class="small text-muted"><?= htmlspecialchars($sub) ?></div>
        </div></div></div>
        <?php endforeach; ?>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-lg-6"><div class="card h-100"><div class="card-header">Serviço de IA</div><div class="card-body">
            <?php if ($st === null): ?><div class="text-muted">IA desligada — o serviço não é consultado.</div>
            <?php else: ?><div class="<?= $st['ok'] ? 'text-success' : 'text-danger' ?>"><i class="bi bi-<?= $st['ok'] ? 'check-circle-fill' : 'x-circle-fill' ?>"></i> <?= htmlspecialchars($st['mensagem']) ?></div><?php endif; ?>
            <div class="small text-muted mt-2">Endereço: <?= htmlspecialchars($cfg['ollama_url']) ?> · Modelo: <?= htmlspecialchars($cfg['modelo']) ?></div>
            <div class="small text-muted">Últimas 24 h: <?= $m['exec24h'] ?> execução(ões), <?= $m['erros24h'] ?> erro(s)<?= $m['exec24h'] ? ' (' . round($m['erros24h'] / $m['exec24h'] * 100) . '%)' : '' ?> ·
                tempo médio <?= $m['media_ms'] !== null ? round($m['media_ms'] / 1000, 1) . ' s' : '—' ?> · p95 <?= $m['p95_ms'] !== null ? round($m['p95_ms'] / 1000, 1) . ' s' : '—' ?></div>
            <form method="POST" class="mt-3 d-flex gap-2">
                <?= csrfField() ?>
                <button name="action" value="executar_agora" class="btn btn-outline-primary btn-sm" <?= $cfg['modo'] === 'desligado' ? 'disabled' : '' ?>><i class="bi bi-play-fill"></i> Executar um ciclo agora</button>
                <button name="action" value="reprocessar_falhas" class="btn btn-outline-secondary btn-sm" <?= ($m['falhas'] + ($m['estados']['erro'] ?? 0)) ? '' : 'disabled' ?>><i class="bi bi-arrow-repeat"></i> Reprocessar falhas</button>
            </form>
        </div></div></div>
        <div class="col-lg-6"><div class="card h-100"><div class="card-header">Falhas recentes</div>
            <?php if (!$erros): ?><div class="card-body text-muted">Nenhuma falha pendente.</div><?php else: ?>
            <table class="table table-sm mb-0"><tbody>
                <?php foreach ($erros as $e): ?><tr>
                    <td><span class="badge bg-<?= $e['estado'] === 'falha' ? 'danger' : 'warning' ?>"><?= htmlspecialchars($e['estado']) ?></span></td>
                    <td class="text-truncate" style="max-width:180px" title="<?= htmlspecialchars($e['valor']) ?>"><?= htmlspecialchars($e['valor']) ?></td>
                    <td class="small text-muted"><?= (int)$e['tentativas'] ?>x · <?= htmlspecialchars($e['ultimo_erro'] ?? '') ?></td>
                </tr><?php endforeach; ?>
            </tbody></table><?php endif; ?>
        </div></div>
    </div>
    <div class="card"><div class="card-header">Atividade recente</div>
        <?php if (!$logs): ?><div class="card-body text-muted">Sem atividade registrada ainda.</div><?php else: ?>
        <table class="table table-sm mb-0"><tbody>
            <?php foreach ($logs as $l): ?><tr>
                <td class="small text-muted text-nowrap"><?= htmlspecialchars($l['ts']) ?></td>
                <td><?= htmlspecialchars($l['resultado']) ?></td>
                <td><?= htmlspecialchars($l['valor'] ?? '') ?></td>
                <td class="small text-muted"><?= $l['duracao_ms'] ? round($l['duracao_ms'] / 1000, 1) . ' s' : '' ?> <?= htmlspecialchars($l['mensagem'] ?? '') ?></td>
            </tr><?php endforeach; ?>
        </tbody></table><?php endif; ?>
    </div>

<?php elseif ($aba === 'revisao'):
    $f_q = trim($_GET['q'] ?? '');
    $f_t = in_array($_GET['t'] ?? '', ['dominio', 'app'], true) ? $_GET['t'] : '';
    $f_s = isset(IA_CATEGORIAS[$_GET['s'] ?? '']) ? $_GET['s'] : '';
    $f_r = in_array($_GET['r'] ?? '', ['0', '1'], true) ? $_GET['r'] : '';
    $onde = ["estado IN ('sugerida', 'revisao')"]; $pf = [];
    if ($f_q !== '') { $onde[] = 'valor LIKE :q'; $pf[':q'] = '%' . addcslashes($f_q, '%_\\') . '%'; }
    if ($f_t !== '') { $onde[] = 'tipo = :t'; $pf[':t'] = $f_t; }
    if ($f_s !== '') { $onde[] = 'categoria_sugerida = :s'; $pf[':s'] = $f_s; }
    if ($f_r !== '') { $onde[] = 'revisao = :r'; $pf[':r'] = (int)$f_r; }
    $cnt = $db->prepare('SELECT COUNT(*) FROM ia_sugestoes WHERE ' . implode(' AND ', $onde)); $cnt->execute($pf);
    $totalFila = (int)$cnt->fetchColumn();
    $st = $db->prepare('SELECT * FROM ia_sugestoes WHERE ' . implode(' AND ', $onde) . ' ORDER BY horas_sem_categoria DESC, id LIMIT ' . IA_LOTE_MAX); $st->execute($pf);
    $fila = $st->fetchAll(PDO::FETCH_ASSOC);
    $podeAprovar = in_array($cfg['modo'], ['revisao', 'auto'], true); ?>
    <?php if (!$podeAprovar): ?><div class="alert alert-info">Modo <strong>observação</strong>: as sugestões aparecem aqui só para análise; aprovar fica desabilitado. Passe para "revisão" em Configuração quando a avaliação mostrar boa concordância.</div><?php endif; ?>
    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-start mb-2">
        <form method="GET" class="d-flex flex-wrap gap-2 align-items-center">
            <input type="hidden" name="page" value="ia"><input type="hidden" name="aba" value="revisao">
            <input name="q" value="<?= htmlspecialchars($f_q) ?>" placeholder="Buscar chave…" class="form-control form-control-sm" style="width:170px">
            <select name="t" class="form-select form-select-sm" style="width:auto"><option value="">Site e aplicativo</option><option value="dominio" <?= $f_t === 'dominio' ? 'selected' : '' ?>>Só sites</option><option value="app" <?= $f_t === 'app' ? 'selected' : '' ?>>Só aplicativos</option></select>
            <select name="s" class="form-select form-select-sm" style="width:auto"><option value="">Qualquer sugestão</option><?php foreach (array_keys(IA_CATEGORIAS) as $k): ?><option value="<?= $k ?>" <?= $f_s === $k ? 'selected' : '' ?>><?= $k ?></option><?php endforeach; ?></select>
            <select name="r" class="form-select form-select-sm" style="width:auto"><option value="">Com ou sem "revisar"</option><option value="0" <?= $f_r === '0' ? 'selected' : '' ?>>Sem "revisar"</option><option value="1" <?= $f_r === '1' ? 'selected' : '' ?>>Só "revisar"</option></select>
            <button class="btn btn-outline-primary btn-sm">Filtrar</button>
            <?php if ($f_q !== '' || $f_t !== '' || $f_s !== '' || $f_r !== ''): ?><a class="btn btn-link btn-sm" href="index.php?page=ia&aba=revisao">limpar</a><?php endif; ?>
        </form>
        <form method="POST" onsubmit="return confirm('Refazer todas as sugestões ainda não decididas com o prompt e a política atuais?')"><?= csrfField() ?>
            <button name="action" value="reanalisar" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-repeat"></i> Reanalisar sugestões</button></form>
    </div>

    <form method="POST" id="formLoteRevisao" class="card card-body py-2 mb-2 d-flex flex-row flex-wrap gap-2 align-items-center">
        <?= csrfField() ?>
        <span class="small fw-semibold"><span class="contagem-ia">0</span> selecionada(s)</span>
        <button name="action" value="lote_aprovar" class="btn btn-success btn-sm btn-lote-ia" <?= $podeAprovar ? '' : 'disabled' ?> data-confirm="Aprovar as selecionadas na categoria que a IA sugeriu? Cada uma vira uma regra e classifica os eventos sem categoria da chave. Itens marcados &quot;revisar&quot; também serão aprovados.">Aprovar com a sugestão da IA</button>
        <span class="small text-muted">ou</span>
        <?= str_replace('name="categoria_id"', 'name="categoria_lote"', iaSelectCategoria($categorias, $catPorPont[1]['id'] ?? null)) ?>
        <button name="action" value="lote_aprovar_cat" class="btn btn-outline-success btn-sm btn-lote-ia" <?= $podeAprovar ? '' : 'disabled' ?> data-confirm="Aprovar TODAS as selecionadas na categoria escolhida, ignorando a sugestão da IA?">Aprovar todas como…</button>
        <button name="action" value="lote_rejeitar" class="btn btn-outline-secondary btn-sm btn-lote-ia ms-auto" data-confirm="Rejeitar as selecionadas? As chaves não voltam para a fila.">Rejeitar selecionadas</button>
    </form>

    <div class="card"><div class="card-header">Sugestões aguardando revisão — mostrando <?= count($fila) ?> de <?= $totalFila ?><?= $totalFila > count($fila) ? ' (use os filtros para ver o restante; lote máximo de ' . IA_LOTE_MAX . ')' : '' ?></div>
    <?php if (!$fila): ?><div class="card-body text-muted">Nada aguardando revisão<?= $totalFila === 0 && ($f_q . $f_t . $f_s . $f_r) !== '' ? ' com estes filtros' : '' ?>.</div><?php else: ?>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th style="width:34px"><input type="checkbox" class="form-check-input chk-todos-ia" title="Selecionar todas as exibidas"></th><th>Chave</th><th>Contexto</th><th>Sugestão</th><th>Justificativa</th><th>Ação</th></tr></thead><tbody>
        <?php foreach ($fila as $s):
            $ctx = json_decode($s['contexto'] ?? '[]', true) ?: [];
            $sug = iaCategoriaIdPorNome($db, $s['categoria_sugerida'] ?? 'neutro'); ?>
        <tr>
            <td><input type="checkbox" class="form-check-input chk-ia" name="ids[]" value="<?= (int)$s['id'] ?>" form="formLoteRevisao"></td>
            <td><span class="badge bg-secondary"><?= $s['tipo'] === 'dominio' ? 'site' : 'aplicativo' ?></span> <strong><?= htmlspecialchars($s['valor']) ?></strong></td>
            <td class="small text-muted"><?= (int)($ctx['eventos'] ?? 0) ?> evento(s) · <?= htmlspecialchars((string)$s['horas_sem_categoria']) ?> h
                <?php foreach ((array)($ctx['titulos'] ?? []) as $t): ?><div class="text-truncate" style="max-width:260px" title="<?= htmlspecialchars($t) ?>">“<?= htmlspecialchars(iaSanitizarTexto($t, 70)) ?>”</div><?php endforeach; ?></td>
            <td><?= iaBadgeCat($s['categoria_sugerida'] ?? 'neutro') ?><?= $s['revisao'] ? ' <span class="badge bg-warning text-dark" title="O modelo (ou as travas de segurança) pediu análise humana">revisar</span>' : '' ?></td>
            <td class="small" style="max-width:320px"><?= htmlspecialchars($s['justificativa'] ?? '') ?></td>
            <td><form method="POST" class="d-flex gap-1 align-items-center">
                <?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <?= iaSelectCategoria($categorias, $sug) ?>
                <button name="action" value="aprovar" class="btn btn-success btn-sm" <?= $podeAprovar ? '' : 'disabled' ?> title="Grava uma regra e classifica os eventos sem categoria desta chave">Aprovar</button>
                <button name="action" value="rejeitar" class="btn btn-outline-secondary btn-sm" onclick="return confirm('Rejeitar esta sugestão? A chave não volta para a fila.')">Rejeitar</button>
            </form></td>
        </tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?></div>

<?php elseif ($aba === 'aprovadas'):
    $g_q = trim($_GET['q'] ?? '');
    $g_t = in_array($_GET['t'] ?? '', ['dominio', 'app'], true) ? $_GET['t'] : '';
    $g_c = (int)($_GET['c'] ?? 0);
    $onde = ["s.estado = 'aprovada'"]; $pf = [];
    if ($g_q !== '') { $onde[] = 's.valor LIKE :q'; $pf[':q'] = '%' . addcslashes($g_q, '%_\\') . '%'; }
    if ($g_t !== '') { $onde[] = 's.tipo = :t'; $pf[':t'] = $g_t; }
    if ($g_c > 0) { $onde[] = 'r.categoria_id = :c'; $pf[':c'] = $g_c; }
    $base = ' FROM ia_sugestoes s JOIN categoria_regras r ON r.id = s.regra_id AND r.origem = \'ia\' WHERE ' . implode(' AND ', $onde);
    $cnt = $db->prepare('SELECT COUNT(*)' . $base); $cnt->execute($pf); $totalApr = (int)$cnt->fetchColumn();
    $st = $db->prepare('SELECT s.*, r.categoria_id AS regra_cat' . $base . ' ORDER BY s.decidido_em DESC LIMIT ' . IA_LOTE_MAX); $st->execute($pf);
    $apr = $st->fetchAll(PDO::FETCH_ASSOC); ?>
    <form method="GET" class="d-flex flex-wrap gap-2 align-items-center mb-2">
        <input type="hidden" name="page" value="ia"><input type="hidden" name="aba" value="aprovadas">
        <input name="q" value="<?= htmlspecialchars($g_q) ?>" placeholder="Buscar chave…" class="form-control form-control-sm" style="width:170px">
        <select name="t" class="form-select form-select-sm" style="width:auto"><option value="">Site e aplicativo</option><option value="dominio" <?= $g_t === 'dominio' ? 'selected' : '' ?>>Só sites</option><option value="app" <?= $g_t === 'app' ? 'selected' : '' ?>>Só aplicativos</option></select>
        <select name="c" class="form-select form-select-sm" style="width:auto"><option value="0">Qualquer categoria</option><?php foreach ($categorias as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $g_c === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nome']) ?></option><?php endforeach; ?></select>
        <button class="btn btn-outline-primary btn-sm">Filtrar</button>
        <?php if ($g_q !== '' || $g_t !== '' || $g_c > 0): ?><a class="btn btn-link btn-sm" href="index.php?page=ia&aba=aprovadas">limpar</a><?php endif; ?>
    </form>

    <form method="POST" id="formLoteAprovadas" class="card card-body py-2 mb-2 d-flex flex-row flex-wrap gap-2 align-items-center">
        <?= csrfField() ?>
        <span class="small fw-semibold"><span class="contagem-ia">0</span> selecionada(s)</span>
        <?= str_replace('name="categoria_id"', 'name="categoria_lote"', iaSelectCategoria($categorias, null)) ?>
        <button name="action" value="lote_alterar" class="btn btn-outline-primary btn-sm btn-lote-ia" data-confirm="Alterar a categoria de TODAS as selecionadas? Os eventos dessas chaves que estavam na categoria anterior serão atualizados.">Alterar categoria das selecionadas</button>
        <button name="action" value="lote_excluir" class="btn btn-outline-danger btn-sm btn-lote-ia ms-auto" data-confirm="Excluir as classificações selecionadas? As regras são apagadas e os eventos voltam ao que as demais regras decidirem (sem categoria, se nenhuma casar).">Excluir selecionadas</button>
    </form>

    <div class="card"><div class="card-header">Classificações criadas pela IA e aprovadas — mostrando <?= count($apr) ?> de <?= $totalApr ?> — se alguma estiver errada, altere a categoria ou exclua</div>
    <?php if (!$apr): ?><div class="card-body text-muted">Nenhuma classificação da IA aprovada<?= ($g_q . $g_t) !== '' || $g_c > 0 ? ' com estes filtros' : ' ainda' ?>.</div><?php else: ?>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th style="width:34px"><input type="checkbox" class="form-check-input chk-todos-ia" title="Selecionar todas as exibidas"></th><th>Chave</th><th>Categoria atual</th><th>Eventos</th><th>Aprovada por</th><th>Ação</th></tr></thead><tbody>
        <?php foreach ($apr as $s): $c = $catPorId[$s['regra_cat']] ?? null; ?>
        <tr>
            <td><input type="checkbox" class="form-check-input chk-ia" name="ids[]" value="<?= (int)$s['id'] ?>" form="formLoteAprovadas"></td>
            <td><span class="badge bg-secondary"><?= $s['tipo'] === 'dominio' ? 'site' : 'aplicativo' ?></span> <strong><?= htmlspecialchars($s['valor']) ?></strong>
                <div class="small text-muted"><?= htmlspecialchars($s['justificativa'] ?? '') ?></div></td>
            <td><span class="badge" style="background:<?= htmlspecialchars($c['cor'] ?? '#94a2b8') ?>"><?= htmlspecialchars($c['nome'] ?? '?') ?></span></td>
            <td class="small"><?= (int)$s['eventos_aplicados'] ?></td>
            <td class="small text-muted"><?= htmlspecialchars((string)$s['decidido_por']) ?><br><?= htmlspecialchars((string)$s['decidido_em']) ?></td>
            <td><form method="POST" class="d-flex gap-1 align-items-center">
                <?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <?= iaSelectCategoria($categorias, $s['regra_cat']) ?>
                <button name="action" value="alterar" class="btn btn-outline-primary btn-sm" onclick="return confirm('Alterar a categoria? Os eventos desta chave que estavam na categoria anterior serão atualizados.')">Alterar</button>
                <button name="action" value="excluir" class="btn btn-outline-danger btn-sm" onclick="return confirm('Excluir esta classificação? A regra é apagada e os eventos desta chave voltam ao que as demais regras decidirem (sem categoria, se nenhuma casar).')">Excluir</button>
            </form></td>
        </tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?></div>

<?php elseif ($aba === 'avaliacao'):
    $av = iaResumoAvaliacao($db); ?>
    <div class="alert alert-secondary small">A avaliação pede ao modelo as chaves que <strong>você já classificou</strong> (menos as usadas como exemplo no prompt) e compara com a sua categoria. Não altera nenhuma classificação. A concordância medida aqui é a base para decidir se a IA pode passar do modo observação para revisão (e, mais tarde, automático).</div>
    <form method="POST" class="d-flex gap-2 align-items-center mb-3">
        <?= csrfField() ?>
        <label class="small text-muted">Chaves por categoria</label>
        <input type="number" name="limite" value="15" min="1" max="100" class="form-control form-control-sm" style="width:80px">
        <button name="action" value="iniciar_avaliacao" class="btn btn-primary btn-sm"><i class="bi bi-speedometer2"></i> Iniciar avaliação</button>
    </form>
    <?php if (!$av): ?><div class="text-muted">Nenhuma avaliação executada ainda.</div><?php else: ?>
    <div class="card mb-3"><div class="card-header">Última avaliação (<?= htmlspecialchars($av['execucao']) ?>)</div><div class="card-body">
        <div class="row text-center mb-3">
            <div class="col"><div class="fs-3 fw-bold"><?= $av['concordancia'] !== null ? $av['concordancia'] . '%' : '—' ?></div><div class="small text-muted">concordância com a sua categorização</div></div>
            <div class="col"><div class="fs-3 fw-bold"><?= $av['respondidas'] ?>/<?= $av['total'] ?></div><div class="small text-muted">chaves respondidas</div></div>
            <div class="col"><div class="fs-3 fw-bold"><?= $av['com_revisao'] ?></div><div class="small text-muted">marcadas "revisar" pelo modelo</div></div>
            <div class="col"><div class="fs-3 fw-bold"><?= $av['erros'] ?></div><div class="small text-muted">erros</div></div>
        </div>
        <table class="table table-sm table-bordered w-auto"><thead><tr><th>Sua categoria ↓ / IA →</th><th>produtivo</th><th>improdutivo</th><th>neutro</th></tr></thead><tbody>
            <?php foreach (['produtivo', 'improdutivo', 'neutro'] as $a): ?><tr><th><?= $a ?></th>
                <?php foreach (['produtivo', 'improdutivo', 'neutro'] as $b): ?><td class="<?= $a === $b ? 'table-success' : '' ?>"><?= (int)($av['matriz'][$a][$b] ?? 0) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
        </tbody></table>
        <?php if ($av['divergentes']): ?><div class="fw-semibold mt-3 mb-1">Divergências (<?= count($av['divergentes']) ?>)</div>
        <table class="table table-sm"><tbody><?php foreach ($av['divergentes'] as $d): ?><tr>
            <td><?= htmlspecialchars($d['valor']) ?></td><td>você: <?= iaBadgeCat($d['categoria_atual']) ?></td><td>IA: <?= iaBadgeCat($d['categoria_ia']) ?><?= $d['revisao'] ? ' <span class="badge bg-warning text-dark">revisar</span>' : '' ?></td>
        </tr><?php endforeach; ?></tbody></table><?php endif; ?>
    </div></div><?php endif; ?>

<?php else: ?>
    <div class="card" style="max-width:760px"><div class="card-header">Configuração</div><div class="card-body">
    <form method="POST">
        <?= csrfField() ?>
        <div class="mb-3"><label class="form-label">Modo de operação</label>
            <select name="modo" class="form-select">
                <?php foreach ($rotuloModo as $k => $r): ?><option value="<?= $k ?>" <?= $cfg['modo'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($r) ?></option><?php endforeach; ?>
            </select>
            <div class="form-text">Desligado: nada é processado. Observação: a IA sugere e você compara, sem aplicar. Revisão: você aprova cada sugestão. Automático: aplica sozinho só o que o modelo não marcou para revisão — use só depois de validar a concordância na aba Avaliação.</div></div>
        <div class="row g-3">
            <div class="col-md-8"><label class="form-label">Endereço do serviço (interno)</label><input name="ollama_url" class="form-control" value="<?= htmlspecialchars($cfg['ollama_url']) ?>"></div>
            <div class="col-md-4"><label class="form-label">Modelo</label><input name="modelo" class="form-control" value="<?= htmlspecialchars($cfg['modelo']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Timeout (s)</label><input type="number" name="timeout_s" class="form-control" value="<?= (int)$cfg['timeout_s'] ?>"></div>
            <div class="col-md-3"><label class="form-label">Tentativas</label><input type="number" name="max_tentativas" class="form-control" value="<?= (int)$cfg['max_tentativas'] ?>"></div>
            <div class="col-md-3"><label class="form-label">Itens por ciclo</label><input type="number" name="lote_por_execucao" class="form-control" value="<?= (int)$cfg['lote_por_execucao'] ?>"></div>
            <div class="col-md-3"><label class="form-label">Segundos por ciclo</label><input type="number" name="limite_segundos" class="form-control" value="<?= (int)$cfg['limite_segundos'] ?>"></div>
            <div class="col-md-4"><label class="form-label">Janela de descoberta (dias)</label><input type="number" name="dias_descoberta" class="form-control" value="<?= (int)$cfg['dias_descoberta'] ?>"></div>
            <div class="col-md-8 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="enviar_titulo" id="et" value="1" <?= $cfg['enviar_titulo'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="et">Enviar até 3 títulos de janela (truncados) de aplicativos de desktop ao modelo</label><div class="form-text">Sites: só o domínio é enviado, sempre. Usuário e máquina nunca são enviados.</div></div></div>
        </div>
        <div class="mt-3"><label class="form-label">Política da instituição (enviada ao modelo)</label>
            <textarea name="politica" rows="7" class="form-control" style="font-size:13px"><?= htmlspecialchars(trim((string)($cfg['politica'] ?? '')) !== '' ? $cfg['politica'] : iaPoliticaPadrao()) ?></textarea>
            <div class="form-text">Descreva em linguagem simples o que é produtivo, improdutivo e neutro aqui. É o que mais influencia a qualidade das sugestões. Depois de alterar, use <strong>Reanalisar sugestões</strong> na Fila de revisão. Não escreva dados pessoais.</div></div>
        <div class="mt-3 d-flex gap-2">
            <button name="action" value="salvar_config" class="btn btn-primary">Salvar</button>
            <button name="action" value="testar" class="btn btn-outline-secondary" formnovalidate>Testar conexão</button>
        </div>
    </form></div></div>
<?php endif; ?>
<script>
(function () {
    document.querySelectorAll('form[id^="formLote"]').forEach(function (form) {
        var chks = function () { return document.querySelectorAll('.chk-ia[form="' + form.id + '"]'); };
        var cont = form.querySelector('.contagem-ia');
        var botoes = form.querySelectorAll('.btn-lote-ia');
        var todos = document.querySelector('.chk-todos-ia');
        function atualizar() {
            var n = 0; chks().forEach(function (c) { if (c.checked) n++; });
            cont.textContent = n;
            botoes.forEach(function (b) { b.dataset.n = n; });
            if (todos) todos.checked = n > 0 && n === chks().length;
        }
        if (todos) todos.addEventListener('change', function () { chks().forEach(function (c) { c.checked = todos.checked; }); atualizar(); });
        chks().forEach(function (c) { c.addEventListener('change', atualizar); });
        form.addEventListener('submit', function (e) {
            var b = e.submitter; var n = parseInt(cont.textContent, 10);
            if (!n) { e.preventDefault(); alert('Selecione ao menos um item.'); return; }
            if (b && b.dataset.confirm && !confirm(n + ' item(ns) selecionado(s).\n\n' + b.dataset.confirm)) e.preventDefault();
        });
        atualizar();
    });
})();
</script>