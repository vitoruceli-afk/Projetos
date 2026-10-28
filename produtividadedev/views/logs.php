<?php
requireAdmin();
$db = getDB();

$maquinaId = (int)($_GET['maquina_id'] ?? 0);
$where = $maquinaId > 0 ? 'WHERE s.maquina_id = :mid' : '';
$params = $maquinaId > 0 ? [':mid' => $maquinaId] : [];

$porPagina = 50;
$pagina = max(1, (int)($_GET['pg'] ?? 1));
$offset = ($pagina - 1) * $porPagina;

$totalStmt = $db->prepare("SELECT COUNT(*) FROM sincronizacoes s {$where}");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();

$stmt = $db->prepare("SELECT s.*, m.nome AS maquina_nome FROM sincronizacoes s
    JOIN maquinas m ON m.id = s.maquina_id {$where}
    ORDER BY s.iniciado_em DESC LIMIT {$porPagina} OFFSET {$offset}");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$maquinas = $db->query("SELECT id, nome FROM maquinas ORDER BY nome ASC")->fetchAll();
$totalPaginas = max(1, (int)ceil($total / $porPagina));
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Logs de Sincronização</h1>
        <div class="page-sub">Histórico de execuções — manuais e do sync_runner.php agendado</div>
    </div>
</div>

<form method="GET" class="entity-list-toolbar">
    <input type="hidden" name="page" value="logs">
    <select name="maquina_id" class="form-select form-select-sm" style="max-width: 240px" onchange="this.form.submit()">
        <option value="0">Todas as máquinas</option>
        <?php foreach ($maquinas as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $maquinaId === (int)$m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['nome']) ?></option><?php endforeach; ?>
    </select>
</form>

<div class="table-responsive">
    <table class="table table-bordered bg-white align-middle table-sm">
        <thead class="table-dark"><tr><th>Início</th><th>Duração</th><th>Máquina</th><th>Origem</th><th>Status</th><th>Eventos</th><th>Mensagem</th></tr></thead>
        <tbody>
            <?php foreach ($logs as $l): ?>
            <tr>
                <td class="text-nowrap"><small class="mono"><?= htmlspecialchars($l['iniciado_em']) ?></small></td>
                <td class="text-nowrap">
                    <?php if ($l['finalizado_em']): ?>
                        <small class="mono"><?= (strtotime($l['finalizado_em']) - strtotime($l['iniciado_em'])) ?>s</small>
                    <?php else: ?><span class="badge bg-info">Em execução</span><?php endif; ?>
                </td>
                <td><?= htmlspecialchars($l['maquina_nome']) ?></td>
                <td><span class="badge bg-light"><?= htmlspecialchars($l['origem']) ?></span></td>
                <td>
                    <?php if ($l['status'] === 'ok'): ?><span class="badge bg-success">OK</span>
                    <?php elseif ($l['status'] === 'parcial'): ?><span class="badge bg-warning">Parcial</span>
                    <?php elseif ($l['status'] === 'erro'): ?><span class="badge bg-danger">Erro</span>
                    <?php else: ?><span class="badge bg-info">Executando</span><?php endif; ?>
                </td>
                <td><?= (int)$l['eventos_novos'] ?></td>
                <td class="text-truncate" style="max-width: 380px" title="<?= htmlspecialchars($l['mensagem']) ?>"><?= htmlspecialchars($l['mensagem']) ?: '—' ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($logs)): ?><tr><td colspan="7" class="text-center text-muted py-3">Nenhuma sincronização registrada ainda.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPaginas > 1): ?>
<nav>
    <ul class="pagination pagination-sm">
        <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="index.php?page=logs&maquina_id=<?= $maquinaId ?>&pg=<?= max(1, $pagina - 1) ?>">Anterior</a></li>
        <li class="page-item disabled"><span class="page-link">Página <?= $pagina ?> de <?= $totalPaginas ?></span></li>
        <li class="page-item <?= $pagina >= $totalPaginas ? 'disabled' : '' ?>"><a class="page-link" href="index.php?page=logs&maquina_id=<?= $maquinaId ?>&pg=<?= min($totalPaginas, $pagina + 1) ?>">Próxima</a></li>
    </ul>
</nav>
<?php endif; ?>
