<?php
requireAdmin();
$db = getDB();
$formError = '';
$formSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrfVerify();

    if ($_POST['action'] === 'salvar') {
        $id = (int)($_POST['id'] ?? 0);
        $modelo = [
            'nome' => trim($_POST['nome'] ?? ''),
            'fabricante' => trim($_POST['fabricante'] ?? ''),
            'codigo_modelo' => trim($_POST['codigo_modelo'] ?? ''),
            'descricao' => trim($_POST['descricao'] ?? ''),
            'material' => trim($_POST['material'] ?? ''),
            'tipo' => array_key_exists($_POST['tipo'] ?? '', ETIQUETA_TIPOS) ? $_POST['tipo'] : 'rolo',
            'impressao_tipo' => array_key_exists($_POST['impressao_tipo'] ?? '', ETIQUETA_IMPRESSAO_TIPOS) ? $_POST['impressao_tipo'] : 'termica_direta',
            'orientacao' => array_key_exists($_POST['orientacao'] ?? '', ETIQUETA_ORIENTACOES) ? $_POST['orientacao'] : 'retrato',
            'rotacao' => array_key_exists((int)($_POST['rotacao'] ?? 0), ETIQUETA_ROTACOES) ? (int)$_POST['rotacao'] : 0,
            'linhas' => max(1, (int)($_POST['linhas'] ?? 1)),
            'colunas' => max(1, (int)($_POST['colunas'] ?? 1)),
            'dpi' => max(1, (int)($_POST['dpi'] ?? 203)),
            'escala' => max(0.5, min(2, (float)($_POST['escala'] ?? 1))),
            'ativo' => isset($_POST['ativo']) ? 1 : 0,
            'padrao' => isset($_POST['padrao']) ? 1 : 0,
        ];
        foreach (['largura_mm', 'altura_mm', 'largura_folha_mm', 'altura_folha_mm', 'margem_superior_mm',
                  'margem_inferior_mm', 'margem_esquerda_mm', 'margem_direita_mm', 'espacamento_horizontal_mm',
                  'espacamento_vertical_mm', 'gap_mm', 'ajuste_x_mm', 'ajuste_y_mm'] as $campo) {
            $modelo[$campo] = (float)str_replace(',', '.', (string)($_POST[$campo] ?? 0));
        }

        // Elementos chegam como lista; linhas sem tipo (template do "adicionar") são descartadas.
        $elementos = [];
        foreach ($_POST['el'] ?? [] as $linha) {
            if (empty($linha['tipo'])) continue;
            $elementos[] = [
                'tipo' => $linha['tipo'],
                'campo' => $linha['campo'] ?? '',
                'texto_fixo' => $linha['texto_fixo'] ?? '',
                'x_mm' => (float)str_replace(',', '.', (string)($linha['x_mm'] ?? 0)),
                'y_mm' => (float)str_replace(',', '.', (string)($linha['y_mm'] ?? 0)),
                'largura_mm' => (float)str_replace(',', '.', (string)($linha['largura_mm'] ?? 0)),
                'altura_mm' => (float)str_replace(',', '.', (string)($linha['altura_mm'] ?? 0)),
                'fonte' => $linha['fonte'] ?? 'Arial',
                'tamanho_fonte' => (float)str_replace(',', '.', (string)($linha['tamanho_fonte'] ?? 6)),
                'negrito' => !empty($linha['negrito']) ? 1 : 0,
                'alinhamento' => $linha['alinhamento'] ?? 'left',
                'rotacao' => (int)($linha['rotacao'] ?? 0),
                'mostrar_valor' => !empty($linha['mostrar_valor']) ? 1 : 0,
            ];
        }

        $erros = etiquetaValidarModelo($modelo);
        if ($modelo['nome'] === '') { $erros[] = 'Informe o nome do modelo.'; }

        if ($erros) {
            $formError = implode('<br>', array_map('htmlspecialchars', $erros));
        } else {
            try {
                $db->beginTransaction();
                if ($modelo['padrao']) {
                    $db->exec("UPDATE etiqueta_modelos SET padrao = 0"); // padrão é um só
                }
                if ($id > 0) {
                    $sets = [];
                    $params = [':id' => $id];
                    foreach ($modelo as $campo => $valor) {
                        $sets[] = "{$campo} = :{$campo}";
                        $params[':' . $campo] = $valor;
                    }
                    $db->prepare("UPDATE etiqueta_modelos SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
                    if ($elementos) { etiquetaSalvarElementos($db, $id, $elementos); }
                    registrarLog('Etiquetas', 'Modelo de etiqueta editado', "modelo: {$modelo['nome']}");
                } else {
                    $id = etiquetaCriarModelo($db, $modelo, $elementos ?: null);
                    registrarLog('Etiquetas', 'Modelo de etiqueta criado', "modelo: {$modelo['nome']} ({$modelo['largura_mm']}x{$modelo['altura_mm']} mm)");
                }
                $db->commit();
                header('Location: index.php?page=etiqueta_modelos&edit=' . $id . '&ok=1');
                exit;
            } catch (PDOException $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                $formError = 'Erro ao salvar o modelo: ' . htmlspecialchars($e->getMessage());
            }
        }
    } elseif ($_POST['action'] === 'duplicar') {
        // Duplicar é o caminho mais rápido pra criar uma variação (mesma disposição, outro tamanho).
        $origem = etiquetaModelo($db, (int)($_POST['id'] ?? 0));
        if ($origem) {
            $novo = $origem;
            unset($novo['id']);
            $novo['nome'] = $origem['nome'] . ' (cópia)';
            $novo['padrao'] = 0;
            $novoId = etiquetaCriarModelo($db, $novo, etiquetaElementos($db, (int)$origem['id']));
            registrarLog('Etiquetas', 'Modelo de etiqueta duplicado', "origem: {$origem['nome']}");
            header('Location: index.php?page=etiqueta_modelos&edit=' . $novoId);
            exit;
        }
    } elseif ($_POST['action'] === 'excluir') {
        $id = (int)($_POST['id'] ?? 0);
        $modelo = etiquetaModelo($db, $id);
        if ($modelo) {
            $db->prepare("DELETE FROM etiqueta_elementos WHERE modelo_id = :m")->execute([':m' => $id]);
            $db->prepare("DELETE FROM etiqueta_modelos WHERE id = :id")->execute([':id' => $id]);
            $db->prepare("UPDATE local_users SET etiqueta_modelo_id = NULL WHERE etiqueta_modelo_id = :id")->execute([':id' => $id]);
            registrarLog('Etiquetas', 'Modelo de etiqueta excluído', "modelo: {$modelo['nome']}");
        }
        header('Location: index.php?page=etiqueta_modelos');
        exit;
    }
}

