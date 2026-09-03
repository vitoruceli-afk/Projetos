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

        // Entrada: fornecedor de quem os itens vieram, escolhido por busca no cadastro de
        // Fornecedores (ou já vinculado automaticamente pela importação de NFe) — obrigatório,
        // mesmo padrão do paciente na Saída.
        $fornecedorId = null;
        if ($ehEntrada) {
            $fornecedorIdPost = (int)($_POST['fornecedor_id'] ?? 0);
            if ($fornecedorIdPost > 0) {
                $stmt = $db->prepare("SELECT id FROM fornecedores WHERE id = :id");
                $stmt->bindValue(':id', $fornecedorIdPost, PDO::PARAM_INT);
                $stmt->execute();
                if ($stmt->fetchColumn()) {
                    $fornecedorId = $fornecedorIdPost;
                }
            }
        }

        if (!$ehEntrada && !$pacienteId) {
            $formError = 'Selecione o paciente a quem os itens desta saída se destinam.';
        } elseif ($ehEntrada && !$fornecedorId) {
            $formError = 'Selecione o fornecedor de quem os itens desta entrada vieram.';
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
            $db->prepare("INSERT INTO movimentacao_confirmacoes (tipo, usuario, paciente_id, fornecedor_id, total_itens, total_quantidade) VALUES (:t, :u, :p, :f, :ti, :tq)")
               ->execute([':t' => $tipoMov, ':u' => $_SESSION['user_logged_in'], ':p' => $pacienteId, ':f' => $fornecedorId, ':ti' => count($itens), ':tq' => $totalQuantidade]);
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
                        // Validade é só mês/ano (input type="month", manda "AAAA-MM") — grava
                        // sempre no último dia daquele mês, único jeito de guardar "mês/ano" numa
                        // coluna DATE sem perder o cálculo por dia de vencido/urgente/alerta.
                        $validade = mesAnoParaUltimoDia($item['validade'] ?? '');
                        $valorUnitario = is_numeric($item['valor_unitario'] ?? null) ? (float)$item['valor_unitario'] : -1;
                        // Valor de venda: opcional (fica 0 se o operador não informar) — é o valor
                        // de compra que é obrigatório na Entrada.
                        $valorVenda = is_numeric($item['valor_venda'] ?? null) ? max(0, (float)$item['valor_venda']) : 0;
                        if ($quantidade <= 0 || $lote === '' || !$validade) {
                            $erroItem = "Item {$numero} (" . htmlspecialchars($medicamento['produto']) . '): quantidade, lote e validade (mês/ano) são obrigatórios.';
                            break;
                        }
                        if ($valorUnitario < 0) {
                            $erroItem = "Item {$numero} (" . htmlspecialchars($medicamento['produto']) . '): informe o valor de compra.';
                            break;
                        }

                        $stmt = $db->prepare("SELECT * FROM insumo_lotes WHERE medicamento_id = :m AND lote = :l");
                        $stmt->execute([':m' => $medicamento['id'], ':l' => $lote]);
                        $loteRow = $stmt->fetch();

                        if ($loteRow) {
                            $db->prepare("UPDATE insumo_lotes SET quantidade = quantidade + :q, valor_unitario = :vu, valor_venda = :vv WHERE id = :id")
                               ->execute([':q' => $quantidade, ':vu' => $valorUnitario, ':vv' => $valorVenda, ':id' => $loteRow['id']]);
                            $loteId = $loteRow['id'];
                        } else {
                            $db->prepare("INSERT INTO insumo_lotes (medicamento_id, lote, validade, quantidade, valor_unitario, valor_venda) VALUES (:m, :l, :v, :q, :vu, :vv)")
                               ->execute([':m' => $medicamento['id'], ':l' => $lote, ':v' => $validade, ':q' => $quantidade, ':vu' => $valorUnitario, ':vv' => $valorVenda]);
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
                        // que foi pago/o preço de venda daquela entrada), pra não deixar o operador
                        // alterar na retirada.
                        $valorUnitario = (float)$loteRow['valor_unitario'];
                        $valorVenda = (float)$loteRow['valor_venda'];
                    }

                    $db->prepare("INSERT INTO movimentacoes (medicamento_id, lote_id, confirmacao_id, tipo, quantidade, valor_unitario, valor_venda, usuario, observacao) VALUES (:m, :lo, :c, :t, :q, :vu, :vv, :u, :o)")
                       ->execute([':m' => $medicamento['id'], ':lo' => $loteId, ':c' => $confirmacaoId, ':t' => $tipoMov, ':q' => $quantidade, ':vu' => $valorUnitario, ':vv' => $valorVenda, ':u' => $_SESSION['user_logged_in'], ':o' => $observacao]);
                } else {
                    // Insumo funciona exatamente como medicamento: lotes em insumo_lotes
                    // (insumo_id em vez de medicamento_id), quantidade/lote/validade/valor
                    // unitário sempre lançados aqui na Entrada/Saída, nunca no cadastro.
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
                    if (!$insumo && $ehEntrada && trim($item['produto'] ?? '') !== '') {
                        // Item da nota sem correspondência no catálogo — em vez de travar a
                        // confirmação, cadastra como insumo novo agora, com o nome vindo da própria
                        // NFe (ver aviso "Novo — será cadastrado..." na tela de Entrada). Só entra
                        // aqui vindo da importação de NFe: o scan manual sempre resolve item_id
                        // antes de deixar clicar em "Inserir", então nunca chega com produto setado
                        // e insumo não encontrado.
                        $insumo = buscarOuCriarInsumo($db, $item['produto'], $codigo);
                    }
                    if (!$insumo) {
                        $erroItem = "Item {$numero}: insumo com código \"" . htmlspecialchars($codigo) . '" não foi encontrado.';
                        break;
                    }

                    if ($ehEntrada) {
                        $lote = trim($item['lote'] ?? '');
                        $validade = mesAnoParaUltimoDia($item['validade'] ?? '');
                        $valorUnitario = is_numeric($item['valor_unitario'] ?? null) ? (float)$item['valor_unitario'] : -1;
                        $valorVenda = is_numeric($item['valor_venda'] ?? null) ? max(0, (float)$item['valor_venda']) : 0;
                        if ($quantidade <= 0 || $lote === '' || !$validade) {
                            $erroItem = "Item {$numero} (" . htmlspecialchars($insumo['nome_comercial']) . '): quantidade, lote e validade (mês/ano) são obrigatórios.';
                            break;
                        }
                        if ($valorUnitario < 0) {
                            $erroItem = "Item {$numero} (" . htmlspecialchars($insumo['nome_comercial']) . '): informe o valor de compra.';
                            break;
                        }

                        $stmt = $db->prepare("SELECT * FROM insumo_lotes WHERE insumo_id = :i AND lote = :l");
                        $stmt->execute([':i' => $insumo['id'], ':l' => $lote]);
                        $loteRow = $stmt->fetch();

                        if ($loteRow) {
                            $db->prepare("UPDATE insumo_lotes SET quantidade = quantidade + :q, valor_unitario = :vu, valor_venda = :vv WHERE id = :id")
                               ->execute([':q' => $quantidade, ':vu' => $valorUnitario, ':vv' => $valorVenda, ':id' => $loteRow['id']]);
                            $loteId = $loteRow['id'];
                        } else {
                            $db->prepare("INSERT INTO insumo_lotes (insumo_id, lote, validade, quantidade, valor_unitario, valor_venda) VALUES (:i, :l, :v, :q, :vu, :vv)")
                               ->execute([':i' => $insumo['id'], ':l' => $lote, ':v' => $validade, ':q' => $quantidade, ':vu' => $valorUnitario, ':vv' => $valorVenda]);
                            $loteId = (int)$db->lastInsertId();
                        }

                        // Estoque mínimo do insumo: opcional, informada na Entrada, igual medicamento.
                        if (array_key_exists('estoque_minimo', $item) && trim((string)$item['estoque_minimo']) !== '') {
                            $db->prepare("UPDATE insumos SET estoque_minimo = :em WHERE id = :id")
                               ->execute([':em' => (int)$item['estoque_minimo'], ':id' => $insumo['id']]);
                        }
                    } else {
                        // Um insumo pode ter vários lotes com validades diferentes em estoque ao
                        // mesmo tempo — a baixa é sempre no lote específico escolhido pelo operador.
                        $loteId = (int)($item['lote_id'] ?? 0);
                        if ($quantidade <= 0 || $loteId <= 0) {
                            $erroItem = "Item {$numero} (" . htmlspecialchars($insumo['nome_comercial']) . '): selecione o lote e informe uma quantidade válida.';
                            break;
                        }

                        // FOR UPDATE: trava a linha do lote até o fim da transação, pra duas saídas
                        // concorrentes do mesmo lote não aprovarem a mesma checagem de saldo antes de
                        // qualquer uma delas descontar (o que deixaria a quantidade negativa).
                        $stmt = $db->prepare("SELECT * FROM insumo_lotes WHERE id = :id AND insumo_id = :i FOR UPDATE");
                        $stmt->execute([':id' => $loteId, ':i' => $insumo['id']]);
                        $loteRow = $stmt->fetch();

                        if (!$loteRow) {
                            $erroItem = "Item {$numero} (" . htmlspecialchars($insumo['nome_comercial']) . '): lote inválido ou não pertence a este insumo.';
                            break;
                        }
                        if ($quantidade > (int)$loteRow['quantidade']) {
                            $erroItem = "Item {$numero} (" . htmlspecialchars($insumo['nome_comercial']) . '): estoque insuficiente no lote "' . htmlspecialchars($loteRow['lote']) . '" (há apenas ' . (int)$loteRow['quantidade'] . ' ' . htmlspecialchars($insumo['unidade_medida']) . '(s)).';
                            break;
                        }

                        $db->prepare("UPDATE insumo_lotes SET quantidade = quantidade - :q WHERE id = :id")
                           ->execute([':q' => $quantidade, ':id' => $loteRow['id']]);
                        $loteId = $loteRow['id'];
                        // Valor não vem do formulário na Saída — sempre o valor gravado no lote (o
                        // que foi pago/o preço de venda daquela entrada), pra não deixar o operador
                        // alterar na retirada.
                        $valorUnitario = (float)$loteRow['valor_unitario'];
                        $valorVenda = (float)$loteRow['valor_venda'];
                    }

                    $db->prepare("INSERT INTO movimentacoes (insumo_id, lote_id, confirmacao_id, tipo, quantidade, valor_unitario, valor_venda, usuario, observacao) VALUES (:i, :lo, :c, :t, :q, :vu, :vv, :u, :o)")
                       ->execute([':i' => $insumo['id'], ':lo' => $loteId, ':c' => $confirmacaoId, ':t' => $tipoMov, ':q' => $quantidade, ':vu' => $valorUnitario, ':vv' => $valorVenda, ':u' => $_SESSION['user_logged_in'], ':o' => $observacao]);
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
                if ($fornecedorId) {
                    $nomeFornecedor = $db->prepare("SELECT razao_social FROM fornecedores WHERE id = :id");
                    $nomeFornecedor->execute([':id' => $fornecedorId]);
                    $detalhesLog .= ', fornecedor: ' . $nomeFornecedor->fetchColumn();
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

    <?php if ($tab === 'entrada'): ?>
    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="scan-card h-100">
                <label class="form-label">Fornecedor</label>
                <div class="form-text mb-2">Busque por razão social, nome fantasia ou CNPJ o fornecedor de quem os itens desta entrada vieram. Ao importar uma NFe, o fornecedor da nota é vinculado automaticamente (e cadastrado na hora, se ainda não existir).</div>
                <input type="hidden" name="fornecedor_id" id="fornecedorIdField" value="">

                <div id="fornecedorSelecionadoWrap" style="display:none;" class="d-flex align-items-center justify-content-between border rounded p-2 mb-2">
                    <div>
                        <div class="fw-bold" id="fornecedorSelecionadoNome"></div>
                        <div class="small text-muted mono" id="fornecedorSelecionadoCnpj"></div>
                    </div>
                    <button type="button" id="fornecedorTrocarBtn" class="btn btn-sm btn-outline-secondary">Trocar</button>
                </div>

                <div id="fornecedorBuscaWrap">
                    <div class="scan-input-row">
                        <input type="text" id="fornecedorBuscaInput" class="form-control" autocomplete="off" placeholder="Razão social, nome fantasia ou CNPJ do fornecedor...">
                    </div>
                    <div id="fornecedorResultados"></div>
                    <a href="index.php?page=fornecedores" target="_blank" class="small">Fornecedor novo? Cadastre aqui</a>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="scan-card h-100 d-flex flex-column">
                <label class="form-label">Nota Fiscal (XML)</label>
                <div class="form-text mb-2">Leia o código de barras da DANFE e envie o arquivo XML da nota — os itens são lidos automaticamente e ficam prontos pra conferir e adicionar à lista de Entrada.</div>
                <button type="button" id="nfeAbrirModalBtn" class="btn btn-outline-primary mt-auto"><i class="bi bi-upload"></i> Importar Nota Fiscal</button>
                <div id="nfeResumoBadge" class="form-text"></div>
            </div>
        </div>
    </div>

    <!-- Área de importação em largura total (não é modal — fica dentro do conteúdo normal da
    página, como as outras telas, só expandida pra caber a tabela de conferência dos itens). -->
    <div class="card mb-3" id="nfeAreaWrap" style="display:none;">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Importar Nota Fiscal (XML)</span>
            <button type="button" class="btn-close" id="nfeFecharBtn" aria-label="Fechar"></button>
        </div>
        <div class="card-body">
            <div class="scan-input-row mb-2" style="max-width:420px;">
                <input type="text" id="nfeChaveInput" class="form-control mono" autocomplete="off" placeholder="Chave de acesso da NFe (44 dígitos, opcional)" maxlength="44" inputmode="numeric">
            </div>
            <div class="row g-2 align-items-end" style="max-width:560px;">
                <div class="col-sm-8">
                    <input type="file" id="nfeXmlInput" class="form-control" accept=".xml,text/xml">
                </div>
                <div class="col-sm-4">
                    <button type="button" id="nfeImportarBtn" class="btn btn-outline-primary w-100"><i class="bi bi-upload"></i> Importar Nota</button>
                </div>
            </div>
            <div id="nfeChaveAviso" class="form-text"></div>

            <div id="nfeResumo" class="mt-3"></div>
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
                            <label class="form-label">Validade (mês/ano)</label>
                            <input type="month" id="validadeInput" class="form-control">
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($tab === 'entrada'): ?>
                    <div class="row g-2 mt-0">
                        <div class="col-sm-4" id="valorUnitarioWrap">
                            <label class="form-label">Valor Compra (R$)</label>
                            <input type="number" id="valorUnitarioInput" class="form-control" min="0" step="0.01" placeholder="0,00">
                        </div>
                        <div class="col-sm-4" id="valorVendaWrap">
                            <label class="form-label">Valor Venda (R$)</label>
                            <input type="number" id="valorVendaInput" class="form-control" min="0" step="0.01" placeholder="0,00">
                            <div class="form-text">Opcional. Usado no resumo financeiro da Saída.</div>
                        </div>
                        <div class="col-sm-4" id="quantidadeMinimaWrap">
                            <label class="form-label">Quantidade mínima</label>
                            <input type="number" id="quantidadeMinimaInput" class="form-control" min="0" placeholder="Não cadastrada">
                            <div class="form-text">Nível de estoque abaixo do qual este item deve ser reposto. Fica salvo pra próxima entrada já vir preenchido.</div>
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

<div class="modal fade" id="vincularNfeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Vincular Item da Nota a um Medicamento ou Insumo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted" id="vincularNfeNomeOriginal"></p>
                <input type="text" id="vincularNfeInput" class="form-control mb-3" autocomplete="off" placeholder="Digite o nome do medicamento ou insumo...">
                <div id="vincularNfeResultados"></div>
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
    var valorVendaInput = document.getElementById('valorVendaInput');
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

    // Validade só trabalha com mês/ano — "AAAA-MM" (input type="month") ou "AAAA-MM-DD" (valor
    // que às vezes chega já truncado do backend) dão o mesmo resultado, já que só os dois
    // primeiros pedaços importam.
    function formatarDataBr(iso) {
        var partes = String(iso).split('-');
        return partes.length >= 2 ? (partes[1] + '/' + partes[0]) : iso;
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
                '<td class="text-end mono">' + formatarMoeda(l.valor_venda) + '</td>' +
                '<td class="text-center"><span class="badge ' + statusBadgeClass(l.status) + '">' + esc(l.status_label) + '</span></td></tr>';
        }).join('');
        return '<div class="table-responsive mt-2"><table class="table table-sm mb-0">' +
            '<thead><tr><th>Lote</th><th>Validade</th><th class="text-center">Qtd.</th><th class="text-end">Valor Compra</th><th class="text-end">Valor Venda</th><th class="text-center">Status</th></tr></thead>' +
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
        var valorVenda = opt.getAttribute('data-valor-venda');
        quantidadeInput.max = qtd;
        loteSelectHint.textContent = qtd + ' unidade(s) disponível(is) neste lote · Valor venda: ' + formatarMoeda(valorVenda);
    }

    // Medicamento e insumo funcionam exatamente do mesmo jeito na Entrada/Saída (ambos rastreados
    // por lote em insumo_lotes) — loteSelectWrap (Saída) e quantidadeMinimaWrap (Entrada) já ficam
    // visíveis pra qualquer um dos dois tipos; só reseta o hint/max daqui.
    function ajustarCamposPorTipo(tipo) {
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
                            return '<option value="' + l.id + '" data-quantidade="' + l.quantidade + '" data-lote="' + esc(l.lote) + '" data-validade-br="' + esc(l.validade_br) + '" data-valor="' + l.valor_unitario + '" data-valor-venda="' + l.valor_venda + '">' +
                                l.lote + ' · vence em ' + l.validade_br + ' · ' + l.quantidade + ' un.' +
                                '</option>';
                        }).join('');
                        loteSelect.selectedIndex = 0;
                        atualizarHintLote();
                    }
                } else {
                    var i = data.insumo;
                    itemAtual = { tipo: 'insumo', dados: i };

                    // Traz a quantidade mínima já cadastrada pra esse insumo (se houver); em branco
                    // quando ainda não foi informada, pro operador cadastrar agora.
                    if (quantidadeMinimaInput) {
                        quantidadeMinimaInput.value = (i.estoque_minimo === null || i.estoque_minimo === undefined) ? '' : i.estoque_minimo;
                    }

                    var statusHtmlIns = i.status
                        ? '<span class="badge ' + statusBadgeClass(i.status) + '">' + esc(i.status_label) + '</span>'
                        : '<span class="badge bg-secondary">Sem estoque</span>';

                    scanResult.innerHTML =
                        '<div class="scan-summary">' +
                        '<div style="width:100%;">' +
                            '<span class="badge bg-secondary mb-2">Insumo</span>' +
                            '<div class="scan-summary-title">' + esc(i.nome_comercial) + '</div>' +
                            '<div class="scan-summary-sub">' + esc(i.marca || '—') + (i.categoria ? ' · ' + esc(i.categoria) : '') + '</div>' +
                            '<div class="scan-summary-grid">' +
                                '<div><div class="entity-field-label">Estoque total</div><div class="entity-field-value">' + esc(i.estoque_total) + ' ' + esc(i.unidade_medida) + '</div></div>' +
                                (i.categoria ? '<div><div class="entity-field-label">Categoria</div><div class="entity-field-value">' + esc(i.categoria) + '</div></div>' : '') +
                            '</div>' +
                            '<div class="mt-2">' + statusHtmlIns + '</div>' +
                            montarListaLotes(i.lotes) +
                        '</div></div>';

                    if (tab === 'saida') {
                        if (!i.lotes.length) {
                            campos.style.display = 'none';
                            return;
                        }
                        loteSelect.innerHTML = i.lotes.map(function (l) {
                            return '<option value="' + l.id + '" data-quantidade="' + l.quantidade + '" data-lote="' + esc(l.lote) + '" data-validade-br="' + esc(l.validade_br) + '" data-valor="' + l.valor_unitario + '" data-valor-venda="' + l.valor_venda + '">' +
                                l.lote + ' · vence em ' + l.validade_br + ' · ' + l.quantidade + ' ' + i.unidade_medida +
                                '</option>';
                        }).join('');
                        loteSelect.selectedIndex = 0;
                        atualizarHintLote();
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

    // ---- Entrada: busca e seleção do fornecedor de quem os itens vieram (uma vez por
    // confirmação, não item a item) — mesmo padrão do paciente na Saída. selecionarFornecedor()
    // também é chamada pela importação de NFe logo abaixo, pra vincular automaticamente o
    // fornecedor da nota (cadastrando-o na hora, se ainda não existir). ----
    var selecionarFornecedor = function () {};
    if (tab === 'entrada') (function () {
        var fornecedorIdField = document.getElementById('fornecedorIdField');
        var fornecedorBuscaInput = document.getElementById('fornecedorBuscaInput');
        var fornecedorResultados = document.getElementById('fornecedorResultados');
        var fornecedorBuscaWrap = document.getElementById('fornecedorBuscaWrap');
        var fornecedorSelecionadoWrap = document.getElementById('fornecedorSelecionadoWrap');
        var fornecedorSelecionadoNome = document.getElementById('fornecedorSelecionadoNome');
        var fornecedorSelecionadoCnpj = document.getElementById('fornecedorSelecionadoCnpj');
        var fornecedorTrocarBtn = document.getElementById('fornecedorTrocarBtn');
        var fornecedorBuscaTimer = null;

        selecionarFornecedor = function (f) {
            fornecedorIdField.value = f.id;
            fornecedorSelecionadoNome.textContent = f.razao_social + (f.nome_fantasia ? ' (' + f.nome_fantasia + ')' : '');
            fornecedorSelecionadoCnpj.textContent = f.cnpj;
            fornecedorSelecionadoWrap.style.display = '';
            fornecedorBuscaWrap.style.display = 'none';
            fornecedorResultados.innerHTML = '';
            fornecedorBuscaInput.value = '';
        };

        fornecedorBuscaInput.addEventListener('input', function () {
            clearTimeout(fornecedorBuscaTimer);
            var termo = fornecedorBuscaInput.value.trim();
            if (termo.length < 2) {
                fornecedorResultados.innerHTML = '';
                return;
            }
            fornecedorBuscaTimer = setTimeout(function () {
                fetch('ajax_buscar_fornecedor.php?busca=' + encodeURIComponent(termo))
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.fornecedores || !data.fornecedores.length) {
                            fornecedorResultados.innerHTML = '<div class="text-muted small mt-2">Nenhum fornecedor encontrado.</div>';
                            return;
                        }
                        fornecedorResultados.innerHTML = '<div class="list-group mt-2">' +
                            data.fornecedores.map(function (f, idx) {
                                return '<button type="button" class="list-group-item list-group-item-action py-2 btn-fornecedor-opcao" data-idx="' + idx + '">' +
                                    '<div class="fw-bold" style="font-size:13px;">' + esc(f.razao_social) + (f.nome_fantasia ? ' <span class="text-muted fw-normal">(' + esc(f.nome_fantasia) + ')</span>' : '') + '</div>' +
                                    '<div class="small text-muted mono">' + esc(f.cnpj) + '</div>' +
                                    '</button>';
                            }).join('') + '</div>';
                        fornecedorResultados.querySelectorAll('.btn-fornecedor-opcao').forEach(function (btn) {
                            btn.addEventListener('click', function () {
                                selecionarFornecedor(data.fornecedores[parseInt(btn.getAttribute('data-idx'), 10)]);
                            });
                        });
                    })
                    .catch(function () {
                        fornecedorResultados.innerHTML = '<div class="alert alert-danger small mt-2 mb-0">Erro ao buscar fornecedor.</div>';
                    });
            }, 300);
        });

        fornecedorTrocarBtn.addEventListener('click', function () {
            fornecedorIdField.value = '';
            fornecedorSelecionadoWrap.style.display = 'none';
            fornecedorBuscaWrap.style.display = '';
            fornecedorBuscaInput.focus();
        });
    })();

    // ---- Entrada: importação de Nota Fiscal (XML) — lê os itens da nota, tenta casar cada um
    // com um medicamento (por EAN) ou insumo (por EAN) já cadastrado, e deixa o operador conferir
    // lote/validade/valor antes de jogar cada item na mesma fila usada pelo scan manual. ----
    if (tab === 'entrada') (function () {
        var nfeAbrirModalBtn = document.getElementById('nfeAbrirModalBtn');
        var nfeAreaWrap = document.getElementById('nfeAreaWrap');
        var nfeFecharBtn = document.getElementById('nfeFecharBtn');
        var nfeResumoBadge = document.getElementById('nfeResumoBadge');
        var nfeChaveInput = document.getElementById('nfeChaveInput');
        var nfeChaveAviso = document.getElementById('nfeChaveAviso');
        var nfeXmlInput = document.getElementById('nfeXmlInput');
        var nfeImportarBtn = document.getElementById('nfeImportarBtn');
        var nfeResumo = document.getElementById('nfeResumo');
        var vincularNfeModalEl = document.getElementById('vincularNfeModal');
        var vincularNfeModal = null; // só instanciado no primeiro uso, mesmo motivo do buscarNomeModal acima.
        var vincularNfeInput = document.getElementById('vincularNfeInput');
        var vincularNfeResultados = document.getElementById('vincularNfeResultados');
        var vincularNfeNomeOriginal = document.getElementById('vincularNfeNomeOriginal');
        var vincularNfeTimer = null;

        var nfeInfo = null;
        var nfeItens = [];
        var nfeVincularIndex = null;

        function round2(v) {
            return Math.round((Number(v) + Number.EPSILON) * 100) / 100;
        }

        // Não é modal: só mostra/esconde a área de importação dentro do próprio fluxo da página
        // (largura total do conteúdo, mas sem cobrir o rail/topbar da aplicação).
        nfeAbrirModalBtn.addEventListener('click', function () {
            nfeAreaWrap.style.display = '';
            nfeAreaWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        nfeFecharBtn.addEventListener('click', function () {
            nfeAreaWrap.style.display = 'none';
        });

        nfeChaveInput.addEventListener('input', function () {
            nfeChaveInput.value = nfeChaveInput.value.replace(/\D/g, '').slice(0, 44);
        });

        // Recalcula quantidade/valor de venda exibidos quando o item é marcado como fracionado —
        // a quantidade da nota (por caixa/embalagem) vira quantidade de unidades soltas, e o VALOR
        // DE VENDA por caixa é rateado entre elas (o valor de compra fica fixo, do jeito que veio
        // da nota — não é dividido: representa o custo da embalagem inteira, não da fração).
        function aplicarFracao(item) {
            if (item.fracionado && item.fracaoQtd > 0) {
                item.quantidade = Math.round(item.quantidadeBase * item.fracaoQtd);
                item.valor_venda = round2(item.valorVendaBase / item.fracaoQtd);
            } else {
                item.quantidade = item.quantidadeBase;
                item.valor_venda = item.valorVendaBase;
            }
        }

        function renderNfeResumo() {
            if (!nfeItens.length) { nfeResumo.innerHTML = ''; nfeResumoBadge.textContent = ''; return; }

            var pendentes = nfeItens.filter(function (it) { return !it.encontrado; }).length;
            var aAdicionar = nfeItens.filter(function (it) { return !it.adicionado; }).length;

            nfeResumoBadge.textContent = nfeItens.length + ' item(ns) na nota' +
                (aAdicionar ? ', ' + aAdicionar + ' pendente(s) de adicionar' : ', todos adicionados') +
                (pendentes ? ' (' + pendentes + ' novo(s), sem cadastro ainda)' : '');

            var cabecalho = '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">' +
                '<div class="small text-muted">' +
                    (nfeInfo && nfeInfo.numero ? 'NF nº ' + esc(nfeInfo.numero) + (nfeInfo.emitente ? ' · ' + esc(nfeInfo.emitente) : '') : 'Itens da nota') +
                    (pendentes ? ' · <span class="text-warning fw-bold">' + pendentes + ' item(ns) novo(s) — sem correspondência no catálogo</span>' : '') +
                '</div>' +
                (aAdicionar ? '<button type="button" class="btn btn-sm btn-outline-success" id="nfeAdicionarTodosBtn"><i class="bi bi-plus-lg"></i> Adicionar todos (' + aAdicionar + ')</button>' : '') +
            '</div>';

            var linhas = nfeItens.map(function (it, idx) {
                // Item não encontrado no catálogo (nem por EAN, nem por id): não bloqueia a
                // confirmação — é cadastrado como insumo novo automaticamente ao ser adicionado
                // (mesma lógica já usada pro fornecedor da nota). "Vincular a item existente" fica
                // como alternativa só pra quando o produto já existe sob outro nome/EAN.
                var produtoCel = it.encontrado
                    ? '<span class="badge ' + (it.tipo === 'medicamento' ? 'bg-info text-dark' : 'bg-secondary') + ' mb-1">' + (it.tipo === 'medicamento' ? 'Medicamento' : 'Insumo') + '</span><div style="font-size:12.5px;">' + esc(it.produto) + '</div>'
                    : '<span class="badge bg-warning text-dark mb-1">Novo</span><div style="font-size:12.5px;">' + esc(it.nome_nfe) + '</div>' +
                      '<div class="text-warning" style="font-size:11.5px;"><i class="bi bi-exclamation-triangle"></i> Não encontrado — será cadastrado como <strong>insumo</strong> ao adicionar.</div>' +
                      '<button type="button" class="btn btn-sm btn-outline-secondary mt-1 btn-vincular-nfe" data-idx="' + idx + '">Vincular a item existente</button>';

                var fracaoHtml = '<div class="d-flex align-items-center gap-1 mb-1">' +
                        '<input type="checkbox" class="form-check-input nfe-fracionado" data-idx="' + idx + '" id="nfeFrac' + idx + '" ' + (it.fracionado ? 'checked' : '') + ' ' + (it.adicionado ? 'disabled' : '') + '>' +
                        '<label for="nfeFrac' + idx + '" class="form-check-label small mb-0">Item Fracionado</label>' +
                    '</div>' +
                    (it.fracionado ?
                        '<div class="d-flex gap-1">' +
                            '<input type="number" min="1" step="1" class="form-control form-control-sm nfe-fracao-qtd" data-idx="' + idx + '" style="width:70px;" placeholder="Qtd." value="' + (it.fracaoQtd || '') + '" ' + (it.adicionado ? 'disabled' : '') + '>' +
                            '<input type="text" class="form-control form-control-sm nfe-fracao-unidade" data-idx="' + idx + '" style="width:90px;" placeholder="unidade" value="' + esc(it.fracaoUnidade) + '" ' + (it.adicionado ? 'disabled' : '') + '>' +
                        '</div>'
                    : '');

                return '<tr>' +
                    '<td style="min-width:170px;">' + produtoCel + '</td>' +
                    '<td style="width:90px;"><input type="number" min="1" class="form-control form-control-sm nfe-quantidade" data-idx="' + idx + '" value="' + it.quantidade + '" ' + (it.adicionado ? 'disabled' : '') + '></td>' +
                    '<td style="width:120px;"><input type="text" class="form-control form-control-sm nfe-lote" data-idx="' + idx + '" value="' + esc(it.lote) + '" ' + (it.adicionado ? 'disabled' : '') + '></td>' +
                    '<td style="width:130px;"><input type="month" class="form-control form-control-sm nfe-validade" data-idx="' + idx + '" value="' + (it.validade || '') + '" ' + (it.adicionado ? 'disabled' : '') + '></td>' +
                    '<td style="width:110px;"><input type="number" min="0" step="0.01" class="form-control form-control-sm nfe-valor" data-idx="' + idx + '" value="' + it.valor_unitario + '" ' + (it.adicionado ? 'disabled' : '') + '></td>' +
                    '<td style="width:110px;"><input type="number" min="0" step="0.01" class="form-control form-control-sm nfe-valor-venda" data-idx="' + idx + '" value="' + it.valor_venda + '" ' + (it.adicionado ? 'disabled' : '') + '></td>' +
                    '<td style="width:150px;">' + fracaoHtml + '</td>' +
                    '<td style="width:110px;">' +
                        (it.adicionado
                            ? '<span class="badge bg-success">Adicionado</span>'
                            : '<button type="button" class="btn btn-sm btn-outline-success btn-adicionar-nfe" data-idx="' + idx + '"><i class="bi bi-plus-lg"></i> Adicionar</button>') +
                    '</td>' +
                '</tr>';
            }).join('');

            nfeResumo.innerHTML = cabecalho +
                '<div class="table-responsive"><table class="table table-sm table-striped mb-0">' +
                '<thead><tr><th>Produto</th><th>Quantidade</th><th>Lote</th><th>Validade</th><th>Valor Compra</th><th>Valor Venda</th><th>Fração</th><th></th></tr></thead>' +
                '<tbody>' + linhas + '</tbody></table></div>';

            nfeResumo.querySelectorAll('.nfe-quantidade').forEach(function (el) {
                el.addEventListener('input', function () {
                    var it = nfeItens[parseInt(el.getAttribute('data-idx'), 10)];
                    it.quantidade = parseInt(el.value, 10) || 0;
                    it.quantidadeBase = it.quantidade;
                });
            });
            nfeResumo.querySelectorAll('.nfe-lote').forEach(function (el) {
                el.addEventListener('input', function () {
                    nfeItens[parseInt(el.getAttribute('data-idx'), 10)].lote = el.value;
                });
            });
            nfeResumo.querySelectorAll('.nfe-validade').forEach(function (el) {
                el.addEventListener('input', function () {
                    nfeItens[parseInt(el.getAttribute('data-idx'), 10)].validade = el.value;
                });
            });
            nfeResumo.querySelectorAll('.nfe-valor').forEach(function (el) {
                el.addEventListener('input', function () {
                    var it = nfeItens[parseInt(el.getAttribute('data-idx'), 10)];
                    it.valor_unitario = parseFloat(el.value) || 0;
                    it.valorUnitarioBase = it.valor_unitario;
                });
            });
            nfeResumo.querySelectorAll('.nfe-valor-venda').forEach(function (el) {
                el.addEventListener('input', function () {
                    var it = nfeItens[parseInt(el.getAttribute('data-idx'), 10)];
                    // Editar direto aqui sempre define o valor de venda da FRAÇÃO atual (não da
                    // caixa/embalagem inteira) — reaplicar a fração de novo (ex.: mudar a
                    // quantidade da fração depois) partiria desse valor já fracionado, o que
                    // ficaria errado. Por isso o valor digitado vira a nova base direto.
                    it.valorVendaBase = (parseFloat(el.value) || 0) * (it.fracionado && it.fracaoQtd > 0 ? it.fracaoQtd : 1);
                    it.valor_venda = parseFloat(el.value) || 0;
                });
            });
            nfeResumo.querySelectorAll('.nfe-fracionado').forEach(function (el) {
                el.addEventListener('change', function () {
                    var it = nfeItens[parseInt(el.getAttribute('data-idx'), 10)];
                    it.fracionado = el.checked;
                    aplicarFracao(it);
                    renderNfeResumo();
                });
            });
            nfeResumo.querySelectorAll('.nfe-fracao-qtd').forEach(function (el) {
                el.addEventListener('input', function () {
                    var it = nfeItens[parseInt(el.getAttribute('data-idx'), 10)];
                    it.fracaoQtd = parseInt(el.value, 10) || 0;
                    aplicarFracao(it);
                    renderNfeResumo();
                });
            });
            nfeResumo.querySelectorAll('.nfe-fracao-unidade').forEach(function (el) {
                el.addEventListener('input', function () {
                    nfeItens[parseInt(el.getAttribute('data-idx'), 10)].fracaoUnidade = el.value;
                });
            });
            nfeResumo.querySelectorAll('.btn-vincular-nfe').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    nfeVincularIndex = parseInt(btn.getAttribute('data-idx'), 10);
                    if (!vincularNfeModal) { vincularNfeModal = new bootstrap.Modal(vincularNfeModalEl); }
                    vincularNfeNomeOriginal.textContent = 'Item na nota: ' + nfeItens[nfeVincularIndex].nome_nfe;
                    vincularNfeInput.value = '';
                    vincularNfeResultados.innerHTML = '';
                    vincularNfeModal.show();
                    setTimeout(function () { vincularNfeInput.focus(); }, 300);
                });
            });
            nfeResumo.querySelectorAll('.btn-adicionar-nfe').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    adicionarNfeItem(parseInt(btn.getAttribute('data-idx'), 10));
                });
            });
            var todosBtn = document.getElementById('nfeAdicionarTodosBtn');
            if (todosBtn) {
                todosBtn.addEventListener('click', function () {
                    var pulados = 0;
                    nfeItens.forEach(function (it, idx) {
                        if (!it.adicionado) {
                            if (!adicionarNfeItem(idx, true)) pulados++;
                        }
                    });
                    if (pulados) alert(pulados + ' item(ns) não foram adicionados por falta de lote, validade ou valor unitário — complete os dados e adicione manualmente.');
                });
            }
        }

        function adicionarNfeItem(idx, silencioso) {
            var it = nfeItens[idx];
            if (it.adicionado) return false;

            var quantidade = parseInt(it.quantidade, 10);
            if (!quantidade || quantidade <= 0) { if (!silencioso) alert('Informe uma quantidade válida.'); return false; }
            var lote = (it.lote || '').trim();
            if (!lote) { if (!silencioso) alert('Informe o lote de "' + it.produto + '".'); return false; }
            if (!it.validade) { if (!silencioso) alert('Informe a validade de "' + it.produto + '".'); return false; }
            var valorUnitario = parseFloat(it.valor_unitario);
            if (isNaN(valorUnitario) || valorUnitario < 0) { if (!silencioso) alert('Informe o valor de compra de "' + it.produto + '".'); return false; }
            var valorVenda = parseFloat(it.valor_venda) || 0;

            var observacao = 'Nota fiscal' + (nfeInfo && nfeInfo.numero ? ' nº ' + nfeInfo.numero : '') + (nfeInfo && nfeInfo.emitente ? ' · ' + nfeInfo.emitente : '');
            if (it.fracionado && it.fracaoQtd > 0) {
                observacao += ' · Fracionado: embalagem com ' + it.fracaoQtd + ' ' + (it.fracaoUnidade || 'un.');
            }

            // Item sem correspondência no catálogo (it.tipo/it.item_id nulos) é sempre tratado como
            // insumo novo — o backend cadastra na hora ao confirmar (ver "Novo — será cadastrado..."
            // acima e a mesma lógica já usada pro fornecedor da nota).
            itens.push({
                tipo_item: it.tipo || 'insumo',
                item_id: it.item_id || 0,
                codigo_barras: it.codigo_barras || '',
                produto: it.produto,
                apresentacao: it.apresentacao,
                laboratorio: it.laboratorio,
                quantidade: quantidade,
                observacao: observacao,
                lote: lote,
                validade: it.validade,
                validadeBr: formatarDataBr(it.validade),
                valor_unitario: valorUnitario,
                valor_venda: valorVenda
            });
            renderItens();

            it.adicionado = true;
            renderNfeResumo();
            return true;
        }

        nfeImportarBtn.addEventListener('click', function () {
            if (!nfeXmlInput.files || !nfeXmlInput.files.length) {
                alert('Selecione o arquivo XML da nota fiscal.');
                return;
            }
            var csrfToken = document.querySelector('input[name="csrf_token"]').value;
            var body = new FormData();
            body.set('csrf_token', csrfToken);
            body.set('xml', nfeXmlInput.files[0]);

            nfeImportarBtn.disabled = true;
            nfeResumo.innerHTML = '<div class="text-muted small">Lendo nota fiscal...</div>';
            nfeChaveAviso.textContent = '';

            fetch('ajax_nfe_importar.php', { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    nfeImportarBtn.disabled = false;
                    if (!data.sucesso) {
                        nfeResumo.innerHTML = '<div class="alert alert-danger mb-0">' + esc(data.error) + '</div>';
                        return;
                    }
                    nfeInfo = data.nfe;
                    if (data.fornecedor) {
                        selecionarFornecedor(data.fornecedor);
                    }
                    nfeItens = data.itens.map(function (it) {
                        return Object.assign({}, it, {
                            quantidadeBase: it.quantidade,
                            valorUnitarioBase: it.valor_unitario,
                            // A nota não traz preço de venda — fica em branco pro operador
                            // preencher (é opcional; some entradas não têm preço de venda definido).
                            valor_venda: 0,
                            valorVendaBase: 0,
                            fracionado: false,
                            fracaoQtd: null,
                            fracaoUnidade: '',
                            adicionado: false
                        });
                    });

                    var chaveLida = nfeChaveInput.value.trim();
                    if (chaveLida && nfeInfo.chave && chaveLida !== nfeInfo.chave) {
                        nfeChaveAviso.innerHTML = '<span class="text-danger fw-bold">Atenção: a chave lida no código de barras não confere com a chave desta nota.</span>';
                    } else if (nfeInfo.chave) {
                        nfeChaveInput.value = nfeInfo.chave;
                    }

                    renderNfeResumo();
                })
                .catch(function () {
                    nfeImportarBtn.disabled = false;
                    nfeResumo.innerHTML = '<div class="alert alert-danger mb-0">Erro ao importar a nota. Tente novamente.</div>';
                });
        });

        // ---- Busca (por nome) pra vincular um item da nota sem EAN reconhecido a um medicamento
        // ou insumo já cadastrado — mesmo endpoint da busca principal, resultado só atualiza a
        // linha da nota em vez do formulário de scan. ----
        vincularNfeInput.addEventListener('input', function () {
            clearTimeout(vincularNfeTimer);
            var termo = vincularNfeInput.value.trim();
            if (termo.length < 2) {
                vincularNfeResultados.innerHTML = '';
                return;
            }
            vincularNfeTimer = setTimeout(function () {
                fetch('ajax_buscar_nome.php?busca=' + encodeURIComponent(termo))
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.itens || !data.itens.length) {
                            vincularNfeResultados.innerHTML = '<div class="text-muted small mt-2">Nenhum resultado encontrado.</div>';
                            return;
                        }
                        vincularNfeResultados.innerHTML = '<div class="list-group">' +
                            data.itens.map(function (it, idx) {
                                return '<button type="button" class="list-group-item list-group-item-action py-2 btn-vincular-opcao" data-idx="' + idx + '">' +
                                    '<span class="badge ' + (it.tipo === 'medicamento' ? 'bg-info text-dark' : 'bg-secondary') + ' mb-1">' + (it.tipo === 'medicamento' ? 'Medicamento' : 'Insumo') + '</span>' +
                                    '<div class="fw-bold" style="font-size:13px;">' + esc(it.titulo) + '</div>' +
                                    (it.subtitulo ? '<div class="small text-muted">' + esc(it.subtitulo) + '</div>' : '') +
                                    '</button>';
                            }).join('') + '</div>';
                        vincularNfeResultados.querySelectorAll('.btn-vincular-opcao').forEach(function (btn) {
                            btn.addEventListener('click', function () {
                                var escolhido = data.itens[parseInt(btn.getAttribute('data-idx'), 10)];
                                fetch('ajax_buscar_item.php?id=' + escolhido.id + '&tipo=' + escolhido.tipo)
                                    .then(function (r) { return r.json(); })
                                    .then(function (resultado) {
                                        if (!resultado.found) { alert('Item não encontrado.'); return; }
                                        var it = nfeItens[nfeVincularIndex];
                                        if (resultado.tipo === 'medicamento') {
                                            var m = resultado.medicamento;
                                            it.tipo = 'medicamento'; it.item_id = m.id; it.produto = m.produto;
                                            it.laboratorio = m.laboratorio; it.apresentacao = m.apresentacao; it.codigo_barras = m.codigo_barras;
                                        } else {
                                            var i2 = resultado.insumo;
                                            it.tipo = 'insumo'; it.item_id = i2.id; it.produto = i2.nome_comercial;
                                            it.laboratorio = i2.marca; it.apresentacao = i2.categoria; it.codigo_barras = i2.codigo_barras;
                                        }
                                        it.encontrado = true;
                                        vincularNfeModal.hide();
                                        renderNfeResumo();
                                    })
                                    .catch(function () {
                                        alert('Erro ao buscar. Tente novamente.');
                                    });
                            });
                        });
                    })
                    .catch(function () {
                        vincularNfeResultados.innerHTML = '<div class="alert alert-danger small mt-2 mb-0">Erro ao buscar.</div>';
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
            // Entrada acompanha custo (compra); Saída — o que interessa é o valor de venda, já que
            // é isso que é "consumido"/repassado na retirada.
            var subtotal = tab === 'saida'
                ? (item.valor_venda || 0) * item.quantidade
                : (item.valor_unitario || 0) * item.quantidade;
            var linhaValores = tab === 'saida'
                ? formatarMoeda(item.valor_venda) + ' / un. · Subtotal: ' + formatarMoeda(subtotal)
                : 'Compra: ' + formatarMoeda(item.valor_unitario) + ' / un.' +
                    (item.valor_venda ? ' · Venda: ' + formatarMoeda(item.valor_venda) + ' / un.' : '') +
                    ' · Subtotal: ' + formatarMoeda(subtotal);
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
                        '<div class="entity-sub mono">' + linhaValores + '</div>' +
                    '</div>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger btn-remover-item" data-idx="' + idx + '" title="Remover"><i class="bi bi-x-lg"></i></button>' +
                '</div>' +
            '</div>';
        }).join('');

        if (resumoFinanceiroWrap) {
            // Resumo financeiro da Saída é em cima do valor de venda, não do custo de compra.
            var totalGeral = itens.reduce(function (soma, item) { return soma + (item.valor_venda || 0) * item.quantidade; }, 0);
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
            alert('Informe o valor de compra.');
            return;
        }

        // Medicamento e insumo são estruturalmente iguais aqui (ambos rastreados por lote em
        // insumo_lotes) — só os nomes dos campos do catálogo diferem.
        var d = itemAtual.dados;
        var ehMedicamento = itemAtual.tipo === 'medicamento';
        var unidade = ehMedicamento ? 'un.' : d.unidade_medida;
        var novoItem = {
            tipo_item: itemAtual.tipo,
            item_id: d.id,
            codigo_barras: d.codigo_barras,
            produto: ehMedicamento ? d.produto : d.nome_comercial,
            laboratorio: ehMedicamento ? d.laboratorio : d.marca,
            apresentacao: ehMedicamento ? d.apresentacao : d.categoria,
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
            novoItem.valor_unitario = parseFloat(valorUnitarioInput.value);
            novoItem.valor_venda = parseFloat(valorVendaInput.value) || 0;
        } else {
            var opt = loteSelect.options[loteSelect.selectedIndex];
            if (!opt || !opt.value) { alert('Selecione o lote.'); return; }
            var disponivel = parseInt(opt.getAttribute('data-quantidade'), 10);
            if (quantidade > disponivel) { alert('Quantidade maior que o saldo disponível neste lote (' + disponivel + ' ' + unidade + ').'); return; }
            novoItem.lote_id = parseInt(opt.value, 10);
            novoItem.lote = opt.getAttribute('data-lote');
            novoItem.validadeBr = opt.getAttribute('data-validade-br');
            novoItem.valor_unitario = parseFloat(opt.getAttribute('data-valor')) || 0;
            novoItem.valor_venda = parseFloat(opt.getAttribute('data-valor-venda')) || 0;
        }

        itens.push(novoItem);
        renderItens();

        itemAtual = null;
        codigoInput.value = '';
        scanResult.innerHTML = '';
        campos.style.display = 'none';
        if (valorUnitarioInput) valorUnitarioInput.value = '';
        if (valorVendaInput) valorVendaInput.value = '';
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
        if (tab === 'entrada' && !document.getElementById('fornecedorIdField').value) {
            e.preventDefault();
            alert('Selecione o fornecedor de quem os itens desta entrada vieram.');
            return;
        }
        var mensagem = tab === 'entrada' ? 'Você confirma os itens a serem inseridos?' : 'Você confirma os itens a serem retirados?';
        if (!confirm(mensagem)) {
            e.preventDefault();
            return;
        }
        itensJsonField.value = JSON.stringify(itens);
        // Evita duplo clique/duplo submit gravar a mesma confirmação duas vezes enquanto a
        // página ainda está navegando para o resultado.
        confirmarBtn.disabled = true;
    });
})();
</script>
