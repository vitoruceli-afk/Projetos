<?php
$db = getDB();
$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrfVerify();

    if ($_POST['action'] === 'add' || $_POST['action'] === 'update') {
        $razaoSocial = trim($_POST['razao_social'] ?? '');
        $nomeFantasia = trim($_POST['nome_fantasia'] ?? '');
        $cnpj = preg_replace('/\D/', '', $_POST['cnpj'] ?? '');
        $endereco = trim($_POST['endereco'] ?? '');
        $bairro = trim($_POST['bairro'] ?? '');
        $cep = trim($_POST['cep'] ?? '');
        $municipio = trim($_POST['municipio'] ?? '');
        $uf = strtoupper(trim($_POST['uf'] ?? ''));
        $pais = trim($_POST['pais'] ?? '') ?: 'Brasil';
        $telefone = trim($_POST['telefone'] ?? '');
        $inscricaoEstadual = trim($_POST['inscricao_estadual'] ?? '');
        $inscricaoMunicipal = trim($_POST['inscricao_municipal'] ?? '');

        if ($razaoSocial === '') {
            $formError = 'Informe o nome / razão social do fornecedor.';
        } elseif (!validarCNPJ($cnpj)) {
            $formError = 'CNPJ inválido. Confira os números digitados.';
        } else {
            try {
                $params = [
                    ':rs' => $razaoSocial, ':nf' => $nomeFantasia, ':cnpj' => $cnpj, ':end' => $endereco,
                    ':ba' => $bairro, ':cep' => $cep, ':mu' => $municipio, ':uf' => $uf, ':pa' => $pais,
                    ':tel' => $telefone, ':ie' => $inscricaoEstadual, ':im' => $inscricaoMunicipal,
                ];
                if ($_POST['action'] === 'add') {
                    $sql = "INSERT INTO fornecedores (razao_social, nome_fantasia, cnpj, endereco, bairro, cep, municipio, uf, pais, telefone, inscricao_estadual, inscricao_municipal)
                        VALUES (:rs, :nf, :cnpj, :end, :ba, :cep, :mu, :uf, :pa, :tel, :ie, :im)";
                    $db->prepare($sql)->execute($params);
                    registrarLog('Fornecedores', 'Fornecedor cadastrado', "razão social: {$razaoSocial}, CNPJ: " . formatarCNPJ($cnpj));
                } else {
                    $id = (int)($_POST['id'] ?? 0);
                    $params[':id'] = $id;
                    $sql = "UPDATE fornecedores SET razao_social=:rs, nome_fantasia=:nf, cnpj=:cnpj, endereco=:end, bairro=:ba,
                        cep=:cep, municipio=:mu, uf=:uf, pais=:pa, telefone=:tel, inscricao_estadual=:ie, inscricao_municipal=:im
                        WHERE id=:id";
                    $db->prepare($sql)->execute($params);
                    registrarLog('Fornecedores', 'Fornecedor editado', "razão social: {$razaoSocial}, CNPJ: " . formatarCNPJ($cnpj));
                }
                header("Location: index.php?page=fornecedores");
                exit;
            } catch (PDOException $e) {
                $formError = (strpos($e->getMessage(), 'Duplicate') !== false)
                    ? 'Já existe um fornecedor cadastrado com esse CNPJ.'
                    : 'Erro ao salvar: ' . $e->getMessage();
            }
        }
    } elseif ($_POST['action'] === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT razao_social FROM fornecedores WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $nome = $stmt->fetchColumn();
        if ($nome !== false) {
            $db->prepare("DELETE FROM fornecedores WHERE id = :id")->execute([':id' => $id]);
            registrarLog('Fornecedores', 'Fornecedor excluído', "razão social: {$nome}");
        }
        header("Location: index.php?page=fornecedores");
        exit;
    }
}

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM fornecedores WHERE id = :id");
    $stmt->bindValue(':id', (int)$_GET['edit'], PDO::PARAM_INT);
    $stmt->execute();
    $editing = $stmt->fetch();
}

