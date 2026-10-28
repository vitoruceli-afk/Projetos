<?php
// Ambiente dos testes: usa um banco PRÓPRIO (produtividade_aw_test) — nunca o banco de trabalho. Só roda pela linha de comando.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!defined('DB_NAME')) define('DB_NAME', getenv('TESTE_DB_NAME') ?: 'produtividade_aw_test');
if (DB_NAME === 'produtividade_aw') { fwrite(STDERR, "Recusado: os testes não rodam no banco de trabalho.\n"); exit(2); }
require_once dirname(__DIR__) . '/config.php';

// ---- mini framework ----
$GLOBALS['__testes'] = []; $GLOBALS['__falhas'] = 0; $GLOBALS['__asserts'] = 0;
function teste($nome, callable $fn) { $GLOBALS['__testes'][] = [$nome, $fn]; }
function afirmar($cond, $msg) { $GLOBALS['__asserts']++; if (!$cond) throw new RuntimeException('FALHOU: ' . $msg); }
function igual($esperado, $obtido, $msg) { afirmar($esperado === $obtido || $esperado == $obtido && !is_array($esperado), $msg . ' (esperado ' . json_encode($esperado) . ', obtido ' . json_encode($obtido) . ')'); }
function lanca(callable $fn, $trecho, $msg) {
    $ok = false;
    try { $fn(); } catch (Throwable $e) { $ok = ($trecho === '' || stripos($e->getMessage(), $trecho) !== false); if (!$ok) throw new RuntimeException("FALHOU: {$msg} — exceção inesperada: " . $e->getMessage()); }
    afirmar($ok, $msg . ' (esperava exceção)');
}
function executarTestes() {
    $t0 = microtime(true);
    foreach ($GLOBALS['__testes'] as [$nome, $fn]) {
        try { $fn(); echo "  [OK]    {$nome}\n"; }
        catch (Throwable $e) { $GLOBALS['__falhas']++; echo "  [FALHA] {$nome}\n          " . $e->getMessage() . "\n          em " . basename($e->getFile()) . ':' . $e->getLine() . "\n"; }
    }
    $n = count($GLOBALS['__testes']);
    printf("\n%d teste(s), %d verificação(ões), %d falha(s) — %.1fs\n", $n, $GLOBALS['__asserts'], $GLOBALS['__falhas'], microtime(true) - $t0);
    exit($GLOBALS['__falhas'] > 0 ? 1 : 0);
}

// ---- dados de teste ----
function db() { return getDB(); }
function resetar() {
    $d = db();
    $d->exec("SET FOREIGN_KEY_CHECKS = 0");
    foreach (['ia_avaliacao', 'ia_log', 'ia_sugestoes', 'eventos', 'buckets', 'categoria_regras', 'categorias', 'maquinas'] as $t) $d->exec("TRUNCATE TABLE {$t}");
    $d->exec("SET FOREIGN_KEY_CHECKS = 1");
    $d->exec("UPDATE ia_config SET modo = 'revisao', max_tentativas = 3, lote_por_execucao = 10, limite_segundos = 50, enviar_titulo = 1, ultima_descoberta = NULL WHERE id = 1");
    $d->exec("INSERT INTO categorias (id, nome, cor, pontuacao, ordem) VALUES (1, 'Produtivo', '#16a34a', 1, 10), (2, 'Improdutivo', '#dc2626', -1, 20), (3, 'Neutro', '#94a2b8', 0, 30)");
    $d->exec("INSERT INTO maquinas (id, nome, host, porta, ativo) VALUES (1, 'MAQ-TESTE', 'maq-teste.local', 5600, 1)");
    $d->exec("INSERT INTO buckets (id, maquina_id, bucket_id, tipo) VALUES (1, 1, 'aw-watcher-window_t', 'window'), (2, 1, 'aw-watcher-web_t', 'web')");
    awRegrasCategorizacao($d, true);
}
function evento($tipo, $seg, $app, $titulo, $url, $cat = null) {
    static $n = 0; $n++;
    db()->prepare("INSERT INTO eventos (bucket_id, maquina_id, tipo, ts, duracao, app, titulo, url, categoria_id) VALUES (:b, 1, :t, :ts, :d, :a, :ti, :u, :c)")
        ->execute([':b' => $tipo === 'web' ? 2 : 1, ':t' => $tipo, ':ts' => gmdate('Y-m-d H:i:s', time() - 3600 - $n * 10) . '.000', ':d' => $seg, ':a' => $app, ':ti' => $titulo, ':u' => $url, ':c' => $cat]);
    return (int)db()->lastInsertId();
}
function regra($cat, $campo, $tipo, $padrao, $prio = 50, $origem = 'manual') {
    db()->prepare("INSERT INTO categoria_regras (categoria_id, campo, tipo, padrao, prioridade, origem) VALUES (:c, :campo, :t, :p, :pr, :o)")
        ->execute([':c' => $cat, ':campo' => $campo, ':t' => $tipo, ':p' => $padrao, ':pr' => $prio, ':o' => $origem]);
    awRegrasCategorizacao(db(), true);
    return (int)db()->lastInsertId();
}
function catEvento($id) { $v = db()->query("SELECT categoria_id FROM eventos WHERE id = " . (int)$id)->fetchColumn(); return $v === null || $v === false ? null : (int)$v; }
// Transporte falso do Ollama: $respostas = lista de JSON (string) devolvidos em ordem, ou uma Closure; registra as chamadas em $GLOBALS['__chamadas'].
function transporteFalso($respostas) {
    $GLOBALS['__chamadas'] = [];
    return function ($metodo, $url, $corpo, $timeout) use ($respostas) {
        $GLOBALS['__chamadas'][] = ['metodo' => $metodo, 'url' => $url, 'corpo' => $corpo];
        $r = $respostas instanceof Closure ? $respostas(count($GLOBALS['__chamadas'])) : ($respostas[count($GLOBALS['__chamadas']) - 1] ?? end($respostas));
        if ($r instanceof Throwable) throw $r;
        if (is_array($r)) return $r;
        return [200, json_encode(['message' => ['content' => $r]]), ''];
    };
}
function respostaIA($cat, $revisao = false, $just = 'ok') { return json_encode(['categoria' => $cat, 'justificativa' => $just, 'revisao' => $revisao]); }
function executarPhp($codigo) {
    $arq = tempnam(sys_get_temp_dir(), 'tst'); file_put_contents($arq, "<?php\n" . $codigo);
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 ' . escapeshellarg($arq) . ' 2>&1'); @unlink($arq);
    return (string)$out;
}
