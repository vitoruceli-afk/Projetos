<?php
$db = getDB();
$formError = '';

const PACIENTE_SEXO = ['masculino' => 'Masculino', 'feminino' => 'Feminino', 'prefiro_nao_informar' => 'Prefiro não informar'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrfVerify();

    if ($_POST['action'] === 'add' || $_POST['action'] === 'update') {
        $nomeCompleto = trim($_POST['nome_completo'] ?? '');
        $dataNascimento = trim($_POST['data_nascimento'] ?? '');
        $sexo = array_key_exists($_POST['sexo'] ?? '', PACIENTE_SEXO) ? $_POST['sexo'] : 'prefiro_nao_informar';
        $cpf = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
        $nomeMae = trim($_POST['nome_mae'] ?? '');
        $telefoneCelular = trim($_POST['telefone_celular'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $cep = trim($_POST['cep'] ?? '');
        $logradouro = trim($_POST['logradouro'] ?? '');
        $numero = trim($_POST['numero'] ?? '');
        $complemento = trim($_POST['complemento'] ?? '');
        $bairro = trim($_POST['bairro'] ?? '');
        $cidade = trim($_POST['cidade'] ?? '');
        $uf = strtoupper(trim($_POST['uf'] ?? ''));
        $tipoAtendimento = ($_POST['tipo_atendimento'] ?? '') === 'convenio' ? 'convenio' : 'particular';
        $convenioNome = trim($_POST['convenio_nome'] ?? '');
        $convenioCarteirinha = trim($_POST['convenio_carteirinha'] ?? '');
        $emergenciaNome = trim($_POST['emergencia_nome'] ?? '');
        $emergenciaTelefone = trim($_POST['emergencia_telefone'] ?? '');

        if ($nomeCompleto === '' || $dataNascimento === '' || $nomeMae === '') {
            $formError = 'Nome completo, data de nascimento e nome da mãe são obrigatórios.';
        } elseif (!validarCPF($cpf)) {
            $formError = 'CPF inválido. Confira os números digitados.';
        } elseif ($tipoAtendimento === 'convenio' && ($convenioNome === '' || $convenioCarteirinha === '')) {
            $formError = 'Informe o nome do convênio e o número da carteirinha.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $formError = 'Informe um e-mail válido.';
        } else {
            try {
                $foto = salvarFotoPaciente($_FILES['foto'] ?? []);

                $params = [
                    ':nc' => $nomeCompleto, ':dn' => $dataNascimento, ':sx' => $sexo, ':cpf' => $cpf, ':nm' => $nomeMae,
                    ':tc' => $telefoneCelular, ':em' => $email, ':cep' => $cep, ':lg' => $logradouro, ':nu' => $numero,
                    ':cp' => $complemento, ':ba' => $bairro, ':ci' => $cidade, ':uf' => $uf, ':ta' => $tipoAtendimento,
                    ':cvn' => $convenioNome, ':cvc' => $convenioCarteirinha, ':en' => $emergenciaNome, ':et' => $emergenciaTelefone,
                ];

                if ($_POST['action'] === 'add') {
                    $sql = "INSERT INTO pacientes (nome_completo, data_nascimento, foto, sexo, cpf, nome_mae, telefone_celular, email,
                        cep, logradouro, numero, complemento, bairro, cidade, uf, tipo_atendimento, convenio_nome, convenio_carteirinha,
                        emergencia_nome, emergencia_telefone)
                        VALUES (:nc, :dn, :foto, :sx, :cpf, :nm, :tc, :em, :cep, :lg, :nu, :cp, :ba, :ci, :uf, :ta, :cvn, :cvc, :en, :et)";
                    $params[':foto'] = $foto;
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                    registrarLog('Pacientes', 'Paciente cadastrado', "nome: {$nomeCompleto}, CPF: " . formatarCPF($cpf));
                } else {
                    $id = (int)($_POST['id'] ?? 0);
                    $params[':id'] = $id;
                    if ($foto !== '') {
                        $old = $db->prepare("SELECT foto FROM pacientes WHERE id = :id");
                        $old->bindValue(':id', $id, PDO::PARAM_INT);
                        $old->execute();
                        $oldFoto = $old->fetchColumn();
                        $sql = "UPDATE pacientes SET nome_completo=:nc, data_nascimento=:dn, foto=:foto, sexo=:sx, cpf=:cpf, nome_mae=:nm,
                            telefone_celular=:tc, email=:em, cep=:cep, logradouro=:lg, numero=:nu, complemento=:cp, bairro=:ba,
                            cidade=:ci, uf=:uf, tipo_atendimento=:ta, convenio_nome=:cvn, convenio_carteirinha=:cvc,
                            emergencia_nome=:en, emergencia_telefone=:et WHERE id=:id";
                        $params[':foto'] = $foto;
                        if ($oldFoto) { @unlink(UPLOAD_DIR_PACIENTES . '/' . $oldFoto); }
                    } else {
                        $sql = "UPDATE pacientes SET nome_completo=:nc, data_nascimento=:dn, sexo=:sx, cpf=:cpf, nome_mae=:nm,
                            telefone_celular=:tc, email=:em, cep=:cep, logradouro=:lg, numero=:nu, complemento=:cp, bairro=:ba,
                            cidade=:ci, uf=:uf, tipo_atendimento=:ta, convenio_nome=:cvn, convenio_carteirinha=:cvc,
                            emergencia_nome=:en, emergencia_telefone=:et WHERE id=:id";
                    }
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                    registrarLog('Pacientes', 'Paciente editado', "nome: {$nomeCompleto}, CPF: " . formatarCPF($cpf));
                }
                header("Location: index.php?page=pacientes");
                exit;
            } catch (PDOException $e) {
                // PDOException estende RuntimeException — precisa vir ANTES desse catch, senão o
                // bloco genérico abaixo intercepta primeiro e a mensagem amigável nunca é usada.
                $formError = (strpos($e->getMessage(), 'Duplicate') !== false)
                    ? 'Já existe um paciente cadastrado com esse CPF.'
                    : 'Erro ao salvar: ' . $e->getMessage();
            } catch (RuntimeException $e) {
                $formError = $e->getMessage();
            }
        }
    } elseif ($_POST['action'] === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT nome_completo, foto FROM pacientes WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $paciente = $stmt->fetch();
        if ($paciente) {
            $db->prepare("DELETE FROM pacientes WHERE id = :id")->execute([':id' => $id]);
            if ($paciente['foto']) { @unlink(UPLOAD_DIR_PACIENTES . '/' . $paciente['foto']); }
            registrarLog('Pacientes', 'Paciente excluído', "nome: {$paciente['nome_completo']}");
        }
        header("Location: index.php?page=pacientes");
        exit;
    }
}

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM pacientes WHERE id = :id");
    $stmt->bindValue(':id', (int)$_GET['edit'], PDO::PARAM_INT);
    $stmt->execute();
    $editing = $stmt->fetch();
}

