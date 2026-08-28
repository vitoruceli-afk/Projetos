<?php
$db = getDB();
$tab = ($_GET['tab'] ?? 'entrada') === 'saida' ? 'saida' : 'entrada';
$formError = '';
$formSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrfVerify();

    if ($_POST['action'] === 'confirmar_entradas') {
        // Fluxo em duas etapas: cada "Inserir" só empilha o item no navegador (itens_json); nada
        // é gravado até o operador conferir a lista e confirmar — tudo é aplicado numa única
        // transação para não deixar entradas "pela metade" se algum item da lista for inválido.
        $itens = json_decode($_POST['itens_json'] ?? '[]', true);
        if (!is_array($itens) || count($itens) === 0) {
            $formError = 'Nenhum item foi adicionado para confirmar a entrada.';
        } else {
            $erroItem = null;
            $db->beginTransaction();

            // Cabeçalho da confirmação: liga todos os itens desta mesma ação de "Confirmar
            // Entrada", para o relatório mostrar a ação como um todo em vez de item a item.
            $totalQuantidade = array_sum(array_map(function ($i) { return (int)($i['quantidade'] ?? 0); }, $itens));
            $db->prepare("INSERT INTO movimentacao_confirmacoes (tipo, usuario, total_itens, total_quantidade) VALUES ('entrada', :u, :ti, :tq)")
               ->execute([':u' => $_SESSION['user_logged_in'], ':ti' => count($itens), ':tq' => $totalQuantidade]);
            $confirmacaoId = (int)$db->lastInsertId();

            foreach ($itens as $idx => $item) {
                $codigo = trim($item['codigo_barras'] ?? '');
                $quantidade = (int)($item['quantidade'] ?? 0);
                $lote = trim($item['lote'] ?? '');
                $validade = trim($item['validade'] ?? '');
                $observacao = trim($item['observacao'] ?? '');

                $medicamento = $codigo !== '' ? findMedicamentoByBarcode($db, $codigo) : null;
                $numero = $idx + 1;

                if (!$medicamento) {
                    $erroItem = "Item {$numero}: medicamento com código \"" . htmlspecialchars($codigo) . '" não foi encontrado.';
                    break;
                }
                if ($quantidade <= 0 || $lote === '' || $validade === '') {
                    $erroItem = "Item {$numero} (" . htmlspecialchars($medicamento['produto']) . '): quantidade, lote e validade são obrigatórios.';
                    break;
                }

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
                $db->prepare("INSERT INTO movimentacoes (medicamento_id, lote_id, confirmacao_id, tipo, quantidade, usuario, observacao) VALUES (:m, :lo, :c, 'entrada', :q, :u, :o)")
                   ->execute([':m' => $medicamento['id'], ':lo' => $loteId, ':c' => $confirmacaoId, ':q' => $quantidade, ':u' => $_SESSION['user_logged_in'], ':o' => $observacao]);
            }

            if ($erroItem) {
                $db->rollBack();
                $formError = $erroItem . ' Nenhum item da lista foi gravado — corrija e confirme novamente.';
            } else {
                $db->commit();
                header('Location: index.php?page=movimentacao&tab=entrada&ok=1&qtd=' . count($itens));
                exit;
            }
        }
    } elseif ($_POST['action'] === 'saida') {
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
        } else {
            // Um medicamento pode ter vários lotes com validades diferentes em estoque ao mesmo
            // tempo — a baixa é feita sempre no lote específico escolhido pelo operador (não
            // automaticamente pelo mais próximo do vencimento), para dar baixa no lote correto.
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

                    header('Location: index.php?page=movimentacao&tab=saida&ok=1');
                    exit;
                }
            }
        }
    }
}

