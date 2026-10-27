<?php
// Cache de página curto (arquivo) para as telas pesadas do dashboard. Cada tela do dashboard roda ~12 consultas
// que somam dezenas de milhares de eventos (de 2 a 10 s por carga); os dados só mudam a cada sincronização
// (a cada ~5 min por máquina), então repetir tudo a cada clique/recarga não traz informação nova.
// O cache vale para quem abre a MESMA tela (mesmos parâmetros, em qualquer ordem) com o mesmo perfil
// (administrador ou não — há botões só do administrador), por $ttl segundos. "&nocache=1" na URL força
// recalcular e regrava o cache. prewarm_dashboard.php (cron) regrava as telas principais antes de expirarem.

// Pasta do cache: dentro do app (compartilhada entre o Apache e o cron — o /tmp do Apache é privado, o da linha
// de comando não); se não for gravável, cai para a pasta temporária do sistema.
function dirCachePaginas() {
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'cache_paginas';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (is_dir($dir) && is_writable($dir)) {
        // as páginas em cache têm dados do dashboard: nunca servir a pasta pela web
        if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        return $dir;
    }
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'produtividade_cache';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

// Arquivo do cache de uma tela. Os parâmetros entram ordenados e com os padrões explícitos, para que
// "index.php?page=dashboard" e "index.php?periodo=7d&visao=geral&page=dashboard" sejam a mesma tela.
function arquivoCachePagina($chavePagina, $perfil, array $get) {
    unset($get['nocache'], $get['detalhe'], $get['painel'], $get['cat'], $get['app']);
    $get += ['page' => 'dashboard', 'visao' => 'geral', 'periodo' => 'hoje'];
    ksort($get);
    return dirCachePaginas() . DIRECTORY_SEPARATOR . md5($chavePagina . '|' . $perfil . '|' . http_build_query($get)) . '.html';
}

function paginaComCache($chavePagina, $ttl, callable $renderizar) {
    // Requisições de detalhe sob demanda (JSON, ver detalheDashboardSobDemanda) nunca passam pelo cache da
    // página: a view responde o JSON e encerra, e servir o HTML em cache no lugar quebraria o pop-up.
    if (isset($_GET['detalhe'])) { $renderizar(); return; }
    $perfil = function_exists('isAdmin') && isAdmin() ? 'adm' : 'usr';
    $arq = arquivoCachePagina($chavePagina, $perfil, $_GET);
    $forcar = !empty($_GET['nocache']);

    $html = null; $geradoEm = null;
    if (!$forcar && is_file($arq) && (time() - filemtime($arq)) < $ttl) {
        $html = @file_get_contents($arq);
        if ($html !== false) $geradoEm = filemtime($arq);
        else $html = null;
    }
    if ($html === null) {
        ob_start();
        $renderizar();
        $html = ob_get_clean();
        $geradoEm = time();
        // grava de forma atômica (arquivo temporário + rename) para nunca servir um arquivo pela metade
        $tmp = $arq . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $html) !== false) { @chmod($tmp, 0664); @rename($tmp, $arq); }
    }
    echo $html;

    $qs = $_GET; unset($qs['nocache']); $qs['nocache'] = 1;
    echo '<div class="text-muted small mt-3" style="text-align:right">Dados com atualização a cada ' . (int)($ttl / 60) . ' min · gerado às '
        . date('H:i:s', $geradoEm) . ' · <a href="index.php?' . htmlspecialchars(http_build_query($qs)) . '">atualizar agora</a></div>';
}