if (isset($_GET['ok'])) { $formSuccess = 'Modelo salvo.'; }

$editando = null;
$elementosEdicao = [];
if (isset($_GET['edit'])) {
    $editando = etiquetaModelo($db, (int)$_GET['edit']);
    if ($editando) { $elementosEdicao = etiquetaElementos($db, (int)$editando['id']); }
}
if (isset($_GET['novo'])) {
    // Modelo em branco com valores iniciais razoáveis pro assistente.
    $editando = ['id' => 0, 'nome' => '', 'fabricante' => 'Genérico', 'codigo_modelo' => '', 'descricao' => '',
        'material' => '', 'tipo' => 'rolo', 'largura_mm' => 50, 'altura_mm' => 25, 'largura_folha_mm' => 210,
        'altura_folha_mm' => 297, 'linhas' => 1, 'colunas' => 1, 'margem_superior_mm' => 0, 'margem_inferior_mm' => 0,
        'margem_esquerda_mm' => 0, 'margem_direita_mm' => 0, 'espacamento_horizontal_mm' => 0,
        'espacamento_vertical_mm' => 0, 'gap_mm' => 2, 'dpi' => 203, 'orientacao' => 'retrato', 'rotacao' => 0,
        'impressao_tipo' => 'termica_direta', 'ajuste_x_mm' => 0, 'ajuste_y_mm' => 0, 'escala' => 1,
        'ativo' => 1, 'padrao' => 0];
    $elementosEdicao = etiquetaElementosPadrao(50, 25);
}

$modelos = $db->query("SELECT * FROM etiqueta_modelos ORDER BY tipo ASC, largura_mm ASC, altura_mm ASC")->fetchAll();
$avisosElementos = ($editando && $elementosEdicao) ? etiquetaValidarElementos($editando, $elementosEdicao) : [];
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Modelos de Etiqueta</h1>
        <div class="page-sub">Formatos configuráveis em milímetros — rolo térmico ou folha A4, com os elementos posicionados livremente</div>
    </div>
    <a href="index.php?page=etiqueta_modelos&novo=1" class="btn btn-outline-success"><i class="bi bi-plus-lg"></i> Novo Modelo</a>
