<?php
// Agendamentos de provas/eventos. O usuário cadastra o evento e escolhe um alvo (hotspot ou
// regra de firewall); a aplicação deriva automaticamente os dois passos temporizados
// (SCHEDULE_MARGIN_MINUTES antes do início e depois do fim) em schedule_actions, que são
// aplicados no MikroTik pelo schedule_runner.php — sem precisar de ninguém na tela na hora.
//
// Assim como na tela "Ações", o alvo selecionável é sempre o conjunto liberado pelo
// Administrador (rule_permissions), tanto para Usuário Padrão quanto para Administrador.
$api = getActiveRouterAPI();
if (!$api) { echo "<div class='alert alert-danger'>Conexão falhou ao tentar se comunicar com o MikroTik.</div>"; return; }

$routerId = (int)$_SESSION['active_router'];
$db = getDB();
$formError = '';

$permStmtH = $db->prepare("SELECT rule_id FROM rule_permissions WHERE router_id = :r AND rule_type = 'hotspot'");
$permStmtH->bindValue(':r', $routerId, PDO::PARAM_INT);
$permStmtH->execute();
$permittedHotspot = array_flip($permStmtH->fetchAll(PDO::FETCH_COLUMN));

$permStmtF = $db->prepare("SELECT rule_id FROM rule_permissions WHERE router_id = :r AND rule_type = 'firewall'");
$permStmtF->bindValue(':r', $routerId, PDO::PARAM_INT);
$permStmtF->execute();
$permittedFirewall = array_flip($permStmtF->fetchAll(PDO::FETCH_COLUMN));

$routerNameStmt = $db->prepare("SELECT name FROM routers WHERE id = :r");
$routerNameStmt->bindValue(':r', $routerId, PDO::PARAM_INT);
$routerNameStmt->execute();
$routerLabel = $routerNameStmt->fetchColumn() ?: "Roteador #{$routerId}";

// ---- Alvos disponíveis (só os liberados) ----
$api->write('/ip/hotspot/print');
$rawHotspot = $api->read();
$hotspotTargets = [];
if (is_array($rawHotspot)) {
    foreach ($rawHotspot as $item) {
        if (!is_array($item)) continue;
        $id = $item['.id'] ?? $item['name'] ?? null;
        if (!$id || !isset($permittedHotspot[$id])) continue;
        $label = trim($item['comment'] ?? '') !== '' ? $item['comment'] : ($item['name'] ?? $id);
        $hotspotTargets[$id] = $label;
    }
}

$api->write('/ip/firewall/filter/print');
$rawFirewall = $api->read();
$api->disconnect();
$firewallTargets = [];
if (is_array($rawFirewall)) {
    foreach ($rawFirewall as $item) {
        if (!is_array($item) || !isset($item['.id'])) continue;
        if (!isset($permittedFirewall[$item['.id']])) continue;
        $label = trim($item['comment'] ?? '') !== '' ? $item['comment'] : ('ID ' . $item['.id']);
        $firewallTargets[$item['.id']] = $label;
    }
}

