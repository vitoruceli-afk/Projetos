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
            $unidadesGeradas = 0; // quantas unidades físicas foram etiquetadas nesta confirmação
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

                // Saída lida pelo código interno de uma unidade fracionada: a baixa é sempre de 1
                // unidade física, identificada pelo código, e não pela quantidade digitada. Trava a
                // unidade E o lote na mesma transação — assim duas leituras simultâneas do mesmo
                // código não conseguem baixar a mesma ampola duas vezes, e o saldo do lote
                // (que segue sendo a fonte de verdade do estoque) desce junto.
                if (!$ehEntrada && !empty($item['unidade_id'])) {
                    $stmt = $db->prepare("SELECT * FROM unidades_estoque WHERE id = :id FOR UPDATE");
                    $stmt->execute([':id' => (int)$item['unidade_id']]);
                    $unidade = $stmt->fetch();

                    if (!$unidade) {
                        $erroItem = "Item {$numero}: unidade não encontrada.";
                        break;
                    }
                    if ($unidade['status'] !== 'DISPONIVEL') {
                        $erroItem = "Item {$numero}: a unidade " . htmlspecialchars($unidade['codigo_interno'])
                            . ' não está disponível (status atual: ' . unidadeStatusLabel($unidade['status']) . ').';
                        break;
                    }

                    $stmt = $db->prepare("SELECT * FROM insumo_lotes WHERE id = :id FOR UPDATE");
                    $stmt->execute([':id' => $unidade['lote_id']]);
                    $loteRow = $stmt->fetch();
                    if (!$loteRow || (int)$loteRow['quantidade'] < 1) {
                        $erroItem = "Item {$numero}: o lote da unidade " . htmlspecialchars($unidade['codigo_interno']) . ' está sem saldo em estoque.';
                        break;
                    }

                    $db->prepare("UPDATE insumo_lotes SET quantidade = quantidade - 1 WHERE id = :id")
                       ->execute([':id' => $loteRow['id']]);

                    $db->prepare("INSERT INTO movimentacoes (medicamento_id, insumo_id, lote_id, unidade_id, confirmacao_id, tipo, quantidade, valor_unitario, valor_venda, usuario, observacao)
                        VALUES (:m, :i, :lo, :un, :c, :t, 1, :vu, :vv, :u, :o)")
                       ->execute([
                            ':m' => $unidade['medicamento_id'], ':i' => $unidade['insumo_id'], ':lo' => $loteRow['id'],
                            ':un' => $unidade['id'], ':c' => $confirmacaoId, ':t' => $tipoMov,
                            ':vu' => (float)$loteRow['valor_unitario'], ':vv' => (float)$loteRow['valor_venda'],
                            ':u' => $_SESSION['user_logged_in'], ':o' => $observacao,
                       ]);
                    $movimentacaoSaidaId = (int)$db->lastInsertId();

                    $db->prepare("UPDATE unidades_estoque SET status = 'UTILIZADA', utilizado_em = NOW(), utilizado_por = :u, movimentacao_saida_id = :mv WHERE id = :id")
                       ->execute([':u' => $_SESSION['user_logged_in'], ':mv' => $movimentacaoSaidaId, ':id' => $unidade['id']]);

                    registrarLog('Unidades', 'Unidade utilizada na saída',
                        'código: ' . $unidade['codigo_interno'] . ', lote ' . $loteRow['lote']
                        . ', validade ' . formatarValidade($loteRow['validade'])
                        . ', entrada ' . codigoReferenciaEntrada((int)$unidade['confirmacao_id']));
                    continue;
                }

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

                        // Lote com unidades etiquetadas só sai pela leitura do código de cada
                        // unidade: baixar por quantidade aqui derrubaria o saldo sem marcar
                        // nenhuma unidade como utilizada, deixando etiquetas válidas sem lastro.
                        $etiquetadas = unidadesDisponiveisDoLote($db, (int)$loteRow['id']);
                        if ($etiquetadas > 0) {
                            $erroItem = "Item {$numero} (" . htmlspecialchars($medicamento['produto']) . '): o lote "' . htmlspecialchars($loteRow['lote'])
                                . '" tem ' . $etiquetadas . ' unidade(s) etiquetada(s) — a saída precisa ser feita lendo o código de barras de cada unidade.';
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
                    $movimentacaoId = (int)$db->lastInsertId();
                    $unidadeDono = ['medicamento_id' => $medicamento['id'], 'insumo_id' => null];
                    $unidadeNome = $medicamento['produto'];
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

                        // Mesma regra do medicamento: lote etiquetado sai só pela leitura da
                        // etiqueta de cada unidade.
                        $etiquetadas = unidadesDisponiveisDoLote($db, (int)$loteRow['id']);
                        if ($etiquetadas > 0) {
                            $erroItem = "Item {$numero} (" . htmlspecialchars($insumo['nome_comercial']) . '): o lote "' . htmlspecialchars($loteRow['lote'])
                                . '" tem ' . $etiquetadas . ' unidade(s) etiquetada(s) — a saída precisa ser feita lendo o código de barras de cada unidade.';
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
                    $movimentacaoId = (int)$db->lastInsertId();
                    $unidadeDono = ['medicamento_id' => null, 'insumo_id' => $insumo['id']];
                    $unidadeNome = $insumo['nome_comercial'];
                }

                // Fracionamento com etiqueta: gera uma unidade física (código interno de 13
                // caracteres) por item etiquetado, ligada ao lote e à movimentação de entrada que
                // a originou. Só na Entrada, só quando o operador pediu, e nunca mais unidades do
                // que o saldo do lote comporta — o saldo continua sendo insumo_lotes.quantidade,
                // estas linhas são a camada de rastreabilidade individual por cima dele.
                if ($ehEntrada && !empty($item['gerar_unidades'])) {
                    $prefixo = strtoupper(trim((string)($item['unidade_prefixo'] ?? '')));
                    if (!array_key_exists($prefixo, UNIDADE_PREFIXOS)) {
                        $erroItem = "Item {$numero} (" . htmlspecialchars($unidadeNome) . '): selecione um tipo de unidade válido para gerar os códigos de barras.';
                        break;
                    }

                    $qtdEtiquetas = (int)($item['unidades_qtd'] ?? 0);
                    if ($qtdEtiquetas <= 0 || $qtdEtiquetas > $quantidade) {
                        $qtdEtiquetas = $quantidade;
                    }

                    $stmtSaldo = $db->prepare("SELECT quantidade FROM insumo_lotes WHERE id = :id FOR UPDATE");
                    $stmtSaldo->execute([':id' => $loteId]);
                    $saldoLote = (int)$stmtSaldo->fetchColumn();
                    $jaEtiquetadas = unidadesDisponiveisDoLote($db, (int)$loteId);
                    if ($jaEtiquetadas + $qtdEtiquetas > $saldoLote) {
                        $erroItem = "Item {$numero} (" . htmlspecialchars($unidadeNome) . '): o lote "' . htmlspecialchars($lote)
                            . '" tem ' . $saldoLote . ' unidade(s) em estoque e ' . $jaEtiquetadas
                            . ' já etiquetada(s) — não é possível gerar ' . $qtdEtiquetas . ' etiqueta(s).';
                        break;
                    }

                    $codigosGerados = criarUnidadesEstoque($db, [
                        'prefixo' => $prefixo,
                        'quantidade' => $qtdEtiquetas,
                        'medicamento_id' => $unidadeDono['medicamento_id'],
                        'insumo_id' => $unidadeDono['insumo_id'],
                        'lote_id' => $loteId,
                        'confirmacao_id' => $confirmacaoId,
                        'movimentacao_id' => $movimentacaoId,
                        'usuario' => $_SESSION['user_logged_in'],
                        'observacao' => $observacao,
                    ]);

                    $unidadesGeradas += count($codigosGerados);
                    registrarLog('Unidades', 'Unidades fracionadas geradas',
                        count($codigosGerados) . ' unidade(s) ' . UNIDADE_PREFIXOS[$prefixo] . ' de ' . $unidadeNome
                        . ', lote ' . $lote . ', entrada ' . codigoReferenciaEntrada($confirmacaoId)
                        . ' (' . $codigosGerados[0] . ' a ' . $codigosGerados[count($codigosGerados) - 1] . ')');
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
                if ($unidadesGeradas > 0) {
                    $detalhesLog .= ', ' . $unidadesGeradas . ' unidade(s) etiquetada(s)';
                }
                registrarLog('Movimentação', $acaoLog, $detalhesLog);
                $destino = 'index.php?page=movimentacao&tab=' . $tipoMov . '&ok=1&qtd=' . count($itens);
                if ($unidadesGeradas > 0) {
                    // Leva a referência da entrada pra tela oferecer a impressão das etiquetas
                    // recém-geradas logo após a confirmação.
                    $destino .= '&unidades=' . $unidadesGeradas . '&entrada=' . $confirmacaoId;
                }
                header('Location: ' . $destino);
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

$linkEtiquetas = '';
if (isset($_GET['ok'])) {
    $qtd = (int)($_GET['qtd'] ?? 1);
    if ($tab === 'entrada') {
        $formSuccess = $qtd === 1 ? '1 item inserido com sucesso.' : "{$qtd} itens inseridos com sucesso.";
    } else {
        $formSuccess = $qtd === 1 ? '1 item retirado com sucesso.' : "{$qtd} itens retirados com sucesso.";
    }
    // Entrada que gerou etiquetas: oferece a impressão logo aqui, que é quando o operador ainda
    // está com as embalagens na mão pra colar as etiquetas.
    $unidadesGeradasMsg = (int)($_GET['unidades'] ?? 0);
    $entradaGerada = (int)($_GET['entrada'] ?? 0);
    if ($unidadesGeradasMsg > 0 && $entradaGerada > 0) {
        $formSuccess .= ' ' . $unidadesGeradasMsg . ' unidade(s) etiquetada(s) na entrada ' . codigoReferenciaEntrada($entradaGerada) . '.';
        $linkEtiquetas = 'index.php?page=etiquetas_imprimir&entrada=' . $entradaGerada;
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
<?php if ($formSuccess): ?>
    <div class="alert alert-success d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><?= htmlspecialchars($formSuccess) ?></span>
        <?php if ($linkEtiquetas): ?>
            <a href="<?= htmlspecialchars($linkEtiquetas) ?>" target="_blank" class="btn btn-sm btn-outline-success"><i class="bi bi-printer"></i> Imprimir etiquetas</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

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
                            <label class="form-label">Validade (MM/AA)</label>
                            <input type="text" id="validadeInput" class="form-control mono" inputmode="numeric" placeholder="MM/AA" maxlength="5">
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
                    <div class="border rounded p-2 mt-2">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="fracionadoCheck">
                            <label class="form-check-label" for="fracionadoCheck">Item Fracionado</label>
                        </div>
                        <div class="form-text">Embalagem que será aberta em unidades soltas (ex.: caixa com 10 ampolas). A quantidade digitada acima é multiplicada pela fração e o valor de venda é rateado entre as unidades.</div>

                        <div id="fracionadoCamposWrap" style="display:none;">
                            <div class="row g-2 mt-1">
                                <div class="col-sm-6">
                                    <label class="form-label">Unidades por embalagem</label>
                                    <input type="number" id="fracaoQtdInput" class="form-control" min="1" max="9999" step="1" placeholder="Ex.: 10">
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label">Unidade da fração</label>
                                    <select id="fracaoUnidadeInput" class="form-select">
                                        <?php foreach (UNIDADE_PREFIXOS as $pfx => $nome): ?>
                                            <option value="<?= $pfx ?>"><?= htmlspecialchars($nome) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-check mt-2">
                                <input type="checkbox" class="form-check-input" id="gerarUnidadesCheck">
                                <label class="form-check-label" for="gerarUnidadesCheck">Gerar códigos de barras internos para as unidades</label>
                            </div>
                            <div class="form-text">Cada unidade recebe uma etiqueta própria (Code 128) para ser lida na Saída — necessário quando a unidade solta não tem código de barras do fabricante. O tipo do código segue a unidade da fração escolhida acima.</div>

                            <div class="row g-2 mt-1" id="unidadesCamposWrap" style="display:none;">
                                <div class="col-sm-6">
                                    <label class="form-label">Unidades a etiquetar</label>
                                    <input type="number" id="unidadesQtdInput" class="form-control" min="1" max="9999" step="1" placeholder="Todas">
                                    <div class="form-text">Em branco = todas. Menos que o total = fracionamento parcial.</div>
                                </div>
                            </div>
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
    var fracionadoCheck = document.getElementById('fracionadoCheck');
    var fracionadoCamposWrap = document.getElementById('fracionadoCamposWrap');
    var fracaoQtdInput = document.getElementById('fracaoQtdInput');
    var fracaoUnidadeInput = document.getElementById('fracaoUnidadeInput');
    var gerarUnidadesCheck = document.getElementById('gerarUnidadesCheck');
    var unidadesCamposWrap = document.getElementById('unidadesCamposWrap');
    var unidadesQtdInput = document.getElementById('unidadesQtdInput');

    // Tipos de unidade (AMP, FRS, UNI, PCT...) vindos do PHP: a mesma lista serve pra "unidade da
    // fração" e pro prefixo do código de barras da etiqueta — são a mesma coisa, então um campo só
    // define os dois e não há risco de escolher "ampola" na fração e "frasco" na etiqueta.
    var unidadePrefixos = <?= json_encode(UNIDADE_PREFIXOS, JSON_UNESCAPED_UNICODE) ?>;
    function nomeUnidade(prefixo) {
        return unidadePrefixos[prefixo] || prefixo || 'un.';
    }
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

    // ---- Campo de validade: digitado direto como "MM/AA" (máscara automática), convertido pra
    // "AAAA-MM" (o que o backend espera) só na hora de montar o item. Ano de 2 dígitos sempre cai
    // no século 2000 (AA=26 -> 2026) — suficiente pra validade de estoque. ----
    function aplicarMascaraValidade(input) {
        var v = input.value.replace(/\D/g, '').slice(0, 4);
        if (v.length > 2) v = v.replace(/(\d{2})(\d{1,2})/, '$1/$2');
        input.value = v;
    }

    // "MM/AA" -> "AAAA-MM", ou null se incompleto/inválido.
    function validadeParaAnoMes(mmAa) {
        var m = /^(\d{2})\/(\d{2})$/.exec(String(mmAa || '').trim());
        if (!m) return null;
        var mes = parseInt(m[1], 10);
        if (mes < 1 || mes > 12) return null;
        return (2000 + parseInt(m[2], 10)) + '-' + (mes < 10 ? '0' + mes : String(mes));
    }

    // "AAAA-MM" (ou "AAAA-MM-DD") -> "MM/AA", pra preencher o campo com um valor que já veio
    // pronto (ex.: validade extraída do XML da NFe).
    function anoMesParaValidade(anoMes) {
        var m = /^(\d{4})-(\d{2})/.exec(String(anoMes || ''));
        return m ? (m[2] + '/' + m[1].slice(2)) : '';
    }

    if (validadeInput) {
        validadeInput.addEventListener('input', function () { aplicarMascaraValidade(validadeInput); });
    }

    // Fracionamento na entrada manual: os campos da fração só aparecem com a caixa marcada, e a
    // geração de etiquetas só aparece dentro do fracionamento (etiqueta interna só faz sentido
    // pra unidade solta que veio de uma embalagem aberta).
    if (fracionadoCheck) {
        fracionadoCheck.addEventListener('change', function () {
            fracionadoCamposWrap.style.display = fracionadoCheck.checked ? '' : 'none';
            if (!fracionadoCheck.checked) {
                gerarUnidadesCheck.checked = false;
                unidadesCamposWrap.style.display = 'none';
            }
        });
        gerarUnidadesCheck.addEventListener('change', function () {
            unidadesCamposWrap.style.display = gerarUnidadesCheck.checked ? '' : 'none';
        });
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
        var etiquetadas = parseInt(opt.getAttribute('data-etiquetadas'), 10) || 0;
        quantidadeInput.max = qtd;

        // Lote etiquetado não sai por quantidade: cada unidade tem código próprio e precisa ser
        // lida, senão o saldo desceria sem nenhuma unidade ser marcada como utilizada. O backend
        // recusa de qualquer forma — aqui é só pra o operador entender antes de tentar.
        if (etiquetadas > 0) {
            inserirBtn.disabled = true;
            loteSelectHint.innerHTML = '<span class="text-danger fw-bold">Este lote tem ' + etiquetadas +
                ' unidade(s) etiquetada(s): leia o código de barras da etiqueta de cada unidade para dar saída.</span>';
        } else {
            inserirBtn.disabled = false;
            loteSelectHint.textContent = qtd + ' unidade(s) disponível(is) neste lote · Valor venda: ' + formatarMoeda(valorVenda);
        }
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

        // Código interno de unidade fracionada: mostra a ficha completa da unidade (produto, lote,
        // validade, entrada de origem e status) e libera a baixa só se estiver DISPONIVEL. Se já
        // foi utilizada, bloqueia aqui mesmo e informa quando e por quem — o backend revalida
        // isso de novo na confirmação, esta checagem é só pra dar o retorno imediato ao operador.
        if (data.tipo === 'unidade') {
            var u = data.unidade;
            itemAtual = null;
            campos.style.display = 'none';

            var ficha =
                '<div class="scan-summary"><div style="width:100%;">' +
                    '<span class="badge bg-dark mb-2">' + esc(u.tipo_unidade) + ' · unidade fracionada</span> ' +
                    '<span class="badge ' + u.status_badge + ' mb-2">' + esc(u.status_label) + '</span>' +
                    '<div class="scan-summary-title">' + esc(u.produto) + '</div>' +
                    '<div class="scan-summary-sub">' + esc(u.origem || '—') + '</div>' +
                    '<div class="scan-summary-grid">' +
                        '<div><div class="entity-field-label">Código interno</div><div class="entity-field-value mono">' + esc(u.codigo_interno) + '</div></div>' +
                        '<div><div class="entity-field-label">Lote</div><div class="entity-field-value mono">' + esc(u.lote) + '</div></div>' +
                        '<div><div class="entity-field-label">Validade</div><div class="entity-field-value mono">' + esc(u.validade_br) + '</div></div>' +
                        '<div><div class="entity-field-label">Entrada de origem</div><div class="entity-field-value mono">' + esc(u.entrada || '—') + '</div></div>' +
                    '</div>' +
                '</div></div>';

            if (tab !== 'saida') {
                scanResult.innerHTML = ficha +
                    '<div class="alert alert-warning mt-3 mb-0">Este código identifica uma unidade já existente em estoque — use a aba Saída para dar baixa nela.</div>';
                return;
            }

            if (!u.disponivel) {
                var motivo = u.status === 'UTILIZADA'
                    ? 'Esta unidade <strong>já foi utilizada</strong>' +
                      (u.utilizado_em ? ' em ' + esc(u.utilizado_em) : '') +
                      (u.utilizado_por ? ' por ' + esc(u.utilizado_por) : '') + '.'
                    : 'Esta unidade está com status <strong>' + esc(u.status_label) + '</strong> e não pode ser baixada.';
                scanResult.innerHTML = ficha +
                    '<div class="alert alert-danger mt-3 mb-0"><strong>ATENÇÃO:</strong> ' + motivo + ' Não é possível registrar uma nova saída para o código ' + esc(u.codigo_interno) + '.</div>';
                return;
            }

            itemAtual = { tipo: 'unidade', dados: u };
            scanResult.innerHTML = ficha;
            // Unidade física é sempre 1: trava a quantidade e esconde a escolha de lote (o lote já
            // está determinado pelo código lido).
            if (loteSelectWrap) loteSelectWrap.style.display = 'none';
            inserirBtn.disabled = false; // pode ter ficado travado por um lote etiquetado antes
            quantidadeInput.value = 1;
            quantidadeInput.readOnly = true;
            quantidadeHint.textContent = 'Baixa de 1 unidade identificada pelo código ' + u.codigo_interno + '.';
            campos.style.display = 'block';
            observacaoInput.focus();
            return;
        }

        // Item comum (por produto): devolve a tela ao estado normal, caso a leitura anterior
        // tenha sido de uma unidade fracionada.
        quantidadeInput.readOnly = false;
        if (loteSelectWrap && tab === 'saida') loteSelectWrap.style.display = '';

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
                            return '<option value="' + l.id + '" data-quantidade="' + l.quantidade + '" data-lote="' + esc(l.lote) + '" data-validade-br="' + esc(l.validade_br) + '" data-valor="' + l.valor_unitario + '" data-valor-venda="' + l.valor_venda + '" data-etiquetadas="' + (l.unidades_etiquetadas || 0) + '">' +
                                l.lote + ' · vence em ' + l.validade_br + ' · ' + l.quantidade + ' un.' +
                                (l.unidades_etiquetadas ? ' · exige leitura de etiqueta' : '') +
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
                            return '<option value="' + l.id + '" data-quantidade="' + l.quantidade + '" data-lote="' + esc(l.lote) + '" data-validade-br="' + esc(l.validade_br) + '" data-valor="' + l.valor_unitario + '" data-valor-venda="' + l.valor_venda + '" data-etiquetadas="' + (l.unidades_etiquetadas || 0) + '">' +
                                l.lote + ' · vence em ' + l.validade_br + ' · ' + l.quantidade + ' ' + i.unidade_medida +
                                (l.unidades_etiquetadas ? ' · exige leitura de etiqueta' : '') +
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

        // Tipos de unidade disponíveis (AMP, FRS, ...) vêm do PHP — a lista é a mesma usada na
        // validação do backend, então basta acrescentar um prefixo lá pra ele aparecer aqui.
        function prefixosOptions(selecionado) {
            return Object.keys(unidadePrefixos).map(function (pfx) {
                return '<option value="' + pfx + '"' + (pfx === selecionado ? ' selected' : '') + '>' + esc(unidadePrefixos[pfx]) + '</option>';
            }).join('');
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
            // A nota nem sempre traz lote/validade (grupo <rastro> é opcional) — sem os dois, o
            // item não entra na fila mesmo clicando em Adicionar. Avisa isso de cara, além do
            // destaque em vermelho nos campos da própria linha.
            var incompletos = nfeItens.filter(function (it) { return !it.adicionado && (!(it.lote || '').trim() || !it.validade); }).length;

            nfeResumoBadge.textContent = nfeItens.length + ' item(ns) na nota' +
                (aAdicionar ? ', ' + aAdicionar + ' pendente(s) de adicionar' : ', todos adicionados') +
                (pendentes ? ' (' + pendentes + ' novo(s), sem cadastro ainda)' : '') +
                (incompletos ? ' — ' + incompletos + ' sem lote/validade' : '');

            var cabecalho = '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">' +
                '<div class="small text-muted">' +
                    (nfeInfo && nfeInfo.numero ? 'NF nº ' + esc(nfeInfo.numero) + (nfeInfo.emitente ? ' · ' + esc(nfeInfo.emitente) : '') : 'Itens da nota') +
                    (pendentes ? ' · <span class="text-warning fw-bold">' + pendentes + ' item(ns) novo(s) — sem correspondência no catálogo</span>' : '') +
                    (incompletos ? ' · <span class="text-danger fw-bold">' + incompletos + ' item(ns) sem lote/validade — preencha antes de adicionar</span>' : '') +
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
                      // A nota frequentemente não traz EAN pra item novo (cEAN "SEM GTIN") — sem
                      // código de barras salvo no cadastro, a Saída só consegue achar esse insumo
                      // buscando por nome (leitor de código não serve). Deixa editável aqui pro
                      // operador informar o código de barras real do produto (lido da embalagem,
                      // por exemplo), mesmo que a nota não tenha trazido.
                      '<input type="text" class="form-control form-control-sm nfe-codigo-barras mt-1" data-idx="' + idx + '" placeholder="Código de barras (opcional)" value="' + esc(it.codigo_barras) + '">' +
                      '<button type="button" class="btn btn-sm btn-outline-secondary mt-1 btn-vincular-nfe" data-idx="' + idx + '">Vincular a item existente</button>';

                var fracaoHtml = '<div class="d-flex align-items-center gap-1 mb-1">' +
                        '<input type="checkbox" class="form-check-input nfe-fracionado" data-idx="' + idx + '" id="nfeFrac' + idx + '" ' + (it.fracionado ? 'checked' : '') + ' ' + (it.adicionado ? 'disabled' : '') + '>' +
                        '<label for="nfeFrac' + idx + '" class="form-check-label small mb-0">Item Fracionado</label>' +
                    '</div>' +
                    (it.fracionado ?
                        // A unidade da fração é escolhida na lista (Ampola, Frasco, Unidade...) e é
                        // ela que define o prefixo do código de barras da etiqueta — um campo só,
                        // sem risco de fracionar em "ampola" e etiquetar como "frasco".
                        '<div class="d-flex gap-1">' +
                            '<input type="number" min="1" max="9999" step="1" class="form-control form-control-sm nfe-fracao-qtd" data-idx="' + idx + '" style="width:80px;" placeholder="Qtd." value="' + (it.fracaoQtd || '') + '" ' + (it.adicionado ? 'disabled' : '') + '>' +
                            '<select class="form-select form-select-sm nfe-fracao-unidade" data-idx="' + idx + '" style="width:110px;" ' + (it.adicionado ? 'disabled' : '') + '>' +
                                prefixosOptions(it.fracaoUnidade) +
                            '</select>' +
                        '</div>' +
                        // Etiqueta interna: a unidade solta que sai de uma embalagem aberta
                        // normalmente não tem código de barras próprio do fabricante — marcando
                        // aqui, cada unidade ganha um código interno (Code 128) pra Saída.
                        '<div class="d-flex align-items-center gap-1 mt-1">' +
                            '<input type="checkbox" class="form-check-input nfe-gerar-unidades" data-idx="' + idx + '" id="nfeGerUn' + idx + '" ' + (it.gerarUnidades ? 'checked' : '') + ' ' + (it.adicionado ? 'disabled' : '') + '>' +
                            '<label for="nfeGerUn' + idx + '" class="form-check-label small mb-0">Gerar códigos de barras</label>' +
                        '</div>' +
                        (it.gerarUnidades ?
                            '<input type="number" min="1" max="9999" step="1" class="form-control form-control-sm nfe-unidades-qtd mt-1" data-idx="' + idx + '" style="width:110px;" placeholder="Todas" value="' + (it.unidadesQtd || '') + '" ' + (it.adicionado ? 'disabled' : '') + '>'
                        : '')
                    : '');

                // A nota nem sempre traz lote/validade pro item (grupo <rastro> é opcional e varia
                // por produto, principalmente em itens "Novo" sem cadastro prévio) — mas os dois são
                // obrigatórios pra dar entrada. Sem destacar isso na própria linha, "Adicionar"
                // falha com um alerta fácil de não notar (principalmente no "Adicionar todos", que
                // só soma quantos falharam) e o item nunca chega a ser cadastrado/lançado.
                var faltaLote = !it.adicionado && !(it.lote || '').trim();
                var faltaValidade = !it.adicionado && !it.validade;

                return '<tr' + ((faltaLote || faltaValidade) ? ' class="table-warning"' : '') + '>' +
                    '<td style="min-width:170px;">' + produtoCel + '</td>' +
                    '<td style="width:90px;"><input type="number" min="1" class="form-control form-control-sm nfe-quantidade" data-idx="' + idx + '" value="' + it.quantidade + '" ' + (it.adicionado ? 'disabled' : '') + '></td>' +
                    '<td style="width:120px;"><input type="text" class="form-control form-control-sm nfe-lote' + (faltaLote ? ' border-danger' : '') + '" data-idx="' + idx + '" placeholder="obrigatório" value="' + esc(it.lote) + '" ' + (it.adicionado ? 'disabled' : '') + '></td>' +
                    '<td style="width:100px;"><input type="text" inputmode="numeric" maxlength="5" placeholder="MM/AA" class="form-control form-control-sm mono nfe-validade' + (faltaValidade ? ' border-danger' : '') + '" data-idx="' + idx + '" value="' + anoMesParaValidade(it.validade) + '" ' + (it.adicionado ? 'disabled' : '') + '></td>' +
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
            nfeResumo.querySelectorAll('.nfe-codigo-barras').forEach(function (el) {
                el.addEventListener('input', function () {
                    nfeItens[parseInt(el.getAttribute('data-idx'), 10)].codigo_barras = el.value.trim();
                });
            });
            nfeResumo.querySelectorAll('.nfe-lote').forEach(function (el) {
                el.addEventListener('input', function () {
                    nfeItens[parseInt(el.getAttribute('data-idx'), 10)].lote = el.value;
                    // Tira o destaque de "obrigatório" assim que o operador preenche, sem precisar
                    // re-renderizar a tabela inteira (perderia o foco no meio da digitação).
                    el.classList.toggle('border-danger', !el.value.trim());
                });
            });
            nfeResumo.querySelectorAll('.nfe-validade').forEach(function (el) {
                el.addEventListener('input', function () {
                    aplicarMascaraValidade(el);
                    var anoMes = validadeParaAnoMes(el.value);
                    nfeItens[parseInt(el.getAttribute('data-idx'), 10)].validade = anoMes;
                    el.classList.toggle('border-danger', !anoMes);
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
                    var idx = parseInt(el.getAttribute('data-idx'), 10);
                    var it = nfeItens[idx];
                    it.fracaoQtd = parseInt(el.value, 10) || 0;
                    aplicarFracao(it);
                    // Atualiza só os campos afetados (quantidade e valor de venda) na mesma linha —
                    // re-renderizar a tabela inteira aqui destruiria e recriaria este próprio campo
                    // a cada tecla digitada, tirando o foco e deixando digitar só 1 dígito por vez.
                    var qtdInput = nfeResumo.querySelector('.nfe-quantidade[data-idx="' + idx + '"]');
                    if (qtdInput) qtdInput.value = it.quantidade;
                    var vendaInput = nfeResumo.querySelector('.nfe-valor-venda[data-idx="' + idx + '"]');
                    if (vendaInput) vendaInput.value = it.valor_venda;
                });
            });
            nfeResumo.querySelectorAll('.nfe-fracao-unidade').forEach(function (el) {
                el.addEventListener('change', function () {
                    nfeItens[parseInt(el.getAttribute('data-idx'), 10)].fracaoUnidade = el.value;
                });
            });
            nfeResumo.querySelectorAll('.nfe-gerar-unidades').forEach(function (el) {
                el.addEventListener('change', function () {
                    var it = nfeItens[parseInt(el.getAttribute('data-idx'), 10)];
                    it.gerarUnidades = el.checked;
                    renderNfeResumo(); // mostra/esconde a quantidade a etiquetar — muda a estrutura
                });
            });
            nfeResumo.querySelectorAll('.nfe-unidades-qtd').forEach(function (el) {
                el.addEventListener('input', function () {
                    nfeItens[parseInt(el.getAttribute('data-idx'), 10)].unidadesQtd = parseInt(el.value, 10) || 0;
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
                observacao += ' · Fracionado: embalagem com ' + it.fracaoQtd + ' ' + nomeUnidade(it.fracaoUnidade);
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
                valor_venda: valorVenda,
                // Item fracionado: valor_unitario (compra) fica com o preço da embalagem inteira,
                // não da fração — multiplicar por quantidade (já em unidades fracionadas) daria um
                // subtotal inflado. O subtotal desse item usa o valor de venda (esse sim já
                // fracionado) em vez do de compra — ver renderItens().
                fracionado: !!(it.fracionado && it.fracaoQtd > 0),
                // Geração das etiquetas com código interno (uma por unidade física).
                gerar_unidades: !!(it.fracionado && it.gerarUnidades),
                unidade_prefixo: it.fracaoUnidade || 'AMP',
                unidades_qtd: (it.unidadesQtd && it.unidadesQtd > 0) ? Math.min(it.unidadesQtd, quantidade) : quantidade
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
                            // Guarda o prefixo do tipo de unidade (AMP, FRS, UNI...): é ele que
                            // nomeia a fração na observação e define o código da etiqueta.
                            fracaoUnidade: 'AMP',
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
            // é isso que é "consumido"/repassado na retirada. Item fracionado é exceção mesmo na
            // Entrada: valor_unitario (compra) é o preço da embalagem inteira, não da fração — com
            // a quantidade já em unidades fracionadas, compra × quantidade daria um total inflado.
            // Usa o valor de venda (esse sim já fracionado por unidade) pro subtotal desses itens.
            var usarVenda = tab === 'saida' || item.fracionado;
            var subtotal = usarVenda
                ? (item.valor_venda || 0) * item.quantidade
                : (item.valor_unitario || 0) * item.quantidade;
            var linhaValores;
            if (tab === 'saida') {
                linhaValores = formatarMoeda(item.valor_venda) + ' / un. · Subtotal: ' + formatarMoeda(subtotal);
            } else if (item.fracionado) {
                linhaValores = 'Compra (embalagem): ' + formatarMoeda(item.valor_unitario) +
                    (item.valor_venda ? ' · Venda: ' + formatarMoeda(item.valor_venda) + ' / un.' : '') +
                    ' · Subtotal: ' + formatarMoeda(subtotal);
            } else {
                linhaValores = 'Compra: ' + formatarMoeda(item.valor_unitario) + ' / un.' +
                    (item.valor_venda ? ' · Venda: ' + formatarMoeda(item.valor_venda) + ' / un.' : '') +
                    ' · Subtotal: ' + formatarMoeda(subtotal);
            }
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
                        (item.unidade_codigo
                            ? '<div class="entity-sub mono"><span class="badge bg-dark">Unidade</span> ' + esc(item.unidade_codigo) + '</div>'
                            : '') +
                        (item.gerar_unidades
                            ? '<div class="entity-sub"><span class="badge bg-dark">Etiquetas</span> ' + esc(item.unidades_qtd) + ' código(s) ' + esc(item.unidade_prefixo) + ' serão gerados</div>'
                            : '') +
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

        // Saída lida por código interno: a baixa é de UMA unidade física específica — não tem
        // escolha de lote nem quantidade, tudo já vem determinado pelo código da etiqueta.
        if (itemAtual.tipo === 'unidade') {
            var u = itemAtual.dados;
            if (!u.disponivel) { alert('A unidade ' + u.codigo_interno + ' não está disponível para saída.'); return; }
            var jaNaLista = itens.some(function (i) { return i.unidade_id === u.id; });
            if (jaNaLista) { alert('A unidade ' + u.codigo_interno + ' já está na lista desta saída.'); return; }

            itens.push({
                tipo_item: u.tipo_item,
                item_id: u.item_id,
                unidade_id: u.id,
                unidade_codigo: u.codigo_interno,
                codigo_barras: u.codigo_interno,
                produto: u.produto,
                laboratorio: u.origem,
                apresentacao: u.apresentacao,
                quantidade: 1,
                observacao: observacaoInput.value.trim(),
                lote_id: u.lote_id,
                lote: u.lote,
                validadeBr: u.validade_br,
                valor_unitario: u.valor_unitario,
                valor_venda: u.valor_venda
            });
            renderItens();

            itemAtual = null;
            codigoInput.value = '';
            observacaoInput.value = '';
            scanResult.innerHTML = '';
            campos.style.display = 'none';
            codigoInput.focus();
            return;
        }

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
            if (!lote) { alert('Informe o lote.'); return; }
            var validade = validadeParaAnoMes(validadeInput.value);
            if (!validade) { alert('Informe a validade no formato MM/AA.'); return; }
            novoItem.lote = lote;
            novoItem.validade = validade;
            novoItem.validadeBr = formatarDataBr(validade);
            if (quantidadeMinimaInput && quantidadeMinimaInput.value.trim() !== '') {
                novoItem.estoque_minimo = parseInt(quantidadeMinimaInput.value, 10);
            }
            novoItem.valor_unitario = parseFloat(valorUnitarioInput.value);
            novoItem.valor_venda = parseFloat(valorVendaInput.value) || 0;

            // Fracionamento na entrada manual: mesma regra do fracionado da NFe — a quantidade
            // digitada (embalagens) vira quantidade de unidades soltas e o valor de venda é
            // rateado entre elas; o valor de compra fica fixo, referente à embalagem inteira.
            if (fracionadoCheck && fracionadoCheck.checked) {
                var fracaoQtd = parseInt(fracaoQtdInput.value, 10);
                if (!fracaoQtd || fracaoQtd < 1) { alert('Informe quantas unidades vêm na embalagem.'); return; }

                var fracaoPrefixo = fracaoUnidadeInput.value;
                novoItem.quantidade = quantidade * fracaoQtd;
                novoItem.valor_venda = Math.round(((novoItem.valor_venda / fracaoQtd) + Number.EPSILON) * 100) / 100;
                novoItem.fracionado = true;
                novoItem.observacao = (observacao ? observacao + ' · ' : '') +
                    'Fracionado: embalagem com ' + fracaoQtd + ' ' + nomeUnidade(fracaoPrefixo);

                if (gerarUnidadesCheck.checked) {
                    var etiquetas = parseInt(unidadesQtdInput.value, 10);
                    // O tipo do código de barras é a própria unidade da fração escolhida.
                    novoItem.gerar_unidades = true;
                    novoItem.unidade_prefixo = fracaoPrefixo;
                    novoItem.unidades_qtd = (etiquetas && etiquetas > 0)
                        ? Math.min(etiquetas, novoItem.quantidade)
                        : novoItem.quantidade;
                }
            }
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
        if (fracionadoCheck) {
            fracionadoCheck.checked = false;
            gerarUnidadesCheck.checked = false;
            fracionadoCamposWrap.style.display = 'none';
            unidadesCamposWrap.style.display = 'none';
            fracaoQtdInput.value = '';
            fracaoUnidadeInput.selectedIndex = 0;
            unidadesQtdInput.value = '';
        }
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
