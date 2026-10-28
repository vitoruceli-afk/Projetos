<?php
// Motor de classificação por IA: descoberta de chaves sem categoria, fila, aplicação/correção/exclusão e métricas.
// Princípios (ver ia_client.php para o lado do modelo):
//  - NADA aqui toca a recepção de dados: a sincronização continua igual; a IA trabalha em segundo plano (ia_runner.php).
//  - A unidade de classificação é a CHAVE (domínio do site ou aplicativo), não o evento — por isso o volume de inferências
//    fica pequeno mesmo com milhares de eventos por dia.
//  - Uma sugestão só vira classificação quando o administrador a aprova (ou, no modo "auto", quando passa nas travas):
//    nesse momento ela é gravada como regra normal em categoria_regras (origem 'ia', prioridade 200). Eventos já
//    classificados por regras do administrador (prioridade < 200) não mudam; a regra vale para os novos e para os sem categoria.
//  - Todas as operações são idempotentes (chave única por hash, reivindicação atômica, regra única por padrão).

require_once __DIR__ . '/ia_client.php';

const IA_PRIORIDADE_REGRA = 200;
const IA_MODOS = ['desligado', 'observacao', 'revisao', 'auto'];

function iaHash($tipo, $valor) { return sha1($tipo . '|' . $valor); }

// "https://user@WWW.Exemplo.com:8080/x?y" -> "exemplo.com"; null se não for um domínio utilizável (IP, localhost, esquemas internos).
function iaNormalizarHost($bruto) {
    $h = strtolower(trim((string)$bruto));
    $h = preg_replace('/^.*@/', '', $h);
    $h = preg_replace('/:\d+$/', '', $h);
    $h = rtrim($h, '.');
    $h = preg_replace('/^www\./', '', $h);
    return iaDominioValido($h) ? $h : null;
}

// Regra equivalente a uma chave, no mesmo formato das regras já existentes (host com suporte a "blob:").
function iaRegraParaChave($tipo, $valor) {
    if ($tipo === 'dominio') return ['url', 'regex', '^(blob:)?[a-z]+://([^/?#]*\\.)?' . str_replace('.', '\\.', $valor) . '([:/?#]|$)'];
    return ['app', 'contem', $valor];
}

function iaCategoriaIdPorNome(PDO $db, $nome) {
    $p = IA_CATEGORIAS[$nome] ?? null;
    if ($p === null) return null;
    $id = $db->prepare("SELECT id FROM categorias WHERE pontuacao = :p ORDER BY ordem ASC, id ASC LIMIT 1");
    $id->execute([':p' => $p]);
    $v = $id->fetchColumn();
    return $v ? (int)$v : null;
}

function iaLog(PDO $db, $sugestaoId, $resultado, $ms, $mensagem = null) {
    $db->prepare("INSERT INTO ia_log (ts, sugestao_id, resultado, duracao_ms, mensagem) VALUES (NOW(), :s, :r, :ms, :m)")
       ->execute([':s' => $sugestaoId, ':r' => $resultado, ':ms' => $ms, ':m' => $mensagem !== null ? mb_substr($mensagem, 0, 300) : null]);
}