// ---- Ações (criar / excluir) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrfVerify();

    if ($_POST['action'] === 'delete') {
        $sid = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT name FROM schedules WHERE id = :id AND router_id = :r");
        $stmt->execute([':id' => $sid, ':r' => $routerId]);
        $name = $stmt->fetchColumn();

        if ($name !== false) {
            $db->prepare("DELETE FROM schedule_actions WHERE schedule_id = :id")->execute([':id' => $sid]);
            $db->prepare("DELETE FROM schedule_targets WHERE schedule_id = :id")->execute([':id' => $sid]);
            $db->prepare("DELETE FROM schedules WHERE id = :id")->execute([':id' => $sid]);
            logActivity('schedule', 'Excluiu agendamento', "{$routerLabel} — {$name}");
        }
        header("Location: index.php?page=schedules");
        exit;

    } elseif ($_POST['action'] === 'add') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $eventDate = trim($_POST['event_date'] ?? '');
        $startTime = trim($_POST['start_time'] ?? '');
        $endTime = trim($_POST['end_time'] ?? '');
        $targetType = ($_POST['target_type'] ?? '') === 'firewall' ? 'firewall' : 'hotspot';
        $availableTargets = $targetType === 'firewall' ? $firewallTargets : $hotspotTargets;

        // Um evento pode afetar vários itens (ex: prova nos laboratórios 1 a 4). Fica só com os
        // que realmente existem e estão liberados para este usuário — assim um id forjado no
        // POST não passa, mesmo que o formulário não o ofereça.
        $postedIds = array_values(array_unique(array_filter((array)($_POST['target_ids'] ?? []), 'strlen')));
        $targetIds = array_values(array_filter($postedIds, fn($id) => isset($availableTargets[$id])));
        $hasInvalidTarget = count($targetIds) !== count($postedIds);

        // Datas/horas precisam ser reais. Só checar se createFromFormat() devolve objeto não
        // basta: para "2026-02-31" ele "conserta" silenciosamente para 2026-03-03 e retorna um
        // objeto válido — o banco então gravava 0000-00-00 e o agendamento nunca dispararia.
        // Comparar a data reconstruída com o texto original rejeita esses casos.
        $dateObj = DateTime::createFromFormat('Y-m-d', $eventDate);
        $dateOk = $dateObj && $dateObj->format('Y-m-d') === $eventDate;
        $startObj = DateTime::createFromFormat('H:i', $startTime);
        $startOk = $startObj && $startObj->format('H:i') === $startTime;
        $endObj = DateTime::createFromFormat('H:i', $endTime);
        $endOk = $endObj && $endObj->format('H:i') === $endTime;

        if ($name === '') {
            $formError = 'Informe o nome do evento.';
        } elseif (!$dateOk || !$startOk || !$endOk) {
            $formError = 'Data, hora de início e hora de fim precisam ser válidas.';
        } elseif ($startTime === $endTime) {
            $formError = 'A hora de fim precisa ser diferente da hora de início.';
        } elseif (empty($targetIds)) {
            $formError = 'Selecione ao menos um alvo entre os itens liberados.';
        } elseif ($hasInvalidTarget) {
            // Barra alguém tentando agendar um item que não está liberado para ele.
            $formError = 'Um ou mais alvos selecionados não são válidos.';
        } else {
            $schedule = [
                'router_id' => $routerId,
                'event_date' => $eventDate,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'target_type' => $targetType,
            ];

            // schedules.target_id/target_label guardam o primeiro alvo apenas por compatibilidade
            // com agendamentos criados antes de existir seleção múltipla; a lista real fica em
            // schedule_targets.
            $firstId = $targetIds[0];
            $ins = $db->prepare("INSERT INTO schedules
                (router_id, name, description, event_date, start_time, end_time, target_type, target_id, target_label, created_by)
                VALUES (:rid, :name, :desc, :date, :start, :end, :ttype, :tid, :tlabel, :by)");
            $ins->execute([
                ':rid' => $routerId,
                ':name' => $name,
                ':desc' => $description,
                ':date' => $eventDate,
                ':start' => $startTime,
                ':end' => $endTime,
                ':ttype' => $targetType,
                ':tid' => $firstId,
                ':tlabel' => $availableTargets[$firstId],
                ':by' => $_SESSION['user_logged_in'] ?? '',
            ]);
            $scheduleId = (int)$db->lastInsertId();

            $insTarget = $db->prepare("INSERT INTO schedule_targets (schedule_id, target_id, target_label) VALUES (:sid, :tid, :tlabel)");
            $labels = [];
            foreach ($targetIds as $tid) {
                $insTarget->execute([':sid' => $scheduleId, ':tid' => $tid, ':tlabel' => $availableTargets[$tid]]);
                $labels[] = $availableTargets[$tid];
            }

            scheduleSyncActions($db, $scheduleId, $schedule, $targetIds);

            logActivity('schedule', 'Criou agendamento', "{$routerLabel} — {$name} (" . date('d/m/Y', strtotime($eventDate)) . " {$startTime}-{$endTime}, " . ($targetType === 'firewall' ? 'bloqueio' : 'autenticação') . ": " . implode(', ', $labels) . ")");
            header("Location: index.php?page=schedules&mes=" . date('Y-m', strtotime($eventDate)));
            exit;
        }
    }
}

