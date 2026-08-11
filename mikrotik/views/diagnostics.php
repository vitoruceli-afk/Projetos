<?php
$api = getActiveRouterAPI();
if (!$api) { echo "<div class='alert alert-danger'>Conexão falhou.</div>"; return; }

// Agrupa as respostas de ping em: lista de cada pacote + resumo final (sent/received/rtt),
// que no RouterOS vêm embutidos em todas as linhas (a última linha tem os totais acumulados).
function parsePingResult(array $raw) {
    $replies = [];
    $summary = null;
    foreach ($raw as $entry) {
        if (!is_array($entry) || !isset($entry['seq'])) continue;
        $replies[] = [
            'seq' => $entry['seq'],
            'host' => $entry['host'] ?? '-',
            'time' => $entry['time'] ?? null,
        ];
        if (isset($entry['received'])) {
            $summary = [
                'sent' => $entry['sent'] ?? '-',
                'received' => $entry['received'] ?? '0',
                'loss' => $entry['packet-loss'] ?? '-',
                'min' => $entry['min-rtt'] ?? '-',
                'avg' => $entry['avg-rtt'] ?? '-',
                'max' => $entry['max-rtt'] ?? '-',
            ];
        }
    }
    return ['replies' => $replies, 'summary' => $summary];
}

// O traceroute do RouterOS transmite rodadas progressivas (campo '.section'); cada rodada nova
// repete e completa a anterior. Usamos apenas a última seção, que é o resultado final e completo.
function parseTracerouteResult(array $raw) {
    $bySection = [];
    foreach ($raw as $entry) {
        if (!is_array($entry)) continue;
        $section = $entry['.section'] ?? '0';
        $bySection[$section][] = $entry;
    }
    if (empty($bySection)) return [];

    $lastSection = array_key_last($bySection);
    $hops = [];
    $n = 0;
    foreach ($bySection[$lastSection] as $entry) {
        $n++;
        $hops[] = [
            'hop' => $n,
            'address' => $entry['address'] ?? '',
            'loss' => $entry['loss'] ?? null,
            'last' => $entry['last'] ?? null,
            'avg' => $entry['avg'] ?? null,
            'status' => $entry['status'] ?? '',
        ];
    }
    return $hops;
}

