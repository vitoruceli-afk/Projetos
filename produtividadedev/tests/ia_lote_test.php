<?php
// Testes das ações em lote da tela de Classificação por IA (motor e tela).

teste('16. lote: aprovar, alterar, excluir e rejeitar várias chaves; falha de um item não aborta os demais', function () {
    resetar();
    regra(2, 'url', 'regex', '^(blob:)?[a-z]+://([^/?#]*\\.)?conflito\\.com([:/?#]|$)', 10, 'manual');   // regra do administrador: improdutivo
    $ev = [];
    foreach (['a1.com.br', 'a2.com.br', 'a3.com.br', 'conflito.com'] as $h) $ev[$h] = evento('web', 60, null, '', "https://{$h}/x", null);
    $cfg = iaConfig(db()); iaDescobrirChaves(db(), $cfg, true);
    // 'conflito.com' já nasce categorizado pela regra manual em eventos novos; aqui o evento foi gravado sem categoria de propósito
    db()->exec("UPDATE ia_sugestoes SET estado = 'sugerida', categoria_sugerida = 'produtivo'");
    $ids = db()->query("SELECT valor, id FROM ia_sugestoes")->fetchAll(PDO::FETCH_KEY_PAIR);
    $r = iaLoteAprovar(db(), array_values($ids), 'adm', $cfg);
    igual(3, $r['ok'], '3 aprovadas'); igual(1, count($r['falhas']), '1 falha (conflito com regra do administrador)');
    afirmar($r['falhas'][0][0] === 'conflito.com' && stripos($r['falhas'][0][1], 'regra do administrador') !== false, 'motivo da falha informado');
    igual(1, catEvento($ev['a1.com.br']), 'a1 produtivo'); igual(1, catEvento($ev['a3.com.br']), 'a3 produtivo');
    igual(3, (int)db()->query("SELECT COUNT(*) FROM categoria_regras WHERE origem = 'ia'")->fetchColumn(), '3 regras da IA');
    // alterar em lote para improdutivo
    $apr = [$ids['a1.com.br'], $ids['a2.com.br'], $ids['a3.com.br']];
    $r = iaLoteAlterar(db(), $apr, 2, 'adm');
    igual(3, $r['ok'], '3 alteradas'); igual(3, $r['eventos'], '3 eventos acompanharam');
    igual(2, catEvento($ev['a2.com.br']), 'a2 improdutivo');
    // excluir em lote
    $r = iaLoteExcluir(db(), [$ids['a1.com.br'], $ids['a2.com.br']], 'adm');
    igual(2, $r['ok'], '2 excluídas'); igual(null, catEvento($ev['a1.com.br']), 'a1 sem categoria'); igual(2, catEvento($ev['a3.com.br']), 'a3 intacta');
    igual(1, (int)db()->query("SELECT COUNT(*) FROM categoria_regras WHERE origem = 'ia'")->fetchColumn(), 'sobrou 1 regra da IA');
    // excluir de novo item já excluído: falha isolada, sem exceção
    $r = iaLoteExcluir(db(), [$ids['a1.com.br']], 'adm');
    igual(0, $r['ok'], 'já excluída não conta'); igual(1, count($r['falhas']), 'falha registrada');
});

