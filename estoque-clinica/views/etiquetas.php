<?php
$db = getDB();
$formError = '';
$formSuccess = '';

// Cancelar unidade: tira do fluxo (etiqueta perdida/danificada) sem apagar o registro — o código
// nunca é reutilizado, então a unidade fica com status próprio e o histórico preservado.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'status') {
    csrfVerify();
    $unidadeId = (int)($_POST['id'] ?? 0);
    $novoStatus = $_POST['status'] ?? '';

    if (!in_array($novoStatus, ['CANCELADA', 'DANIFICADA', 'PERDIDA', 'DISPONIVEL'], true)) {
        $formError = 'Status inválido.';
    } else {
        $stmt = $db->prepare("SELECT * FROM unidades_estoque WHERE id = :id");
        $stmt->execute([':id' => $unidadeId]);
        $unidade = $stmt->fetch();

        if (!$unidade) {
            $formError = 'Unidade não encontrada.';
        } elseif ($unidade['status'] === 'UTILIZADA') {
            // Unidade já baixada representa uma saída registrada — mexer no status aqui
            // desencontraria o estoque do histórico de movimentações.
            $formError = 'A unidade ' . htmlspecialchars($unidade['codigo_interno']) . ' já foi utilizada e não pode ter o status alterado.';
        } else {
            $db->prepare("UPDATE unidades_estoque SET status = :s WHERE id = :id")
               ->execute([':s' => $novoStatus, ':id' => $unidadeId]);
            registrarLog('Unidades', 'Status da unidade alterado',
                'código: ' . $unidade['codigo_interno'] . ', de ' . $unidade['status'] . ' para ' . $novoStatus);
            $formSuccess = 'Unidade ' . $unidade['codigo_interno'] . ' agora está como ' . unidadeStatusLabel($novoStatus) . '.';
        }
    }
}

$busca = trim($_GET['busca'] ?? '');
$statusFiltro = in_array($_GET['status'] ?? '', UNIDADE_STATUS, true) ? $_GET['status'] : '';
$entradaFiltro = (int)($_GET['entrada'] ?? 0);

$sql = unidadeSelectSql() . " WHERE 1=1";
$params = [];
if ($busca !== '') {
    // Aceita o código interno, o nome do produto, o lote ou a referência da entrada (ENT-...).
    $entradaBusca = (int)preg_replace('/\D/', '', $busca);
    $sql .= " AND (u.codigo_interno LIKE :b OR COALESCE(md.produto, ins.nome_comercial) LIKE :b OR l.lote LIKE :b"
          . ($entradaBusca > 0 ? " OR u.confirmacao_id = :ent" : '') . ")";
    $params[':b'] = "%{$busca}%";
    if ($entradaBusca > 0) { $params[':ent'] = $entradaBusca; }
}
if ($statusFiltro !== '') {
    $sql .= " AND u.status = :st";
    $params[':st'] = $statusFiltro;
}
if ($entradaFiltro > 0) {
    $sql .= " AND u.confirmacao_id = :cf";
    $params[':cf'] = $entradaFiltro;
}
$sql .= " ORDER BY u.id DESC LIMIT 500";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$unidades = $stmt->fetchAll();

$totais = $db->query("SELECT status, COUNT(*) AS total FROM unidades_estoque GROUP BY status")->fetchAll();
$totalPorStatus = [];
foreach ($totais as $t) { $totalPorStatus[$t['status']] = (int)$t['total']; }

// Quantas etiquetas existem no lote e na entrada de cada linha listada — é o que permite oferecer
// "imprimir o lote inteiro" / "a entrada inteira" com o número real, mesmo que o filtro atual
// esteja mostrando só parte delas.
$contarPor = function (string $coluna, array $ids) use ($db): array {
    $ids = array_values(array_filter(array_unique(array_map('intval', $ids))));
    if (!$ids) { return []; }
    $marcadores = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT {$coluna} AS chave, COUNT(*) AS total FROM unidades_estoque
        WHERE {$coluna} IN ({$marcadores}) GROUP BY {$coluna}");
    $stmt->execute($ids);
    $mapa = [];
    foreach ($stmt->fetchAll() as $linha) { $mapa[(int)$linha['chave']] = (int)$linha['total']; }
    return $mapa;
};
$totalPorLote = $contarPor('lote_id', array_column($unidades, 'lote_id'));
$totalPorEntrada = $contarPor('confirmacao_id', array_column($unidades, 'confirmacao_id'));
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Etiquetas / Unidades Fracionadas</h1>
        <div class="page-sub">Unidades físicas geradas no fracionamento das entradas — cada uma com código interno próprio para leitura na Saída</div>
    </div>