</div>

<?php if ($formError): ?><div class="alert alert-danger"><?= $formError ?></div><?php endif; ?>
<?php if ($formSuccess): ?><div class="alert alert-success"><?= htmlspecialchars($formSuccess) ?></div><?php endif; ?>

<?php if ($editando): ?>
<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="salvar">
            <input type="hidden" name="id" value="<?= (int)$editando['id'] ?>">

            <div class="card mb-3">
                <div class="card-header"><?= $editando['id'] ? 'Editar Modelo' : 'Novo Modelo' ?></div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-md-5">
                            <label class="form-label">Nome do modelo</label>
                            <input type="text" name="nome" class="form-control" required value="<?= htmlspecialchars($editando['nome']) ?>" placeholder="Ex.: Medicamento - Ampola 50x25">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Fabricante</label>
                            <input type="text" name="fabricante" class="form-control" value="<?= htmlspecialchars($editando['fabricante']) ?>" placeholder="Genérico">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Código</label>
                            <input type="text" name="codigo_modelo" class="form-control" value="<?= htmlspecialchars($editando['codigo_modelo']) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Material</label>
                            <input type="text" name="material" class="form-control" value="<?= htmlspecialchars($editando['material']) ?>" placeholder="Couché">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Descrição</label>
                            <input type="text" name="descricao" class="form-control" value="<?= htmlspecialchars($editando['descricao']) ?>" placeholder="Onde este modelo é usado">
                        </div>
                    </div>

                    <hr>
                    <div class="entity-field-label mb-2">Tipo e impressão</div>
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label class="form-label">Tipo</label>
                            <select name="tipo" id="tipoSelect" class="form-select">
                                <?php foreach (ETIQUETA_TIPOS as $val => $lbl): ?>
                                    <option value="<?= $val ?>" <?= $editando['tipo'] === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Impressão</label>
                            <select name="impressao_tipo" class="form-select">
                                <?php foreach (ETIQUETA_IMPRESSAO_TIPOS as $val => $lbl): ?>
                                    <option value="<?= $val ?>" <?= $editando['impressao_tipo'] === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">DPI</label>
                            <select name="dpi" class="form-select">
                                <?php foreach ([203, 300, 600] as $dpi): ?>
                                    <option value="<?= $dpi ?>" <?= (int)$editando['dpi'] === $dpi ? 'selected' : '' ?>><?= $dpi ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Orientação</label>
                            <select name="orientacao" class="form-select">
                                <?php foreach (ETIQUETA_ORIENTACOES as $val => $lbl): ?>
                                    <option value="<?= $val ?>" <?= $editando['orientacao'] === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Rotação</label>
                            <select name="rotacao" class="form-select">
                                <?php foreach (ETIQUETA_ROTACOES as $val => $lbl): ?>
                                    <option value="<?= $val ?>" <?= (int)$editando['rotacao'] === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <hr>
                    <div class="entity-field-label mb-2">Dimensões da etiqueta (mm)</div>
                    <div class="row g-2">
                        <div class="col-6 col-md-3">
                            <label class="form-label">Largura</label>
                            <input type="number" step="0.1" min="1" name="largura_mm" class="form-control" value="<?= (float)$editando['largura_mm'] ?>">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label">Altura</label>
                            <input type="number" step="0.1" min="1" name="altura_mm" class="form-control" value="<?= (float)$editando['altura_mm'] ?>">
                        </div>
                        <div class="col-6 col-md-3 campo-rolo">
                            <label class="form-label">Gap entre etiquetas</label>
                            <input type="number" step="0.1" min="0" name="gap_mm" class="form-control" value="<?= (float)$editando['gap_mm'] ?>">
                        </div>
                    </div>

                    <div class="campo-folha">
                        <hr>
                        <div class="entity-field-label mb-2">Folha e grade (mm)</div>
                        <div class="row g-2">
                            <div class="col-6 col-md-3">
                                <label class="form-label">Largura da folha</label>
                                <input type="number" step="0.1" min="0" name="largura_folha_mm" class="form-control" value="<?= (float)$editando['largura_folha_mm'] ?>">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label">Altura da folha</label>
                                <input type="number" step="0.1" min="0" name="altura_folha_mm" class="form-control" value="<?= (float)$editando['altura_folha_mm'] ?>">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label">Colunas</label>
                                <input type="number" min="1" name="colunas" class="form-control" value="<?= (int)$editando['colunas'] ?>">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label">Linhas</label>
                                <input type="number" min="1" name="linhas" class="form-control" value="<?= (int)$editando['linhas'] ?>">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label">Margem superior</label>
                                <input type="number" step="0.1" min="0" name="margem_superior_mm" class="form-control" value="<?= (float)$editando['margem_superior_mm'] ?>">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label">Margem inferior</label>
                                <input type="number" step="0.1" min="0" name="margem_inferior_mm" class="form-control" value="<?= (float)$editando['margem_inferior_mm'] ?>">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label">Margem esquerda</label>
                                <input type="number" step="0.1" min="0" name="margem_esquerda_mm" class="form-control" value="<?= (float)$editando['margem_esquerda_mm'] ?>">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label">Margem direita</label>
                                <input type="number" step="0.1" min="0" name="margem_direita_mm" class="form-control" value="<?= (float)$editando['margem_direita_mm'] ?>">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label">Espaçamento horizontal</label>
                                <input type="number" step="0.1" min="0" name="espacamento_horizontal_mm" class="form-control" value="<?= (float)$editando['espacamento_horizontal_mm'] ?>">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label">Espaçamento vertical</label>
                                <input type="number" step="0.1" min="0" name="espacamento_vertical_mm" class="form-control" value="<?= (float)$editando['espacamento_vertical_mm'] ?>">
                            </div>
                        </div>
                    </div>

                    <hr>
                    <div class="entity-field-label mb-2">Calibração da impressora</div>
                    <div class="form-text mb-2">Duas impressoras do mesmo modelo podem imprimir com pequeno deslocamento. Ajuste aqui sem mexer no tamanho da etiqueta.</div>
                    <div class="row g-2">
                        <div class="col-6 col-md-3">
                            <label class="form-label">Deslocamento X (mm)</label>
                            <input type="number" step="0.1" name="ajuste_x_mm" class="form-control" value="<?= (float)$editando['ajuste_x_mm'] ?>">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label">Deslocamento Y (mm)</label>
                            <input type="number" step="0.1" name="ajuste_y_mm" class="form-control" value="<?= (float)$editando['ajuste_y_mm'] ?>">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label">Escala</label>
                            <input type="number" step="0.01" min="0.5" max="2" name="escala" class="form-control" value="<?= (float)$editando['escala'] ?>">
                        </div>
                        <div class="col-6 col-md-3 d-flex align-items-end">
                            <div>
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="ativoCheck" name="ativo" <?= (int)$editando['ativo'] === 1 ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="ativoCheck">Ativo</label>
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="padraoCheck" name="padrao" <?= (int)$editando['padrao'] === 1 ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="padraoCheck">Padrão do sistema</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Conteúdo da etiqueta</span>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addElementoBtn"><i class="bi bi-plus-lg"></i> Adicionar elemento</button>
                </div>
                <div class="card-body">
                    <div class="form-text mb-2">Posição e tamanho em milímetros, contados a partir do canto superior esquerdo da etiqueta.</div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle" id="elementosTabela">
                            <thead class="table-dark">
                                <tr>
                                    <th>Tipo</th><th>Campo / texto</th>
                                    <th style="width:70px;">X</th><th style="width:70px;">Y</th>
                                    <th style="width:70px;">Larg.</th><th style="width:70px;">Alt.</th>
                                    <th style="width:70px;">Fonte</th><th style="width:56px;">N</th>
                                    <th style="width:96px;">Alinh.</th><th style="width:80px;">Rot.</th>
                                    <th style="width:56px;">Txt</th><th style="width:40px;"></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <button class="btn btn-outline-success"><i class="bi bi-check-lg"></i> Salvar modelo</button>
            <a href="index.php?page=etiqueta_modelos" class="btn btn-outline-secondary">Cancelar</a>
        </form>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">Pré-visualização</div>
            <div class="card-body">
                <?php if ($editando['id']): ?>
                    <div class="form-text mb-2">
                        Tamanho real: <?= (float)$editando['largura_mm'] ?> × <?= (float)$editando['altura_mm'] ?> mm
                        <?php if ($editando['tipo'] === 'folha'): ?>
                            · <?= etiquetasPorFolha($editando) ?> por folha (<?= (int)$editando['colunas'] ?>×<?= (int)$editando['linhas'] ?>)
                        <?php endif; ?>
                    </div>
                    <div class="etq-preview-wrap">
                        <?= etiquetaRenderizar($editando, $elementosEdicao, etiquetaDadosExemplo(), true) ?>
                    </div>

                    <?php if ($avisosElementos): ?>
                        <div class="alert alert-warning mt-3 mb-0">
                            <strong>Atenção:</strong>
                            <ul class="mb-0 ps-3">
                                <?php foreach ($avisosElementos as $aviso): ?><li><?= htmlspecialchars($aviso) ?></li><?php endforeach; ?>
                            </ul>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-success mt-3 mb-0">Todos os elementos cabem na etiqueta e o código de barras está legível.</div>
                    <?php endif; ?>

                    <a class="btn btn-outline-primary w-100 mt-3" target="_blank"
                       href="index.php?page=etiquetas_imprimir&modelo=<?= (int)$editando['id'] ?>&teste=1">
                        <i class="bi bi-printer"></i> Imprimir etiqueta de teste
                    </a>
                <?php else: ?>
                    <p class="text-muted small mb-0">Salve o modelo para ver a prévia e imprimir a etiqueta de teste.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="table-responsive">
    <table class="table table-striped table-hover bg-white align-middle">
        <thead class="table-dark">
            <tr><th>Modelo</th><th>Tipo</th><th>Dimensão</th><th>Impressão</th><th class="text-center">Por folha</th><th class="text-center">Status</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($modelos as $m): ?>
                <tr>
                    <td>
                        <?= htmlspecialchars($m['nome']) ?>
                        <?php if ((int)$m['padrao'] === 1): ?><span class="badge bg-info text-dark">Padrão</span><?php endif; ?>
                        <div class="entity-sub"><?= htmlspecialchars(trim($m['fabricante'] . ' ' . $m['codigo_modelo'])) ?></div>
                    </td>
                    <td><?= etiquetaTipoLabel($m['tipo']) ?></td>
                    <td class="mono"><?= (float)$m['largura_mm'] ?>×<?= (float)$m['altura_mm'] ?> mm</td>
                    <td><?= etiquetaImpressaoLabel($m['impressao_tipo']) ?> · <?= (int)$m['dpi'] ?> DPI</td>
                    <td class="text-center"><?= $m['tipo'] === 'folha' ? etiquetasPorFolha($m) : '—' ?></td>
                    <td class="text-center">
                        <span class="badge <?= (int)$m['ativo'] === 1 ? 'bg-success' : 'bg-secondary' ?>"><?= (int)$m['ativo'] === 1 ? 'Ativo' : 'Inativo' ?></span>
                    </td>
                    <td class="text-nowrap">
                        <a href="index.php?page=etiqueta_modelos&edit=<?= (int)$m['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form method="POST" class="d-inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="duplicar">
                            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                            <button class="btn btn-sm btn-outline-secondary" title="Duplicar"><i class="bi bi-files"></i></button>
                        </form>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Excluir o modelo <?= htmlspecialchars($m['nome'], ENT_QUOTES) ?>?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="excluir">
                            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<style>
