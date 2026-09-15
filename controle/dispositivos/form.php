<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Models\Dispositivo;
use App\Models\TipoDispositivo;
use App\Models\Pep;

$contexto     = 'inicial';
$tituloPagina = 'Novo Dispositivo';

require __DIR__ . '/../includes/bootstrap.php';
Auth::exigirAdmin();

$id       = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editando = $id > 0;
$registro = $editando ? Dispositivo::buscarPorId($id) : null;

if ($editando && $registro === null) {
    Session::flash('danger', 'Dispositivo não encontrado.');
    header('Location: ' . url('dispositivos/listar.php'));
    exit;
}

if ($editando) {
    $tituloPagina = 'Editar Dispositivo';
}

$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo_id         = (int) ($_POST['tipo_id'] ?? 0);
    $pep_id          = !empty($_POST['pep_id']) ? (int) $_POST['pep_id'] : null;
    $nome            = trim($_POST['nome'] ?? '');
    $descricao       = trim($_POST['descricao'] ?? '');
    $numero_serie    = trim($_POST['numero_serie'] ?? '');
    $imei            = trim($_POST['imei'] ?? '');
    $modelo          = trim($_POST['modelo'] ?? '');
    $fabricante      = trim($_POST['fabricante'] ?? '');
    $data_aquisicao  = trim($_POST['data_aquisicao'] ?? '');
    $status          = $_POST['status'] ?? 'ativo';
    $localizacao     = trim($_POST['localizacao'] ?? '');
    $area            = trim($_POST['area'] ?? '');
    $segunda_tela    = trim($_POST['segunda_tela'] ?? '');
    $responsavel     = trim($_POST['responsavel'] ?? '');
    $processador     = trim($_POST['processador'] ?? '');
    $memoria         = trim($_POST['memoria'] ?? '');
    $armazenamento   = trim($_POST['armazenamento'] ?? '');
    $sistema_operacional = trim($_POST['sistema_operacional'] ?? '');
    $valor_aquisicao = (float) str_replace(',', '.', str_replace('.', '', $_POST['valor_aquisicao'] ?? '0'));
    $observacoes     = trim($_POST['observacoes'] ?? '');

    if ($nome === '') {
        $erros[] = 'Informe o nome do dispositivo.';
    }
    if ($tipo_id <= 0) {
        $erros[] = 'Selecione um tipo de dispositivo.';
    } elseif (TipoDispositivo::porId($tipo_id) === null) {
        $erros[] = 'Tipo de dispositivo inválido.';
    }
    if ($imei !== '') {
        $imei = preg_replace('/\D/', '', $imei);
        if (strlen($imei) < 14 || strlen($imei) > 16) {
            $erros[] = 'IMEI inválido (deve ter entre 14 e 16 dígitos).';
        }
    } else {
        $imei = null;
    }
    if ($data_aquisicao !== '' && !\DateTime::createFromFormat('Y-m-d', $data_aquisicao)) {
        // Tentar converter de DD/MM/AAAA
        $parts = explode('/', $data_aquisicao);
        if (count($parts) === 3) {
            $data_aquisicao = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
            if (!\DateTime::createFromFormat('Y-m-d', $data_aquisicao)) {
                $erros[] = 'Data de aquisição inválida.';
            }
        } else {
            $erros[] = 'Data de aquisição inválida (use DD/MM/AAAA ou deixe em branco).';
        }
    } elseif ($data_aquisicao === '') {
        $data_aquisicao = null;
    }

    if ($erros === []) {
        try {
            if ($editando) {
                Dispositivo::atualizar(
                    $id,
                    $tipo_id,
                    $nome,
                    $pep_id,
                    $numero_serie,
                    $modelo,
                    $fabricante,
                    $data_aquisicao,
                    $status,
                    $localizacao,
                    $responsavel,
                    $valor_aquisicao,
                    $descricao,
                    $observacoes,
                    $imei,
                    processador: $processador,
                    memoria: $memoria,
                    armazenamento: $armazenamento,
                    sistema_operacional: $sistema_operacional,
                    area: $area,
                    segunda_tela: $segunda_tela
                );
                Session::flash('success', 'Dispositivo atualizado com sucesso.');
            } else {
                Dispositivo::criar(
                    $tipo_id,
                    $nome,
                    $pep_id,
                    $numero_serie,
                    $modelo,
                    $fabricante,
                    $data_aquisicao,
                    $status,
                    $localizacao,
                    $responsavel,
                    $valor_aquisicao,
                    $descricao,
                    $observacoes,
                    imei: $imei,
                    processador: $processador,
                    memoria: $memoria,
                    armazenamento: $armazenamento,
                    sistema_operacional: $sistema_operacional,
                    area: $area,
                    segunda_tela: $segunda_tela
                );
                Session::flash('success', 'Dispositivo cadastrado com sucesso.');
            }
            header('Location: ' . url('dispositivos/listar.php'));
            exit;
        } catch (\PDOException $e) {
            $erros[] = str_contains($e->getMessage(), 'idx_dispositivos_imei')
                ? 'Já existe um dispositivo cadastrado com este IMEI.'
                : 'Erro ao salvar o dispositivo: ' . $e->getMessage();
        }
    }
}