</div>

<?php if ($formError): ?><div class="alert alert-danger"><?= $formError ?></div><?php endif; ?>
<?php if ($formSuccess): ?><div class="alert alert-success"><?= htmlspecialchars($formSuccess) ?></div><?php endif; ?>

<div class="stat-strip">
    <?php foreach (['DISPONIVEL' => 'green', 'UTILIZADA' => 'blue', 'CANCELADA' => 'red'] as $st => $cor): ?>
        <div class="stat-tile">
            <div>
                <div class="stat-label"><?= unidadeStatusLabel($st) ?></div>
                <div class="stat-value"><?= $totalPorStatus[$st] ?? 0 ?></div>
                <div class="stat-note">unidades</div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="entity-list-toolbar">
    <form method="GET" class="d-flex gap-2 flex-grow-1 flex-wrap">
        <input type="hidden" name="page" value="etiquetas">
        <input type="text" name="busca" class="form-control" style="max-width:280px;" placeholder="Código, produto, lote ou entrada..." value="<?= htmlspecialchars($busca) ?>">
        <select name="status" class="form-select" style="max-width:180px;">
            <option value="">Todos os status</option>
            <?php foreach (UNIDADE_STATUS as $st): ?>
                <option value="<?= $st ?>" <?= $statusFiltro === $st ? 'selected' : '' ?>><?= unidadeStatusLabel($st) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-outline-secondary"><i class="bi bi-search"></i> Filtrar</button>
        <?php if ($entradaFiltro > 0): ?>
            <a href="index.php?page=etiquetas" class="btn btn-outline-secondary">
                <i class="bi bi-x-lg"></i> Limpar filtro da entrada <?= codigoReferenciaEntrada($entradaFiltro) ?>
            </a>
        <?php endif; ?>
    </form>
</div>

<?php if ($entradaFiltro > 0): ?>
    <div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>Mostrando apenas as unidades da entrada <strong><?= codigoReferenciaEntrada($entradaFiltro) ?></strong>.</span>
        <a class="btn btn-sm btn-outline-primary" target="_blank" href="index.php?page=etiquetas_imprimir&amp;entrada=<?= $entradaFiltro ?>">
            <i class="bi bi-printer"></i> Imprimir todas as etiquetas desta entrada
        </a>
    </div>
<?php endif; ?>

