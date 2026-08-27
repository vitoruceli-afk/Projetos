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

// Passos marcados como 'running' são os que o botão "Executar agora" da tela reivindicou. Se
// aquela requisição morreu no meio (navegador fechado, PHP interrompido), o passo ficaria preso
// para sempre — depois de alguns minutos devolvemos ele para 'pending' e o executor assume.
$db->exec("UPDATE schedule_actions SET status = 'pending'
           WHERE status = 'running' AND claimed_at IS NOT NULL
             AND claimed_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");

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

$markExpired = $db->prepare("UPDATE schedule_actions SET status = 'expired', attempts = attempts + 1, last_error = :err WHERE id = :id");

// Uma conexão por roteador, reaproveitada entre os passos do mesmo ciclo.
$apis = [];
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

    // Reivindica o passo antes de aplicar. Se o botão "Executar agora" da tela pegou este mesmo
    // passo primeiro, o UPDATE não afeta nenhuma linha e nós simplesmente pulamos — evita os
    // dois caminhos aplicarem (e registrarem no log) a mesma ação.
    $claim = $db->prepare("UPDATE schedule_actions SET status = 'running', claimed_at = NOW() WHERE id = :id AND status = 'pending'");
    $claim->execute([':id' => $action['id']]);
    if ($claim->rowCount() === 0) {
        echo "[PULADO] {$desc} — já estava sendo executado em outro lugar.\n";
        continue;
    }

    $api = scheduleRouterApi($db, (int)$action['router_id'], $apis);
    $err = null;
    if (scheduleApplyAction($db, $action, $api, $err)) {
        echo "[OK] {$desc} -> disabled={$action['desired_disabled']}\n";
        $okCount++;
    } else {
        echo "[ERRO] {$desc} — {$err}\n";
        $failCount++;
    }
}

foreach ($apis as $api) {
    if ($api) { $api->disconnect(); }
}

echo "[" . date('Y-m-d H:i:s') . "] concluído: {$okCount} aplicada(s), {$failCount} com problema.\n";
