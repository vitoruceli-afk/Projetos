<?php
// Executor da sincronização com o ActivityWatch das máquinas da rede. NÃO é uma tela — roda pela
// linha de comando (Tarefa Agendada do Windows), a cada 1 ou 2 minutos:
//
//   php C:\xampp\htdocs\produtividade\sync_runner.php
//
// Cada máquina ativa só é de fato sincronizada quando já passou o seu intervalo_sync_min desde a
// última vez (ou nunca sincronizou) — então não tem problema nenhum chamar este script com mais
// frequência do que os intervalos configurados; ele só faz trabalho quando há algo vencido.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Este script só pode ser executado pela linha de comando.');
}

require_once __DIR__ . '/config.php';

$db = getDB();

$stmt = $db->query("SELECT * FROM maquinas WHERE ativo = 1
    AND (ultimo_sync_at IS NULL OR ultimo_sync_at <= DATE_SUB(NOW(), INTERVAL intervalo_sync_min MINUTE))");
$devidas = $stmt->fetchAll();

if (empty($devidas)) {
    echo "[" . date('Y-m-d H:i:s') . "] nenhuma máquina devida para sincronização.\n";
    exit(0);
}

foreach ($devidas as $maquina) {
    $resultado = sincronizarMaquina($db, $maquina, 'agendado');
    $status = $resultado['ok'] ? (empty($resultado['aviso']) ? 'ok' : 'parcial: ' . $resultado['aviso']) : ('erro: ' . $resultado['erro']);
    echo "[" . date('Y-m-d H:i:s') . "] {$maquina['nome']} ({$maquina['host']}:{$maquina['porta']}) — {$status} — {$resultado['eventos_novos']} evento(s)\n";
}
