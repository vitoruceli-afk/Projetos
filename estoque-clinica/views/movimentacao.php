<?php
$db = getDB();
$tab = ($_GET['tab'] ?? 'entrada') === 'saida' ? 'saida' : 'entrada';
$formError = '';
$formSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrfVerify();

    if ($_POST['action'] === 'confirmar_entradas' || $_POST['action'] === 'confirmar_saidas') {
        // Fluxo em duas etapas (igual para Entrada e Saída, e para medicamento ou insumo): cada
        // "Inserir" só empilha o item no navegador (itens_json); nada é gravado até o operador
        // conferir a lista e confirmar — tudo aplicado numa única transação para não deixar nada
        // "pela metade" se algum item da lista for inválido.
        $ehEntrada = $_POST['action'] === 'confirmar_entradas';
        $tipoMov = $ehEntrada ? 'entrada' : 'saida';
        $itens = json_decode($_POST['itens_json'] ?? '[]', true);

        // Saída: paciente a que os itens se destinam, escolhido por busca no cadastro de
        // Pacientes — obrigatório, vale pra confirmação inteira, não item a item.
        $pacienteId = null;
        if (!$ehEntrada) {
            $pacienteIdPost = (int)($_POST['paciente_id'] ?? 0);
            if ($pacienteIdPost > 0) {
                $stmt = $db->prepare("SELECT id FROM pacientes WHERE id = :id");
                $stmt->bindValue(':id', $pacienteIdPost, PDO::PARAM_INT);
                $stmt->execute();
                if ($stmt->fetchColumn()) {
                    $pacienteId = $pacienteIdPost;
                }
            }
        }

        if (!$ehEntrada && !$pacienteId) {
            $formError = 'Selecione o paciente a quem os itens desta saída se destinam.';
        } elseif (!is_array($itens) || count($itens) === 0) {
            $formError = $ehEntrada ? 'Nenhum item foi adicionado para confirmar a entrada.' : 'Nenhum item foi adicionado para confirmar a saída.';
        } else {
          try {
            $erroItem = null;
            $db->beginTransaction();

            // Cabeçalho da confirmação: liga todos os itens desta mesma ação de "Confirmar
            // Entrada/Saída" (medicamentos e insumos juntos), para o relatório mostrar a ação como
            // um todo em vez de item a item.
            $totalQuantidade = array_sum(array_map(function ($i) { return (int)($i['quantidade'] ?? 0); }, $itens));
            $db->prepare("INSERT INTO movimentacao_confirmacoes (tipo, usuario, paciente_id, total_itens, total_quantidade) VALUES (:t, :u, :p, :ti, :tq)")
               ->execute([':t' => $tipoMov, ':u' => $_SESSION['user_logged_in'], ':p' => $pacienteId, ':ti' => count($itens), ':tq' => $totalQuantidade]);
            $confirmacaoId = (int)$db->lastInsertId();

            foreach ($itens as $idx => $item) {
                $codigo = trim($item['codigo_barras'] ?? '');
                $itemId = (int)($item['item_id'] ?? 0);
                $tipoItem = ($item['tipo_item'] ?? '') === 'insumo' ? 'insumo' : 'medicamento';
                $quantidade = (int)($item['quantidade'] ?? 0);
                $observacao = trim($item['observacao'] ?? '');
                $numero = $idx + 1;

                if ($tipoItem === 'medicamento') {
                    // Busca preferencialmente por id (vem de qualquer um dos dois jeitos de achar
                    // o item — leitor de código de barras ou busca por nome); código de barras
                    // fica como alternativa para não quebrar nada que ainda dependa só dele.
                    $medicamento = null;
                    if ($itemId > 0) {
                        $stmt = $db->prepare("SELECT * FROM medicamentos_anvisa WHERE id = :id");
                        $stmt->bindValue(':id', $itemId, PDO::PARAM_INT);
                        $stmt->execute();
                        $medicamento = $stmt->fetch() ?: null;
                    }
                    if (!$medicamento && $codigo !== '') {
                        $medicamento = findMedicamentoByBarcode($db, $codigo);
                    }
                    if (!$medicamento) {
                        $erroItem = "Item {$numero}: medicamento com código \"" . htmlspecialchars($codigo) . '" não foi encontrado.';
                        break;
                    }

                    if ($ehEntrada) {
                        $lote = trim($item['lote'] ?? '');
                        $validade = trim($item['validade'] ?? '');
                        $valorUnitario = is_numeric($item['valor_unitario'] ?? null) ? (float)$item['valor_unitario'] : -1;
                        if ($quantidade <= 0 || $lote === '' || $validade === '') {
                            $erroItem = "Item {$numero} (" . htmlspecialchars($medicamento['produto']) . '): quantidade, lote e validade são obrigatórios.';
                            break;
                        }
                        if ($valorUnitario < 0) {
                            $erroItem = "Item {$numero} (" . htmlspecialchars($medicamento['produto']) . '): informe o valor unitário.';
                            break;
                        }

                        $stmt = $db->prepare("SELECT * FROM insumo_lotes WHERE medicamento_id = :m AND lote = :l");
                        $stmt->execute([':m' => $medicamento['id'], ':l' => $lote]);
                        $loteRow = $stmt->fetch();

                        if ($loteRow) {
                            $db->prepare("UPDATE insumo_lotes SET quantidade = quantidade + :q, valor_unitario = :vu WHERE id = :id")
                               ->execute([':q' => $quantidade, ':vu' => $valorUnitario, ':id' => $loteRow['id']]);
                            $loteId = $loteRow['id'];
                        } else {
                            $db->prepare("INSERT INTO insumo_lotes (medicamento_id, lote, validade, quantidade, valor_unitario) VALUES (:m, :l, :v, :q, :vu)")
                               ->execute([':m' => $medicamento['id'], ':l' => $lote, ':v' => $validade, ':q' => $quantidade, ':vu' => $valorUnitario]);
                            $loteId = (int)$db->lastInsertId();
                        }

                        // Quantidade mínima do medicamento: opcional, informada na Entrada. Se o
                        // operador deixou em branco (nada mudou), não mexe no que já estava salvo.
                        if (array_key_exists('estoque_minimo', $item) && trim((string)$item['estoque_minimo']) !== '') {
                            $db->prepare("UPDATE medicamentos_anvisa SET estoque_minimo = :em WHERE id = :id")
                               ->execute([':em' => (int)$item['estoque_minimo'], ':id' => $medicamento['id']]);
                        }
                    } else {
                        // Um medicamento pode ter vários lotes com validades diferentes em estoque ao
                        // mesmo tempo — a baixa é sempre no lote específico escolhido pelo operador
                        // (não automaticamente pelo mais próximo do vencimento).
                        $loteId = (int)($item['lote_id'] ?? 0);
                        if ($quantidade <= 0 || $loteId <= 0) {
                            $erroItem = "Item {$numero} (" . htmlspecialchars($medicamento['produto']) . '): selecione o lote e informe uma quantidade válida.';
                            break;
                        }

                        // FOR UPDATE: trava a linha do lote até o fim da transação, pra duas saídas
                        // concorrentes do mesmo lote não aprovarem a mesma checagem de saldo antes de
                        // qualquer uma delas descontar (o que deixaria a quantidade negativa).
                        $stmt = $db->prepare("SELECT * FROM insumo_lotes WHERE id = :id AND medicamento_id = :m FOR UPDATE");
                        $stmt->execute([':id' => $loteId, ':m' => $medicamento['id']]);
                        $loteRow = $stmt->fetch();

                        if (!$loteRow) {
                            $erroItem = "Item {$numero} (" . htmlspecialchars($medicamento['produto']) . '): lote inválido ou não pertence a este medicamento.';
                            break;
                        }
                        if ($quantidade > (int)$loteRow['quantidade']) {
                            $erroItem = "Item {$numero} (" . htmlspecialchars($medicamento['produto']) . '): estoque insuficiente no lote "' . htmlspecialchars($loteRow['lote']) . '" (há apenas ' . (int)$loteRow['quantidade'] . ' unidade(s)).';
                            break;
                        }

                        $db->prepare("UPDATE insumo_lotes SET quantidade = quantidade - :q WHERE id = :id")
                           ->execute([':q' => $quantidade, ':id' => $loteRow['id']]);
                        $loteId = $loteRow['id'];
                        // Valor não vem do formulário na Saída — sempre o valor gravado no lote (o
                        // que foi pago naquela entrada), pra não deixar o operador alterar na retirada.
                        $valorUnitario = (float)$loteRow['valor_unitario'];
                    }

                    $db->prepare("INSERT INTO movimentacoes (medicamento_id, lote_id, confirmacao_id, tipo, quantidade, valor_unitario, usuario, observacao) VALUES (:m, :lo, :c, :t, :q, :vu, :u, :o)")
                       ->execute([':m' => $medicamento['id'], ':lo' => $loteId, ':c' => $confirmacaoId, ':t' => $tipoMov, ':q' => $quantidade, ':vu' => $valorUnitario, ':u' => $_SESSION['user_logged_in'], ':o' => $observacao]);
                } else {
                    // Insumo: não tem uma sub-tabela de lotes como medicamento (uma única
                    // quantidade por registro) — mas a entrada também registra lote/validade,
                    // atualizando os campos do próprio insumo (a entrada mais recente é a que
                    // fica valendo, já que não há histórico de lotes separado).
                    // Busca por id primeiro — necessário pra insumo sem EAN cadastrado, que só é
                    // localizável pela busca por nome.
                    $insumo = null;
                    if ($itemId > 0) {
                        $stmt = $db->prepare("SELECT * FROM insumos WHERE id = :id");
                        $stmt->bindValue(':id', $itemId, PDO::PARAM_INT);
                        $stmt->execute();
                        $insumo = $stmt->fetch() ?: null;
                    }
                    if (!$insumo && $codigo !== '') {
                        $insumo = findInsumoByBarcode($db, $codigo);
                    }
                    if (!$insumo) {
                        $erroItem = "Item {$numero}: insumo com código \"" . htmlspecialchars($codigo) . '" não foi encontrado.';
                        break;
                    }
                    if ($quantidade <= 0) {
                        $erroItem = "Item {$numero} (" . htmlspecialchars($insumo['nome_comercial']) . '): informe uma quantidade válida.';
                        break;
                    }

                    if ($ehEntrada) {
                        $lote = trim($item['lote'] ?? '');
                        $validade = trim($item['validade'] ?? '');
                        $valorUnitario = is_numeric($item['valor_unitario'] ?? null) ? (float)$item['valor_unitario'] : -1;
                        if ($lote === '' || $validade === '') {
                            $erroItem = "Item {$numero} (" . htmlspecialchars($insumo['nome_comercial']) . '): lote e validade são obrigatórios.';
                            break;
                        }
                        if ($valorUnitario < 0) {
                            $erroItem = "Item {$numero} (" . htmlspecialchars($insumo['nome_comercial']) . '): informe o valor unitário.';
                            break;
                        }
                        $db->prepare("UPDATE insumos SET quantidade = quantidade + :q, lote = :l, validade = :v, valor_unitario = :vu WHERE id = :id")
                           ->execute([':q' => $quantidade, ':l' => $lote, ':v' => $validade, ':vu' => $valorUnitario, ':id' => $insumo['id']]);
                    } else {
                        // FOR UPDATE: relê quantidade/valor sob lock, pra duas saídas concorrentes do
                        // mesmo insumo não aprovarem a mesma checagem de saldo antes de qualquer uma
                        // delas descontar (o que deixaria a quantidade negativa).
                        $stmtLock = $db->prepare("SELECT quantidade, valor_unitario FROM insumos WHERE id = :id FOR UPDATE");
                        $stmtLock->bindValue(':id', $insumo['id'], PDO::PARAM_INT);
                        $stmtLock->execute();
                        $insumoLocked = $stmtLock->fetch();

                        if ($quantidade > (int)$insumoLocked['quantidade']) {
                            $erroItem = "Item {$numero} (" . htmlspecialchars($insumo['nome_comercial']) . '): estoque insuficiente (há apenas ' . (int)$insumoLocked['quantidade'] . ' ' . htmlspecialchars($insumo['unidade_medida']) . '(s)).';
                            break;
                        }
                        $db->prepare("UPDATE insumos SET quantidade = quantidade - :q WHERE id = :id")
                           ->execute([':q' => $quantidade, ':id' => $insumo['id']]);
                        // Valor não vem do formulário na Saída — sempre o valor cadastrado no insumo
                        // (a última entrada), pra não deixar o operador alterar na retirada.
                        $valorUnitario = (float)$insumoLocked['valor_unitario'];
                    }

                    $db->prepare("INSERT INTO movimentacoes (insumo_id, confirmacao_id, tipo, quantidade, valor_unitario, usuario, observacao) VALUES (:i, :c, :t, :q, :vu, :u, :o)")
                       ->execute([':i' => $insumo['id'], ':c' => $confirmacaoId, ':t' => $tipoMov, ':q' => $quantidade, ':vu' => $valorUnitario, ':u' => $_SESSION['user_logged_in'], ':o' => $observacao]);
                }
            }

            if ($erroItem) {
                $db->rollBack();
                $formError = $erroItem . ' Nenhum item da lista foi gravado — corrija e confirme novamente.';
            } else {
                $db->commit();
                $acaoLog = $ehEntrada ? 'Entrada confirmada' : 'Saída confirmada';
                $detalhesLog = count($itens) . ' item(ns), ' . $totalQuantidade . ' unidade(s) no total';
                if ($pacienteId) {
                    $nomePaciente = $db->prepare("SELECT nome_completo FROM pacientes WHERE id = :id");
                    $nomePaciente->execute([':id' => $pacienteId]);
                    $detalhesLog .= ', paciente: ' . $nomePaciente->fetchColumn();
                }
                registrarLog('Movimentação', $acaoLog, $detalhesLog);
                header('Location: index.php?page=movimentacao&tab=' . $tipoMov . '&ok=1&qtd=' . count($itens));
                exit;
            }
          } catch (PDOException $e) {
              // Erro inesperado do banco no meio da transação (ex.: dois operadores cadastrando o
              // mesmo lote novo ao mesmo tempo, timeout de lock, conexão caindo) — sem isso, a
              // exceção não tratada derrubava a página com um erro fatal cru no meio da conferência
              // do operador. Com o rollback, nada fica gravado pela metade; a mensagem orienta a
              // tentar de novo (na maioria dos casos, como o do lote duplicado, a segunda tentativa
              // já funciona, pois na primeira o lote acabou sendo criado por outra requisição).
              if ($db->inTransaction()) {
                  $db->rollBack();
              }
              $formError = 'Ocorreu um erro inesperado ao gravar a movimentação. Nenhum item foi salvo — confira a lista e tente confirmar novamente.';
          }
        }
    }
}

