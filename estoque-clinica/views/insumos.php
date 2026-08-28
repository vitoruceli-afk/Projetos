<?php
requireAdmin();
$db = getDB();
$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrfVerify();

    if ($_POST['action'] === 'add' || $_POST['action'] === 'update') {
        $nome = trim($_POST['nome'] ?? '');
        $laboratorioId = (int)($_POST['laboratorio_id'] ?? 0);
        $composicao = trim($_POST['composicao'] ?? '');
        $codigoBarras = trim($_POST['codigo_barras'] ?? '');
        $unidade = trim($_POST['unidade_medida'] ?? '') ?: 'unidade';
        $observacoes = trim($_POST['observacoes'] ?? '');
        $loteInicial = trim($_POST['lote_inicial'] ?? '');
        $validadeInicial = trim($_POST['validade_inicial'] ?? '');
        $quantidadeInicial = (int)($_POST['quantidade_inicial'] ?? 0);

        if ($nome === '' || $laboratorioId <= 0 || $codigoBarras === '') {
            $formError = 'Nome, laboratório e código de barras são obrigatórios.';
        } elseif ($loteInicial !== '' && ($validadeInicial === '' || $quantidadeInicial <= 0)) {
            $formError = 'Para registrar o lote inicial, informe validade e quantidade.';
        } else {
            try {
                $foto = salvarFotoInsumo($_FILES['foto'] ?? []);

                if ($_POST['action'] === 'add') {
                    $stmt = $db->prepare("INSERT INTO insumos (nome, laboratorio_id, composicao, codigo_barras, unidade_medida, observacoes, foto)
                        VALUES (:n, :lab, :comp, :cod, :un, :obs, :foto)");
                    $stmt->bindValue(':foto', $foto);
                    $stmt->bindValue(':n', $nome);
                    $stmt->bindValue(':lab', $laboratorioId, PDO::PARAM_INT);
                    $stmt->bindValue(':comp', $composicao);
                    $stmt->bindValue(':cod', $codigoBarras);
                    $stmt->bindValue(':un', $unidade);
                    $stmt->bindValue(':obs', $observacoes);
                    $stmt->execute();
                    $insumoId = (int)$db->lastInsertId();

                    if ($loteInicial !== '') {
                        $insLote = $db->prepare("INSERT INTO insumo_lotes (insumo_id, lote, validade, quantidade) VALUES (:i, :l, :v, :q)");
                        $insLote->execute([':i' => $insumoId, ':l' => $loteInicial, ':v' => $validadeInicial, ':q' => $quantidadeInicial]);
                        $insMov = $db->prepare("INSERT INTO movimentacoes (insumo_id, lote_id, tipo, quantidade, usuario, observacao) VALUES (:i, :lo, 'entrada', :q, :u, 'Estoque inicial no cadastro')");
                        $insMov->execute([':i' => $insumoId, ':lo' => (int)$db->lastInsertId(), ':q' => $quantidadeInicial, ':u' => $_SESSION['user_logged_in']]);
                    }
                } else {
                    $id = (int)($_POST['id'] ?? 0);
                    if ($foto !== '') {
                        $old = $db->prepare("SELECT foto FROM insumos WHERE id = :id");
                        $old->bindValue(':id', $id, PDO::PARAM_INT);
                        $old->execute();
                        $oldFoto = $old->fetchColumn();
                        $stmt = $db->prepare("UPDATE insumos SET nome=:n, laboratorio_id=:lab, composicao=:comp, codigo_barras=:cod, unidade_medida=:un, observacoes=:obs, foto=:foto WHERE id=:id");
                        $stmt->bindValue(':foto', $foto);
                        if ($oldFoto) { @unlink(UPLOAD_DIR . '/' . $oldFoto); }
                    } else {
                        $stmt = $db->prepare("UPDATE insumos SET nome=:n, laboratorio_id=:lab, composicao=:comp, codigo_barras=:cod, unidade_medida=:un, observacoes=:obs WHERE id=:id");
                    }
                    $stmt->bindValue(':n', $nome);
                    $stmt->bindValue(':lab', $laboratorioId, PDO::PARAM_INT);
                    $stmt->bindValue(':comp', $composicao);
                    $stmt->bindValue(':cod', $codigoBarras);
                    $stmt->bindValue(':un', $unidade);
                    $stmt->bindValue(':obs', $observacoes);
                    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
                    $stmt->execute();
                }
                header("Location: index.php?page=insumos");
                exit;
            } catch (RuntimeException $e) {
                $formError = $e->getMessage();
            } catch (PDOException $e) {
                $formError = (strpos($e->getMessage(), 'Duplicate') !== false)
                    ? 'Já existe um insumo cadastrado com esse código de barras.'
                    : 'Erro ao salvar: ' . $e->getMessage();
            }
        }
    } elseif ($_POST['action'] === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("UPDATE insumos SET ativo = 1 - ativo WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        header("Location: index.php?page=insumos");
        exit;
    } elseif ($_POST['action'] === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT foto FROM insumos WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $foto = $stmt->fetchColumn();

        $db->prepare("DELETE FROM movimentacoes WHERE insumo_id = :id")->execute([':id' => $id]);
        $db->prepare("DELETE FROM insumo_lotes WHERE insumo_id = :id")->execute([':id' => $id]);
        $del = $db->prepare("DELETE FROM insumos WHERE id = :id");
        $del->bindValue(':id', $id, PDO::PARAM_INT);
        $del->execute();
        if ($foto) { @unlink(UPLOAD_DIR . '/' . $foto); }
        header("Location: index.php?page=insumos");
        exit;
    }
}

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM insumos WHERE id = :id");
    $stmt->bindValue(':id', (int)$_GET['edit'], PDO::PARAM_INT);
    $stmt->execute();
    $editing = $stmt->fetch();
}
$novoCodigo = trim($_GET['novo_codigo'] ?? '');