// ---------- descoberta ----------
// Procura, nos eventos recentes SEM categoria, domínios e aplicativos ainda desconhecidos pela IA e os coloca na fila.
// Só olha eventos sem categoria: o que já tem regra (do administrador ou da IA) nunca chega aqui.
function iaDescobrirChaves(PDO $db, array $cfg, $forcar = false) {
    if (!$forcar && !empty($cfg['ultima_descoberta']) && strtotime($cfg['ultima_descoberta']) > time() - 600) return 0;
    $desde = gmdate('Y-m-d H:i:s', time() - 86400 * max(1, (int)$cfg['dias_descoberta']));
    $ins = $db->prepare("INSERT IGNORE INTO ia_sugestoes (chave_hash, tipo, valor, contexto, horas_sem_categoria, estado, proxima_tentativa)
                         VALUES (:h, :t, :v, :c, :hs, 'pendente', NOW())");
    $novos = 0;

    $stmt = $db->prepare("SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(url, '://', -1), '/', 1), '?', 1) AS h, COUNT(*) AS n, SUM(duracao) AS seg
                          FROM eventos WHERE tipo = 'web' AND categoria_id IS NULL AND ts > :d AND url REGEXP '^https?://' GROUP BY h");
    $stmt->execute([':d' => $desde]);
    $sites = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $h = iaNormalizarHost($r['h']);
        if ($h === null) continue;
        $sites[$h]['n'] = ($sites[$h]['n'] ?? 0) + (int)$r['n'];
        $sites[$h]['seg'] = ($sites[$h]['seg'] ?? 0) + (float)$r['seg'];
    }
    foreach ($sites as $h => $d) {
        $ins->execute([':h' => iaHash('dominio', $h), ':t' => 'dominio', ':v' => $h,
                       ':c' => json_encode(['eventos' => $d['n']]), ':hs' => round($d['seg'] / 3600, 2)]);
        $novos += $ins->rowCount();
    }

    $stmt = $db->prepare("SELECT app, COUNT(*) AS n, SUM(duracao) AS seg FROM eventos
                          WHERE tipo = 'window' AND categoria_id IS NULL AND ts > :d AND app IS NOT NULL AND app <> '' GROUP BY app");
    $stmt->execute([':d' => $desde]);
    $tit = $db->prepare("SELECT titulo FROM eventos WHERE tipo = 'window' AND categoria_id IS NULL AND app = :a AND ts > :d AND titulo IS NOT NULL AND titulo <> ''
                         GROUP BY titulo ORDER BY SUM(duracao) DESC LIMIT 3");
    $existe = $db->prepare("SELECT 1 FROM ia_sugestoes WHERE chave_hash = :h");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $app = (string)$r['app'];
        if (in_array(strtolower($app), IA_NAVEGADORES, true)) continue;   // janela de navegador: a categoria vem da aba (domínio)
        $h = iaHash('app', strtolower($app));
        $existe->execute([':h' => $h]);
        if ($existe->fetchColumn()) continue;
        $tit->execute([':a' => $app, ':d' => $desde]);
        $ins->execute([':h' => $h, ':t' => 'app', ':v' => $app,
                       ':c' => json_encode(['eventos' => (int)$r['n'], 'titulos' => $tit->fetchAll(PDO::FETCH_COLUMN)], JSON_UNESCAPED_UNICODE),
                       ':hs' => round($r['seg'] / 3600, 2)]);
        $novos += $ins->rowCount();
    }
    $db->exec("UPDATE ia_config SET ultima_descoberta = NOW() WHERE id = 1");
    return $novos;
}

// ---------- fila ----------
// Reivindica até $max itens de forma atômica (UPDATE ... LIMIT 1 com token): dois processos nunca pegam o mesmo item.
function iaReivindicar(PDO $db, $max) {
    $db->exec("UPDATE ia_sugestoes SET estado = 'pendente', claim = NULL WHERE estado = 'processando' AND iniciado_em < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $itens = [];
    for ($i = 0; $i < $max; $i++) {
        $token = bin2hex(random_bytes(8));
        $up = $db->prepare("UPDATE ia_sugestoes SET estado = 'processando', claim = :t, iniciado_em = NOW()
                            WHERE estado IN ('pendente', 'erro') AND (proxima_tentativa IS NULL OR proxima_tentativa <= NOW())
                            ORDER BY horas_sem_categoria DESC, id ASC LIMIT 1");
        $up->execute([':t' => $token]);
        if ($up->rowCount() === 0) break;
        $sel = $db->prepare("SELECT * FROM ia_sugestoes WHERE claim = :t AND estado = 'processando'");
        $sel->execute([':t' => $token]);
        if ($row = $sel->fetch(PDO::FETCH_ASSOC)) $itens[] = $row;
    }
    return $itens;
}

// Processa um item reivindicado. Falha do serviço NUNCA inventa categoria: o item volta para nova tentativa (espera
// progressiva 2^n minutos) e, esgotadas as tentativas, fica em 'falha' até o administrador reprocessar.
function iaProcessarUma(PDO $db, array $cfg, array $sug, ?callable $transporte = null) {
    $r = iaClassificar($db, $cfg, $sug, $transporte);
    if (!$r['ok']) {
        $tent = (int)$sug['tentativas'] + 1;
        $esgotou = $tent >= (int)$cfg['max_tentativas'];
        $db->prepare("UPDATE ia_sugestoes SET estado = :e, tentativas = :t, claim = NULL, ultimo_erro = :err,
                      proxima_tentativa = DATE_ADD(NOW(), INTERVAL :min MINUTE) WHERE id = :id AND claim = :c")
           ->execute([':e' => $esgotou ? 'falha' : 'erro', ':t' => $tent, ':err' => mb_substr($r['erro'], 0, 300),
                      ':min' => 2 ** min($tent, 6), ':id' => $sug['id'], ':c' => $sug['claim']]);
        iaLog($db, $sug['id'], $esgotou ? 'falha' : 'erro', $r['ms'] ?? 0, $r['erro']);
        return $esgotou ? 'falha' : 'erro';
    }
    $estado = $r['revisao'] ? 'revisao' : 'sugerida';
    $up = $db->prepare("UPDATE ia_sugestoes SET estado = :e, categoria_sugerida = :c, justificativa = :j, revisao = :r, modelo = :m, prompt_versao = :pv,
                        claim = NULL, ultimo_erro = NULL WHERE id = :id AND claim = :cl");
    $up->execute([':e' => $estado, ':c' => $r['categoria'], ':j' => $r['justificativa'], ':r' => $r['revisao'] ? 1 : 0,
                  ':m' => $r['enviado'] ? $cfg['modelo'] : null, ':pv' => IA_PROMPT_VERSAO, ':id' => $sug['id'], ':cl' => $sug['claim']]);
    iaLog($db, $sug['id'], $estado, $r['ms'], null);
    // modo "auto": só aplica o que o modelo não marcou para revisão E que de fato foi ao modelo; o resto continua na fila humana
    if ($cfg['modo'] === 'auto' && $estado === 'sugerida' && $r['enviado']) {
        try { iaAplicarSugestao($db, (int)$sug['id'], iaCategoriaIdPorNome($db, $r['categoria']), 'IA (modo auto)', $cfg); }
        catch (Throwable $e) { iaLog($db, $sug['id'], 'erro', 0, 'auto: ' . $e->getMessage()); }
    }
    return $estado;
}

function iaProcessarLote(PDO $db, array $cfg, ?callable $transporte = null) {
    $t0 = time(); $res = [];
    while (time() - $t0 < (int)$cfg['limite_segundos'] && count($res) < (int)$cfg['lote_por_execucao']) {
        $itens = iaReivindicar($db, 1);
        if (!$itens) break;
        $res[] = iaProcessarUma($db, $cfg, $itens[0], $transporte);
    }
    return $res;
}

// ---------- aplicar / corrigir / excluir ----------
// Reclassifica os eventos de uma chave com as regras VIGENTES. $deCategorias: null = só eventos sem categoria (aplicação);
// lista = só eventos hoje nessas categorias (correção/exclusão). Eventos cuja categoria vem de uma regra do administrador
// continuam iguais, porque classificarEvento() respeita a prioridade. Devolve quantos eventos mudaram.
function iaReclassificarChave(PDO $db, $tipo, $valor, ?array $deCategorias = null) {
    awRegrasCategorizacao($db, true);   // recarrega as regras (a regra acabou de mudar)
    $filtroCat = $deCategorias === null ? 'categoria_id IS NULL' : ('categoria_id IN (' . implode(',', array_map('intval', $deCategorias ?: [0])) . ')');
    if ($tipo === 'dominio') {
        $base = "SELECT id, app, titulo, url, categoria_id FROM eventos WHERE tipo = 'web' AND {$filtroCat} AND url LIKE :like AND id > :ult ORDER BY id LIMIT 5000";
        $param = [':like' => '%' . addcslashes($valor, '%_\\') . '%'];
    } else {
        $base = "SELECT id, app, titulo, url, categoria_id FROM eventos WHERE tipo = 'window' AND {$filtroCat} AND app = :app AND id > :ult ORDER BY id LIMIT 5000";
        $param = [':app' => $valor];
    }
    $sel = $db->prepare($base);
    $upd = $db->prepare("UPDATE eventos SET categoria_id = :c WHERE id = :id");
    $ult = 0; $mudou = 0;
    do {
        $sel->execute($param + [':ult' => $ult]);
        $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $ult = (int)$r['id'];
            $nova = classificarEvento($db, $r['app'], $r['titulo'], $r['url']);
            if (($nova === null) !== ($r['categoria_id'] === null) || ($nova !== null && (int)$nova !== (int)$r['categoria_id'])) {
                $upd->execute([':c' => $nova, ':id' => $r['id']]);
                $mudou++;
            }
        }
    } while (count($rows) === 5000);
    return $mudou;
}

// Depois de mudar a regra de um domínio, as janelas do navegador (que herdam a categoria da aba) precisam acompanhar. Só mexe
// nas recentes: o histórico mais antigo é preservado (a categorização já feita é estática).
function iaRealinharJanelas(PDO $db, ?array $deCategorias, $dias = 7) {
    $desde = gmdate('Y-m-d H:i:s', time() - 86400 * $dias);
    if ($deCategorias) {
        $in = implode(',', array_map('intval', $deCategorias));
        $nav = "'" . implode("','", IA_NAVEGADORES) . "'";
        $sel = $db->prepare("SELECT id, app, titulo FROM eventos WHERE tipo = 'window' AND LOWER(app) IN ({$nav}) AND ts > :d AND categoria_id IN ({$in})");
        $sel->execute([':d' => $desde]);
        $upd = $db->prepare("UPDATE eventos SET categoria_id = :c WHERE id = :id");
        foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $r) $upd->execute([':c' => classificarEvento($db, $r['app'], $r['titulo'], null), ':id' => $r['id']]);
    }
    return herdarCategoriaDasAbas($db, null, $desde);
}

function iaExigirModoRevisao(array $cfg) {
    if (!in_array($cfg['modo'], ['revisao', 'auto'], true)) {
        throw new RuntimeException('A classificação por IA está em modo "' . ($cfg['modo'] === 'observacao' ? 'observação' : $cfg['modo']) . '": aprovar sugestões só é possível nos modos "revisão" e "auto".');
    }
}

function iaAplicarSugestao(PDO $db, $id, $categoriaId, $usuario, ?array $cfg = null) {
    $cfg = $cfg ?? iaConfig($db);
    iaExigirModoRevisao($cfg);
    $cat = $db->prepare("SELECT id FROM categorias WHERE id = :i");
    $cat->execute([':i' => $categoriaId]);
    if (!$categoriaId || !$cat->fetchColumn()) throw new RuntimeException('Categoria inválida.');

    $db->beginTransaction();
    try {
        $s = $db->prepare("SELECT * FROM ia_sugestoes WHERE id = :i FOR UPDATE");
        $s->execute([':i' => $id]);
        $sug = $s->fetch(PDO::FETCH_ASSOC);
        if (!$sug) throw new RuntimeException('Sugestão não encontrada.');
        if ($sug['estado'] === 'aprovada' && (int)$sug['categoria_id_final'] === (int)$categoriaId) { $db->commit(); return 0; }   // idempotente
        if ($sug['estado'] === 'aprovada') throw new RuntimeException('Esta sugestão já está aprovada em outra categoria — use "Alterar categoria".');
        if ($sug['estado'] === 'processando') throw new RuntimeException('A sugestão está sendo processada agora; tente de novo em instantes.');
        [$campo, $tipoRegra, $padrao] = iaRegraParaChave($sug['tipo'], $sug['valor']);
        $ex = $db->prepare("SELECT id, categoria_id, origem FROM categoria_regras WHERE campo = :c AND tipo = :t AND padrao = :p LIMIT 1");
        $ex->execute([':c' => $campo, ':t' => $tipoRegra, ':p' => $padrao]);
        $regra = $ex->fetch(PDO::FETCH_ASSOC);
        if ($regra && $regra['origem'] === 'manual' && (int)$regra['categoria_id'] !== (int)$categoriaId) {
            throw new RuntimeException('Já existe uma regra do administrador para esta chave em outra categoria; ela prevalece e não foi alterada.');
        }
        if ($regra) { $regraId = (int)$regra['id']; }
        else {
            $db->prepare("INSERT INTO categoria_regras (categoria_id, campo, tipo, padrao, prioridade, origem, sugestao_id) VALUES (:c, :campo, :t, :p, :pr, 'ia', :s)")
               ->execute([':c' => $categoriaId, ':campo' => $campo, ':t' => $tipoRegra, ':p' => $padrao, ':pr' => IA_PRIORIDADE_REGRA, ':s' => $id]);
            $regraId = (int)$db->lastInsertId();
        }
        $db->prepare("UPDATE ia_sugestoes SET estado = 'aprovada', categoria_id_final = :c, regra_id = :r, decidido_por = :u, decidido_em = NOW() WHERE id = :i")
           ->execute([':c' => $categoriaId, ':r' => $regraId, ':u' => mb_substr((string)$usuario, 0, 100), ':i' => $id]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }

    $n = iaReclassificarChave($db, $sug['tipo'], $sug['valor'], null);
    if ($sug['tipo'] === 'dominio') iaRealinharJanelas($db, null);
    $db->prepare("UPDATE ia_sugestoes SET eventos_aplicados = :n WHERE id = :i")->execute([':n' => $n, ':i' => $id]);
    iaLog($db, $id, 'aprovada', 0, "{$usuario}: {$n} evento(s)");
    return $n;
}

function iaRejeitarSugestao(PDO $db, $id, $usuario) {
    $db->prepare("UPDATE ia_sugestoes SET estado = 'rejeitada', decidido_por = :u, decidido_em = NOW() WHERE id = :i AND estado IN ('sugerida', 'revisao', 'erro', 'falha', 'pendente')")
       ->execute([':u' => mb_substr((string)$usuario, 0, 100), ':i' => $id]);
    iaLog($db, $id, 'rejeitada', 0, (string)$usuario);
}

function iaClassificacaoAprovada(PDO $db, $id) {
    $s = $db->prepare("SELECT s.*, r.categoria_id AS regra_categoria FROM ia_sugestoes s JOIN categoria_regras r ON r.id = s.regra_id AND r.origem = 'ia'
                       WHERE s.id = :i AND s.estado = 'aprovada'");
    $s->execute([':i' => $id]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Classificação da IA não encontrada (ou a regra não é da IA).');
    return $row;
}

// Corrige uma classificação da IA que o administrador considera errada: troca a categoria da regra e refaz os eventos que
// estavam na categoria antiga (só os dessa chave).
function iaAlterarClassificacao(PDO $db, $id, $novaCategoriaId, $usuario) {
    $sug = iaClassificacaoAprovada($db, $id);
    $velha = (int)$sug['regra_categoria'];
    $novaCategoriaId = (int)$novaCategoriaId;
    $c = $db->prepare("SELECT 1 FROM categorias WHERE id = :i");
    $c->execute([':i' => $novaCategoriaId]);
    if (!$c->fetchColumn()) throw new RuntimeException('Categoria inválida.');
    if ($velha === $novaCategoriaId) return 0;
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE categoria_regras SET categoria_id = :c WHERE id = :r AND origem = 'ia'")->execute([':c' => $novaCategoriaId, ':r' => $sug['regra_id']]);
        $db->prepare("UPDATE ia_sugestoes SET categoria_id_final = :c, decidido_por = :u, decidido_em = NOW() WHERE id = :i")
           ->execute([':c' => $novaCategoriaId, ':u' => mb_substr((string)$usuario, 0, 100), ':i' => $id]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    $n = iaReclassificarChave($db, $sug['tipo'], $sug['valor'], [$velha]);
    if ($sug['tipo'] === 'dominio') iaRealinharJanelas($db, [$velha]);
    iaLog($db, $id, 'alterada', 0, "{$usuario}: {$velha} -> {$novaCategoriaId}, {$n} evento(s)");
    return $n;
}

// Exclui uma classificação da IA: apaga a regra, marca a sugestão como rejeitada (a chave não volta para a fila) e devolve os
// eventos dessa chave ao que as demais regras decidirem (sem categoria, se nenhuma casar).
function iaExcluirClassificacao(PDO $db, $id, $usuario) {
    $sug = iaClassificacaoAprovada($db, $id);
    $velha = (int)$sug['regra_categoria'];
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM categoria_regras WHERE id = :r AND origem = 'ia'")->execute([':r' => $sug['regra_id']]);
        $db->prepare("UPDATE ia_sugestoes SET estado = 'rejeitada', regra_id = NULL, categoria_id_final = NULL, decidido_por = :u, decidido_em = NOW() WHERE id = :i")
           ->execute([':u' => mb_substr((string)$usuario, 0, 100), ':i' => $id]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    $n = iaReclassificarChave($db, $sug['tipo'], $sug['valor'], [$velha]);
    if ($sug['tipo'] === 'dominio') iaRealinharJanelas($db, [$velha]);
    iaLog($db, $id, 'excluida', 0, "{$usuario}: {$n} evento(s)");
    return $n;
}

// Item em erro/falha volta para a fila (reprocessamento administrativo).
function iaReprocessarFalhas(PDO $db) {
    $st = $db->prepare("UPDATE ia_sugestoes SET estado = 'pendente', tentativas = 0, proxima_tentativa = NOW(), ultimo_erro = NULL, claim = NULL WHERE estado IN ('erro', 'falha')");
    $st->execute();
    return $st->rowCount();
}

// Depois de mudar o prompt/política/modelo: devolve à fila as sugestões que AINDA não foram decididas (sugeridas e em revisão),
// para serem refeitas. Decisões já tomadas (aprovadas/rejeitadas) nunca são tocadas.
function iaReanalisarSugestoes(PDO $db) {
    $st = $db->prepare("UPDATE ia_sugestoes SET estado = 'pendente', tentativas = 0, proxima_tentativa = NOW(), claim = NULL, categoria_sugerida = NULL, justificativa = NULL, revisao = 0
                         WHERE estado IN ('sugerida', 'revisao')");
    $st->execute();
    return $st->rowCount();
}

// ---------- monitoramento ----------
function iaMetricas(PDO $db) {
    $m = ['estados' => []];
    foreach ($db->query("SELECT estado, COUNT(*) n FROM ia_sugestoes GROUP BY estado")->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $m['estados'][$k] = (int)$v;
    $log = $db->query("SELECT COUNT(*) n, SUM(resultado IN ('erro', 'falha')) erros, AVG(CASE WHEN duracao_ms > 0 THEN duracao_ms END) media
                       FROM ia_log WHERE ts > DATE_SUB(NOW(), INTERVAL 24 HOUR) AND resultado IN ('sugerida', 'revisao', 'erro', 'falha')")->fetch(PDO::FETCH_ASSOC);
    $m['exec24h'] = (int)$log['n']; $m['erros24h'] = (int)$log['erros']; $m['media_ms'] = $log['media'] !== null ? (int)$log['media'] : null;
    $ms = $db->query("SELECT duracao_ms FROM ia_log WHERE ts > DATE_SUB(NOW(), INTERVAL 24 HOUR) AND resultado IN ('sugerida', 'revisao') AND duracao_ms > 0 ORDER BY duracao_ms")->fetchAll(PDO::FETCH_COLUMN);
    $m['p95_ms'] = $ms ? (int)$ms[(int)floor(0.95 * (count($ms) - 1))] : null;
    $m['regras_manuais'] = (int)$db->query("SELECT COUNT(*) FROM categoria_regras WHERE origem = 'manual' AND ativo = 1")->fetchColumn();
    $m['regras_ia'] = (int)$db->query("SELECT COUNT(*) FROM categoria_regras WHERE origem = 'ia' AND ativo = 1")->fetchColumn();
    $m['eventos_por_ia'] = (int)$db->query("SELECT COALESCE(SUM(eventos_aplicados), 0) FROM ia_sugestoes WHERE estado = 'aprovada'")->fetchColumn();
    $m['fila_revisao'] = ($m['estados']['sugerida'] ?? 0) + ($m['estados']['revisao'] ?? 0);
    $m['aguardando'] = ($m['estados']['pendente'] ?? 0) + ($m['estados']['processando'] ?? 0) + ($m['estados']['erro'] ?? 0);
    $m['falhas'] = $m['estados']['falha'] ?? 0;
    return $m;
}

// ---------- avaliação (modo de observação) ----------
// Compara a IA com a categorização atual: pede ao modelo as chaves JÁ classificadas pelo administrador (menos as usadas como
// exemplo no prompt) e registra concordância. Não altera nenhuma categoria.
function iaAvaliar(PDO $db, array $cfg, $limitePorCategoria = 15, ?callable $transporte = null) {
    $exec = date('YmdHis');
    $usados = [];
    foreach (iaExemplosPrompt($db) as $e) $usados[$e['tipo'] . '|' . $e['valor']] = true;
    $porCat = [];
    foreach (iaChavesConhecidas($db) as $k) {
        if (isset($usados[$k['tipo'] . '|' . $k['valor']])) continue;
        $porCat[$k['categoria']][] = $k;
    }
    $ins = $db->prepare("INSERT INTO ia_avaliacao (execucao, ts, tipo, valor, categoria_atual, categoria_ia, revisao, erro) VALUES (:e, NOW(), :t, :v, :a, :i, :r, :err)");
    foreach ($porCat as $cat => $lista) {
        $passo = max(1, (int)floor(count($lista) / $limitePorCategoria));   // amostra espaçada (determinística)
        $n = 0;
        for ($i = 0; $i < count($lista) && $n < $limitePorCategoria; $i += $passo, $n++) {
            $k = $lista[$i];
            $r = iaClassificar($db, $cfg, ['tipo' => $k['tipo'], 'valor' => $k['valor'], 'contexto' => []], $transporte);
            $ins->execute([':e' => $exec, ':t' => $k['tipo'], ':v' => $k['valor'], ':a' => $cat, ':i' => $r['ok'] ? $r['categoria'] : null,
                           ':r' => !empty($r['revisao']) ? 1 : 0, ':err' => $r['ok'] ? null : mb_substr($r['erro'], 0, 300)]);
        }
    }
    return $exec;
}

function iaResumoAvaliacao(PDO $db, $execucao = null) {
    $execucao = $execucao ?? $db->query("SELECT MAX(execucao) FROM ia_avaliacao")->fetchColumn();
    if (!$execucao) return null;
    $linhas = $db->prepare("SELECT tipo, valor, categoria_atual, categoria_ia, revisao, erro FROM ia_avaliacao WHERE execucao = :e ORDER BY categoria_atual, valor");
    $linhas->execute([':e' => $execucao]);
    $linhas = $linhas->fetchAll(PDO::FETCH_ASSOC);
    $matriz = []; $total = 0; $acertos = 0; $comResp = 0; $revisoes = 0; $erros = 0;
    foreach ($linhas as $l) {
        $total++;
        if ($l['categoria_ia'] === null) { $erros++; continue; }
        $comResp++;
        $matriz[$l['categoria_atual']][$l['categoria_ia']] = ($matriz[$l['categoria_atual']][$l['categoria_ia']] ?? 0) + 1;
        if ($l['categoria_atual'] === $l['categoria_ia']) $acertos++;
        if ((int)$l['revisao'] === 1) $revisoes++;
    }
    return ['execucao' => $execucao, 'total' => $total, 'respondidas' => $comResp, 'erros' => $erros, 'concordancia' => $comResp ? round($acertos / $comResp * 100, 1) : null,
            'com_revisao' => $revisoes, 'matriz' => $matriz, 'divergentes' => array_values(array_filter($linhas, fn($l) => $l['categoria_ia'] !== null && $l['categoria_ia'] !== $l['categoria_atual']))];
}

// Dispara a avaliação em segundo plano (demora minutos: ~6 s por chave).
function iaIniciarAvaliacaoEmSegundoPlano($limite) {
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'ia_avaliacao.php';
    $php = PHP_BINARY;
    if (PHP_OS_FAMILY === 'Windows') { pclose(popen('start /B "" "' . $php . '" "' . $script . '" ' . (int)$limite . ' > NUL 2>&1', 'r')); }
    else { exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . (int)$limite . ' > /dev/null 2>&1 &'); }
}
