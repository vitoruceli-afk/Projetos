<?php
$db = getDB();
$busca = trim($_GET['busca'] ?? '');

// Só entram medicamentos que JÁ tiveram alguma entrada registrada (têm lote em insumo_lotes) —
// o catálogo ANVISA/CMED completo tem dezenas de milhares de registros que a clínica nunca usou,
// e "Estoque" deve mostrar o que de fato está sendo controlado, não o catálogo de referência inteiro.
$sqlMed = "SELECT md.id, md.produto AS nome, md.apresentacao, md.estoque_minimo,
        COALESCE(SUM(l.quantidade), 0) AS estoque_total, COUNT(l.id) AS qtd_lotes
    FROM medicamentos_anvisa md
    INNER JOIN insumo_lotes l ON l.medicamento_id = md.id";
$paramsMed = [];
if ($busca !== '') {
    $sqlMed .= " WHERE md.produto LIKE :b";
    $paramsMed[':b'] = "%{$busca}%";
}
$sqlMed .= " GROUP BY md.id, md.produto, md.apresentacao, md.estoque_minimo ORDER BY md.produto ASC";
$stmt = $db->prepare($sqlMed);
$stmt->execute($paramsMed);
$itens = array_map(function ($m) {
    return [
        'tipo' => 'medicamento', 'id' => (int)$m['id'], 'nome' => $m['nome'], 'apresentacao' => $m['apresentacao'],
        'estoque_total' => (int)$m['estoque_total'], 'qtd_lotes' => (int)$m['qtd_lotes'],
        'estoque_minimo' => $m['estoque_minimo'] !== null ? (int)$m['estoque_minimo'] : null,
    ];
}, $stmt->fetchAll());

$sqlIns = "SELECT id, nome_comercial AS nome, categoria, quantidade AS estoque_total, estoque_minimo, lote FROM insumos";
$paramsIns = [];
if ($busca !== '') {
    $sqlIns .= " WHERE nome_comercial LIKE :b";
    $paramsIns[':b'] = "%{$busca}%";
}
$sqlIns .= " ORDER BY nome_comercial ASC";
$stmt = $db->prepare($sqlIns);
$stmt->execute($paramsIns);
foreach ($stmt->fetchAll() as $i) {
    // Insumo não tem sub-tabela de lotes — só um lote (o da entrada mais recente), se algum já
    // foi cadastrado.
    $itens[] = [
        'tipo' => 'insumo', 'id' => (int)$i['id'], 'nome' => $i['nome'], 'apresentacao' => $i['categoria'],
        'estoque_total' => (int)$i['estoque_total'], 'qtd_lotes' => $i['lote'] !== '' ? 1 : 0,
        'estoque_minimo' => (int)$i['estoque_minimo'],
    ];
}

usort($itens, function ($a, $b) { return strcasecmp($a['nome'], $b['nome']); });
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Estoque</h1>
        <div class="page-sub">Medicamentos e insumos com estoque controlado — quantidade total (soma de todos os lotes) e estoque mínimo</div>
    </div>
</div>

<div class="entity-list-toolbar">
    <form method="GET" class="d-flex gap-2 flex-grow-1">
        <input type="hidden" name="page" value="estoque">
        <input type="text" name="busca" class="form-control" placeholder="Buscar medicamento ou insumo..." value="<?= htmlspecialchars($busca) ?>">
        <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
    </form>
</div>