teste('17. lote: categoria única para todas, rejeição, limites e bloqueio na observação', function () {
    resetar();
    foreach (['b1.com.br', 'b2.com.br'] as $h) evento('web', 60, null, '', "https://{$h}/", null);
    $cfg = iaConfig(db()); iaDescobrirChaves(db(), $cfg, true);
    db()->exec("UPDATE ia_sugestoes SET estado = 'sugerida', categoria_sugerida = 'produtivo'");
    $ids = db()->query("SELECT id FROM ia_sugestoes ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $r = iaLoteAprovar(db(), $ids, 'adm', $cfg, 3);   // todas como Neutro, ignorando a sugestão (produtivo)
    igual(2, $r['ok'], 'aprovadas'); igual(2, (int)db()->query("SELECT COUNT(*) FROM categoria_regras WHERE origem = 'ia' AND categoria_id = 3")->fetchColumn(), 'categoria forçada aplicada');
    lanca(fn() => iaLoteAprovar(db(), [], 'adm', $cfg), 'Nenhum item', 'lote vazio é recusado');
    lanca(fn() => iaLoteRejeitar(db(), range(1, IA_LOTE_MAX + 1), 'adm'), 'no máximo', 'lote acima do limite é recusado');
    evento('web', 60, null, '', 'https://c1.com.br/', null); evento('web', 60, null, '', 'https://c2.com.br/', null);
    iaDescobrirChaves(db(), $cfg, true);
    $novos = db()->query("SELECT id FROM ia_sugestoes WHERE estado = 'pendente'")->fetchAll(PDO::FETCH_COLUMN);
    db()->exec("UPDATE ia_sugestoes SET estado = 'sugerida' WHERE estado = 'pendente'");
    igual(2, iaLoteRejeitar(db(), $novos, 'adm')['ok'], 'rejeitadas em lote');
    igual(2, (int)db()->query("SELECT COUNT(*) FROM ia_sugestoes WHERE estado = 'rejeitada'")->fetchColumn(), 'estado rejeitada');
    db()->exec("UPDATE ia_config SET modo = 'observacao' WHERE id = 1");
    lanca(fn() => iaLoteAprovar(db(), [1], 'adm'), 'observação', 'lote de aprovação bloqueado na observação');
});

teste('18. lote pela tela: POST com CSRF aplica a ação; sem seleção avisa; sem token é recusado', function () {
    resetar();
    db()->exec("DELETE FROM local_users WHERE username = 't_adm'");
    db()->exec("INSERT INTO local_users (username, password_hash, enabled, role) VALUES ('t_adm', 'x', 1, 'admin')");
    foreach (['d1.com.br', 'd2.com.br'] as $h) evento('web', 60, null, '', "https://{$h}/", null);
    iaDescobrirChaves(db(), iaConfig(db()), true);
    db()->exec("UPDATE ia_sugestoes SET estado = 'sugerida', categoria_sugerida = 'improdutivo'");
    $ids = db()->query("SELECT id FROM ia_sugestoes ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $raiz = addslashes(dirname(__DIR__));
    $post = function ($dados, $comToken = true) use ($raiz) {
        $tok = $comToken ? "['csrf_token' => csrfToken()]" : '[]';
        return executarPhp("define('DB_NAME', '" . DB_NAME . "'); chdir('{$raiz}'); require 'config.php'; @session_start(); \$_SESSION['user_logged_in'] = 't_adm'; \$_SESSION['last_activity'] = time();
            \$_SERVER['REQUEST_METHOD'] = 'POST'; \$_GET = ['page' => 'ia', 'aba' => 'revisao']; \$_POST = " . var_export($dados, true) . " + {$tok};
            register_shutdown_function(function () { echo '|FLASH=' . json_encode(\$_SESSION['ia_flash'] ?? null, JSON_UNESCAPED_UNICODE); });
            ob_start(); include 'views/ia.php';");
    };
    $sem = $post(['action' => 'lote_aprovar', 'ids' => $ids], false);
    afirmar(stripos($sem, 'CSRF') !== false, 'sem token é recusado');
    igual(0, (int)db()->query("SELECT COUNT(*) FROM categoria_regras WHERE origem = 'ia'")->fetchColumn(), 'nada aplicado sem token');
    $vazio = $post(['action' => 'lote_aprovar', 'ids' => []]);
    afirmar(strpos($vazio, 'Nenhum item selecionado') !== false, 'sem seleção avisa: ' . substr($vazio, -200));
    $ok = $post(['action' => 'lote_aprovar', 'ids' => $ids]);
    afirmar(strpos($ok, '2 aprovada(s)') !== false, 'resumo do lote: ' . substr($ok, -250));
    igual(2, (int)db()->query("SELECT COUNT(*) FROM categoria_regras WHERE origem = 'ia' AND categoria_id = 2")->fetchColumn(), 'regras criadas na categoria sugerida');
    $exc = $post(['action' => 'lote_excluir', 'ids' => $ids]);
    afirmar(strpos($exc, '2 excluída(s)') !== false, 'excluir em lote pela tela: ' . substr($exc, -250));
    igual(0, (int)db()->query("SELECT COUNT(*) FROM categoria_regras WHERE origem = 'ia'")->fetchColumn(), 'regras removidas');
});
