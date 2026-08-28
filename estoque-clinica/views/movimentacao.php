<?php
$db = getDB();
$tab = ($_GET['tab'] ?? 'entrada') === 'saida' ? 'saida' : 'entrada';
$formError = '';
$formSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrfVerify();
    $codigo = trim($_POST['codigo_barras'] ?? '');
    $quantidade = (int)($_POST['quantidade'] ?? 0);
    $observacao = trim($_POST['observacao'] ?? '');

    $insumo = $codigo !== '' ? findInsumoByBarcode($db, $codigo) : null;

    if ($codigo === '') {
        $formError = 'Leia ou digite o código de barras do insumo.';
    } elseif (!$insumo) {
        $formError = 'Nenhum insumo cadastrado com o código "' . htmlspecialchars($codigo) . '". Cadastre-o primeiro em Insumos.';
    } elseif (!$insumo['ativo']) {
        $formError = 'Este insumo está inativo no catálogo.';
    } elseif ($quantidade <= 0) {
        $formError = 'Informe uma quantidade válida.';
    } elseif ($_POST['action'] === 'entrada') {
        $lote = trim($_POST['lote'] ?? '');
        $validade = trim($_POST['validade'] ?? '');
        if ($lote === '' || $validade === '') {
            $formError = 'Informe o lote e a validade para dar entrada.';
        } else {
            $stmt = $db->prepare("SELECT * FROM insumo_lotes WHERE insumo_id = :i AND lote = :l");
            $stmt->execute([':i' => $insumo['id'], ':l' => $lote]);
            $loteRow = $stmt->fetch();

            if ($loteRow) {
                $db->prepare("UPDATE insumo_lotes SET quantidade = quantidade + :q WHERE id = :id")
                   ->execute([':q' => $quantidade, ':id' => $loteRow['id']]);
                $loteId = $loteRow['id'];
            } else {
                $db->prepare("INSERT INTO insumo_lotes (insumo_id, lote, validade, quantidade) VALUES (:i, :l, :v, :q)")
                   ->execute([':i' => $insumo['id'], ':l' => $lote, ':v' => $validade, ':q' => $quantidade]);
                $loteId = (int)$db->lastInsertId();
            }
            $db->prepare("INSERT INTO movimentacoes (insumo_id, lote_id, tipo, quantidade, usuario, observacao) VALUES (:i, :lo, 'entrada', :q, :u, :o)")
               ->execute([':i' => $insumo['id'], ':lo' => $loteId, ':q' => $quantidade, ':u' => $_SESSION['user_logged_in'], ':o' => $observacao]);

            header("Location: index.php?page=movimentacao&tab=entrada&ok=1");
            exit;
        }
    } elseif ($_POST['action'] === 'saida') {
        $disponivel = insumoEstoqueTotal($db, $insumo['id']);
        if ($quantidade > $disponivel) {
            $formError = "Estoque insuficiente: há apenas {$disponivel} " . htmlspecialchars($insumo['unidade_medida']) . "(s) disponível(is).";
        } else {
            $lotes = $db->prepare("SELECT * FROM insumo_lotes WHERE insumo_id = :i AND quantidade > 0 ORDER BY validade ASC");
            $lotes->execute([':i' => $insumo['id']]);
            $lotes = $lotes->fetchAll();

            $restante = $quantidade;
            $db->beginTransaction();
            foreach ($lotes as $l) {
                if ($restante <= 0) break;
                $consumir = min($restante, (int)$l['quantidade']);
                $db->prepare("UPDATE insumo_lotes SET quantidade = quantidade - :c WHERE id = :id")
                   ->execute([':c' => $consumir, ':id' => $l['id']]);
                $db->prepare("INSERT INTO movimentacoes (insumo_id, lote_id, tipo, quantidade, usuario, observacao) VALUES (:i, :lo, 'saida', :q, :u, :o)")
                   ->execute([':i' => $insumo['id'], ':lo' => $l['id'], ':q' => $consumir, ':u' => $_SESSION['user_logged_in'], ':o' => $observacao]);
                $restante -= $consumir;
            }
            $db->commit();

            header("Location: index.php?page=movimentacao&tab=saida&ok=1");
            exit;
        }
    }
}

if (isset($_GET['ok'])) {
    $formSuccess = $tab === 'entrada' ? 'Entrada registrada com sucesso.' : 'Saída registrada com sucesso.';
}
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Entrada / Saída de Insumos</h1>
        <div class="page-sub">Leia o código de barras com o leitor ou digite manualmente</div>
    </div>
</div>

<ul class="nav nav-tabs mb-4">
    <li class="nav-item"><a class="nav-link <?= $tab === 'entrada' ? 'active' : '' ?>" href="index.php?page=movimentacao&tab=entrada">Entrada</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'saida' ? 'active' : '' ?>" href="index.php?page=movimentacao&tab=saida">Saída</a></li>
</ul>

<?php if ($formError): ?><div class="alert alert-danger"><?= $formError ?></div><?php endif; ?>
<?php if ($formSuccess): ?><div class="alert alert-success"><?= htmlspecialchars($formSuccess) ?></div><?php endif; ?>