require __DIR__ . '/../includes/header.php';

$tipos = TipoDispositivo::listar();
$peps = Pep::listar();

$v = static function(string $c) use ($registro) {
    $val = $_POST[$c] ?? $registro[$c] ?? '';
    // Converter data de ISO para DD/MM/AAAA para exibição
    if ($c === 'data_aquisicao' && $val && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$val)) {
        $parts = explode('-', (string)$val);
        $val = $parts[2] . '/' . $parts[1] . '/' . $parts[0];
    }
    return e((string)$val);
};

$selected = static fn(mixed $val, mixed $comp) => $val == $comp ? 'selected' : '';
?>

<h2 class="mb-4"><?= e($tituloPagina) ?></h2>

<?php if ($erros !== []): ?>
    <div class="alert alert-danger">
        <?php foreach ($erros as $erro): ?><div><?= e($erro) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="POST">
            <!-- Seção: Informações Básicas -->
            <div class="mb-4 pb-3 border-bottom">
                <h5 class="mb-3">
                    <i class="bi bi-info-circle"></i> Informações Básicas
                </h5>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Tipo de Dispositivo <span class="text-danger">*</span></label>
                        <select name="tipo_id" class="form-control" id="tipoDispositivo" required>
                            <option value="">Selecione um tipo...</option>
                            <?php foreach ($tipos as $t): ?>
                                <option value="<?= $t['id'] ?>"
                                        data-movel="<?= in_array($t['nome'], ['Celular/Smartphone', 'Tablet'], true) ? '1' : '0' ?>"
                                        <?= $selected($_POST['tipo_id'] ?? $registro['tipo_id'] ?? '', $t['id']) ?>>
                                    <?= e($t['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Nome <span class="text-danger">*</span></label>
                        <input type="text" name="nome" class="form-control" value="<?= $v('nome') ?>" required
                               placeholder="Ex: Notebook Dell">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Descrição</label>
                    <textarea name="descricao" class="form-control" rows="2"
                              placeholder="Descrição adicional do dispositivo"><?= $v('descricao') ?></textarea>
                </div>
            </div>

            <!-- Seção: Especificações Técnicas -->
            <div class="mb-4 pb-3 border-bottom">
                <h5 class="mb-3">
                    <i class="bi bi-cpu"></i> Especificações Técnicas
                </h5>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Fabricante</label>
                        <input type="text" name="fabricante" class="form-control" value="<?= $v('fabricante') ?>"
                               placeholder="Ex: Dell, HP, Apple">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Modelo</label>
                        <input type="text" name="modelo" class="form-control" value="<?= $v('modelo') ?>"
                               placeholder="Ex: OptiPlex 7090">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Número de Série</label>
                        <input type="text" name="numero_serie" class="form-control" value="<?= $v('numero_serie') ?>"
                               placeholder="Ex: ABC123DEF456">
                    </div>
                </div>
                <div class="row" id="campoImei" hidden>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">IMEI</label>
                        <input type="text" name="imei" class="form-control" value="<?= $v('imei') ?>"
                               placeholder="15 dígitos" maxlength="16" inputmode="numeric">
                        <small class="text-muted">Disque *#06# no aparelho para consultar.</small>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Processador</label>
                        <input type="text" name="processador" class="form-control" value="<?= $v('processador') ?>"
                               placeholder="Ex: Intel i5-1135G7">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Memória</label>
                        <input type="text" name="memoria" class="form-control" value="<?= $v('memoria') ?>"
                               placeholder="Ex: 8GB">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Armazenamento</label>
                        <input type="text" name="armazenamento" class="form-control" value="<?= $v('armazenamento') ?>"
                               placeholder="Ex: 256GB SSD">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Sistema Operacional</label>
                        <input type="text" name="sistema_operacional" class="form-control" value="<?= $v('sistema_operacional') ?>"
                               placeholder="Ex: Windows 11">
                    </div>
                </div>
            </div>

            <!-- Seção: Informações de Localização e Responsabilidade -->
            <div class="mb-4 pb-3 border-bottom">
                <h5 class="mb-3">
                    <i class="bi bi-geo-alt"></i> Localização e Responsabilidade
                </h5>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">PEP / Projeto</label>
                        <select name="pep_id" class="form-control">
                            <option value="">Sem PEP associado</option>
                            <?php foreach ($peps as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= $selected($_POST['pep_id'] ?? $registro['pep_id'] ?? '', $p['id']) ?>>
                                    [<?= e($p['pep']) ?>] <?= e($p['projeto']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Localização Física / Projeto</label>
                        <input type="text" name="localizacao" class="form-control" value="<?= $v('localizacao') ?>"
                               placeholder="Ex: Sala 101, Mesa 5">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Responsável / Usuário</label>
                        <input type="text" name="responsavel" class="form-control" value="<?= $v('responsavel') ?>"
                               placeholder="Nome da pessoa responsável">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Área</label>
                        <input type="text" name="area" class="form-control" value="<?= $v('area') ?>"
                               placeholder="Ex: TI, Financeiro">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">2ª Tela</label>
                        <input type="text" name="segunda_tela" class="form-control" value="<?= $v('segunda_tela') ?>"
                               placeholder="Ex: Sim / Não ou modelo do monitor">
                    </div>
                </div>
            </div>

            <!-- Seção: Informações Financeiras e Status -->
            <div class="mb-4 pb-3 border-bottom">
                <h5 class="mb-3">
                    <i class="bi bi-cash-coin"></i> Informações Financeiras
                </h5>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Valor de Aquisição</label>
                        <input type="text" name="valor_aquisicao" class="form-control" value="<?= $v('valor_aquisicao') ?>"
                               placeholder="0,00" id="valorAquisicao">
                        <small class="text-muted">Formato: 1.234,56 ou deixe em branco</small>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Data de Aquisição</label>
                        <input type="text" name="data_aquisicao" class="form-control" value="<?= $v('data_aquisicao') ?>"
                               placeholder="DD/MM/AAAA" id="dataAquisicao">
                    </div>
                </div>
            </div>

            <!-- Seção: Status e Observações -->
            <div class="mb-4">
                <h5 class="mb-3">
                    <i class="bi bi-clipboard-check"></i> Status e Observações
                </h5>
                <div class="mb-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control">
                        <option value="ativo" <?= $selected($_POST['status'] ?? $registro['status'] ?? 'ativo', 'ativo') ?>>Ativo</option>
                        <option value="inativo" <?= $selected($_POST['status'] ?? $registro['status'] ?? '', 'inativo') ?>>Inativo</option>
                        <option value="manutenção" <?= $selected($_POST['status'] ?? $registro['status'] ?? '', 'manutenção') ?>>Em Manutenção</option>
                        <option value="descartado" <?= $selected($_POST['status'] ?? $registro['status'] ?? '', 'descartado') ?>>Descartado</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Observações</label>
                    <textarea name="observacoes" class="form-control" rows="3"
                              placeholder="Anotações adicionais sobre o dispositivo"><?= $v('observacoes') ?></textarea>
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check-lg"></i> <?= $editando ? 'Atualizar' : 'Cadastrar' ?>
                </button>
                <a href="<?= url('dispositivos/listar.php') ?>" class="btn btn-secondary">
                    <i class="bi bi-x-lg"></i> Cancelar
                </a>
            </div>
        </form>
    </div>
</div>

<script>
// Mostrar o campo IMEI apenas para dispositivos móveis (celular/tablet)
(function () {
    const tipoSelect = document.getElementById('tipoDispositivo');
    const campoImei = document.getElementById('campoImei');

    function atualizarCampoImei() {
        const opcao = tipoSelect.options[tipoSelect.selectedIndex];
        campoImei.hidden = !(opcao && opcao.dataset.movel === '1');
    }

    tipoSelect.addEventListener('change', atualizarCampoImei);
    atualizarCampoImei();
})();

// Formatar valor monetário
document.getElementById('valorAquisicao').addEventListener('input', function(e) {
    let valor = e.target.value.replace(/\D/g, '');
    if (valor.length > 2) {
        valor = valor.slice(0, -2) + ',' + valor.slice(-2);
    }
    if (valor.length > 6) {
        valor = valor.replace(/(\d)(?=(?:\d{3})*,)/g, '$1.');
    }
    e.target.value = valor;
});

// Formatar data
document.getElementById('dataAquisicao').addEventListener('input', function(e) {
    let valor = e.target.value.replace(/\D/g, '');
    if (valor.length > 8) {
        valor = valor.slice(0, 8);
    }
    if (valor.length > 4) {
        valor = valor.slice(0, 4) + '/' + valor.slice(4);
    }
    if (valor.length > 2) {
        valor = valor.slice(0, 2) + '/' + valor.slice(2);
    }
    e.target.value = valor;
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