$busca = trim($_GET['busca'] ?? '');
$sql = "SELECT * FROM fornecedores WHERE 1=1";
$params = [];
if ($busca !== '') {
    $buscaCnpj = preg_replace('/\D/', '', $busca);
    if ($buscaCnpj !== '') {
        $sql .= " AND (razao_social LIKE :b OR nome_fantasia LIKE :b OR cnpj LIKE :bc)";
        $params[':b'] = "%{$busca}%";
        $params[':bc'] = "%{$buscaCnpj}%";
    } else {
        $sql .= " AND (razao_social LIKE :b OR nome_fantasia LIKE :b)";
        $params[':b'] = "%{$busca}%";
    }
}
$sql .= " ORDER BY razao_social ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$fornecedores = $stmt->fetchAll();
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Fornecedores</h1>
        <div class="page-sub">Cadastro de fornecedores — vinculados às entradas de estoque (manual ou via importação de NFe)</div>
    </div>
</div>

<?php if ($formError): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($formError) ?></div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-header"><?= $editing ? 'Editar Fornecedor' : 'Novo Fornecedor' ?></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $editing ? 'update' : 'add' ?>">
            <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>

            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <label class="form-label">Nome / Razão Social</label>
                    <input type="text" name="razao_social" class="form-control" value="<?= htmlspecialchars($editing['razao_social'] ?? '') ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Nome Fantasia</label>
                    <input type="text" name="nome_fantasia" class="form-control" value="<?= htmlspecialchars($editing['nome_fantasia'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">CNPJ</label>
                    <input type="text" name="cnpj" id="cnpjInput" class="form-control mono" placeholder="00.000.000/0000-00" maxlength="18" value="<?= htmlspecialchars($editing ? formatarCNPJ($editing['cnpj']) : '') ?>" required>
                </div>
            </div>

            <hr>
            <div class="entity-field-label mb-2">Endereço</div>
            <div class="row g-2 mb-3">
                <div class="col-md-3">
                    <label class="form-label">CEP</label>
                    <div class="d-flex gap-1">
                        <input type="text" name="cep" id="cepInput" class="form-control mono" placeholder="00000-000" maxlength="9" value="<?= htmlspecialchars($editing['cep'] ?? '') ?>">
                        <button type="button" id="buscarCepBtn" class="btn btn-outline-secondary btn-sm"><i class="bi bi-search"></i></button>
                    </div>
                    <div class="form-text" id="cepHint"></div>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Endereço</label>
                    <input type="text" name="endereco" id="enderecoInput" class="form-control" placeholder="Rua, número, complemento..." value="<?= htmlspecialchars($editing['endereco'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Bairro / Distrito</label>
                    <input type="text" name="bairro" id="bairroInput" class="form-control" value="<?= htmlspecialchars($editing['bairro'] ?? '') ?>">
                </div>
                <div class="col-md-5">
                    <label class="form-label">Município</label>
                    <input type="text" name="municipio" id="municipioInput" class="form-control" value="<?= htmlspecialchars($editing['municipio'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">UF</label>
                    <input type="text" name="uf" id="ufInput" class="form-control" maxlength="2" style="text-transform:uppercase;" value="<?= htmlspecialchars($editing['uf'] ?? '') ?>">
                </div>
                <div class="col-md-5">
                    <label class="form-label">País</label>
                    <input type="text" name="pais" class="form-control" value="<?= htmlspecialchars($editing['pais'] ?? 'Brasil') ?>">
                </div>
            </div>

            <hr>
            <div class="entity-field-label mb-2">Contato e Inscrições</div>
            <div class="row g-2 mb-3">
                <div class="col-md-4">
                    <label class="form-label">Telefone</label>
                    <input type="text" name="telefone" class="form-control" placeholder="(00) 0000-0000" value="<?= htmlspecialchars($editing['telefone'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Inscrição Estadual</label>
                    <input type="text" name="inscricao_estadual" class="form-control" value="<?= htmlspecialchars($editing['inscricao_estadual'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Inscrição Municipal</label>
                    <input type="text" name="inscricao_municipal" class="form-control" value="<?= htmlspecialchars($editing['inscricao_municipal'] ?? '') ?>">
                </div>
            </div>

            <button class="btn btn-outline-success mt-2"><?= $editing ? 'Salvar Alterações' : 'Salvar Fornecedor' ?></button>
            <?php if ($editing): ?>
                <a href="index.php?page=fornecedores" class="btn btn-outline-secondary mt-2">Cancelar Edição</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="entity-list-toolbar">
    <form method="GET" class="d-flex gap-2 flex-grow-1">
        <input type="hidden" name="page" value="fornecedores">
        <input type="text" name="busca" class="form-control" placeholder="Buscar por razão social, nome fantasia ou CNPJ..." value="<?= htmlspecialchars($busca) ?>">
        <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
    </form>
</div>

