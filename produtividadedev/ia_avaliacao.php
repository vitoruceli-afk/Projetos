<?php
// Avaliação da IA em modo de observação: compara o modelo com a categorização que o administrador já fez (não altera
// nenhuma categoria). Uso: php ia_avaliacao.php [chaves_por_categoria]   (~6 s por chave). Resultado em Classificação por IA > Avaliação.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/config.php';
$db = getDB();
$trava = fopen(sys_get_temp_dir() . '/produtividade_ia_avaliacao.lock', 'c');
if (!$trava || !flock($trava, LOCK_EX | LOCK_NB)) { echo "avaliação já em execução\n"; exit(0); }
$cfg = iaConfig($db);
$limite = max(1, min(100, (int)($argv[1] ?? 15)));
$exec = iaAvaliar($db, $cfg, $limite);
$r = iaResumoAvaliacao($db, $exec);
echo "avaliação {$exec}: {$r['respondidas']}/{$r['total']} respondidas, concordância " . ($r['concordancia'] ?? '-') . "%, {$r['erros']} erro(s)\n";
