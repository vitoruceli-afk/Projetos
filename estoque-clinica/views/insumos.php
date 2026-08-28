<?php
requireAdmin();
$db = getDB();
$formError = '';

const INSUMO_UNIDADES = ['unidade', 'caixa', 'pacote', 'frasco', 'rolo', 'par', 'kit', 'ml', 'litro', 'grama', 'kg'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrfVerify();

    if ($_POST['action'] === 'add' || $_POST['action'] === 'update') {
        $nomeComercial = trim($_POST['nome_comercial'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $marca = trim($_POST['marca'] ?? '');
        $categoria = trim($_POST['categoria'] ?? '');
        $codigoBarras = trim($_POST['codigo_barras'] ?? '');
        $quantidade = (int)($_POST['quantidade'] ?? 0);
        $estoqueMinimo = (int)($_POST['estoque_minimo'] ?? 0);
        $unidadeMedida = in_array($_POST['unidade_medida'] ?? '', INSUMO_UNIDADES, true) ? $_POST['unidade_medida'] : 'unidade';
        $lote = trim($_POST['lote'] ?? '');
        $validade = trim($_POST['validade'] ?? '');

        if ($nomeComercial === '') {
            $formError = 'Informe o nome comercial do insumo.';
        } elseif ($quantidade < 0 || $estoqueMinimo < 0) {
            $formError = 'Quantidade e estoque mínimo não podem ser negativos.';
        } else {
            $params = [
                ':n' => $nomeComercial, ':d' => $descricao, ':m' => $marca, ':c' => $categoria, ':eb' => $codigoBarras,
                ':q' => $quantidade, ':em' => $estoqueMinimo, ':u' => $unidadeMedida,
                ':l' => $lote, ':v' => $validade !== '' ? $validade : null,
            ];
            if ($_POST['action'] === 'add') {
                $stmt = $db->prepare("INSERT INTO insumos (nome_comercial, descricao, marca, categoria, codigo_barras, quantidade, estoque_minimo, unidade_medida, lote, validade)
                    VALUES (:n, :d, :m, :c, :eb, :q, :em, :u, :l, :v)");
                $stmt->execute($params);
                registrarLog('Insumos', 'Insumo criado', "nome: {$nomeComercial}, quantidade: {$quantidade} {$unidadeMedida}");
            } else {
                $id = (int)($_POST['id'] ?? 0);
                $params[':id'] = $id;
                $stmt = $db->prepare("UPDATE insumos SET nome_comercial=:n, descricao=:d, marca=:m, categoria=:c, codigo_barras=:eb,
                    quantidade=:q, estoque_minimo=:em, unidade_medida=:u, lote=:l, validade=:v WHERE id=:id");
                $stmt->execute($params);
                registrarLog('Insumos', 'Insumo editado', "nome: {$nomeComercial}, quantidade: {$quantidade} {$unidadeMedida}");
            }
            header("Location: index.php?page=insumos");
            exit;
        }
    } elseif ($_POST['action'] === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT nome_comercial FROM insumos WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $nome = $stmt->fetchColumn();
        if ($nome !== false) {
            $db->prepare("DELETE FROM insumos WHERE id = :id")->execute([':id' => $id]);
            registrarLog('Insumos', 'Insumo excluído', "nome: {$nome}");
        }
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

$categorias = $db->query("SELECT DISTINCT categoria FROM insumos WHERE categoria <> '' ORDER BY categoria ASC")->fetchAll(PDO::FETCH_COLUMN);
$busca = trim($_GET['busca'] ?? '');
$categoriaFiltro = trim($_GET['categoria'] ?? '');

$sql = "SELECT * FROM insumos WHERE 1=1";
$params = [];
if ($busca !== '') {
    $sql .= " AND (nome_comercial LIKE :b OR marca LIKE :b OR codigo_barras LIKE :b)";
    $params[':b'] = "%{$busca}%";
}
if ($categoriaFiltro !== '') {
    $sql .= " AND categoria = :cat";
    $params[':cat'] = $categoriaFiltro;
}
$sql .= " ORDER BY nome_comercial ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$insumos = $stmt->fetchAll();
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Insumos</h1>
        <div class="page-sub">Cadastro de insumos de uso/consumo da clínica (materiais, itens de escritório etc.)</div>
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
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'add' ?>">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>

                    <div class="mb-2">
                        <label class="form-label">Nome comercial</label>
                        <input type="text" name="nome_comercial" class="form-control" value="<?= htmlspecialchars($editing['nome_comercial'] ?? '') ?>" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">EAN (código de barras)</label>
                        <input type="text" name="codigo_barras" class="form-control mono" placeholder="Leia com o leitor ou digite" value="<?= htmlspecialchars($editing['codigo_barras'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Descrição do item</label>
                        <textarea name="descricao" class="form-control" rows="2"><?= htmlspecialchars($editing['descricao'] ?? '') ?></textarea>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label">Marca</label>
                            <input type="text" name="marca" class="form-control" value="<?= htmlspecialchars($editing['marca'] ?? '') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Categoria</label>
                            <input type="text" name="categoria" class="form-control" list="categoriasList" value="<?= htmlspecialchars($editing['categoria'] ?? '') ?>">
                            <datalist id="categoriasList">
                                <?php foreach ($categorias as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-4">
                            <label class="form-label">Quantidade</label>
                            <input type="number" name="quantidade" class="form-control" min="0" value="<?= htmlspecialchars($editing['quantidade'] ?? '0') ?>" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label">Estoque mínimo</label>
                            <input type="number" name="estoque_minimo" class="form-control" min="0" value="<?= htmlspecialchars($editing['estoque_minimo'] ?? '0') ?>">
                        </div>
                        <div class="col-4">
                            <label class="form-label">Unidade</label>
                            <select name="unidade_medida" class="form-select">
                                <?php foreach (INSUMO_UNIDADES as $u): ?>
                                    <option value="<?= $u ?>" <?= (($editing['unidade_medida'] ?? 'unidade') === $u) ? 'selected' : '' ?>><?= ucfirst($u) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label">Lote</label>
                            <input type="text" name="lote" class="form-control" value="<?= htmlspecialchars($editing['lote'] ?? '') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Data de vencimento</label>
                            <input type="date" name="validade" class="form-control" value="<?= htmlspecialchars($editing['validade'] ?? '') ?>">
                        </div>
                    </div>

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
            <form method="GET" class="d-flex gap-2 flex-grow-1 flex-wrap">
                <input type="hidden" name="page" value="insumos">
                <input type="text" name="busca" class="form-control" style="max-width:240px;" placeholder="Buscar por nome, marca ou EAN..." value="<?= htmlspecialchars($busca) ?>">
                <select name="categoria" class="form-select" style="max-width:200px;">
                    <option value="">Todas as categorias</option>
                    <?php foreach ($categorias as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>" <?= $categoriaFiltro === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-outline-secondary"><i class="bi bi-search"></i> Filtrar</button>
            </form>
        </div>

        <div class="entity-list">
            <?php if (empty($insumos)): ?>
                <div class="card"><div class="card-body text-center text-muted py-4">Nenhum insumo encontrado.</div></div>
            <?php endif; ?>
            <?php foreach ($insumos as $i):
                $estoqueBaixo = (int)$i['quantidade'] <= (int)$i['estoque_minimo'];
                $statusVenc = $i['validade'] ? statusVencimento($i['validade']) : null;
            ?>
                <div class="entity-card">
                    <div class="entity-card-head">
                        <div class="entity-title-wrap">
                            <div>
                                <div class="entity-title"><?= htmlspecialchars($i['nome_comercial']) ?></div>
                                <div class="entity-sub">
                                    <?= htmlspecialchars($i['marca'] ?: '—') ?>
                                    <?php if ($i['categoria']): ?> · <?= htmlspecialchars($i['categoria']) ?><?php endif; ?>
                                    <?php if ($i['codigo_barras']): ?> · <span class="mono"><?= htmlspecialchars($i['codigo_barras']) ?></span><?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="entity-badges">
                            <?php if ($estoqueBaixo): ?><span class="badge bg-warning text-dark">Estoque baixo</span><?php endif; ?>
                            <?php if ($statusVenc): ?><span class="badge <?= statusVencimentoBadgeClass($statusVenc) ?>"><?= statusVencimentoLabel($statusVenc) ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="entity-grid">
                        <div><div class="entity-field-label">Quantidade</div><div class="entity-field-value"><?= (int)$i['quantidade'] ?> <?= htmlspecialchars($i['unidade_medida']) ?></div></div>
                        <div><div class="entity-field-label">Estoque mínimo</div><div class="entity-field-value"><?= (int)$i['estoque_minimo'] ?> <?= htmlspecialchars($i['unidade_medida']) ?></div></div>
                        <div><div class="entity-field-label">Lote</div><div class="entity-field-value"><?= htmlspecialchars($i['lote'] ?: '—') ?></div></div>
                        <div><div class="entity-field-label">Vencimento</div><div class="entity-field-value"><?= $i['validade'] ? date('d/m/Y', strtotime($i['validade'])) : '—' ?></div></div>
                        <?php if (!empty($i['descricao'])): ?>
                            <div class="full"><div class="entity-field-label">Descrição</div><div class="entity-field-value"><?= htmlspecialchars($i['descricao']) ?></div></div>
                        <?php endif; ?>
                    </div>
                    <div class="entity-actions">
                        <div class="entity-actions-buttons">
                            <a href="index.php?page=insumos&edit=<?= (int)$i['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i> Editar</a>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Excluir este insumo?');">
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
