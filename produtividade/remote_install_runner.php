<?php
// Executor de uma instalação remota enfileirada. NÃO é uma tela — é disparado em segundo plano
// (start /B) por install_client.php assim que o admin clica em "Instalar" na tela de Máquinas, e
// roda uma única instalação (o id vem por argumento) antes de terminar. Não é uma tarefa agendada
// como o sync_runner.php: cada clique dispara um processo novo.
//
//   php-win.exe C:\xampp\htdocs\produtividade\remote_install_runner.php <id_da_instalacao>

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Este script só pode ser executado pela linha de comando.');
}

require_once __DIR__ . '/config.php';

$instalacaoId = (int)($argv[1] ?? 0);
if ($instalacaoId <= 0) {
    fwrite(STDERR, "Uso: remote_install_runner.php <id_da_instalacao>\n");
    exit(1);
}

$db = getDB();
executarInstalacaoRemota($db, $instalacaoId);
