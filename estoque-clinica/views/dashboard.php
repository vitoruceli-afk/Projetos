<?php
requireAdmin();
$db = getDB();

$totalInsumos = (int)$db->query("SELECT COUNT(*) FROM insumos WHERE ativo = 1")->fetchColumn();

$hoje = date('Y-m-d');
$em30 = date('Y-m-d', strtotime('+' . VENCIMENTO_ALERTA_DIAS . ' days'));
$em7 = date('Y-m-d', strtotime('+' . VENCIMENTO_URGENTE_DIAS . ' days'));

// Conta INSUMOS distintos (não lotes) que têm ao menos um lote nessas faixas de vencimento.
$countInsumosComLote = function (string $where, array $params) use ($db) {
    $sql = "SELECT COUNT(DISTINCT insumo_id) FROM insumo_lotes WHERE quantidade > 0 AND {$where}";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
};

$totalVencidos = $countInsumosComLote('validade < :hoje', [':hoje' => $hoje]);
$totalAVencer7 = $countInsumosComLote('validade >= :hoje AND validade <= :em7', [':hoje' => $hoje, ':em7' => $em7]);
$totalAVencer30 = $countInsumosComLote('validade >= :hoje AND validade <= :em30', [':hoje' => $hoje, ':em30' => $em30]);

$recentMov = $db->query("SELECT m.*, i.nome AS insumo_nome FROM movimentacoes m
    JOIN insumos i ON i.id = m.insumo_id
    ORDER BY m.created_at DESC, m.id DESC LIMIT 8")->fetchAll();

$proximosVencer = $db->query("SELECT i.id, i.nome, l.lote, l.validade, l.quantidade
    FROM insumo_lotes l JOIN insumos i ON i.id = l.insumo_id
    WHERE l.quantidade > 0
    ORDER BY l.validade ASC LIMIT 8")->fetchAll();

function timeAgoEC($datetime) {
    $ts = strtotime($datetime);
    $diff = time() - $ts;
    if ($diff < 60) return 'agora mesmo';
    if ($diff < 3600) return 'há ' . (int)floor($diff / 60) . ' min';
    if (date('Y-m-d', $ts) === date('Y-m-d')) return 'há ' . (int)floor($diff / 3600) . 'h';
    if (date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day'))) return 'Ontem, ' . date('H:i', $ts);
    return date('d/m, H:i', $ts);
}
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Dashboard</h1>
        <div class="page-sub">Visão geral do estoque de insumos</div>
    </div>
</div>

<div class="stat-strip">
    <div class="stat-tile">
        <div>
            <div class="stat-label">Insumos Cadastrados</div>
            <div class="stat-value"><?= $totalInsumos ?></div>
            <div class="stat-note">ativos no catálogo</div>
        </div>
        <div class="stat-icon blue"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M20.5 7.5l-8.5-5-8.5 5 8.5 5 8.5-5z"/><path d="M3.5 7.5v9l8.5 5 8.5-5v-9"/></svg></div>
    </div>
    <div class="stat-tile">
        <div>
            <div class="stat-label">A Vencer em 30 dias</div>
            <div class="stat-value warning-c"><?= $totalAVencer30 ?></div>
            <div class="stat-note">insumos com lote nesta faixa</div>
        </div>
        <div class="stat-icon orange"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3.5v3M16 3.5v3"/></svg></div>
    </div>
    <div class="stat-tile">
        <div>
            <div class="stat-label">A Vencer em 7 dias</div>
            <div class="stat-value critical-c"><?= $totalAVencer7 ?></div>
            <div class="stat-note">atenção imediata</div>
        </div>
        <div class="stat-icon red"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M12 9v4M12 17h.01M10.3 3.9L2.6 18a1.5 1.5 0 001.3 2.2h16.2a1.5 1.5 0 001.3-2.2L13.7 3.9a1.5 1.5 0 00-2.6 0z"/></svg></div>
    </div>
    <div class="stat-tile">
        <div>
            <div class="stat-label">Vencidos</div>
            <div class="stat-value critical-c"><?= $totalVencidos ?></div>
            <div class="stat-note">precisam ser retirados</div>
        </div>
        <div class="stat-icon red"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/></svg></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Próximos a Vencer</span>
                <a href="index.php?page=relatorios&status=alerta" class="small fw-bold">Ver relatório</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr><th>Insumo</th><th>Lote</th><th>Validade</th><th class="text-center">Qtd.</th><th class="text-center">Status</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($proximosVencer)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">Nenhum lote em estoque.</td></tr>
                            <?php else: ?>
                                <?php foreach ($proximosVencer as $l): $st = statusVencimento($l['validade']); ?>
                                    <tr class="<?= $st === 'vencido' ? 'table-danger' : ($st === 'urgente' ? 'table-warning' : '') ?>">
                                        <td><?= htmlspecialchars($l['nome']) ?></td>
                                        <td class="mono"><?= htmlspecialchars($l['lote']) ?></td>
                                        <td class="mono"><?= date('d/m/Y', strtotime($l['validade'])) ?></td>
                                        <td class="text-center"><?= (int)$l['quantidade'] ?></td>
                                        <td class="text-center"><span class="badge <?= statusVencimentoBadgeClass($st) ?>"><?= statusVencimentoLabel($st) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header">Movimentações Recentes</div>
            <div class="card-body">
                <?php if (empty($recentMov)): ?>
                    <p class="text-muted mb-0 small">Nenhuma movimentação registrada ainda.</p>
                <?php else: ?>
                    <div class="activity-list">
                        <?php foreach ($recentMov as $m): ?>
                            <div class="activity-row">
                                <div class="activity-icon">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <?= $m['tipo'] === 'entrada' ? '<path d="M12 19V5M5 12l7-7 7 7"/>' : '<path d="M12 5v14M5 12l7 7 7-7"/>' ?>
                                    </svg>
                                </div>
                                <div>
                                    <div class="activity-title"><?= $m['tipo'] === 'entrada' ? 'Entrada' : 'Saída' ?> · <?= htmlspecialchars($m['insumo_nome']) ?></div>
                                    <div class="activity-sub"><?= (int)$m['quantidade'] ?> un. · <?= htmlspecialchars($m['usuario']) ?></div>
                                </div>
                                <div class="activity-time"><?= timeAgoEC($m['created_at']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