if (isset($_GET['ok'])) {
    if ($tab === 'entrada') {
        $qtd = (int)($_GET['qtd'] ?? 1);
        $formSuccess = $qtd === 1 ? '1 item inserido com sucesso.' : "{$qtd} itens inseridos com sucesso.";
    } else {
        $formSuccess = 'Saída registrada com sucesso.';
    }
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

<?php if ($tab === 'entrada'): ?>

<form method="POST" id="movForm">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="confirmar_entradas">
    <input type="hidden" name="itens_json" id="itensJsonField" value="[]">

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="scan-card">
                <label class="form-label">Código de barras</label>
                <div class="scan-input-row">
                    <input type="text" id="codigoInput" class="form-control mono" autocomplete="off" placeholder="Leia o código de barras..." autofocus>
                    <button type="button" id="buscarBtn" class="btn btn-outline-primary"><i class="bi bi-search"></i> Buscar</button>
                </div>

                <div id="scanResult"></div>

                <div id="camposMovimentacao" style="display:none;">
                    <hr>
                    <div class="row g-2">
                        <div class="col-sm-4">
                            <label class="form-label">Quantidade</label>
                            <input type="number" id="quantidadeInput" class="form-control" min="1">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label">Lote</label>
                            <input type="text" id="loteInput" class="form-control">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label">Validade</label>
                            <input type="date" id="validadeInput" class="form-control">
                        </div>
                    </div>
                    <div class="mb-2 mt-2">
                        <label class="form-label">Observação (opcional)</label>
                        <input type="text" id="observacaoInput" class="form-control">
                    </div>
                    <button type="button" id="inserirBtn" class="btn btn-outline-success w-100 mt-2">
                        <i class="bi bi-plus-lg"></i> Inserir
                    </button>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Itens a Inserir</span>
                    <span class="badge bg-light" id="itensCount">0</span>
                </div>
                <div class="card-body">
                    <div id="itensLista">
                        <p class="text-muted small mb-0">Nenhum item adicionado ainda. Busque um medicamento ao lado e clique em "Inserir".</p>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-top pt-3">
                    <button type="submit" id="confirmarEntradaBtn" class="btn btn-success w-100" disabled>
                        Confirmar Entrada
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<?php else: ?>

<div class="scan-card">
    <form method="POST" id="movForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="saida">
        <label class="form-label">Código de barras</label>
        <div class="scan-input-row">
            <input type="text" id="codigoInput" class="form-control mono" autocomplete="off" placeholder="Leia o código de barras..." autofocus>
            <button type="button" id="buscarBtn" class="btn btn-outline-primary"><i class="bi bi-search"></i> Buscar</button>
        </div>
        <input type="hidden" name="codigo_barras" id="codigoBarrasField">

        <div id="scanResult"></div>

        <div id="camposMovimentacao" style="display:none;">
            <hr>
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
            <div class="mb-2 mt-2">
                <label class="form-label">Observação (opcional)</label>
                <input type="text" name="observacao" class="form-control">
            </div>
            <button type="submit" class="btn btn-outline-danger w-100 mt-2">Confirmar Saída</button>
        </div>
    </form>
</div>

<?php endif; ?>

<script>
(function () {
    var tipo = '<?= $tab ?>';
    var codigoInput = document.getElementById('codigoInput');
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

    var ultimoMedicamento = null;

    function buscar() {
        var codigo = codigoInput.value.trim();
        if (!codigo) return;
        scanResult.innerHTML = '<div class="text-muted small mt-2">Buscando...</div>';
        campos.style.display = 'none';
        ultimoMedicamento = null;

        fetch('ajax_buscar_medicamento.php?codigo=' + encodeURIComponent(codigo))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.found) {
                    scanResult.innerHTML = '<div class="alert alert-warning mt-3 mb-0">' + esc(data.error) + '</div>';
                    return;
                }
                var m = data.medicamento;
                ultimoMedicamento = m;

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
                    var codigoBarrasField = document.getElementById('codigoBarrasField');
                    codigoBarrasField.value = m.codigo_barras;
                    var loteSelect = document.getElementById('loteSelect');
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

    function atualizarHintLote() {
        var loteSelect = document.getElementById('loteSelect');
        var loteSelectHint = document.getElementById('loteSelectHint');
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

    var loteSelectEl = document.getElementById('loteSelect');
    if (loteSelectEl) { loteSelectEl.addEventListener('change', atualizarHintLote); }

    // ---- Entrada: fila de itens conferida antes de gravar qualquer coisa no banco ----
    if (tipo === 'entrada') {
        var itensEntrada = [];
        var loteInput = document.getElementById('loteInput');
        var validadeInput = document.getElementById('validadeInput');
        var observacaoInput = document.getElementById('observacaoInput');
        var inserirBtn = document.getElementById('inserirBtn');
        var itensLista = document.getElementById('itensLista');
        var itensCount = document.getElementById('itensCount');
        var confirmarBtn = document.getElementById('confirmarEntradaBtn');
        var itensJsonField = document.getElementById('itensJsonField');
        var movForm = document.getElementById('movForm');

        function formatarDataBr(iso) {
            var partes = iso.split('-');
            return partes.length === 3 ? (partes[2] + '/' + partes[1] + '/' + partes[0]) : iso;
        }

        function renderItens() {
            itensCount.textContent = itensEntrada.length;
            confirmarBtn.disabled = itensEntrada.length === 0;

            if (itensEntrada.length === 0) {
                itensLista.innerHTML = '<p class="text-muted small mb-0">Nenhum item adicionado ainda. Busque um medicamento ao lado e clique em "Inserir".</p>';
                return;
            }

            itensLista.innerHTML = itensEntrada.map(function (item, idx) {
                return '<div class="entity-card" style="padding:10px 12px;margin-bottom:8px;">' +
                    '<div class="d-flex justify-content-between align-items-start gap-2">' +
                        '<div class="min-w-0">' +
                            '<div class="entity-title" style="font-size:13px;">' + esc(item.produto) + '</div>' +
                            (item.laboratorio ? '<div class="entity-sub">' + esc(item.laboratorio) + '</div>' : '') +
                            (item.apresentacao ? '<div class="entity-sub">' + esc(item.apresentacao) + '</div>' : '') +
                            '<div class="entity-sub">Lote ' + esc(item.lote) + ' · vence em ' + esc(item.validadeBr) + ' · ' + esc(item.quantidade) + ' un.' +
                                (item.observacao ? ' · ' + esc(item.observacao) : '') +
                            '</div>' +
                        '</div>' +
                        '<button type="button" class="btn btn-sm btn-outline-danger btn-remover-item" data-idx="' + idx + '" title="Remover"><i class="bi bi-x-lg"></i></button>' +
                    '</div>' +
                '</div>';
            }).join('');

            itensLista.querySelectorAll('.btn-remover-item').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    itensEntrada.splice(parseInt(btn.getAttribute('data-idx'), 10), 1);
                    renderItens();
                });
            });
        }

        inserirBtn.addEventListener('click', function () {
            if (!ultimoMedicamento) return;
            var quantidade = parseInt(quantidadeInput.value, 10);
            var lote = loteInput.value.trim();
            var validade = validadeInput.value;
            var observacao = observacaoInput.value.trim();

            if (!quantidade || quantidade <= 0) { alert('Informe uma quantidade válida.'); return; }
            if (!lote) { alert('Informe o lote.'); return; }
            if (!validade) { alert('Informe a validade.'); return; }

            itensEntrada.push({
                codigo_barras: ultimoMedicamento.codigo_barras,
                produto: ultimoMedicamento.produto,
                apresentacao: ultimoMedicamento.apresentacao,
                laboratorio: ultimoMedicamento.laboratorio,
                quantidade: quantidade,
                lote: lote,
                validade: validade,
                validadeBr: formatarDataBr(validade),
                observacao: observacao
            });
            renderItens();

            ultimoMedicamento = null;
            codigoInput.value = '';
            scanResult.innerHTML = '';
            campos.style.display = 'none';
            codigoInput.focus();
        });

        movForm.addEventListener('submit', function (e) {
            if (itensEntrada.length === 0) {
                e.preventDefault();
                alert('Adicione ao menos um item antes de confirmar a entrada.');
                return;
            }
            if (!confirm('Você confirma os itens a serem inseridos?')) {
                e.preventDefault();
                return;
            }
            itensJsonField.value = JSON.stringify(itensEntrada);
        });
    }
})();
</script>
