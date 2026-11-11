<?php
// Herança de categoria aba -> janela do navegador e cores do card "Top 10" por título.

function evt($tipo, $tsUtc, $app, $titulo, $url, $cat = null, $seg = 60) {
    db()->prepare("INSERT INTO eventos (bucket_id, maquina_id, tipo, ts, duracao, app, titulo, url, categoria_id) VALUES (:b, 1, :t, :ts, :d, :a, :ti, :u, :c)")
        ->execute([':b' => $tipo === 'web' ? 2 : 1, ':t' => $tipo, ':ts' => $tsUtc, ':d' => $seg, ':a' => $app, ':ti' => $titulo, ':u' => $url, ':c' => $cat]);
    return (int)db()->lastInsertId();
}
function regrasNavegador() {
    regra(3, 'app', 'contem', 'Chrome', 90);                    // navegador sozinho = Neutro
    regra(3, 'titulo', 'contem', 'WhatsApp', 90);               // WhatsApp = Neutro
    regra(1, 'titulo', 'contem', 'Chamado', 10);                // regra de título ampla (Produtivo)
    regra(1, 'url', 'regex', '^(blob:)?[a-z]+://([^/?#]*\\.)?markchat\\.com\\.br([:/?#]|$)', 10);
    regra(2, 'url', 'regex', '^(blob:)?[a-z]+://([^/?#]*\\.)?youtube\\.com([:/?#]|$)', 50);
    regra(3, 'url', 'regex', '^(blob:)?[a-z]+://([^/?#]*\\.)?web\\.whatsapp\\.com([:/?#]|$)', 90);
}

teste('22. herança: janela sem aba correspondente NÃO herda de outro site aberto no mesmo instante', function () {
    resetar(); regrasNavegador();
    $t = gmdate('Y-m-d H:i:s', time() - 7200);
    // aba do MarkChat (Produtivo) no mesmo segundo, mas a janela é do WhatsApp e a aba do WhatsApp não foi registrada
    evt('web', "{$t}.100", null, 'MarkChat - Agente', 'https://faesa.markchat.com.br/agente', 1);
    $w = evt('window', "{$t}.000", 'chrome.exe', 'WhatsApp - Google Chrome', null, 1);   // herdado errado antes = Produtivo
    herdarCategoriaDasAbas(db());
    igual(3, catEvento($w), 'WhatsApp volta para Neutro (regra da própria janela), sem herdar o MarkChat');
});

teste('23. herança: janela com aba correspondente herda a categoria específica da aba (mesmo com título "amplo")', function () {
    resetar(); regrasNavegador();
    $t = gmdate('Y-m-d H:i:s', time() - 7200);
    evt('web', "{$t}.100", null, 'Chamado do além - YouTube', 'https://www.youtube.com/watch?v=x', 2);
    $w = evt('window', "{$t}.000", 'chrome.exe', 'Chamado do além - YouTube - Google Chrome', null, 1);   // título casa "Chamado" (Produtivo)
    herdarCategoriaDasAbas(db());
    igual(2, catEvento($w), 'vídeo do YouTube fica Improdutivo pela aba, não Produtivo pelo título "Chamado"');
    // MarkChat: aba produtiva com título correspondente
    $t2 = gmdate('Y-m-d H:i:s', time() - 3600);
    evt('web', "{$t2}.050", null, 'MarkChat - Agente', 'https://faesa.markchat.com.br/', 1);
    $w2 = evt('window', "{$t2}.000", 'chrome.exe', 'MarkChat - Agente - Google Chrome', null, 3);
    herdarCategoriaDasAbas(db());
    igual(1, catEvento($w2), 'MarkChat herda Produtivo da aba correspondente');
});