$labs = $db->query("SELECT id, nome FROM laboratorios ORDER BY nome ASC")->fetchAll();

$busca = trim($_GET['busca'] ?? '');
$sql = "SELECT i.*, l.nome AS laboratorio_nome FROM insumos i LEFT JOIN laboratorios l ON l.id = i.laboratorio_id WHERE 1=1";
$params = [];
if ($busca !== '') {
    $sql .= " AND (i.nome LIKE :b OR i.codigo_barras LIKE :b)";
    $params[':b'] = "%{$busca}%";
}
$sql .= " ORDER BY i.nome ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$insumos = $stmt->fetchAll();
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Insumos</h1>
        <div class="page-sub">Catálogo de insumos da clínica</div>
    </div>
</div>

<?php if ($formError): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($formError) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><?= $editing ? 'Editar Insumo' : 'Novo Insumo' ?></div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'add' ?>">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>

                    <div class="mb-2">
                        <label class="form-label">Nome do insumo</label>
                        <input type="text" name="nome" class="form-control" value="<?= htmlspecialchars($editing['nome'] ?? '') ?>" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Laboratório / Fabricante</label>
                        <select name="laboratorio_id" class="form-select" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($labs as $lab): ?>
                                <option value="<?= (int)$lab['id'] ?>" <?= (($editing['laboratorio_id'] ?? null) == $lab['id']) ? 'selected' : '' ?>><?= htmlspecialchars($lab['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($labs)): ?>
                            <div class="form-text">Nenhum laboratório cadastrado — <a href="index.php?page=laboratorios">cadastre um primeiro</a>.</div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Composição</label>
                        <textarea name="composicao" class="form-control" rows="2"><?= htmlspecialchars($editing['composicao'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Código de barras</label>
                        <input type="text" name="codigo_barras" class="form-control mono" value="<?= htmlspecialchars($editing['codigo_barras'] ?? $novoCodigo) ?>" placeholder="Leia com o leitor ou digite" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Unidade de medida</label>
                        <input type="text" name="unidade_medida" class="form-control" value="<?= htmlspecialchars($editing['unidade_medida'] ?? 'unidade') ?>" placeholder="unidade, caixa, ampola, ml...">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Foto do insumo</label>
                        <input type="file" name="foto" class="form-control" accept="image/*">
                        <?php if (!empty($editing['foto'])): ?>
                            <div class="form-text">Já existe uma foto salva; envie outra para substituir.</div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Observações</label>
                        <textarea name="observacoes" class="form-control" rows="2"><?= htmlspecialchars($editing['observacoes'] ?? '') ?></textarea>
                    </div>

                    <?php if (!$editing): ?>
                    <hr>
                    <div class="form-text mb-2">Opcional: registre o primeiro lote já na hora do cadastro (para novas entradas, use a tela <strong>Entrada / Saída</strong>).</div>
                    <div class="mb-2"><input type="text" name="lote_inicial" class="form-control" placeholder="Lote"></div>
                    <div class="row g-2 mb-2">
                        <div class="col-6"><input type="date" name="validade_inicial" class="form-control" placeholder="Validade"></div>
                        <div class="col-6"><input type="number" name="quantidade_inicial" class="form-control" min="1" placeholder="Quantidade"></div>
                    </div>
                    <?php endif; ?>

                    <button class="btn btn-outline-success w-100 mt-2"><?= $editing ? 'Salvar Alterações' : 'Salvar Insumo' ?></button>
                    <?php if ($editing): ?>
                        <a href="index.php?page=insumos" class="btn btn-outline-secondary w-100 mt-2">Cancelar Edição</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="entity-list-toolbar">
            <form method="GET" class="d-flex gap-2 flex-grow-1">
                <input type="hidden" name="page" value="insumos">
                <input type="text" name="busca" class="form-control" placeholder="Buscar por nome ou código de barras..." value="<?= htmlspecialchars($busca) ?>">
                <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
            </form>
        </div>

        <div class="entity-list">
            <?php if (empty($insumos)): ?>
                <div class="card"><div class="card-body text-center text-muted py-4">Nenhum insumo encontrado.</div></div>
            <?php endif; ?>
            <?php foreach ($insumos as $i): ?>
                <?php
                    $estoque = insumoEstoqueTotal($db, $i['id']);
                    $proxLote = insumoProximoLote($db, $i['id']);
                    $status = $proxLote ? statusVencimento($proxLote['validade']) : null;
                    $fotoUrl = insumoFotoUrl($i['foto']);
                ?>
                <div class="entity-card <?= $i['ativo'] ? '' : 'is-disabled' ?>">
                    <div class="entity-card-head">
                        <div class="entity-title-wrap">
                            <?php if ($fotoUrl): ?>
                                <img src="<?= htmlspecialchars($fotoUrl) ?>" class="entity-thumb" alt="">
                            <?php else: ?>
                                <div class="entity-thumb-placeholder"><i class="bi bi-image"></i></div>
                            <?php endif; ?>
                            <div>
                                <div class="entity-title"><?= htmlspecialchars($i['nome']) ?></div>
                                <div class="entity-sub"><?= htmlspecialchars($i['laboratorio_nome'] ?: '—') ?> · <span class="mono"><?= htmlspecialchars($i['codigo_barras']) ?></span></div>
                            </div>
                        </div>
                        <div class="entity-badges">
                            <?php if (!$i['ativo']): ?><span class="badge bg-secondary">Inativo</span><?php endif; ?>
                            <?php if ($status): ?><span class="badge <?= statusVencimentoBadgeClass($status) ?>"><?= statusVencimentoLabel($status) ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="entity-grid">
                        <div><div class="entity-field-label">Estoque total</div><div class="entity-field-value"><?= $estoque ?> <?= htmlspecialchars($i['unidade_medida']) ?></div></div>
                        <div><div class="entity-field-label">Próximo vencimento</div><div class="entity-field-value"><?= $proxLote ? date('d/m/Y', strtotime($proxLote['validade'])) : '—' ?></div></div>
                        <?php if (!empty($i['composicao'])): ?>
                            <div class="full"><div class="entity-field-label">Composição</div><div class="entity-field-value"><?= htmlspecialchars($i['composicao']) ?></div></div>
                        <?php endif; ?>
                    </div>
                    <div class="entity-actions">
                        <div class="entity-actions-buttons">
                            <a href="index.php?page=insumos&edit=<?= (int)$i['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i> Editar</a>
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary"><?= $i['ativo'] ? 'Desativar' : 'Ativar' ?></button>
                            </form>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Excluir este insumo e todos os seus lotes/movimentações?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