if (isset($_GET['ok'])) {
    $qtd = (int)($_GET['qtd'] ?? 1);
    if ($tab === 'entrada') {
        $formSuccess = $qtd === 1 ? '1 item inserido com sucesso.' : "{$qtd} itens inseridos com sucesso.";
    } else {
        $formSuccess = $qtd === 1 ? '1 item retirado com sucesso.' : "{$qtd} itens retirados com sucesso.";
    }
}
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Entrada / Saída</h1>
        <div class="page-sub">Leia o código de barras com o leitor ou digite manualmente — busca na base de medicamentos (ANVISA/CMED) e no catálogo de Insumos</div>
    </div>
</div>

<ul class="nav nav-tabs mb-4">
    <li class="nav-item"><a class="nav-link <?= $tab === 'entrada' ? 'active' : '' ?>" href="index.php?page=movimentacao&tab=entrada">Entrada</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'saida' ? 'active' : '' ?>" href="index.php?page=movimentacao&tab=saida">Saída</a></li>
</ul>

<?php if ($formError): ?><div class="alert alert-danger"><?= $formError ?></div><?php endif; ?>
<?php if ($formSuccess): ?><div class="alert alert-success"><?= htmlspecialchars($formSuccess) ?></div><?php endif; ?>

<form method="POST" id="movForm">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="<?= $tab === 'entrada' ? 'confirmar_entradas' : 'confirmar_saidas' ?>">
    <input type="hidden" name="itens_json" id="itensJsonField" value="[]">

    <?php if ($tab === 'saida'): ?>
    <div class="scan-card mb-3">
        <label class="form-label">Paciente</label>
        <div class="form-text mb-2">Busque por nome ou CPF o paciente a quem os itens desta saída se destinam.</div>
        <input type="hidden" name="paciente_id" id="pacienteIdField" value="">

        <div id="pacienteSelecionadoWrap" style="display:none;" class="d-flex align-items-center justify-content-between border rounded p-2 mb-2">
            <div>
                <div class="fw-bold" id="pacienteSelecionadoNome"></div>
                <div class="small text-muted mono" id="pacienteSelecionadoCpf"></div>
            </div>
            <button type="button" id="pacienteTrocarBtn" class="btn btn-sm btn-outline-secondary">Trocar</button>
        </div>

        <div id="pacienteBuscaWrap">
            <div class="scan-input-row">
                <input type="text" id="pacienteBuscaInput" class="form-control" autocomplete="off" placeholder="Nome ou CPF do paciente...">
            </div>
            <div id="pacienteResultados"></div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="scan-card">
                <label class="form-label">Código de barras</label>
                <div class="scan-input-row">
                    <input type="text" id="codigoInput" class="form-control mono" autocomplete="off" placeholder="Leia o código de barras..." autofocus>
                    <button type="button" id="buscarBtn" class="btn btn-outline-primary"><i class="bi bi-search"></i> Buscar</button>
                </div>
                <button type="button" id="buscarNomeBtn" class="btn btn-outline-secondary btn-sm w-100 mt-2">
                    <i class="bi bi-search"></i> Buscar por nome (medicamento ou insumo)
                </button>

                <div id="scanResult"></div>

                <div id="camposMovimentacao" style="display:none;">
                    <hr>
                    <?php if ($tab === 'saida'): ?>
                    <div class="mb-2" id="loteSelectWrap">
                        <label class="form-label">Lote a dar saída</label>
                        <select id="loteSelect" class="form-select"></select>
                        <div class="form-text" id="loteSelectHint"></div>
                    </div>
                    <?php endif; ?>
                    <div class="row g-2">
                        <div class="col-sm-<?= $tab === 'entrada' ? '4' : '12' ?>" id="quantidadeCol">
                            <label class="form-label">Quantidade</label>
                            <input type="number" id="quantidadeInput" class="form-control" min="1">
                            <div class="form-text" id="quantidadeHint"></div>
                        </div>
                        <?php if ($tab === 'entrada'): ?>
                        <div class="col-sm-4" id="loteTextWrap">
                            <label class="form-label">Lote</label>
                            <input type="text" id="loteInput" class="form-control">
                        </div>
                        <div class="col-sm-4" id="validadeWrap">
                            <label class="form-label">Validade</label>
                            <input type="date" id="validadeInput" class="form-control">
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($tab === 'entrada'): ?>
                    <div class="row g-2 mt-0">
                        <div class="col-sm-4" id="valorUnitarioWrap">
                            <label class="form-label">Valor unitário (R$)</label>
                            <input type="number" id="valorUnitarioInput" class="form-control" min="0" step="0.01" placeholder="0,00">
                        </div>
                        <div class="col-sm-4" id="quantidadeMinimaWrap">
                            <label class="form-label">Quantidade mínima</label>
                            <input type="number" id="quantidadeMinimaInput" class="form-control" min="0" placeholder="Não cadastrada">
                            <div class="form-text">Nível de estoque abaixo do qual este medicamento deve ser reposto. Fica salvo pra próxima entrada já vir preenchido.</div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="mb-2 mt-2">
                        <label class="form-label">Observação (opcional)</label>
                        <input type="text" id="observacaoInput" class="form-control">
                    </div>
                    <button type="button" id="inserirBtn" class="btn <?= $tab === 'entrada' ? 'btn-outline-success' : 'btn-outline-danger' ?> w-100 mt-2">
                        <i class="bi bi-plus-lg"></i> Inserir
                    </button>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><?= $tab === 'entrada' ? 'Itens a Inserir' : 'Itens a Retirar' ?></span>
                    <span class="badge bg-light" id="itensCount">0</span>
                </div>
                <div class="card-body">
                    <div id="itensLista">
                        <p class="text-muted small mb-0">Nenhum item adicionado ainda. Busque um medicamento ou insumo ao lado e clique em "Inserir".</p>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-top pt-3">
                    <?php if ($tab === 'saida'): ?>
                    <div class="d-flex justify-content-between align-items-center mb-2" id="resumoFinanceiroWrap" style="display:none !important;">
                        <span class="text-muted small">Resumo financeiro desta saída</span>
                        <span class="fw-bold" id="resumoFinanceiroTotal">R$ 0,00</span>
                    </div>
                    <?php endif; ?>
                    <button type="submit" id="confirmarBtn" class="btn <?= $tab === 'entrada' ? 'btn-success' : 'btn-danger' ?> w-100" disabled>
                        <?= $tab === 'entrada' ? 'Confirmar Entrada' : 'Confirmar Saída' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<div class="modal fade" id="buscarNomeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Buscar Medicamento ou Insumo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <input type="text" id="buscarNomeInput" class="form-control mb-3" autocomplete="off" placeholder="Digite o nome do medicamento ou insumo...">
                <div id="buscarNomeResultados"></div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var tab = '<?= $tab ?>';
    var codigoInput = document.getElementById('codigoInput');
    var buscarBtn = document.getElementById('buscarBtn');
    var scanResult = document.getElementById('scanResult');
    var campos = document.getElementById('camposMovimentacao');
    var quantidadeInput = document.getElementById('quantidadeInput');
    var quantidadeHint = document.getElementById('quantidadeHint');
    var loteSelectWrap = document.getElementById('loteSelectWrap');
    var loteSelect = document.getElementById('loteSelect');
    var loteSelectHint = document.getElementById('loteSelectHint');
    var loteInput = document.getElementById('loteInput');
    var validadeInput = document.getElementById('validadeInput');
    var quantidadeMinimaWrap = document.getElementById('quantidadeMinimaWrap');
    var quantidadeMinimaInput = document.getElementById('quantidadeMinimaInput');
    var valorUnitarioInput = document.getElementById('valorUnitarioInput');
    var observacaoInput = document.getElementById('observacaoInput');
    var inserirBtn = document.getElementById('inserirBtn');
    var itensLista = document.getElementById('itensLista');
    var itensCount = document.getElementById('itensCount');
    var confirmarBtn = document.getElementById('confirmarBtn');
    var resumoFinanceiroWrap = document.getElementById('resumoFinanceiroWrap');
    var resumoFinanceiroTotal = document.getElementById('resumoFinanceiroTotal');
    var itensJsonField = document.getElementById('itensJsonField');
    var movForm = document.getElementById('movForm');

    function statusBadgeClass(status) {
        return { vencido: 'bg-danger', urgente: 'bg-warning text-dark', alerta: 'bg-info text-dark', ok: 'bg-success' }[status] || 'bg-secondary';
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = (s === null || s === undefined) ? '' : String(s);
        return d.innerHTML;
    }

    function formatarDataBr(iso) {
        var partes = iso.split('-');
        return partes.length === 3 ? (partes[2] + '/' + partes[1] + '/' + partes[0]) : iso;
    }

    function formatarMoeda(valor) {
        return 'R$ ' + (Number(valor) || 0).toFixed(2).replace('.', ',');
    }

    function montarListaLotes(lotes) {
        if (!lotes.length) {
            return '<div class="text-muted small mt-2">Nenhum lote com saldo em estoque.</div>';
        }
        var linhas = lotes.map(function (l) {
            return '<tr><td class="mono">' + esc(l.lote) + '</td><td class="mono">' + esc(l.validade_br) + '</td>' +
                '<td class="text-center">' + esc(l.quantidade) + '</td>' +
                '<td class="text-end mono">' + formatarMoeda(l.valor_unitario) + '</td>' +
                '<td class="text-center"><span class="badge ' + statusBadgeClass(l.status) + '">' + esc(l.status_label) + '</span></td></tr>';
        }).join('');
        return '<div class="table-responsive mt-2"><table class="table table-sm mb-0">' +
            '<thead><tr><th>Lote</th><th>Validade</th><th class="text-center">Qtd.</th><th class="text-end">Valor Unit.</th><th class="text-center">Status</th></tr></thead>' +
            '<tbody>' + linhas + '</tbody></table></div>';
    }

    var itemAtual = null; // { tipo: 'medicamento'|'insumo', dados: {...} }

    function atualizarHintLote() {
        if (!loteSelect || !loteSelectHint) return;
        var opt = loteSelect.options[loteSelect.selectedIndex];
        if (!opt || !opt.value) {
            loteSelectHint.textContent = '';
            quantidadeInput.removeAttribute('max');
            return;
        }
        var qtd = opt.getAttribute('data-quantidade');
        var valor = opt.getAttribute('data-valor');
        quantidadeInput.max = qtd;
        loteSelectHint.textContent = qtd + ' unidade(s) disponível(is) neste lote · Valor unitário: ' + formatarMoeda(valor);
    }

    // Ajusta os campos visíveis conforme o tipo do item encontrado na busca. Na Entrada, Lote e
    // Validade aparecem tanto para medicamento quanto para insumo (loteTextWrap/validadeWrap só
    // existem no DOM quando a aba é Entrada). Na Saída, a seleção de lote é só de medicamento —
    // insumo não é rastreado por lote, então dá saída direto na quantidade.
    function ajustarCamposPorTipo(tipo) {
        var ehMedicamento = tipo === 'medicamento';
        if (loteSelectWrap) loteSelectWrap.style.display = ehMedicamento ? '' : 'none';
        // Quantidade mínima só se aplica a medicamento na Entrada — insumo já tem seu próprio
        // estoque mínimo cadastrado na tela Insumos.
        if (quantidadeMinimaWrap) quantidadeMinimaWrap.style.display = ehMedicamento ? '' : 'none';
        quantidadeHint.textContent = '';
        quantidadeInput.removeAttribute('max');
    }

    // Processa a resposta de ajax_buscar_item.php (achado por código de barras OU escolhido na
    // busca por nome) — mesma lógica pros dois jeitos de encontrar o item.
    function processarResultado(data) {
        if (!data.found) {
            scanResult.innerHTML = '<div class="alert alert-warning mt-3 mb-0">' + esc(data.error) + '</div>';
            return;
        }

        ajustarCamposPorTipo(data.tipo);

                if (data.tipo === 'medicamento') {
                    var m = data.medicamento;
                    itemAtual = { tipo: 'medicamento', dados: m };

                    // Traz a quantidade mínima já cadastrada pra esse medicamento (se houver);
                    // em branco quando ainda não foi informada, pro operador cadastrar agora.
                    if (quantidadeMinimaInput) {
                        quantidadeMinimaInput.value = (m.estoque_minimo === null || m.estoque_minimo === undefined) ? '' : m.estoque_minimo;
                    }

                    var statusHtml = m.status
                        ? '<span class="badge ' + statusBadgeClass(m.status) + '">' + esc(m.status_label) + '</span>'
                        : '<span class="badge bg-secondary">Sem estoque</span>';

                    scanResult.innerHTML =
                        '<div class="scan-summary">' +
                        '<div style="width:100%;">' +
                            '<span class="badge bg-info text-dark mb-2">Medicamento</span>' +
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

                    if (tab === 'saida') {
                        if (!m.lotes.length) {
                            campos.style.display = 'none';
                            return;
                        }
                        loteSelect.innerHTML = m.lotes.map(function (l) {
                            return '<option value="' + l.id + '" data-quantidade="' + l.quantidade + '" data-lote="' + esc(l.lote) + '" data-validade-br="' + esc(l.validade_br) + '" data-valor="' + l.valor_unitario + '">' +
                                l.lote + ' · vence em ' + l.validade_br + ' · ' + l.quantidade + ' un.' +
                                '</option>';
                        }).join('');
                        loteSelect.selectedIndex = 0;
                        atualizarHintLote();
                    }
                } else {
                    var i = data.insumo;
                    itemAtual = { tipo: 'insumo', dados: i };

                    var statusHtmlIns = i.estoque_baixo
                        ? '<span class="badge bg-warning text-dark">Estoque baixo</span>'
                        : '<span class="badge bg-success">Estoque ok</span>';
                    if (i.status) {
                        statusHtmlIns += ' <span class="badge ' + statusBadgeClass(i.status) + '">' + esc(i.status_label) + '</span>';
                    }

                    scanResult.innerHTML =
                        '<div class="scan-summary">' +
                        '<div style="width:100%;">' +
                            '<span class="badge bg-secondary mb-2">Insumo</span>' +
                            '<div class="scan-summary-title">' + esc(i.nome_comercial) + '</div>' +
                            '<div class="scan-summary-sub">' + esc(i.marca || '—') + (i.categoria ? ' · ' + esc(i.categoria) : '') + '</div>' +
                            '<div class="scan-summary-grid">' +
                                '<div><div class="entity-field-label">Estoque atual</div><div class="entity-field-value">' + esc(i.quantidade) + ' ' + esc(i.unidade_medida) + '</div></div>' +
                                '<div><div class="entity-field-label">Estoque mínimo</div><div class="entity-field-value">' + esc(i.estoque_minimo) + ' ' + esc(i.unidade_medida) + '</div></div>' +
                                (i.lote ? '<div><div class="entity-field-label">Lote</div><div class="entity-field-value">' + esc(i.lote) + '</div></div>' : '') +
                                (i.validade_br ? '<div><div class="entity-field-label">Vencimento</div><div class="entity-field-value">' + esc(i.validade_br) + '</div></div>' : '') +
                                (tab === 'saida' ? '<div><div class="entity-field-label">Valor unitário</div><div class="entity-field-value">' + formatarMoeda(i.valor_unitario) + '</div></div>' : '') +
                            '</div>' +
                            '<div class="mt-2">' + statusHtmlIns + '</div>' +
                        '</div></div>';

                    if (tab === 'saida') {
                        quantidadeInput.max = i.quantidade;
                        quantidadeHint.textContent = i.quantidade + ' ' + i.unidade_medida + '(s) disponível(is).';
                    }
                }

        campos.style.display = 'block';
        quantidadeInput.focus();
    }

    function buscar() {
        var codigo = codigoInput.value.trim();
        if (!codigo) return;
        scanResult.innerHTML = '<div class="text-muted small mt-2">Buscando...</div>';
        campos.style.display = 'none';
        itemAtual = null;

        fetch('ajax_buscar_item.php?codigo=' + encodeURIComponent(codigo))
            .then(function (r) { return r.json(); })
            .then(processarResultado)
            .catch(function () {
                scanResult.innerHTML = '<div class="alert alert-danger mt-3 mb-0">Erro ao buscar. Tente novamente.</div>';
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

    // ---- Busca por nome (medicamento ou insumo), pra quando não dá pra ler o código de barras ----
    (function () {
        var buscarNomeBtn = document.getElementById('buscarNomeBtn');
        var buscarNomeInput = document.getElementById('buscarNomeInput');
        var buscarNomeResultados = document.getElementById('buscarNomeResultados');
        var buscarNomeModalEl = document.getElementById('buscarNomeModal');
        var buscarNomeModal = null; // instanciado só no primeiro uso: nesse ponto do carregamento
        // da página o bootstrap.bundle.min.js (carregado no layout, depois do conteúdo) ainda não
        // rodou, então "new bootstrap.Modal(...)" aqui na abertura do script quebraria.
        var buscarNomeTimer = null;

        buscarNomeBtn.addEventListener('click', function () {
            if (!buscarNomeModal) { buscarNomeModal = new bootstrap.Modal(buscarNomeModalEl); }
            buscarNomeResultados.innerHTML = '';
            buscarNomeInput.value = '';
            buscarNomeModal.show();
            setTimeout(function () { buscarNomeInput.focus(); }, 300);
        });

        buscarNomeInput.addEventListener('input', function () {
            clearTimeout(buscarNomeTimer);
            var termo = buscarNomeInput.value.trim();
            if (termo.length < 2) {
                buscarNomeResultados.innerHTML = '';
                return;
            }
            buscarNomeTimer = setTimeout(function () {
                fetch('ajax_buscar_nome.php?busca=' + encodeURIComponent(termo))
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.itens || !data.itens.length) {
                            buscarNomeResultados.innerHTML = '<div class="text-muted small mt-2">Nenhum resultado encontrado.</div>';
                            return;
                        }
                        buscarNomeResultados.innerHTML = '<div class="list-group">' +
                            data.itens.map(function (it, idx) {
                                return '<button type="button" class="list-group-item list-group-item-action py-2 btn-item-opcao" data-idx="' + idx + '">' +
                                    '<span class="badge ' + (it.tipo === 'medicamento' ? 'bg-info text-dark' : 'bg-secondary') + ' mb-1">' + (it.tipo === 'medicamento' ? 'Medicamento' : 'Insumo') + '</span>' +
                                    '<div class="fw-bold" style="font-size:13px;">' + esc(it.titulo) + '</div>' +
                                    (it.subtitulo ? '<div class="small text-muted">' + esc(it.subtitulo) + '</div>' : '') +
                                    '</button>';
                            }).join('') + '</div>';
                        buscarNomeResultados.querySelectorAll('.btn-item-opcao').forEach(function (btn) {
                            btn.addEventListener('click', function () {
                                var escolhido = data.itens[parseInt(btn.getAttribute('data-idx'), 10)];
                                buscarNomeModal.hide();
                                codigoInput.value = '';
                                scanResult.innerHTML = '<div class="text-muted small mt-2">Carregando...</div>';
                                campos.style.display = 'none';
                                itemAtual = null;
                                fetch('ajax_buscar_item.php?id=' + escolhido.id + '&tipo=' + escolhido.tipo)
                                    .then(function (r) { return r.json(); })
                                    .then(processarResultado)
                                    .catch(function () {
                                        scanResult.innerHTML = '<div class="alert alert-danger mt-3 mb-0">Erro ao buscar. Tente novamente.</div>';
                                    });
                            });
                        });
                    })
                    .catch(function () {
                        buscarNomeResultados.innerHTML = '<div class="alert alert-danger small mt-2 mb-0">Erro ao buscar.</div>';
                    });
            }, 300);
        });
    })();

    // ---- Saída: busca e seleção do paciente a quem os itens se destinam (uma vez por
    // confirmação, não item a item) ----
    if (tab === 'saida') {
        var pacienteIdField = document.getElementById('pacienteIdField');
        var pacienteBuscaInput = document.getElementById('pacienteBuscaInput');
        var pacienteResultados = document.getElementById('pacienteResultados');
        var pacienteBuscaWrap = document.getElementById('pacienteBuscaWrap');
        var pacienteSelecionadoWrap = document.getElementById('pacienteSelecionadoWrap');
        var pacienteSelecionadoNome = document.getElementById('pacienteSelecionadoNome');
        var pacienteSelecionadoCpf = document.getElementById('pacienteSelecionadoCpf');
        var pacienteTrocarBtn = document.getElementById('pacienteTrocarBtn');
        var pacienteBuscaTimer = null;

        function selecionarPaciente(p) {
            pacienteIdField.value = p.id;
            pacienteSelecionadoNome.textContent = p.nome_completo;
            pacienteSelecionadoCpf.textContent = p.cpf;
            pacienteSelecionadoWrap.style.display = '';
            pacienteBuscaWrap.style.display = 'none';
            pacienteResultados.innerHTML = '';
            pacienteBuscaInput.value = '';
        }

        pacienteBuscaInput.addEventListener('input', function () {
            clearTimeout(pacienteBuscaTimer);
            var termo = pacienteBuscaInput.value.trim();
            if (termo.length < 2) {
                pacienteResultados.innerHTML = '';
                return;
            }
            pacienteBuscaTimer = setTimeout(function () {
                fetch('ajax_buscar_paciente.php?busca=' + encodeURIComponent(termo))
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.pacientes || !data.pacientes.length) {
                            pacienteResultados.innerHTML = '<div class="text-muted small mt-2">Nenhum paciente encontrado.</div>';
                            return;
                        }
                        pacienteResultados.innerHTML = '<div class="list-group mt-2">' +
                            data.pacientes.map(function (p, idx) {
                                return '<button type="button" class="list-group-item list-group-item-action py-2 btn-paciente-opcao" data-idx="' + idx + '">' +
                                    '<div class="fw-bold" style="font-size:13px;">' + esc(p.nome_completo) + '</div>' +
                                    '<div class="small text-muted mono">' + esc(p.cpf) + '</div>' +
                                    '</button>';
                            }).join('') + '</div>';
                        pacienteResultados.querySelectorAll('.btn-paciente-opcao').forEach(function (btn) {
                            btn.addEventListener('click', function () {
                                selecionarPaciente(data.pacientes[parseInt(btn.getAttribute('data-idx'), 10)]);
                            });
                        });
                    })
                    .catch(function () {
                        pacienteResultados.innerHTML = '<div class="alert alert-danger small mt-2 mb-0">Erro ao buscar paciente.</div>';
                    });
            }, 300);
        });

        pacienteTrocarBtn.addEventListener('click', function () {
            pacienteIdField.value = '';
            pacienteSelecionadoWrap.style.display = 'none';
            pacienteBuscaWrap.style.display = '';
            pacienteBuscaInput.focus();
        });
    }

    // ---- Fila de itens conferida antes de gravar qualquer coisa no banco ----
    var itens = [];

    function renderItens() {
        itensCount.textContent = itens.length;
        confirmarBtn.disabled = itens.length === 0;

        if (itens.length === 0) {
            itensLista.innerHTML = '<p class="text-muted small mb-0">Nenhum item adicionado ainda. Busque um medicamento ou insumo ao lado e clique em "Inserir".</p>';
            if (resumoFinanceiroWrap) resumoFinanceiroWrap.style.setProperty('display', 'none', 'important');
            return;
        }

        itensLista.innerHTML = itens.map(function (item, idx) {
            var detalheLote = item.lote
                ? 'Lote ' + esc(item.lote) + ' · vence em ' + esc(item.validadeBr) + ' · '
                : '';
            var subtotal = (item.valor_unitario || 0) * item.quantidade;
            return '<div class="entity-card" style="padding:10px 12px;margin-bottom:8px;">' +
                '<div class="d-flex justify-content-between align-items-start gap-2">' +
                    '<div class="min-w-0">' +
                        '<div class="entity-title" style="font-size:13px;">' + esc(item.produto) +
                            ' <span class="badge ' + (item.tipo_item === 'medicamento' ? 'bg-info text-dark' : 'bg-secondary') + '">' + (item.tipo_item === 'medicamento' ? 'Medicamento' : 'Insumo') + '</span>' +
                        '</div>' +
                        (item.laboratorio ? '<div class="entity-sub">' + esc(item.laboratorio) + '</div>' : '') +
                        (item.apresentacao ? '<div class="entity-sub">' + esc(item.apresentacao) + '</div>' : '') +
                        '<div class="entity-sub">' + detalheLote + esc(item.quantidade) + ' un.' +
                            (item.observacao ? ' · ' + esc(item.observacao) : '') +
                        '</div>' +
                        '<div class="entity-sub mono">' + formatarMoeda(item.valor_unitario) + ' / un. · Subtotal: ' + formatarMoeda(subtotal) + '</div>' +
                    '</div>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger btn-remover-item" data-idx="' + idx + '" title="Remover"><i class="bi bi-x-lg"></i></button>' +
                '</div>' +
            '</div>';
        }).join('');

        if (resumoFinanceiroWrap) {
            var totalGeral = itens.reduce(function (soma, item) { return soma + (item.valor_unitario || 0) * item.quantidade; }, 0);
            resumoFinanceiroTotal.textContent = formatarMoeda(totalGeral);
            resumoFinanceiroWrap.style.setProperty('display', 'flex', 'important');
        }

        itensLista.querySelectorAll('.btn-remover-item').forEach(function (btn) {
            btn.addEventListener('click', function () {
                itens.splice(parseInt(btn.getAttribute('data-idx'), 10), 1);
                renderItens();
            });
        });
    }

    inserirBtn.addEventListener('click', function () {
        if (!itemAtual) return;
        var quantidade = parseInt(quantidadeInput.value, 10);
        var observacao = observacaoInput.value.trim();

        if (!quantidade || quantidade <= 0) { alert('Informe uma quantidade válida.'); return; }

        if (tab === 'entrada' && (valorUnitarioInput.value.trim() === '' || parseFloat(valorUnitarioInput.value) < 0)) {
            alert('Informe o valor unitário.');
            return;
        }

        var novoItem;
        if (itemAtual.tipo === 'medicamento') {
            var m = itemAtual.dados;
            novoItem = {
                tipo_item: 'medicamento',
                item_id: m.id,
                codigo_barras: m.codigo_barras,
                produto: m.produto,
                apresentacao: m.apresentacao,
                laboratorio: m.laboratorio,
                quantidade: quantidade,
                observacao: observacao
            };
            if (tab === 'entrada') {
                var lote = loteInput.value.trim();
                var validade = validadeInput.value;
                if (!lote) { alert('Informe o lote.'); return; }
                if (!validade) { alert('Informe a validade.'); return; }
                novoItem.lote = lote;
                novoItem.validade = validade;
                novoItem.validadeBr = formatarDataBr(validade);
                if (quantidadeMinimaInput && quantidadeMinimaInput.value.trim() !== '') {
                    novoItem.estoque_minimo = parseInt(quantidadeMinimaInput.value, 10);
                }
            } else {
                var opt = loteSelect.options[loteSelect.selectedIndex];
                if (!opt || !opt.value) { alert('Selecione o lote.'); return; }
                var disponivel = parseInt(opt.getAttribute('data-quantidade'), 10);
                if (quantidade > disponivel) { alert('Quantidade maior que o saldo disponível neste lote (' + disponivel + ' un.).'); return; }
                novoItem.lote_id = parseInt(opt.value, 10);
                novoItem.lote = opt.getAttribute('data-lote');
                novoItem.validadeBr = opt.getAttribute('data-validade-br');
            }
        } else {
            var i = itemAtual.dados;
            if (tab === 'saida' && quantidade > i.quantidade) {
                alert('Quantidade maior que o estoque disponível (' + i.quantidade + ' ' + i.unidade_medida + ').');
                return;
            }
            novoItem = {
                tipo_item: 'insumo',
                item_id: i.id,
                codigo_barras: i.codigo_barras,
                produto: i.nome_comercial,
                laboratorio: i.marca,
                apresentacao: i.categoria,
                quantidade: quantidade,
                observacao: observacao
            };
            if (tab === 'entrada') {
                var loteIns = loteInput.value.trim();
                var validadeIns = validadeInput.value;
                if (!loteIns) { alert('Informe o lote.'); return; }
                if (!validadeIns) { alert('Informe a validade.'); return; }
                novoItem.lote = loteIns;
                novoItem.validade = validadeIns;
                novoItem.validadeBr = formatarDataBr(validadeIns);
            }
        }

        if (tab === 'entrada') {
            novoItem.valor_unitario = parseFloat(valorUnitarioInput.value);
        } else if (itemAtual.tipo === 'medicamento') {
            novoItem.valor_unitario = parseFloat(loteSelect.options[loteSelect.selectedIndex].getAttribute('data-valor')) || 0;
        } else {
            novoItem.valor_unitario = itemAtual.dados.valor_unitario || 0;
        }

        itens.push(novoItem);
        renderItens();

        itemAtual = null;
        codigoInput.value = '';
        scanResult.innerHTML = '';
        campos.style.display = 'none';
        if (valorUnitarioInput) valorUnitarioInput.value = '';
        codigoInput.focus();
    });

    movForm.addEventListener('submit', function (e) {
        if (itens.length === 0) {
            e.preventDefault();
            alert('Adicione ao menos um item antes de confirmar.');
            return;
        }
        if (tab === 'saida' && !pacienteIdField.value) {
            e.preventDefault();
            alert('Selecione o paciente a quem os itens desta saída se destinam.');
            return;
        }
        var mensagem = tab === 'entrada' ? 'Você confirma os itens a serem inseridos?' : 'Você confirma os itens a serem retirados?';
        if (!confirm(mensagem)) {
            e.preventDefault();
            return;
        }
        itensJsonField.value = JSON.stringify(itens);
    });
})();
</script>