teste('24. herança: aba correspondente neutra ou sem categoria -> vale a regra da própria janela', function () {
    resetar(); regrasNavegador();
    $t = gmdate('Y-m-d H:i:s', time() - 7200);
    evt('web', "{$t}.100", null, 'WhatsApp', 'https://web.whatsapp.com/', 3);
    $w = evt('window', "{$t}.000", 'chrome.exe', '(2) WhatsApp - Google Chrome', null, 2);   // estava Improdutivo
    $t2 = gmdate('Y-m-d H:i:s', time() - 3000);
    evt('web', "{$t2}.100", null, 'Página qualquer', 'https://site-sem-regra.com.br/', null);
    $w2 = evt('window', "{$t2}.000", 'chrome.exe', 'Página qualquer - Google Chrome', null, 1);   // estava Produtivo por herança antiga
    $n = herdarCategoriaDasAbas(db());
    igual(3, catEvento($w), 'WhatsApp com aba neutra = Neutro');
    igual(3, catEvento($w2), 'aba sem categoria: janela fica com a regra do navegador (Neutro)');
    igual(2, $n, 'duas janelas corrigidas');
    igual(0, herdarCategoriaDasAbas(db()), 'rodar de novo não muda nada (idempotente)');
});

teste('25. Top 10 por aplicativo: cada título vem com a cor da SUA categoria', function () {
    resetar(); regrasNavegador();
    db()->exec("DELETE FROM local_users WHERE username = 't_adm'");
    db()->exec("INSERT INTO local_users (username, password_hash, enabled, role) VALUES ('t_adm', 'x', 1, 'admin')");
    db()->exec("UPDATE categorias SET cor = '#00aa00' WHERE id = 1"); db()->exec("UPDATE categorias SET cor = '#cc0000' WHERE id = 2"); db()->exec("UPDATE categorias SET cor = '#999999' WHERE id = 3");
    $dia = (new DateTime('today 12:00', new DateTimeZone('America/Sao_Paulo')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    evt('window', "{$dia}.000", 'chrome.exe', 'WhatsApp - Google Chrome', null, 3, 3000);
    evt('window', "{$dia}.500", 'chrome.exe', 'MarkChat - Agente - Google Chrome', null, 1, 2400);
    evt('window', "{$dia}.700", 'chrome.exe', 'Spotify – Músicas curtidas - Google Chrome', null, 2, 1200);
    evt('window', "{$dia}.900", 'chrome.exe', 'Spotify – Músicas curtidas - Google Chrome', null, 1, 60);   // minoria: predomina Improdutivo
    $painel = 'p' . substr(md5('|'), 0, 6);
    $get = ['page' => 'dashboard', 'visao' => 'geral', 'periodo' => 'hoje', 'detalhe' => 'apptitulos', 'painel' => $painel, 'app' => 'chrome.exe'];
    $out = executarPhp("define('DB_NAME', '" . DB_NAME . "'); chdir('" . addslashes(dirname(__DIR__)) . "'); require 'config.php'; @session_start(); \$_SESSION['user_logged_in'] = 't_adm'; \$_SESSION['last_activity'] = time();
        \$_SERVER['REQUEST_METHOD'] = 'GET'; \$_GET = " . var_export($get, true) . "; \$_SERVER['QUERY_STRING'] = http_build_query(\$_GET); include 'index.php';");
    $j = json_decode(substr($out, strpos($out, '{')), true);
    afirmar(is_array($j) && isset($j['cores']), 'resposta tem as cores por título: ' . substr($out, 0, 200));
    $cor = array_combine($j['labels'], $j['cores']);
    igual('#999999', $cor['WhatsApp - Google Chrome'], 'WhatsApp cinza (Neutro)');
    igual('#00aa00', $cor['MarkChat - Agente - Google Chrome'], 'MarkChat verde (Produtivo)');
    igual('#cc0000', $cor['Spotify – Músicas curtidas - Google Chrome'], 'Spotify vermelho (Improdutivo predominante)');
    igual(['WhatsApp - Google Chrome', 'MarkChat - Agente - Google Chrome', 'Spotify – Músicas curtidas - Google Chrome'], $j['labels'], 'ordenado pelo tempo');
});