$busca = trim($_GET['busca'] ?? '');
$sql = "SELECT * FROM pacientes WHERE 1=1";
$params = [];
if ($busca !== '') {
    $buscaCpf = preg_replace('/\D/', '', $busca);
    if ($buscaCpf !== '') {
        $sql .= " AND (nome_completo LIKE :b OR cpf LIKE :bc)";
        $params[':b'] = "%{$busca}%";
        $params[':bc'] = "%{$buscaCpf}%";
    } else {
        $sql .= " AND nome_completo LIKE :b";
        $params[':b'] = "%{$busca}%";
    }
}
$sql .= " ORDER BY nome_completo ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$pacientes = $stmt->fetchAll();

function calcularIdade($dataNascimento) {
    if (!$dataNascimento) return null;
    $nasc = new DateTime($dataNascimento);
    $hoje = new DateTime('today');
    return $hoje->diff($nasc)->y;
}
?>
<div class="page-head">
    <div>
        <h1 class="page-title">Pacientes</h1>
        <div class="page-sub">Cadastro de pacientes da clínica</div>
    </div>
</div>

<?php if ($formError): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($formError) ?></div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-header"><?= $editing ? 'Editar Paciente' : 'Novo Paciente' ?></div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $editing ? 'update' : 'add' ?>">
            <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>

            <div class="entity-field-label mb-2">1. Dados de Identificação</div>
            <div class="row g-2 mb-3">
                <div class="col-md-3 d-flex flex-column align-items-center justify-content-center">
                    <?php $fotoUrl = $editing ? pacienteFotoUrl($editing['foto']) : null; ?>
                    <img id="fotoPreview" src="<?= $fotoUrl ? htmlspecialchars($fotoUrl) : '' ?>" class="entity-thumb mb-2" style="width:72px;height:72px;<?= $fotoUrl ? '' : 'display:none;' ?>" alt="">
                    <div id="fotoPlaceholder" class="entity-thumb-placeholder mb-2" style="width:72px;height:72px;<?= $fotoUrl ? 'display:none;' : '' ?>"><i class="bi bi-person"></i></div>
                    <input type="file" name="foto" id="fotoInput" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
                    <div class="form-text text-center">JPG, JPEG ou PNG</div>
                </div>
                <div class="col-md-9">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label">Nome completo</label>
                            <input type="text" name="nome_completo" class="form-control" value="<?= htmlspecialchars($editing['nome_completo'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Data de nascimento</label>
                            <input type="date" name="data_nascimento" class="form-control" value="<?= htmlspecialchars($editing['data_nascimento'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Sexo</label>
                            <select name="sexo" class="form-select">
                                <?php foreach (PACIENTE_SEXO as $val => $label): ?>
                                    <option value="<?= $val ?>" <?= (($editing['sexo'] ?? 'prefiro_nao_informar') === $val) ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">CPF</label>
                            <input type="text" name="cpf" id="cpfInput" class="form-control mono" placeholder="000.000.000-00" maxlength="14" value="<?= htmlspecialchars($editing ? formatarCPF($editing['cpf']) : '') ?>" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Nome completo da mãe</label>
                            <input type="text" name="nome_mae" class="form-control" value="<?= htmlspecialchars($editing['nome_mae'] ?? '') ?>" required>
                        </div>
                    </div>
                </div>
            </div>

            <hr>
            <div class="entity-field-label mb-2">2. Informações de Contato</div>
            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <label class="form-label">Telefone celular</label>
                    <input type="text" name="telefone_celular" class="form-control" placeholder="(00) 00000-0000" value="<?= htmlspecialchars($editing['telefone_celular'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">E-mail</label>
                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($editing['email'] ?? '') ?>">
                </div>
            </div>

            <hr>
            <div class="entity-field-label mb-2">3. Localização</div>
            <div class="row g-2 mb-3">
                <div class="col-md-3">
                    <label class="form-label">CEP</label>
                    <div class="d-flex gap-1">
                        <input type="text" name="cep" id="cepInput" class="form-control mono" placeholder="00000-000" maxlength="9" value="<?= htmlspecialchars($editing['cep'] ?? '') ?>">
                        <button type="button" id="buscarCepBtn" class="btn btn-outline-secondary btn-sm"><i class="bi bi-search"></i></button>
                    </div>
                    <div class="form-text" id="cepHint"></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Logradouro</label>
                    <input type="text" name="logradouro" id="logradouroInput" class="form-control" value="<?= htmlspecialchars($editing['logradouro'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Número</label>
                    <input type="text" name="numero" class="form-control" value="<?= htmlspecialchars($editing['numero'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Complemento</label>
                    <input type="text" name="complemento" class="form-control" value="<?= htmlspecialchars($editing['complemento'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Bairro</label>
                    <input type="text" name="bairro" id="bairroInput" class="form-control" value="<?= htmlspecialchars($editing['bairro'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Cidade</label>
                    <input type="text" name="cidade" id="cidadeInput" class="form-control" value="<?= htmlspecialchars($editing['cidade'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">UF</label>
                    <input type="text" name="uf" id="ufInput" class="form-control" maxlength="2" style="text-transform:uppercase;" value="<?= htmlspecialchars($editing['uf'] ?? '') ?>">
                </div>
            </div>

            <hr>
            <div class="entity-field-label mb-2">4. Informações de Atendimento</div>
            <div class="row g-2 mb-3">
                <div class="col-md-3">
                    <label class="form-label">Tipo de atendimento</label>
                    <select name="tipo_atendimento" id="tipoAtendimentoSelect" class="form-select">
                        <option value="particular" <?= (($editing['tipo_atendimento'] ?? 'particular') === 'particular') ? 'selected' : '' ?>>Particular</option>
                        <option value="convenio" <?= (($editing['tipo_atendimento'] ?? '') === 'convenio') ? 'selected' : '' ?>>Convênio</option>
                    </select>
                </div>
                <div class="col-md-5" id="convenioNomeWrap">
                    <label class="form-label">Nome do convênio</label>
                    <input type="text" name="convenio_nome" class="form-control" value="<?= htmlspecialchars($editing['convenio_nome'] ?? '') ?>">
                </div>
                <div class="col-md-4" id="convenioCarteirinhaWrap">
                    <label class="form-label">Número da carteirinha</label>
                    <input type="text" name="convenio_carteirinha" class="form-control" value="<?= htmlspecialchars($editing['convenio_carteirinha'] ?? '') ?>">
                </div>
            </div>

            <hr>
            <div class="entity-field-label mb-2">5. Contato de Emergência</div>
            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <label class="form-label">Nome do contato de emergência</label>
                    <input type="text" name="emergencia_nome" class="form-control" value="<?= htmlspecialchars($editing['emergencia_nome'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Telefone do contato de emergência</label>
                    <input type="text" name="emergencia_telefone" class="form-control" placeholder="(00) 00000-0000" value="<?= htmlspecialchars($editing['emergencia_telefone'] ?? '') ?>">
                </div>
            </div>

            <button class="btn btn-outline-success mt-2"><?= $editing ? 'Salvar Alterações' : 'Salvar Paciente' ?></button>
            <?php if ($editing): ?>
                <a href="index.php?page=pacientes" class="btn btn-outline-secondary mt-2">Cancelar Edição</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="entity-list-toolbar">
    <form method="GET" class="d-flex gap-2 flex-grow-1">
        <input type="hidden" name="page" value="pacientes">
        <input type="text" name="busca" class="form-control" placeholder="Buscar por nome ou CPF..." value="<?= htmlspecialchars($busca) ?>">
        <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
    </form>
