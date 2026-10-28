<?php
// Executor da classificação por IA — roda pelo cron (não é uma tela), a cada minuto:
//   * * * * *  www-data  /usr/bin/php /var/www/produtividade.faesa.br/ia_runner.php >> /var/log/produtividade_ia.log 2>&1
// Só trabalha se a IA estiver ligada (modo diferente de "desligado") em Classificação por IA > Configuração. A trava de
// arquivo impede duas execuções simultâneas; cada item é reivindicado de forma atômica no banco. Se o serviço de IA estiver
// fora do ar, os itens ficam pendentes com espera progressiva — a coleta e as demais telas não são afetadas.

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/config.php';

$trava = fopen(sys_get_temp_dir() . '/produtividade_ia_runner.lock', 'c');
if (!$trava || !flock($trava, LOCK_EX | LOCK_NB)) exit(0);

$db = getDB();
$cfg = iaConfig($db);
if ($cfg['modo'] === 'desligado') exit(0);

$novas = iaDescobrirChaves($db, $cfg);
$res = iaProcessarLote($db, $cfg);
$db->exec("DELETE FROM ia_log WHERE ts < DATE_SUB(NOW(), INTERVAL 30 DAY)");

if ($novas > 0 || $res) {
    $cont = array_count_values($res);
    echo '[' . date('Y-m-d H:i:s') . "] modo={$cfg['modo']} chaves novas: {$novas} | processadas: " . count($res) . ($cont ? ' (' . implode(', ', array_map(fn($k, $v) => "{$k}={$v}", array_keys($cont), $cont)) . ')' : '') . "\n";
}
