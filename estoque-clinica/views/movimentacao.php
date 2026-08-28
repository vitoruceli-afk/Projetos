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

    $medicamento = $codigo !== '' ? findMedicamentoByBarcode($db, $codigo) : null;

    if ($codigo === '') {
        $formError = 'Leia ou digite o código de barras do medicamento.';
    } elseif (!$medicamento) {
        $formError = 'Nenhum medicamento encontrado com o código "' . htmlspecialchars($codigo) . '" na base da ANVISA/CMED.';
    } elseif ($quantidade <= 0) {
        $formError = 'Informe uma quantidade válida.';
    } elseif ($_POST['action'] === 'entrada') {
        $lote = trim($_POST['lote'] ?? '');
        $validade = trim($_POST['validade'] ?? '');
        if ($lote === '' || $validade === '') {
            $formError = 'Informe o lote e a validade para dar entrada.';
        } else {
            $stmt = $db->prepare("SELECT * FROM insumo_lotes WHERE medicamento_id = :m AND lote = :l");
            $stmt->execute([':m' => $medicamento['id'], ':l' => $lote]);
            $loteRow = $stmt->fetch();

            if ($loteRow) {
                $db->prepare("UPDATE insumo_lotes SET quantidade = quantidade + :q WHERE id = :id")
                   ->execute([':q' => $quantidade, ':id' => $loteRow['id']]);
                $loteId = $loteRow['id'];
            } else {
                $db->prepare("INSERT INTO insumo_lotes (medicamento_id, lote, validade, quantidade) VALUES (:m, :l, :v, :q)")
                   ->execute([':m' => $medicamento['id'], ':l' => $lote, ':v' => $validade, ':q' => $quantidade]);
                $loteId = (int)$db->lastInsertId();
            }
            $db->prepare("INSERT INTO movimentacoes (medicamento_id, lote_id, tipo, quantidade, usuario, observacao) VALUES (:m, :lo, 'entrada', :q, :u, :o)")
               ->execute([':m' => $medicamento['id'], ':lo' => $loteId, ':q' => $quantidade, ':u' => $_SESSION['user_logged_in'], ':o' => $observacao]);

            header("Location: index.php?page=movimentacao&tab=entrada&ok=1");
            exit;
        }
    } elseif ($_POST['action'] === 'saida') {
        // Um medicamento pode ter vários lotes com validades diferentes em estoque ao mesmo
        // tempo — a baixa é feita sempre no lote específico escolhido pelo operador (não mais
        // automaticamente pelo mais próximo do vencimento), para permitir corrigir/ajustar o
        // lote correto quando necessário.
        $loteId = (int)($_POST['lote_id'] ?? 0);
        if ($loteId <= 0) {
            $formError = 'Selecione o lote do qual deseja dar saída.';
        } else {
            $stmt = $db->prepare("SELECT * FROM insumo_lotes WHERE id = :id AND medicamento_id = :m");
            $stmt->execute([':id' => $loteId, ':m' => $medicamento['id']]);
            $loteRow = $stmt->fetch();

            if (!$loteRow) {
                $formError = 'Lote inválido ou não pertence a este medicamento. Busque novamente e selecione o lote.';
            } elseif ($quantidade > (int)$loteRow['quantidade']) {
                $formError = 'Estoque insuficiente no lote "' . htmlspecialchars($loteRow['lote']) . '": há apenas ' . (int)$loteRow['quantidade'] . ' unidade(s) disponível(is) nele.';
            } else {
                $db->prepare("UPDATE insumo_lotes SET quantidade = quantidade - :q WHERE id = :id")
                   ->execute([':q' => $quantidade, ':id' => $loteRow['id']]);
                $db->prepare("INSERT INTO movimentacoes (medicamento_id, lote_id, tipo, quantidade, usuario, observacao) VALUES (:m, :lo, 'saida', :q, :u, :o)")
                   ->execute([':m' => $medicamento['id'], ':lo' => $loteRow['id'], ':q' => $quantidade, ':u' => $_SESSION['user_logged_in'], ':o' => $observacao]);

                header("Location: index.php?page=movimentacao&tab=saida&ok=1");
                exit;
            }
        }
    }
}

