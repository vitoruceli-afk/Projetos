<?php
// Orquestra a instalação remota do MSI (ver Instaladores\msi-build) numa máquina da rede, via
// WMI/DCOM (não depende de WinRM, que não está habilitado em nenhuma máquina do domínio hoje).
// O trabalho pesado (copiar o MSI, autenticar, rodar o msiexec remoto, esperar terminar) roda num
// script à parte, chamado por remote_install_runner.php num processo PHP à parte — instalar pode
// levar mais de um minuto (arquivo de 85 MB + instalação silenciosa), tempo incompatível com uma
// requisição HTTP síncrona. Dois scripts fazem o MESMO trabalho (mesmo contrato stdin/stdout JSON,
// ver cabeçalho de qualquer um deles), escolhidos por PHP_OS_FAMILY: remote_install.ps1 (Windows,
// usa CIM/WMI nativo do PowerShell) ou remote_install.py (Linux, usa impacket pra fazer SMB admin$
// + WMI/DCOM sem depender de nada nativo do Windows — pedido explícito pra Instalação Remota
// funcionar também a partir do servidor de homologação em Ubuntu).

function getInstalacaoConfig(PDO $db) {
    return $db->query("SELECT * FROM instalacao_remota_config ORDER BY id ASC LIMIT 1")->fetch();
}

// Enfileira uma ação remota (instalar/atualizar/desinstalar) para a máquina informada e dispara
// imediatamente um processo PHP em segundo plano para executá-la (start /B: sobrevive ao fim desta
// requisição). A tela de Máquinas consulta o progresso via ajax_status_instalacao.php, fazendo
// polling pelo id retornado. "instalar" e "atualizar" são o MESMO fluxo (rodar o MSI atual de novo
// — o MajorUpgrade do próprio pacote troca a versão instalada); só o rótulo exibido muda.
function dispararInstalacaoRemota(PDO $db, array $maquina, $acao = 'instalar') {
    if (!in_array($acao, ['instalar', 'atualizar', 'desinstalar'], true)) $acao = 'instalar';
    $stmt = $db->prepare("INSERT INTO instalacoes_remotas (maquina_id, acao, iniciado_em, status) VALUES (:m, :a, :i, 'fila')");
    $stmt->execute([':m' => $maquina['id'], ':a' => $acao, ':i' => date('Y-m-d H:i:s')]);
    $instalacaoId = (int)$db->lastInsertId();

    if (PHP_OS_FAMILY === 'Windows') {
        $phpWin = 'C:\\xampp\\php\\php-win.exe';
        $runner = __DIR__ . '\\remote_install_runner.php';
        $cmd = 'start "" /B ' . escapeshellarg($phpWin) . ' ' . escapeshellarg($runner) . ' ' . (int)$instalacaoId . ' > NUL 2>&1';
        $proc = popen('start "" /B cmd /c ' . escapeshellarg($cmd), 'r');
        if ($proc !== false) {
            pclose($proc);
        }
    } else {
        // "&" ao final (shell POSIX) é o equivalente do "start /B" do Windows acima - sobrevive ao
        // fim desta requisição. /dev/null descarta a saída (sem log aqui; o resultado de verdade
        // vai pra instalacoes_remotas.log via finalizarInstalacaoRemota()).
        $runner = __DIR__ . '/remote_install_runner.php';
        exec('php ' . escapeshellarg($runner) . ' ' . (int)$instalacaoId . ' > /dev/null 2>&1 &');
    }

    return $instalacaoId;
}

// Executa de fato uma instalação enfileirada (chamado só pelo remote_install_runner.php, em CLI).
function executarInstalacaoRemota(PDO $db, $instalacaoId) {
    $stmt = $db->prepare("SELECT i.*, m.nome AS maquina_nome, m.host, m.ad_dn FROM instalacoes_remotas i
                           JOIN maquinas m ON m.id = i.maquina_id WHERE i.id = :id");
    $stmt->execute([':id' => $instalacaoId]);
    $instalacao = $stmt->fetch();
    if (!$instalacao) {
        return;
    }

    $db->prepare("UPDATE instalacoes_remotas SET status = 'executando' WHERE id = :id")->execute([':id' => $instalacaoId]);

    $config = getInstalacaoConfig($db);
    if (empty($config['admin_usuario'])) {
        finalizarInstalacaoRemota($db, $instalacaoId, false, 'Nenhuma credencial de administrador configurada em Máquinas > Instalação Remota.', '');
        return;
    }

    $desinstalando = $instalacao['acao'] === 'desinstalar';

    $payload = [
        'computerName' => $instalacao['host'],
        'username' => $config['admin_usuario'],
        'password' => installDecrypt($config['admin_senha']),
        'timeoutSegundos' => (int)$config['timeout_segundos'],
    ];
    if (!$desinstalando) {
        $payload['msiPath'] = $config['msi_path'];
        $payload['serverIp'] = AW_SERVIDOR_IP;
    }

    if (PHP_OS_FAMILY === 'Windows') {
        $script = __DIR__ . ($desinstalando ? '\\remote_uninstall.ps1' : '\\remote_install.ps1');
        $cmd = 'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File ' . escapeshellarg($script);
    } else {
        $script = __DIR__ . ($desinstalando ? '/remote_uninstall.py' : '/remote_install.py');
        $cmd = escapeshellarg(INSTALL_PYTHON_BIN) . ' ' . escapeshellarg($script);
    }

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes);

    if (!is_resource($proc)) {
        finalizarInstalacaoRemota($db, $instalacaoId, false, 'Falha ao iniciar o processo do PowerShell.', '');
        return;
    }

    fwrite($pipes[0], json_encode($payload, JSON_UNESCAPED_UNICODE));
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $codigoSaida = proc_close($proc);

    $resultado = json_decode(trim($stdout), true);
    if ($resultado === null) {
        // Sem JSON no stdout = o script nem chegou ao fim (ex.: o próprio powershell/python caiu).
        // O código de saída ajuda a distinguir: -1073741819 (0xC0000005) = violação de acesso à
        // memória (crash nativo), 1 = erro não tratado do script.
        $detalhe = 'Resposta inválida do script de instalação (código de saída ' . $codigoSaida . ').';
        finalizarInstalacaoRemota($db, $instalacaoId, false, $detalhe,
            "código de saída: {$codigoSaida}\n--- stdout ---\n{$stdout}\n--- stderr ---\n{$stderr}");
        return;
    }

    finalizarInstalacaoRemota($db, $instalacaoId, (bool)$resultado['ok'], $resultado['mensagem'] ?? '', $resultado['log'] ?? '');
}

function finalizarInstalacaoRemota(PDO $db, $instalacaoId, $ok, $mensagem, $log) {
    $db->prepare("UPDATE instalacoes_remotas SET finalizado_em = :f, status = :s, mensagem = :m, log = :l WHERE id = :id")
       ->execute([
           ':f' => date('Y-m-d H:i:s'),
           ':s' => $ok ? 'ok' : 'erro',
           ':m' => mb_substr($mensagem, 0, 500),
           ':l' => $log,
           ':id' => $instalacaoId,
       ]);
}