<div class="table-responsive">
    <table class="table table-striped table-hover bg-white align-middle">
        <thead class="table-dark">
            <tr><th>Tipo</th><th>Nome</th><th>Apresentação</th><th class="text-center">Estoque Total</th><th class="text-center">Qtd. Lotes</th><th class="text-center">Estoque Mínimo</th><th></th></tr>
        </thead>
        <tbody>
            <?php if (empty($itens)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Nenhum medicamento ou insumo encontrado.</td></tr>
            <?php else: ?>
                <?php foreach ($itens as $it): $abaixoMinimo = $it['estoque_minimo'] !== null && $it['estoque_minimo'] > 0 && $it['estoque_total'] <= $it['estoque_minimo']; ?>
                    <tr class="<?= $abaixoMinimo ? ($it['estoque_total'] <= 0 ? 'table-danger' : 'table-warning') : '' ?>">
                        <td><span class="badge <?= $it['tipo'] === 'medicamento' ? 'bg-info text-dark' : 'bg-secondary' ?>"><?= $it['tipo'] === 'medicamento' ? 'Medicamento' : 'Insumo' ?></span></td>
                        <td><?= htmlspecialchars($it['nome']) ?></td>
                        <td><?= htmlspecialchars($it['apresentacao'] ?: '—') ?></td>
                        <td class="text-center fw-bold"><?= $it['estoque_total'] ?></td>
                        <td class="text-center"><?= $it['qtd_lotes'] ?></td>
                        <td class="text-center"><?= $it['estoque_minimo'] !== null ? $it['estoque_minimo'] : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-primary btn-ver-estoque" data-id="<?= $it['id'] ?>" data-tipo="<?= $it['tipo'] ?>">
                                <i class="bi bi-list-ul"></i> Detalhes
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="modal fade" id="estoqueDetalheModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="estoqueDetalheModalTitle">Detalhes</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body" id="estoqueDetalheModalBody">
                <div class="text-muted small">Carregando...</div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var modalEl = document.getElementById('estoqueDetalheModal');
    var modalTitle = document.getElementById('estoqueDetalheModalTitle');
    var modalBody = document.getElementById('estoqueDetalheModalBody');
    var modal = new bootstrap.Modal(modalEl);

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = (s === null || s === undefined) ? '' : String(s);
        return d.innerHTML;
    }
    function fmtMoeda(v) {
        return 'R$ ' + (Number(v) || 0).toFixed(2).replace('.', ',');
    }
    function statusBadgeClass(status) {
        return { vencido: 'bg-danger', urgente: 'bg-warning text-dark', alerta: 'bg-info text-dark', ok: 'bg-success' }[status] || 'bg-secondary';
    }

    document.querySelectorAll('.btn-ver-estoque').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-id');
            var tipo = btn.getAttribute('data-tipo');
            modalTitle.textContent = 'Detalhes';
            modalBody.innerHTML = '<div class="text-muted small">Carregando...</div>';
            modal.show();

            fetch('ajax_estoque_detalhe.php?id=' + encodeURIComponent(id) + '&tipo=' + encodeURIComponent(tipo))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.found) {
                        modalBody.innerHTML = '<div class="alert alert-danger mb-0">' + esc(data.error) + '</div>';
                        return;
                    }
                    modalTitle.textContent = data.nome;

                    var cabecalho = '<div class="entity-grid mb-3">' +
                        '<div><div class="entity-field-label">' + (data.tipo === 'medicamento' ? 'Laboratório' : 'Marca') + '</div><div class="entity-field-value">' + esc(data.origem || '—') + '</div></div>' +
                        (data.apresentacao ? '<div><div class="entity-field-label">' + (data.tipo === 'medicamento' ? 'Apresentação' : 'Categoria') + '</div><div class="entity-field-value">' + esc(data.apresentacao) + '</div></div>' : '') +
                        '<div><div class="entity-field-label">Estoque mínimo</div><div class="entity-field-value">' + (data.estoque_minimo === null ? '—' : esc(data.estoque_minimo)) + '</div></div>' +
                        '</div>';

                    if (!data.lotes.length) {
                        modalBody.innerHTML = cabecalho + '<p class="text-muted small mb-0">Nenhum lote cadastrado.</p>';
                        return;
                    }

                    var linhas = data.lotes.map(function (l) {
                        return '<tr>' +
                            '<td class="mono">' + esc(l.lote) + '</td>' +
                            '<td class="mono">' + esc(l.validade_br) + '</td>' +
                            '<td class="text-center">' + esc(l.quantidade) + '</td>' +
                            '<td class="text-end mono">' + fmtMoeda(l.valor_unitario) + '</td>' +
                            '<td class="text-end mono">' + fmtMoeda(l.valor_total) + '</td>' +
                            '<td class="text-center"><span class="badge ' + statusBadgeClass(l.status) + '">' + esc(l.status_label) + '</span></td>' +
                            '</tr>';
                    }).join('');

                    modalBody.innerHTML = cabecalho +
                        '<div class="table-responsive"><table class="table table-sm table-striped mb-0">' +
                            '<thead><tr><th>Lote</th><th>Validade</th><th class="text-center">Qtd.</th><th class="text-end">Valor Unit.</th><th class="text-end">Valor Total</th><th class="text-center">Status</th></tr></thead>' +
                            '<tbody>' + linhas + '</tbody></table></div>';
                })
                .catch(function () {
                    modalBody.innerHTML = '<div class="alert alert-danger mb-0">Erro ao carregar os detalhes.</div>';
                });
        });
    });
});
</script>