<form method="GET" action="index.php" target="_blank">
    <input type="hidden" name="page" value="etiquetas_imprimir">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <div class="small text-muted"><?= count($unidades) ?> unidade(s) listada(s)<?= count($unidades) === 500 ? ' (limite de 500 — refine o filtro)' : '' ?></div>
        <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-printer"></i> Imprimir selecionadas</button>
    </div>
    <input type="hidden" name="ids" id="idsSelecionados" value="">

    <div class="table-responsive">
        <table class="table table-striped table-hover bg-white align-middle">
            <thead class="table-dark">
                <tr>
                    <th style="width:36px;"><input type="checkbox" id="selecionarTodos" class="form-check-input"></th>
                    <th>Código interno</th><th>Produto</th><th>Lote</th><th>Validade</th>
                    <th>Entrada</th><th class="text-center">Status</th><th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($unidades)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Nenhuma unidade encontrada. Elas são criadas ao marcar "Gerar códigos de barras" no fracionamento de uma Entrada.</td></tr>
                <?php else: ?>
                    <?php foreach ($unidades as $u): ?>
                        <tr>
                            <td><input type="checkbox" class="form-check-input chk-unidade" value="<?= (int)$u['id'] ?>"></td>
                            <td class="mono"><?= htmlspecialchars($u['codigo_interno']) ?></td>
                            <td>
                                <?= htmlspecialchars($u['produto']) ?>
                                <?php if ($u['apresentacao']): ?><div class="entity-sub"><?= htmlspecialchars($u['apresentacao']) ?></div><?php endif; ?>
                            </td>
                            <td class="mono"><?= htmlspecialchars($u['lote']) ?></td>
                            <td class="mono"><?= htmlspecialchars(formatarValidade($u['validade'])) ?></td>
                            <td class="mono"><?= $u['confirmacao_id'] ? codigoReferenciaEntrada((int)$u['confirmacao_id']) : '—' ?></td>
                            <td class="text-center">
                                <span class="badge <?= unidadeStatusBadgeClass($u['status']) ?>"><?= unidadeStatusLabel($u['status']) ?></span>
                                <?php if ($u['status'] === 'UTILIZADA' && $u['utilizado_em']): ?>
                                    <div class="entity-sub"><?= date('d/m/Y H:i', strtotime($u['utilizado_em'])) ?><?= $u['utilizado_por'] ? ' · ' . htmlspecialchars($u['utilizado_por']) : '' ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap">
                                <?php
                                    $qtdLote = $totalPorLote[(int)$u['lote_id']] ?? 1;
                                    $qtdEntrada = $u['confirmacao_id'] ? ($totalPorEntrada[(int)$u['confirmacao_id']] ?? 1) : 0;
                                ?>
                                <div class="btn-group">
                                    <a class="btn btn-sm btn-outline-secondary" target="_blank"
                                       href="index.php?page=etiquetas_imprimir&amp;ids=<?= (int)$u['id'] ?>" title="Imprimir só esta etiqueta">
                                        <i class="bi bi-printer"></i> Avulsa
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle dropdown-toggle-split"
                                            data-bs-toggle="dropdown" aria-expanded="false"><span class="visually-hidden">Mais opções</span></button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item" target="_blank" href="index.php?page=etiquetas_imprimir&amp;lote=<?= (int)$u['lote_id'] ?>">
                                            <i class="bi bi-printer"></i> Lote <?= htmlspecialchars($u['lote']) ?> inteiro (<?= $qtdLote ?>)
                                        </a></li>
                                        <?php if ($u['confirmacao_id']): ?>
                                            <li><a class="dropdown-item" target="_blank" href="index.php?page=etiquetas_imprimir&amp;entrada=<?= (int)$u['confirmacao_id'] ?>">
                                                <i class="bi bi-printer"></i> Entrada <?= codigoReferenciaEntrada((int)$u['confirmacao_id']) ?> inteira (<?= $qtdEntrada ?>)
                                            </a></li>
                                        <?php endif; ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item" href="index.php?page=etiquetas&entrada=<?= (int)$u['confirmacao_id'] ?>">
                                            <i class="bi bi-funnel"></i> Ver só as unidades desta entrada
                                        </a></li>
                                    </ul>
                                </div>
                                <?php if ($u['status'] !== 'UTILIZADA'): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger btn-status-unidade"
                                        data-id="<?= (int)$u['id'] ?>" data-codigo="<?= htmlspecialchars($u['codigo_interno']) ?>"
                                        title="Cancelar unidade"><i class="bi bi-x-lg"></i></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</form>

<form method="POST" id="statusForm" class="d-none">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="status">
    <input type="hidden" name="id" id="statusUnidadeId">
    <input type="hidden" name="status" value="CANCELADA">
</form>

<script>
(function () {
    var selecionarTodos = document.getElementById('selecionarTodos');
    var checkboxes = Array.prototype.slice.call(document.querySelectorAll('.chk-unidade'));
    var idsField = document.getElementById('idsSelecionados');

    selecionarTodos.addEventListener('change', function () {
        checkboxes.forEach(function (c) { c.checked = selecionarTodos.checked; });
    });

    // A impressão abre em outra aba com os ids selecionados na query string — juntar aqui, no
    // submit, evita ter que manter um campo por linha da tabela.
    idsField.form.addEventListener('submit', function (e) {
        var ids = checkboxes.filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
        if (!ids.length) {
            e.preventDefault();
            alert('Selecione ao menos uma unidade para imprimir.');
            return;
        }
        idsField.value = ids.join(',');
    });

    var statusForm = document.getElementById('statusForm');
    var statusUnidadeId = document.getElementById('statusUnidadeId');
    document.querySelectorAll('.btn-status-unidade').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var codigo = btn.getAttribute('data-codigo');
            if (!confirm('Cancelar a unidade ' + codigo + '? Ela deixa de poder ser usada na saída, mas o código continua registrado.')) return;
            statusUnidadeId.value = btn.getAttribute('data-id');
            statusForm.submit();
        });
    });
})();
</script>