// ---- Mês exibido no calendário ----
$monthParam = $_GET['mes'] ?? date('Y-m');
$monthStart = DateTime::createFromFormat('Y-m-d', $monthParam . '-01') ?: new DateTime(date('Y-m-01'));
$monthStart->setTime(0, 0, 0);
$monthKey = $monthStart->format('Y-m');
$prevMonth = (clone $monthStart)->modify('-1 month')->format('Y-m');
$nextMonth = (clone $monthStart)->modify('+1 month')->format('Y-m');

$mesesPt = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
$monthLabel = $mesesPt[(int)$monthStart->format('n')] . ' de ' . $monthStart->format('Y');

// ---- Agendamentos do mês (para o calendário) e futuros (para a lista) ----
$stmt = $db->prepare("SELECT * FROM schedules WHERE router_id = :r AND DATE_FORMAT(event_date, '%Y-%m') = :m ORDER BY event_date ASC, start_time ASC");
$stmt->execute([':r' => $routerId, ':m' => $monthKey]);
$monthSchedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

$byDay = [];
foreach ($monthSchedules as $s) {
    $byDay[(int)date('j', strtotime($s['event_date']))][] = $s;
}

// targets_list traz todos os alvos do evento; o COALESCE com target_label cobre agendamentos
// criados antes de existir seleção múltipla (que só têm o alvo único em schedules).
$stmt = $db->prepare("
    SELECT s.*,
           (SELECT GROUP_CONCAT(st.target_label ORDER BY st.id SEPARATOR ', ')
              FROM schedule_targets st WHERE st.schedule_id = s.id) AS targets_list,
           (SELECT COUNT(*) FROM schedule_targets st WHERE st.schedule_id = s.id) AS targets_count,
           (SELECT COUNT(*) FROM schedule_actions sa WHERE sa.schedule_id = s.id AND sa.status = 'done') AS done_count,
           (SELECT COUNT(*) FROM schedule_actions sa WHERE sa.schedule_id = s.id) AS total_count
    FROM schedules s
    WHERE s.router_id = :r AND s.event_date >= CURDATE()
    ORDER BY s.event_date ASC, s.start_time ASC
    LIMIT 50
");
$stmt->execute([':r' => $routerId]);
$upcoming = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Layout do grid: quantas células vazias antes do dia 1 (semana começando no domingo).
$leadingBlanks = (int)$monthStart->format('w');
$daysInMonth = (int)$monthStart->format('t');
$todayKey = date('Y-m-d');
?>

<div class="page-head">
    <div>
        <h1 class="page-title">Agendamentos</h1>
        <div class="page-sub"><?= htmlspecialchars($routerLabel) ?> · Provas e eventos com ação automática no MikroTik</div>
    </div>
</div>

<?php if ($formError): ?>
    <div class="alert alert-danger py-2"><?= htmlspecialchars($formError) ?></div>
<?php endif; ?>

<?php if (empty($hotspotTargets) && empty($firewallTargets)): ?>
    <div class="alert alert-warning py-2">
        Nenhum hotspot ou regra de firewall liberado neste MikroTik, então ainda não é possível agendar.
        Peça a um Administrador para liberar os itens em "Hotspot" / "Firewall".
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><?= htmlspecialchars($monthLabel) ?></span>
                <span class="d-flex gap-2">
                    <a href="index.php?page=schedules&mes=<?= $prevMonth ?>" class="btn btn-sm btn-outline-secondary">&laquo; Anterior</a>
                    <a href="index.php?page=schedules&mes=<?= $nextMonth ?>" class="btn btn-sm btn-outline-secondary">Próximo &raquo;</a>
                </span>
            </div>
            <div class="card-body">
                <div class="cal-grid">
                    <?php foreach (['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'] as $dow): ?>
                        <div class="cal-dow"><?= $dow ?></div>
                    <?php endforeach; ?>

                    <?php for ($i = 0; $i < $leadingBlanks; $i++): ?>
                        <div class="cal-cell is-empty"></div>
                    <?php endfor; ?>

                    <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                        <?php
                            $cellDate = $monthStart->format('Y-m-') . str_pad($d, 2, '0', STR_PAD_LEFT);
                            $isToday = $cellDate === $todayKey;
                            $dayEvents = $byDay[$d] ?? [];
                        ?>
                        <div class="cal-cell <?= $isToday ? 'is-today' : '' ?>">
                            <div class="cal-day"><?= $d ?></div>
                            <?php foreach ($dayEvents as $ev): ?>
                                <div class="cal-event <?= $ev['target_type'] === 'firewall' ? 'is-block' : 'is-auth' ?>"
                                     title="<?= htmlspecialchars($ev['name'] . ' — ' . substr($ev['start_time'], 0, 5) . ' às ' . substr($ev['end_time'], 0, 5)) ?>">
                                    <?= htmlspecialchars(substr($ev['start_time'], 0, 5)) ?> <?= htmlspecialchars($ev['name']) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endfor; ?>
                </div>
                <div class="cal-legend">
                    <span><i class="cal-dot is-auth"></i> Autenticação (hotspot)</span>
                    <span><i class="cal-dot is-block"></i> Bloqueio (firewall)</span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header">Novo Agendamento</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add">
                    <div class="mb-2">
                        <label class="form-label small text-muted mb-0">Nome do evento</label>
                        <input type="text" name="name" class="form-control" placeholder="Ex: Prova de Cálculo I" maxlength="150" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small text-muted mb-0">Descrição (opcional)</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Detalhes do evento"></textarea>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-12">
                            <label class="form-label small text-muted mb-0">Data</label>
                            <input type="date" name="event_date" class="form-control" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small text-muted mb-0">Hora início</label>
                            <input type="time" name="start_time" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small text-muted mb-0">Hora fim</label>
                            <input type="time" name="end_time" class="form-control" required>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small text-muted mb-0">Ação</label>
                        <select name="target_type" id="schedTargetType" class="form-select">
                            <option value="hotspot">Autenticação — desabilitar a autenticação durante o evento</option>
                            <option value="firewall">Bloqueio — habilitar o bloqueio durante o evento</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <label class="form-label small text-muted mb-0">Alvos <span class="text-muted">(pode marcar vários)</span></label>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0" id="schedToggleAll" style="font-size: 0.72rem;">Marcar todos</button>
                        </div>
                        <div class="target-picker" data-kind="hotspot">
                            <?php if (empty($hotspotTargets)): ?>
                                <div class="text-muted small p-2">Nenhum item liberado.</div>
                            <?php else: ?>
                                <?php foreach ($hotspotTargets as $id => $label): ?>
                                    <label class="target-option">
                                        <input type="checkbox" class="form-check-input" name="target_ids[]" value="<?= htmlspecialchars($id) ?>">
                                        <span><?= htmlspecialchars($label) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="target-picker" data-kind="firewall" hidden>
                            <?php if (empty($firewallTargets)): ?>
                                <div class="text-muted small p-2">Nenhum item liberado.</div>
                            <?php else: ?>
                                <?php foreach ($firewallTargets as $id => $label): ?>
                                    <label class="target-option">
                                        <input type="checkbox" class="form-check-input" name="target_ids[]" value="<?= htmlspecialchars($id) ?>" disabled>
                                        <span><?= htmlspecialchars($label) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="form-text" id="schedSelCount">Nenhum alvo selecionado.</div>
                    </div>
                    <div class="alert alert-info py-2 mb-3" style="font-size: 0.82em;">
                        A ação é aplicada automaticamente <strong><?= (int)SCHEDULE_MARGIN_MINUTES ?> minutos antes</strong> do início
                        e revertida <strong><?= (int)SCHEDULE_MARGIN_MINUTES ?> minutos depois</strong> do fim.
                    </div>
                    <button type="submit" class="btn btn-outline-success w-100" <?= (empty($hotspotTargets) && empty($firewallTargets)) ? 'disabled' : '' ?>>Agendar</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header">Próximos Agendamentos</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Evento</th>
                        <th>Data</th>
                        <th>Horário</th>
                        <th>Ação</th>
                        <th>Alvo</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($upcoming)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">Nenhum agendamento futuro neste MikroTik.</td></tr>
                    <?php else: ?>
                        <?php foreach ($upcoming as $s): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($s['name']) ?></strong>
                                    <?php if (trim($s['description'] ?? '') !== ''): ?>
                                        <div class="small text-muted"><?= htmlspecialchars($s['description']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="mono text-nowrap"><?= htmlspecialchars(date('d/m/Y', strtotime($s['event_date']))) ?></td>
                                <td class="mono text-nowrap"><?= htmlspecialchars(substr($s['start_time'], 0, 5)) ?>–<?= htmlspecialchars(substr($s['end_time'], 0, 5)) ?></td>
                                <td>
                                    <?php if ($s['target_type'] === 'firewall'): ?>
                                        <span class="badge bg-warning text-dark">Bloqueio</span>
                                    <?php else: ?>
                                        <span class="badge bg-info text-dark">Autenticação</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                        $targetsText = $s['targets_list'] ?: ($s['target_label'] !== '' ? $s['target_label'] : $s['target_id']);
                                        $targetsCount = (int)$s['targets_count'] ?: 1;
                                    ?>
                                    <?= htmlspecialchars($targetsText) ?>
                                    <?php if ($targetsCount > 1): ?>
                                        <span class="badge bg-light ms-1"><?= $targetsCount ?> itens</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ((int)$s['done_count'] === 0): ?>
                                        <span class="badge bg-secondary">Aguardando</span>
                                    <?php elseif ((int)$s['done_count'] < (int)$s['total_count']): ?>
                                        <span class="badge bg-primary">Em andamento</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Concluído</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <form method="POST" class="m-0" onsubmit="return confirm('Excluir este agendamento?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Excluir</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// As duas listas de alvos convivem no HTML; ao trocar o tipo de ação mostramos só a
// correspondente e desabilitamos a outra — campos desabilitados não são enviados no POST,
// então nunca sai um alvo que não bate com a ação escolhida.
(function () {
    var typeSel = document.getElementById('schedTargetType');
    var pickers = document.querySelectorAll('.target-picker');
    var counter = document.getElementById('schedSelCount');
    var toggleAll = document.getElementById('schedToggleAll');
    if (!typeSel || !pickers.length) return;

    function activePicker() {
        return document.querySelector('.target-picker[data-kind="' + typeSel.value + '"]');
    }

    function updateCounter() {
        var picker = activePicker();
        var checked = picker ? picker.querySelectorAll('input[type=checkbox]:checked').length : 0;
        counter.textContent = checked === 0
            ? 'Nenhum alvo selecionado.'
            : (checked === 1 ? '1 alvo selecionado.' : checked + ' alvos selecionados.');
        if (toggleAll) {
            var total = picker ? picker.querySelectorAll('input[type=checkbox]').length : 0;
            toggleAll.textContent = (total > 0 && checked === total) ? 'Desmarcar todos' : 'Marcar todos';
        }
    }

    function syncPickers() {
        Array.prototype.forEach.call(pickers, function (p) {
            var match = p.getAttribute('data-kind') === typeSel.value;
            p.hidden = !match;
            // Ao esconder, também limpa e desabilita para não enviar nada do tipo errado.
            Array.prototype.forEach.call(p.querySelectorAll('input[type=checkbox]'), function (cb) {
                cb.disabled = !match;
                if (!match) cb.checked = false;
            });
        });
        updateCounter();
    }

    typeSel.addEventListener('change', syncPickers);

    Array.prototype.forEach.call(pickers, function (p) {
        p.addEventListener('change', updateCounter);
    });

    if (toggleAll) {
        toggleAll.addEventListener('click', function () {
            var picker = activePicker();
            if (!picker) return;
            var boxes = picker.querySelectorAll('input[type=checkbox]');
            var allChecked = boxes.length > 0 && picker.querySelectorAll('input[type=checkbox]:checked').length === boxes.length;
            Array.prototype.forEach.call(boxes, function (cb) { cb.checked = !allChecked; });
            updateCounter();
        });
    }

    syncPickers();
})();
</script>