.etq-preview-wrap { background: #f4f6fa; padding: 10px; border-radius: 8px; display: flex; justify-content: center; }
.etq-label { position: relative; background: #fff; overflow: hidden; color: #000; }
</style>

<?php if ($editando): ?>
<script>
(function () {
    // Os campos de folha só fazem sentido no tipo "folha"; em rolo, o que importa é o gap.
    var tipoSelect = document.getElementById('tipoSelect');
    function ajustarTipo() {
        var ehFolha = tipoSelect.value === 'folha';
        document.querySelectorAll('.campo-folha').forEach(function (el) { el.style.display = ehFolha ? '' : 'none'; });
        document.querySelectorAll('.campo-rolo').forEach(function (el) { el.style.display = ehFolha ? 'none' : ''; });
    }
    tipoSelect.addEventListener('change', ajustarTipo);
    ajustarTipo();

    var tipos = <?= json_encode(ETIQUETA_ELEMENTO_TIPOS, JSON_UNESCAPED_UNICODE) ?>;
    var campos = <?= json_encode(ETIQUETA_CAMPOS, JSON_UNESCAPED_UNICODE) ?>;
    var alinhamentos = <?= json_encode(ETIQUETA_ALINHAMENTOS, JSON_UNESCAPED_UNICODE) ?>;
    var elementos = <?= json_encode(array_map(function ($e) {
        return ['tipo' => $e['tipo'], 'campo' => $e['campo'], 'texto_fixo' => $e['texto_fixo'],
                'x_mm' => (float)$e['x_mm'], 'y_mm' => (float)$e['y_mm'], 'largura_mm' => (float)$e['largura_mm'],
                'altura_mm' => (float)$e['altura_mm'], 'fonte' => $e['fonte'], 'tamanho_fonte' => (float)$e['tamanho_fonte'],
                'negrito' => (int)$e['negrito'], 'alinhamento' => $e['alinhamento'], 'rotacao' => (int)$e['rotacao'],
                'mostrar_valor' => (int)$e['mostrar_valor']];
    }, $elementosEdicao), JSON_UNESCAPED_UNICODE) ?>;

    var tbody = document.querySelector('#elementosTabela tbody');

    function opcoes(mapa, selecionado) {
        return Object.keys(mapa).map(function (k) {
            return '<option value="' + k + '"' + (String(k) === String(selecionado) ? ' selected' : '') + '>' + mapa[k] + '</option>';
        }).join('');
    }

    function num(nome, i, valor, passo) {
        return '<input type="number" step="' + passo + '" class="form-control form-control-sm" name="el[' + i + '][' + nome + ']" value="' + valor + '">';
    }

    function linha(el, i) {
        return '<tr>' +
            '<td><select class="form-select form-select-sm el-tipo" name="el[' + i + '][tipo]">' + opcoes(tipos, el.tipo) + '</select></td>' +
            '<td>' +
                '<select class="form-select form-select-sm el-campo" name="el[' + i + '][campo]">' +
                    '<option value="">—</option>' + opcoes(campos, el.campo) +
                '</select>' +
                '<input type="text" class="form-control form-control-sm mt-1 el-texto" name="el[' + i + '][texto_fixo]" placeholder="Texto fixo" value="' + (el.texto_fixo || '').replace(/"/g, '&quot;') + '">' +
            '</td>' +
            '<td>' + num('x_mm', i, el.x_mm, '0.1') + '</td>' +
            '<td>' + num('y_mm', i, el.y_mm, '0.1') + '</td>' +
            '<td>' + num('largura_mm', i, el.largura_mm, '0.1') + '</td>' +
            '<td>' + num('altura_mm', i, el.altura_mm, '0.1') + '</td>' +
            '<td>' + num('tamanho_fonte', i, el.tamanho_fonte, '0.5') +
                '<input type="hidden" name="el[' + i + '][fonte]" value="' + (el.fonte || 'Arial') + '"></td>' +
            '<td class="text-center"><input type="checkbox" class="form-check-input" name="el[' + i + '][negrito]" ' + (el.negrito ? 'checked' : '') + '></td>' +
            '<td><select class="form-select form-select-sm" name="el[' + i + '][alinhamento]">' + opcoes(alinhamentos, el.alinhamento) + '</select></td>' +
            '<td><select class="form-select form-select-sm" name="el[' + i + '][rotacao]">' +
                [0, 90, 180, 270].map(function (r) { return '<option value="' + r + '"' + (Number(el.rotacao) === r ? ' selected' : '') + '>' + r + '°</option>'; }).join('') +
            '</select></td>' +
            '<td class="text-center"><input type="checkbox" class="form-check-input" name="el[' + i + '][mostrar_valor]" ' + (el.mostrar_valor ? 'checked' : '') + ' title="Mostrar o código em texto abaixo da barra"></td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger btn-remover-el"><i class="bi bi-x-lg"></i></button></td>' +
        '</tr>';
    }

    function render() {
        tbody.innerHTML = elementos.map(linha).join('');
        tbody.querySelectorAll('.btn-remover-el').forEach(function (btn, idx) {
            btn.addEventListener('click', function () { elementos.splice(idx, 1); render(); });
        });
    }

    document.getElementById('addElementoBtn').addEventListener('click', function () {
        elementos.push({ tipo: 'campo', campo: 'produto', texto_fixo: '', x_mm: 2, y_mm: 2,
            largura_mm: 40, altura_mm: 4, fonte: 'Arial', tamanho_fonte: 7, negrito: 0,
            alinhamento: 'left', rotacao: 0, mostrar_valor: 0 });
        render();
    });

    render();
})();
</script>
<?php endif; ?>
