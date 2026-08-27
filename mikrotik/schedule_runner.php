<?php
// Executor dos agendamentos. NÃO é uma tela — roda pela linha de comando (Tarefa Agendada do
// Windows / cron no Linux), idealmente a cada 1 minuto.
//
//   php C:\xampp\htdocs\mikrotik\schedule_runner.php
//
// O que ele faz: procura em schedule_actions tudo que já venceu (run_at <= agora) e ainda está
// 'pending', e aplica no MikroTik correspondente. Cada passo é marcado como 'done' assim que
// aplicado, então rodar o script várias vezes nunca repete uma ação já executada.
//
// Passos vencidos há muito tempo (ex: servidor desligado durante a janela) ainda são aplicados
// até GRACE_MINUTES depois do horário — assim uma queda curta não deixa um hotspot desligado
// para sempre. Passou disso, é marcado como 'expired' e registrado no log, sem aplicar nada
// às cegas num horário em que o efeito seria inesperado.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Este script só pode ser executado pela linha de comando.');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/RouterosAPI.php';

// Janela de tolerância para aplicar um passo atrasado (minutos).
const GRACE_MINUTES = 120;

$db = getDB();
$now = date('Y-m-d H:i:s');

// O rótulo vem de schedule_targets (o item específico deste passo). O COALESCE cobre
// agendamentos antigos, criados quando um evento tinha um único alvo guardado em schedules.
$stmt = $db->prepare("
    SELECT sa.*, s.name AS schedule_name,
           COALESCE(st.target_label, s.target_label, sa.target_id) AS target_label
    FROM schedule_actions sa
    JOIN schedules s ON s.id = sa.schedule_id
    LEFT JOIN schedule_targets st
           ON st.schedule_id = sa.schedule_id AND st.target_id = sa.target_id
    WHERE sa.status = 'pending' AND sa.run_at <= :now
    ORDER BY sa.run_at ASC
");
$stmt->bindValue(':now', $now);
$stmt->execute();
$due = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($due)) {
    echo "[" . $now . "] nada pendente.\n";
    exit(0);
}

$markDone = $db->prepare("UPDATE schedule_actions SET status = 'done', executed_at = NOW(), attempts = attempts + 1, last_error = NULL WHERE id = :id");
$markError = $db->prepare("UPDATE schedule_actions SET status = 'pending', attempts = attempts + 1, last_error = :err WHERE id = :id");
$markExpired = $db->prepare("UPDATE schedule_actions SET status = 'expired', attempts = attempts + 1, last_error = :err WHERE id = :id");

// Uma conexão por roteador, reaproveitada entre os passos do mesmo ciclo.
$apis = [];
function runnerGetApi($routerId, array &$apis, PDO $db) {
    if (array_key_exists($routerId, $apis)) {
        return $apis[$routerId];
    }
    $stmt = $db->prepare("SELECT * FROM routers WHERE id = :id");
    $stmt->bindValue(':id', $routerId, PDO::PARAM_INT);
    $stmt->execute();
    $router = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$router) {
        $apis[$routerId] = null;
        return null;
    }

    $api = new RouterosAPI();
    $api->port = (int)($router['port'] ?? 8728);
    $api->timeout = 5;
    if (!@$api->connect($router['ip'], $router['username'], routerDecrypt($router['password']))) {
        $apis[$routerId] = null;
        return null;
    }
    $apis[$routerId] = $api;
    return $api;
}

$graceSeconds = GRACE_MINUTES * 60;
$okCount = 0;
$failCount = 0;

foreach ($due as $action) {
    $label = $action['target_label'] !== '' ? $action['target_label'] : $action['target_id'];
    $desc = "{$action['schedule_name']} — {$action['phase']} — {$label}";

    // Atrasado demais: não aplica, só registra.
    if ((time() - strtotime($action['run_at'])) > $graceSeconds) {
        $msg = "Passo vencido há mais de " . GRACE_MINUTES . " min (previsto para {$action['run_at']}); não aplicado.";
        $markExpired->execute([':err' => $msg, ':id' => $action['id']]);
        logActivity('schedule', 'Agendamento expirado sem execução', $desc . ' — ' . $msg);
        echo "[EXPIRADO] {$desc}\n";
        $failCount++;
        continue;
    }

    $api = runnerGetApi((int)$action['router_id'], $apis, $db);
    if (!$api) {
        $msg = 'Falha ao conectar no MikroTik (roteador ' . $action['router_id'] . ').';
        $markError->execute([':err' => $msg, ':id' => $action['id']]);
        echo "[ERRO] {$desc} — {$msg}\n";
        $failCount++;
        continue;
    }

    $command = $action['target_type'] === 'firewall' ? '/ip/firewall/filter/set' : '/ip/hotspot/set';

    try {
        $result = $api->comm($command, ['.id' => $action['target_id'], 'disabled' => $action['desired_disabled']], 10);
        $routerError = is_array($result) ? ($result[0]['message'] ?? null) : null;

        if ($routerError) {
            $msg = 'MikroTik recusou: ' . $routerError;
            $markError->execute([':err' => $msg, ':id' => $action['id']]);
            echo "[ERRO] {$desc} — {$msg}\n";
            $failCount++;
            continue;
        }

        $markDone->execute([':id' => $action['id']]);

        $acao = $action['target_type'] === 'hotspot'
            ? ($action['desired_disabled'] === 'yes' ? 'Desabilitou hotspot' : 'Habilitou hotspot')
            : ($action['desired_disabled'] === 'yes' ? 'Desabilitou regra de firewall' : 'Habilitou regra de firewall');

        logActivity('schedule', $acao . ' (agendamento)', $desc);
        echo "[OK] {$desc} -> disabled={$action['desired_disabled']}\n";
        $okCount++;
    } catch (Throwable $e) {
        $msg = 'Exceção ao aplicar: ' . $e->getMessage();
        $markError->execute([':err' => $msg, ':id' => $action['id']]);
        echo "[ERRO] {$desc} — {$msg}\n";
        $failCount++;
    }
}

foreach ($apis as $api) {
    if ($api) { $api->disconnect(); }
}

echo "[" . date('Y-m-d H:i:s') . "] concluído: {$okCount} aplicada(s), {$failCount} com problema.\n";