$tool = 'ping';
$target = '';
$error = '';
$pingResult = null;
$tracerouteHops = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['target_ip'])) {
    csrfVerify();
    $target = trim($_POST['target_ip']);
    $tool = ($_POST['tool'] ?? 'ping') === 'traceroute' ? 'traceroute' : 'ping';

    if (!isValidRouterHost($target)) {
        $error = 'Endereço ou host inválido.';
    } elseif ($tool === 'ping') {
        $raw = $api->comm('/ping', ['address' => $target, 'count' => '4']);
        if (empty($raw)) {
            $error = 'O roteador não retornou resposta para este ping.';
        } else {
            $pingResult = parsePingResult($raw);
        }
    } else {
        $raw = $api->comm('/tool/traceroute', ['address' => $target, 'count' => '1']);
        if (empty($raw)) {
            $error = 'O roteador não retornou resposta para este traceroute.';
        } else {
            $tracerouteHops = parseTracerouteResult($raw);
        }
    }
}
$api->disconnect();
?>
<h1 class="page-title">Diagnóstico de Rede</h1>
<div class="card mt-3">
    <div class="card-body">
        <form method="POST" class="row gx-3 gy-2 align-items-center">
            <?= csrfField() ?>
            <div class="col-sm-5">
                <input type="text" name="target_ip" class="form-control" placeholder="IP ou host de destino (ex: 8.8.8.8)" value="<?= htmlspecialchars($target) ?>" required>
            </div>
            <div class="col-sm-5">
                <div class="btn-group w-100" role="group">
                    <input type="radio" class="btn-check" name="tool" id="tool_ping" value="ping" autocomplete="off" <?= $tool === 'ping' ? 'checked' : '' ?>>
                    <label class="btn btn-outline-primary" for="tool_ping"><i class="bi bi-reception-4"></i> Ping</label>

                    <input type="radio" class="btn-check" name="tool" id="tool_traceroute" value="traceroute" autocomplete="off" <?= $tool === 'traceroute' ? 'checked' : '' ?>>
                    <label class="btn btn-outline-primary" for="tool_traceroute"><i class="bi bi-signpost-split"></i> Traceroute</label>
                </div>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary">Executar</button>
            </div>
        </form>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger mt-4"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($pingResult): ?>
    <?php
        $summary = $pingResult['summary'];
        $lossValue = $summary ? (float)$summary['loss'] : null;
        $lossClass = $lossValue === null ? 'bg-secondary' : ($lossValue == 0 ? 'bg-success' : ($lossValue < 100 ? 'bg-warning text-dark' : 'bg-danger'));
    ?>
    <div class="mt-4">
        <h5>Resultado do Ping para <code><?= htmlspecialchars($target) ?></code></h5>

        <?php if ($summary): ?>
        <div class="row text-center mb-3">
            <div class="col-6 col-md-2 mb-2">
                <div class="card h-100"><div class="card-body py-2"><div class="text-muted small">Enviados</div><div class="fs-5"><?= htmlspecialchars($summary['sent']) ?></div></div></div>
            </div>
            <div class="col-6 col-md-2 mb-2">
                <div class="card h-100"><div class="card-body py-2"><div class="text-muted small">Recebidos</div><div class="fs-5"><?= htmlspecialchars($summary['received']) ?></div></div></div>
            </div>
            <div class="col-6 col-md-2 mb-2">
                <div class="card h-100 <?= $lossClass ?> text-white"><div class="card-body py-2"><div class="small">Perda de Pacotes</div><div class="fs-5"><?= htmlspecialchars($summary['loss']) ?>%</div></div></div>
            </div>
            <div class="col-6 col-md-2 mb-2">
                <div class="card h-100"><div class="card-body py-2"><div class="text-muted small">Mín.</div><div class="fs-6"><?= htmlspecialchars($summary['min']) ?></div></div></div>
            </div>
            <div class="col-6 col-md-2 mb-2">
                <div class="card h-100"><div class="card-body py-2"><div class="text-muted small">Média</div><div class="fs-6"><?= htmlspecialchars($summary['avg']) ?></div></div></div>
            </div>
            <div class="col-6 col-md-2 mb-2">
                <div class="card h-100"><div class="card-body py-2"><div class="text-muted small">Máx.</div><div class="fs-6"><?= htmlspecialchars($summary['max']) ?></div></div></div>
            </div>
        </div>
        <?php endif; ?>

        <table class="table table-sm table-striped bg-white shadow-sm align-middle">
            <thead class="table-dark"><tr><th style="width:80px;">#</th><th>Host</th><th>Tempo de Resposta</th><th class="text-center" style="width:120px;">Status</th></tr></thead>
            <tbody>
                <?php foreach ($pingResult['replies'] as $r): ?>
                    <tr class="<?= $r['time'] ? '' : 'table-light text-muted' ?>">
                        <td class="mono"><?= htmlspecialchars($r['seq']) ?></td>
                        <td class="mono"><?= htmlspecialchars($r['host']) ?></td>
                        <td class="mono"><?= $r['time'] ? htmlspecialchars($r['time']) : '-' ?></td>
                        <td class="text-center">
                            <?php if ($r['time']): ?>
                                <span class="badge bg-success">Respondeu</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Timeout</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($tracerouteHops !== null): ?>
    <div class="mt-4">
        <h5>Resultado do Traceroute para <code><?= htmlspecialchars($target) ?></code></h5>

        <?php if (empty($tracerouteHops)): ?>
            <div class="alert alert-warning">Nenhum salto retornado pelo roteador.</div>
        <?php else: ?>
            <table class="table table-sm table-striped bg-white shadow-sm align-middle">
                <thead class="table-dark">
                    <tr>
                        <th style="width:60px;">Salto</th>
                        <th>Endereço</th>
                        <th>Perda</th>
                        <th>Última</th>
                        <th>Média</th>
                        <th>Observação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tracerouteHops as $h): ?>
                        <?php $timedOut = $h['address'] === ''; ?>
                        <tr class="<?= $timedOut ? 'table-light text-muted' : '' ?>">
                            <td><?= $h['hop'] ?></td>
                            <td><?= $timedOut ? '<span class="badge bg-secondary">Sem resposta</span>' : '<code>' . htmlspecialchars($h['address']) . '</code>' ?></td>
                            <td><?= $h['loss'] !== null ? htmlspecialchars($h['loss']) . '%' : '-' ?></td>
                            <td><?= $h['last'] !== null ? htmlspecialchars($h['last']) . 'ms' : '-' ?></td>
                            <td><?= $h['avg'] !== null ? htmlspecialchars($h['avg']) . 'ms' : '-' ?></td>
                            <td><small class="text-muted"><?= htmlspecialchars($h['status']) ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>