</div>

<div class="entity-list">
    <?php if (empty($pacientes)): ?>
        <div class="card"><div class="card-body text-center text-muted py-4">Nenhum paciente encontrado.</div></div>
    <?php endif; ?>
    <?php foreach ($pacientes as $p): $fotoUrl = pacienteFotoUrl($p['foto']); $idade = calcularIdade($p['data_nascimento']); ?>
        <div class="entity-card">
            <div class="entity-card-head">
                <div class="entity-title-wrap">
                    <?php if ($fotoUrl): ?>
                        <img src="<?= htmlspecialchars($fotoUrl) ?>" class="entity-thumb" alt="">
                    <?php else: ?>
                        <div class="entity-thumb-placeholder"><i class="bi bi-person"></i></div>
                    <?php endif; ?>
                    <div>
                        <div class="entity-title"><?= htmlspecialchars($p['nome_completo']) ?></div>
                        <div class="entity-sub">
                            <?= $idade !== null ? $idade . ' anos' : '—' ?> · <span class="mono"><?= htmlspecialchars(formatarCPF($p['cpf'])) ?></span>
                        </div>
                    </div>
                </div>
                <div class="entity-badges">
                    <span class="badge <?= $p['tipo_atendimento'] === 'convenio' ? 'bg-info text-dark' : 'bg-light' ?>">
                        <?= $p['tipo_atendimento'] === 'convenio' ? 'Convênio' : 'Particular' ?>
                    </span>
                </div>
            </div>
            <div class="entity-grid">
                <div><div class="entity-field-label">Telefone</div><div class="entity-field-value"><?= htmlspecialchars($p['telefone_celular'] ?: '—') ?></div></div>
                <div><div class="entity-field-label">E-mail</div><div class="entity-field-value"><?= htmlspecialchars($p['email'] ?: '—') ?></div></div>
                <?php if ($p['cidade'] || $p['logradouro']): ?>
                    <div class="full"><div class="entity-field-label">Endereço</div><div class="entity-field-value">
                        <?= htmlspecialchars(trim("{$p['logradouro']}, {$p['numero']} - {$p['bairro']}, {$p['cidade']}/{$p['uf']}", ' ,-')) ?>
                    </div></div>
                <?php endif; ?>
                <?php if ($p['tipo_atendimento'] === 'convenio'): ?>
                    <div><div class="entity-field-label">Convênio</div><div class="entity-field-value"><?= htmlspecialchars($p['convenio_nome']) ?></div></div>
                    <div><div class="entity-field-label">Carteirinha</div><div class="entity-field-value"><?= htmlspecialchars($p['convenio_carteirinha']) ?></div></div>
                <?php endif; ?>
                <?php if ($p['emergencia_nome']): ?>
                    <div class="full"><div class="entity-field-label">Contato de emergência</div><div class="entity-field-value"><?= htmlspecialchars($p['emergencia_nome']) ?> · <?= htmlspecialchars($p['emergencia_telefone']) ?></div></div>
                <?php endif; ?>
            </div>
            <div class="entity-actions">
                <div class="entity-actions-buttons">
                    <button type="button" class="btn btn-sm btn-outline-secondary btn-ver-saidas" data-id="<?= (int)$p['id'] ?>">
                        <i class="bi bi-arrow-up-circle"></i> Ver Saídas
                    </button>
                    <a href="index.php?page=pacientes&edit=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i> Editar</a>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Excluir este paciente?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="modal fade" id="pacienteSaidasModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="pacienteSaidasModalTitle">Saídas do Paciente</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body" id="pacienteSaidasModalBody">
                <div class="text-muted small">Carregando...</div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="pacienteSaidaItensModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Itens da Saída</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body" id="pacienteSaidaItensModalBody">
                <div class="text-muted small">Carregando...</div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var cpfInput = document.getElementById('cpfInput');
    cpfInput.addEventListener('input', function () {
        var v = cpfInput.value.replace(/\D/g, '').slice(0, 11);
        if (v.length > 9) v = v.replace(/(\d{3})(\d{3})(\d{3})(\d{1,2})/, '$1.$2.$3-$4');
        else if (v.length > 6) v = v.replace(/(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
        else if (v.length > 3) v = v.replace(/(\d{3})(\d{1,3})/, '$1.$2');
        cpfInput.value = v;
    });

    var fotoInput = document.getElementById('fotoInput');
    var fotoPreview = document.getElementById('fotoPreview');
    var fotoPlaceholder = document.getElementById('fotoPlaceholder');
    fotoInput.addEventListener('change', function () {
        if (!fotoInput.files || !fotoInput.files[0]) return;
        fotoPreview.src = URL.createObjectURL(fotoInput.files[0]);
        fotoPreview.style.display = '';
        fotoPlaceholder.style.display = 'none';
    });

    var tipoAtendimentoSelect = document.getElementById('tipoAtendimentoSelect');
    var convenioNomeWrap = document.getElementById('convenioNomeWrap');
    var convenioCarteirinhaWrap = document.getElementById('convenioCarteirinhaWrap');
    function ajustarConvenio() {
        var mostrar = tipoAtendimentoSelect.value === 'convenio';
        convenioNomeWrap.style.display = mostrar ? '' : 'none';
        convenioCarteirinhaWrap.style.display = mostrar ? '' : 'none';
    }
    tipoAtendimentoSelect.addEventListener('change', ajustarConvenio);
    ajustarConvenio();

    // Busca de endereço via API pública do ViaCEP (https://viacep.com.br) — sem necessidade de
    // proxy no servidor, a própria API já libera CORS pra chamada direta do navegador.
    var cepInput = document.getElementById('cepInput');
    var buscarCepBtn = document.getElementById('buscarCepBtn');
    var cepHint = document.getElementById('cepHint');
    var logradouroInput = document.getElementById('logradouroInput');
    var bairroInput = document.getElementById('bairroInput');
    var cidadeInput = document.getElementById('cidadeInput');
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
                logradouroInput.value = data.logradouro || '';
                bairroInput.value = data.bairro || '';
                cidadeInput.value = data.localidade || '';
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

    // ---- Ver Saídas: histórico de saídas do paciente com o resumo financeiro de cada uma ----
    function esc(s) {
        var d = document.createElement('div');
        d.textContent = (s === null || s === undefined) ? '' : String(s);
        return d.innerHTML;
    }
    function fmtMoeda(v) {
        return 'R$ ' + (Number(v) || 0).toFixed(2).replace('.', ',');
    }

    var saidasModalEl = document.getElementById('pacienteSaidasModal');
    var saidasModalTitle = document.getElementById('pacienteSaidasModalTitle');
    var saidasModalBody = document.getElementById('pacienteSaidasModalBody');
    var saidasModal = null; // instanciado só no primeiro uso: bootstrap.bundle.min.js (carregado no
    // layout, depois do conteúdo) ainda não rodou nesse ponto do carregamento da página.
    var itensModalEl = document.getElementById('pacienteSaidaItensModal');
    var itensModalBody = document.getElementById('pacienteSaidaItensModalBody');
    var itensModal = null;

    document.querySelectorAll('.btn-ver-saidas').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!saidasModal) { saidasModal = new bootstrap.Modal(saidasModalEl); }
            if (!itensModal) { itensModal = new bootstrap.Modal(itensModalEl); }
            var pacienteId = btn.getAttribute('data-id');
            saidasModalTitle.textContent = 'Saídas do Paciente';
            saidasModalBody.innerHTML = '<div class="text-muted small">Carregando...</div>';
            saidasModal.show();

            fetch('ajax_paciente_saidas.php?paciente_id=' + encodeURIComponent(pacienteId))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.found) {
                        saidasModalBody.innerHTML = '<div class="alert alert-danger mb-0">' + esc(data.error) + '</div>';
                        return;
                    }
                    saidasModalTitle.textContent = 'Saídas de ' + data.paciente_nome;

                    if (!data.saidas.length) {
                        saidasModalBody.innerHTML = '<p class="text-muted small mb-0">Nenhuma saída registrada para este paciente ainda.</p>';
                        return;
                    }

                    var linhas = data.saidas.map(function (s) {
                        return '<tr>' +
                            '<td class="mono text-nowrap">' + esc(s.data_br) + '</td>' +
                            '<td class="mono">' + esc(s.hora) + '</td>' +
                            '<td>' + esc(s.usuario) + '</td>' +
                            '<td class="text-center">' + esc(s.total_itens) + '</td>' +
                            '<td class="text-center">' + esc(s.total_quantidade) + '</td>' +
                            '<td class="text-end mono">' + fmtMoeda(s.valor_total) + '</td>' +
                            '<td class="text-nowrap"><button type="button" class="btn btn-sm btn-outline-primary btn-ver-saida-itens" data-grupo="' + s.confirmacao_id + '"><i class="bi bi-list-ul"></i> Ver Itens</button></td>' +
                            '</tr>';
                    }).join('');

                    saidasModalBody.innerHTML =
                        '<div class="d-flex justify-content-between align-items-center mb-2">' +
                            '<span class="text-muted small">Valor total retirado por este paciente</span>' +
                            '<span class="fw-bold fs-5">' + fmtMoeda(data.valor_total_geral) + '</span>' +
                        '</div>' +
                        '<div class="table-responsive"><table class="table table-sm table-striped mb-0">' +
                            '<thead><tr><th>Data</th><th>Hora</th><th>Usuário</th><th class="text-center">Itens</th><th class="text-center">Qtd.</th><th class="text-end">Valor Total</th><th></th></tr></thead>' +
                            '<tbody>' + linhas + '</tbody></table></div>';

                    saidasModalBody.querySelectorAll('.btn-ver-saida-itens').forEach(function (itemBtn) {
                        itemBtn.addEventListener('click', function () {
                            var grupo = itemBtn.getAttribute('data-grupo');
                            itensModalBody.innerHTML = '<div class="text-muted small">Carregando...</div>';
                            saidasModal.hide();
                            itensModal.show();

                            fetch('ajax_movimentacao_itens.php?grupo=' + encodeURIComponent(grupo) + '&tipo=saida')
                                .then(function (r) { return r.json(); })
                                .then(function (idata) {
                                    if (!idata.found) {
                                        itensModalBody.innerHTML = '<div class="alert alert-danger mb-0">' + esc(idata.error) + '</div>';
                                        return;
                                    }
                                    var itensLinhas = idata.itens.map(function (i) {
                                        return '<tr>' +
                                            '<td>' + esc(i.produto) + (i.apresentacao ? '<div class="entity-sub">' + esc(i.apresentacao) + '</div>' : '') + '</td>' +
                                            '<td>' + esc(i.laboratorio || '—') + '</td>' +
                                            '<td class="mono">' + esc(i.lote || '—') + '</td>' +
                                            '<td class="text-center">' + esc(i.quantidade) + '</td>' +
                                            '<td class="text-end mono">' + fmtMoeda(i.valor_unitario) + '</td>' +
                                            '<td class="text-end mono">' + fmtMoeda(i.subtotal) + '</td>' +
                                            '</tr>';
                                    }).join('');
                                    itensModalBody.innerHTML = '<div class="table-responsive"><table class="table table-sm table-striped mb-0">' +
                                        '<thead><tr><th>Medicamento/Insumo</th><th>Laboratório/Marca</th><th>Lote</th><th class="text-center">Qtd.</th><th class="text-end">Valor Unit.</th><th class="text-end">Subtotal</th></tr></thead>' +
                                        '<tbody>' + itensLinhas + '</tbody></table></div>' +
                                        '<div class="d-flex justify-content-between align-items-center border-top mt-2 pt-2">' +
                                            '<span class="fw-bold">Resumo financeiro da operação</span>' +
                                            '<span class="fw-bold fs-5">' + fmtMoeda(idata.valor_total) + '</span>' +
                                        '</div>';
                                })
                                .catch(function () {
                                    itensModalBody.innerHTML = '<div class="alert alert-danger mb-0">Erro ao carregar os itens.</div>';
                                });
                        });
                    });
                })
                .catch(function () {
                    saidasModalBody.innerHTML = '<div class="alert alert-danger mb-0">Erro ao carregar as saídas.</div>';
                });
        });
    });

    itensModalEl.addEventListener('hidden.bs.modal', function () {
        saidasModal.show();
    });
})();
</script>