if (isset($_GET['ok'])) {
    $formSuccess = $tab === 'entrada' ? 'Entrada registrada com sucesso.' : 'Saída registrada com sucesso.';
}
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Entrada / Saída de Medicamentos</h1>
        <div class="page-sub">Leia o código de barras com o leitor ou digite manualmente — busca direto na base da ANVISA/CMED</div>
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
            <?php if ($tab === 'saida'): ?>
            <div class="mb-2">
                <label class="form-label">Lote a dar saída</label>
                <select name="lote_id" id="loteSelect" class="form-select" required></select>
                <div class="form-text" id="loteSelectHint"></div>
            </div>
            <div class="row g-2">
                <div class="col-sm-12">
                    <label class="form-label">Quantidade</label>
                    <input type="number" name="quantidade" id="quantidadeInput" class="form-control" min="1" required>
                </div>
            </div>
            <?php else: ?>
            <div class="row g-2">
                <div class="col-sm-4">
                    <label class="form-label">Quantidade</label>
                    <input type="number" name="quantidade" id="quantidadeInput" class="form-control" min="1" required>
                </div>
                <div class="col-sm-4">
                    <label class="form-label">Lote</label>
                    <input type="text" name="lote" id="loteInput" class="form-control" required>
                </div>
                <div class="col-sm-4">
                    <label class="form-label">Validade</label>
                    <input type="date" name="validade" id="validadeInput" class="form-control" required>
                </div>
            </div>
            <?php endif; ?>
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
    var loteSelect = document.getElementById('loteSelect');
    var loteSelectHint = document.getElementById('loteSelectHint');

    function statusBadgeClass(status) {
        return { vencido: 'bg-danger', urgente: 'bg-warning text-dark', alerta: 'bg-info text-dark', ok: 'bg-success' }[status] || 'bg-secondary';
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = (s === null || s === undefined) ? '' : String(s);
        return d.innerHTML;
    }

    function atualizarHintLote() {
        if (!loteSelect || !loteSelectHint) return;
        var opt = loteSelect.options[loteSelect.selectedIndex];
        if (!opt || !opt.value) {
            loteSelectHint.textContent = '';
            quantidadeInput.removeAttribute('max');
            return;
        }
        var qtd = opt.getAttribute('data-quantidade');
        quantidadeInput.max = qtd;
        loteSelectHint.textContent = qtd + ' unidade(s) disponível(is) neste lote.';
    }

    function montarListaLotes(lotes) {
        if (!lotes.length) {
            return '<div class="text-muted small mt-2">Nenhum lote com saldo em estoque.</div>';
        }
        var linhas = lotes.map(function (l) {
            return '<tr><td class="mono">' + esc(l.lote) + '</td><td class="mono">' + esc(l.validade_br) + '</td>' +
                '<td class="text-center">' + esc(l.quantidade) + '</td>' +
                '<td class="text-center"><span class="badge ' + statusBadgeClass(l.status) + '">' + esc(l.status_label) + '</span></td></tr>';
        }).join('');
        return '<div class="table-responsive mt-2"><table class="table table-sm mb-0">' +
            '<thead><tr><th>Lote</th><th>Validade</th><th class="text-center">Qtd.</th><th class="text-center">Status</th></tr></thead>' +
            '<tbody>' + linhas + '</tbody></table></div>';
    }

    function buscar() {
        var codigo = codigoInput.value.trim();
        if (!codigo) return;
        scanResult.innerHTML = '<div class="text-muted small mt-2">Buscando...</div>';
        campos.style.display = 'none';

        fetch('ajax_buscar_medicamento.php?codigo=' + encodeURIComponent(codigo))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.found) {
                    scanResult.innerHTML = '<div class="alert alert-warning mt-3 mb-0">' + esc(data.error) + '</div>';
                    codigoBarrasField.value = '';
                    return;
                }
                var m = data.medicamento;
                codigoBarrasField.value = m.codigo_barras;

                var statusHtml = m.status
                    ? '<span class="badge ' + statusBadgeClass(m.status) + '">' + esc(m.status_label) + '</span>'
                    : '<span class="badge bg-secondary">Sem estoque</span>';

                scanResult.innerHTML =
                    '<div class="scan-summary">' +
                    '<div style="width:100%;">' +
                        '<div class="scan-summary-title">' + esc(m.produto) + '</div>' +
                        '<div class="scan-summary-sub">' + esc(m.laboratorio || '—') + '</div>' +
                        '<div class="scan-summary-grid">' +
                            '<div><div class="entity-field-label">Estoque total</div><div class="entity-field-value">' + esc(m.estoque_total) + ' un.</div></div>' +
                            (m.apresentacao ? '<div><div class="entity-field-label">Apresentação</div><div class="entity-field-value">' + esc(m.apresentacao) + '</div></div>' : '') +
                            (m.substancia ? '<div class="full"><div class="entity-field-label">Substância</div><div class="entity-field-value">' + esc(m.substancia) + '</div></div>' : '') +
                        '</div>' +
                        '<div class="mt-2">' + statusHtml + '</div>' +
                        montarListaLotes(m.lotes) +
                    '</div></div>';

                if (tipo === 'saida') {
                    if (!m.lotes.length) {
                        campos.style.display = 'none';
                        return;
                    }
                    loteSelect.innerHTML = m.lotes.map(function (l) {
                        return '<option value="' + l.id + '" data-quantidade="' + l.quantidade + '">' +
                            l.lote + ' · vence em ' + l.validade_br + ' · ' + l.quantidade + ' un.' +
                            '</option>';
                    }).join('');
                    loteSelect.selectedIndex = 0;
                    atualizarHintLote();
                }

                campos.style.display = 'block';
                quantidadeInput.focus();
            })
            .catch(function () {
                scanResult.innerHTML = '<div class="alert alert-danger mt-3 mb-0">Erro ao buscar o medicamento. Tente novamente.</div>';
            });
    }

    codigoInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); buscar(); }
    });
    // Código de barras EAN-13 tem exatamente 13 dígitos — assim que o leitor (ou a digitação)
    // completa esse tamanho, busca automaticamente, sem esperar Enter/clique em "Buscar".
    codigoInput.addEventListener('input', function () {
        var valor = codigoInput.value.trim();
        if (/^\d{13}$/.test(valor)) { buscar(); }
    });
    buscarBtn.addEventListener('click', buscar);
    if (loteSelect) { loteSelect.addEventListener('change', atualizarHintLote); }
})();
</script>