<div class="entity-list">
    <?php if (empty($fornecedores)): ?>
        <div class="card"><div class="card-body text-center text-muted py-4">Nenhum fornecedor encontrado.</div></div>
    <?php endif; ?>
    <?php foreach ($fornecedores as $f): ?>
        <div class="entity-card">
            <div class="entity-card-head">
                <div class="entity-title-wrap">
                    <div>
                        <div class="entity-title"><?= htmlspecialchars($f['razao_social']) ?></div>
                        <div class="entity-sub">
                            <?= htmlspecialchars($f['nome_fantasia'] ?: '—') ?> · <span class="mono"><?= htmlspecialchars(formatarCNPJ($f['cnpj'])) ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="entity-grid">
                <div><div class="entity-field-label">Telefone</div><div class="entity-field-value"><?= htmlspecialchars($f['telefone'] ?: '—') ?></div></div>
                <div><div class="entity-field-label">Inscrição Estadual</div><div class="entity-field-value"><?= htmlspecialchars($f['inscricao_estadual'] ?: '—') ?></div></div>
                <div><div class="entity-field-label">Inscrição Municipal</div><div class="entity-field-value"><?= htmlspecialchars($f['inscricao_municipal'] ?: '—') ?></div></div>
                <?php if ($f['municipio'] || $f['endereco']): ?>
                    <div class="full"><div class="entity-field-label">Endereço</div><div class="entity-field-value">
                        <?= htmlspecialchars(trim("{$f['endereco']} - {$f['bairro']}, {$f['municipio']}/{$f['uf']} - {$f['pais']}", ' -,')) ?>
                        <?= $f['cep'] ? ' · CEP ' . htmlspecialchars($f['cep']) : '' ?>
                    </div></div>
                <?php endif; ?>
            </div>
            <div class="entity-actions">
                <div class="entity-actions-buttons">
                    <a href="index.php?page=fornecedores&edit=<?= (int)$f['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i> Editar</a>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Excluir este fornecedor?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script>
(function () {
    var cnpjInput = document.getElementById('cnpjInput');
    cnpjInput.addEventListener('input', function () {
        var v = cnpjInput.value.replace(/\D/g, '').slice(0, 14);
        if (v.length > 12) v = v.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{1,2})/, '$1.$2.$3/$4-$5');
        else if (v.length > 8) v = v.replace(/(\d{2})(\d{3})(\d{3})(\d{1,4})/, '$1.$2.$3/$4');
        else if (v.length > 5) v = v.replace(/(\d{2})(\d{3})(\d{1,3})/, '$1.$2.$3');
        else if (v.length > 2) v = v.replace(/(\d{2})(\d{1,3})/, '$1.$2');
        cnpjInput.value = v;
    });

    // Busca de endereço via API pública do ViaCEP — mesmo recurso já usado no cadastro de Pacientes.
    var cepInput = document.getElementById('cepInput');
    var buscarCepBtn = document.getElementById('buscarCepBtn');
    var cepHint = document.getElementById('cepHint');
    var enderecoInput = document.getElementById('enderecoInput');
    var bairroInput = document.getElementById('bairroInput');
    var municipioInput = document.getElementById('municipioInput');
    var ufInput = document.getElementById('ufInput');

    cepInput.addEventListener('input', function () {
        var v = cepInput.value.replace(/\D/g, '').slice(0, 8);
        if (v.length > 5) v = v.replace(/(\d{5})(\d{1,3})/, '$1-$2');
        cepInput.value = v;
    });

    function buscarCep() {
        var cep = cepInput.value.replace(/\D/g, '');
        if (cep.length !== 8) {
            cepHint.textContent = 'Informe um CEP com 8 dígitos.';
            return;
        }
        cepHint.textContent = 'Buscando...';
        fetch('https://viacep.com.br/ws/' + cep + '/json/')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.erro) {
                    cepHint.textContent = 'CEP não encontrado.';
                    return;
                }
                enderecoInput.value = data.logradouro || enderecoInput.value;
                bairroInput.value = data.bairro || '';
                municipioInput.value = data.localidade || '';
                ufInput.value = data.uf || '';
                cepHint.textContent = 'Endereço preenchido automaticamente.';
            })
            .catch(function () {
                cepHint.textContent = 'Não foi possível buscar o CEP agora. Preencha manualmente.';
            });
    }

    buscarCepBtn.addEventListener('click', buscarCep);
    cepInput.addEventListener('blur', function () {
        if (cepInput.value.replace(/\D/g, '').length === 8) buscarCep();
    });
})();
</script>