<div class="scan-card">
    <form method="POST" id="movForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="<?= $tab ?>">
        <label class="form-label">Código de barras</label>
        <div class="scan-input-row">
            <input type="text" id="codigoInput" class="form-control mono" autocomplete="off" placeholder="Leia o código de barras..." autofocus>
            <button type="button" id="buscarBtn" class="btn btn-outline-primary"><i class="bi bi-search"></i> Buscar</button>
        </div>
        <input type="hidden" name="codigo_barras" id="codigoBarrasField">

        <div id="scanResult"></div>

        <div id="camposMovimentacao" style="display:none;">
            <hr>
            <div class="row g-2">
                <div class="col-sm-<?= $tab === 'entrada' ? '4' : '12' ?>">
                    <label class="form-label">Quantidade</label>
                    <input type="number" name="quantidade" id="quantidadeInput" class="form-control" min="1" required>
                </div>
                <?php if ($tab === 'entrada'): ?>
                <div class="col-sm-4">
                    <label class="form-label">Lote</label>
                    <input type="text" name="lote" id="loteInput" class="form-control" required>
                </div>
                <div class="col-sm-4">
                    <label class="form-label">Validade</label>
                    <input type="date" name="validade" id="validadeInput" class="form-control" required>
                </div>
                <?php endif; ?>
            </div>
            <div class="mb-2 mt-2">
                <label class="form-label">Observação (opcional)</label>
                <input type="text" name="observacao" class="form-control">
            </div>
            <button type="submit" class="btn <?= $tab === 'entrada' ? 'btn-outline-success' : 'btn-outline-danger' ?> w-100 mt-2">
                <?= $tab === 'entrada' ? 'Confirmar Entrada' : 'Confirmar Saída' ?>
            </button>
        </div>
    </form>
</div>

<script>
(function () {
    var tipo = '<?= $tab ?>';
    var codigoInput = document.getElementById('codigoInput');
    var codigoBarrasField = document.getElementById('codigoBarrasField');
    var buscarBtn = document.getElementById('buscarBtn');
    var scanResult = document.getElementById('scanResult');
    var campos = document.getElementById('camposMovimentacao');
    var quantidadeInput = document.getElementById('quantidadeInput');

    function statusBadgeClass(status) {
        return { vencido: 'bg-danger', urgente: 'bg-warning text-dark', alerta: 'bg-info text-dark', ok: 'bg-success' }[status] || 'bg-secondary';
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = (s === null || s === undefined) ? '' : String(s);
        return d.innerHTML;
    }

    function buscar() {
        var codigo = codigoInput.value.trim();
        if (!codigo) return;
        scanResult.innerHTML = '<div class="text-muted small mt-2">Buscando...</div>';
        campos.style.display = 'none';

        fetch('ajax_buscar_insumo.php?codigo=' + encodeURIComponent(codigo))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.found) {
                    scanResult.innerHTML = '<div class="alert alert-warning mt-3 mb-0">' + data.error +
                        ' <a href="index.php?page=insumos&novo_codigo=' + encodeURIComponent(codigo) + '" class="fw-bold">Cadastrar insumo</a></div>';
                    codigoBarrasField.value = '';
                    return;
                }
                var i = data.insumo;
                codigoBarrasField.value = i.codigo_barras;

                var fotoHtml = i.foto_url
                    ? '<img src="' + i.foto_url + '" class="scan-summary-photo" alt="">'
                    : '<div class="scan-summary-photo-placeholder"><i class="bi bi-image"></i></div>';

                var statusHtml = i.status
                    ? '<span class="badge ' + statusBadgeClass(i.status) + '">' + i.status_label + '</span>'
                    : '<span class="badge bg-secondary">Sem estoque</span>';

                var loteInfo = i.lote_atual
                    ? esc(i.lote_atual.lote) + ' · vence em ' + esc(i.lote_atual.validade_br)
                    : 'Nenhum lote em estoque';

                scanResult.innerHTML =
                    '<div class="scan-summary">' + fotoHtml +
                    '<div>' +
                        '<div class="scan-summary-title">' + esc(i.nome) + '</div>' +
                        '<div class="scan-summary-sub">' + esc(i.laboratorio || '—') + '</div>' +
                        '<div class="scan-summary-grid">' +
                            '<div><div class="entity-field-label">Estoque total</div><div class="entity-field-value">' + esc(i.estoque_total) + ' ' + esc(i.unidade_medida) + '</div></div>' +
                            '<div><div class="entity-field-label">Lote / Validade</div><div class="entity-field-value">' + loteInfo + '</div></div>' +
                            (i.composicao ? '<div class="full"><div class="entity-field-label">Composição</div><div class="entity-field-value">' + esc(i.composicao) + '</div></div>' : '') +
                        '</div>' +
                        '<div class="mt-2">' + statusHtml + '</div>' +
                    '</div></div>';

                campos.style.display = 'block';
                if (tipo === 'saida') { quantidadeInput.max = i.estoque_total; }
                quantidadeInput.focus();
            })
            .catch(function () {
                scanResult.innerHTML = '<div class="alert alert-danger mt-3 mb-0">Erro ao buscar o insumo. Tente novamente.</div>';
            });
    }

    codigoInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); buscar(); }
    });
    buscarBtn.addEventListener('click', buscar);
})();
</script>
