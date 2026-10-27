<?php
// Pré-aquece o cache das telas principais do dashboard (ver cache_pagina.php), para que quem abre a tela
// quase sempre encontre a página pronta. Roda pelo cron, por exemplo a cada 4 minutos:
//   */4 5-23 * * *  www-data  php /var/www/produtividade.faesa.br/prewarm_dashboard.php >> /var/log/produtividade_prewarm.log 2>&1
// Cada combinação (perfil × tela × período) roda em um processo PHP próprio — a view declara funções e não
// pode ser incluída duas vezes no mesmo processo — e vários processos rodam ao mesmo tempo (uma consulta usa
// um núcleo; com vários vCPUs, as telas são geradas em paralelo).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__);

const PREWARM_TTL = 300;       // tem que ser o mesmo ttl usado em layout.php
const PREWARM_PARALELO = 4;

if (($argv[1] ?? '') === '--job') {
    // ---- processo filho: gera UMA tela e grava no cache ----
    [, , $perfil, $visao, $periodo, $usuarioSessao] = $argv;
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/index.php';
    $_GET = ['page' => 'dashboard', 'visao' => $visao, 'periodo' => $periodo, 'nocache' => 1];
    $_SERVER['QUERY_STRING'] = http_build_query($_GET);
    require_once 'config.php';
    @session_start();
    $_SESSION['user_logged_in'] = $usuarioSessao; $_SESSION['last_activity'] = time();
    require_once 'cache_pagina.php';
    $page = 'dashboard';
    ob_start();
    paginaComCache('dashboard', PREWARM_TTL, function () { include 'views/dashboard.php'; });
    ob_end_clean();
    exit(0);
}

// ---- processo principal ----
require_once 'cache_pagina.php';
require_once 'config.php';
$trava = fopen(sys_get_temp_dir() . '/produtividade_prewarm.lock', 'c');
if (!$trava || !flock($trava, LOCK_EX | LOCK_NB)) { echo date('c') . " prewarm anterior ainda em execução, pulando\n"; exit(0); }

$db = getDB();
$admin = $db->query("SELECT username FROM local_users WHERE role = 'admin' AND enabled = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (!$admin) { echo date('c') . " sem administrador local ativo, nada a fazer\n"; exit(0); }

$jobs = [];
foreach (['adm' => $admin, 'usr' => '__prewarm_usuario__'] as $perfil => $usuarioSessao)
    foreach (['geral', 'setor', 'usuarios'] as $visao)
        foreach (['hoje', '7d', '30d'] as $periodo)
            $jobs[] = [$perfil, $visao, $periodo, $usuarioSessao];

$t0 = microtime(true);
$ativos = []; $falhas = 0; $feitos = 0;
while ($jobs || $ativos) {
    while ($jobs && count($ativos) < PREWARM_PARALELO) {
        $j = array_shift($jobs);
        $cmd = [PHP_BINARY, '-d', 'memory_limit=1G', __FILE__, '--job', ...$j];
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (is_resource($p)) { fclose($pipes[0]); $ativos[] = [$p, $pipes, $j]; }
        else $falhas++;
    }
    foreach ($ativos as $i => [$p, $pipes, $j]) {
        $st = proc_get_status($p);
        if ($st['running']) continue;
        $err = trim(stream_get_contents($pipes[2])); fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($p);
        if ($st['exitcode'] > 0) $code = $st['exitcode'];
        if ($code !== 0 || $err !== '') { $falhas++; echo date('c') . ' falha em ' . implode('/', array_slice($j, 0, 3)) . ': ' . mb_substr($err, 0, 300) . "\n"; }
        else $feitos++;
        unset($ativos[$i]);
    }
    usleep(100000);
}

// limpa páginas de cache com mais de 1 dia (combinações que ninguém abre mais)
foreach (glob(dirCachePaginas() . '/*.{html,tmp}', GLOB_BRACE) ?: [] as $arq) if (filemtime($arq) < time() - 86400) @unlink($arq);

printf("%s prewarm: %d telas em %.1fs, %d falha(s)\n", date('c'), $feitos, microtime(true) - $t0, $falhas);
