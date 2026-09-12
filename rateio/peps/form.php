<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Models\Pep;

require __DIR__ . '/../includes/bootstrap.php';
Auth::exigirAdmin();

$id       = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editando = $id > 0;
$registro = $editando ? Pep::buscarPorId($id) : null;

if ($editando && $registro === null) {
    Session::flash('danger', 'PEP não encontrado.');
    header('Location: ' . url('peps/listar.php'));
    exit;
}

$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pep                = trim($_POST['pep'] ?? '');
    $projeto            = trim($_POST['projeto'] ?? '');
    $centro_custo       = trim($_POST['centro_custo'] ?? '');
    $periodo_meses      = (int) ($_POST['periodo_meses'] ?? 12);
    $data_inicio_br     = trim($_POST['data_inicio'] ?? '');
    $responsavel_nome   = trim($_POST['responsavel_nome'] ?? '');
    $responsavel_cpf    = trim($_POST['responsavel_cpf'] ?? '');

    // Converter data brasileira (DD/MM/AAAA) para ISO (YYYY-MM-DD)
    $data_inicio = null;
    if ($data_inicio_br !== '') {
        $parts = explode('/', $data_inicio_br);
        if (count($parts) === 3) {
            $data_inicio = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
        }
    }

    if ($pep === '')     { $erros[] = 'Informe o PEP.'; }
    if ($projeto === '') { $erros[] = 'Informe o nome do projeto.'; }
    if ($centro_custo !== '' && strlen($centro_custo) > 12) {
        $erros[] = 'Centro de custo deve ter no máximo 12 caracteres.';
    }
    if ($periodo_meses < 1) {
        $erros[] = 'Período deve ser maior que 0 meses.';
    }
    if ($data_inicio !== null && !\DateTime::createFromFormat('Y-m-d', $data_inicio)) {
        $erros[] = 'Data de início inválida.';
    }
    if (Pep::pepEmUso($pep, $editando ? $id : null)) {
        $erros[] = 'Este PEP já está cadastrado.';
    }

    if ($erros === []) {
        if ($editando) {
            Pep::atualizar(
                $id,
                $pep,
                $projeto,
                $centro_custo,
                $periodo_meses,
                $data_inicio,
                $responsavel_nome,
                $responsavel_cpf
            );
            Session::flash('success', 'PEP atualizado.');
        } else {
            Pep::criar(
                $pep,
                $projeto,
                $centro_custo,
                $periodo_meses,
                $data_inicio,
                $responsavel_nome,
                $responsavel_cpf
            );
            Session::flash('success', 'PEP cadastrado.');
        }
        header('Location: ' . url('peps/listar.php'));
        exit;
    }
}

$contexto     = 'inicial';
$tituloPagina = $editando ? 'Editar PEP' : 'Novo PEP';
require __DIR__ . '/../includes/header.php';

// Helper para preencher valores do formulário
$v = static function(string $c) use ($registro) {
    $val = $_POST[$c] ?? $registro[$c] ?? '';
    // Se for data_inicio e estiver em formato ISO, converter para BR
    if ($c === 'data_inicio' && $val !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$val)) {
        $parts = explode('-', (string)$val);
        $val = $parts[2] . '/' . $parts[1] . '/' . $parts[0];
    }
    return e((string)$val);
};

// Calcular data de término automaticamente se houver data de início
$data_termino_display = '';
if (isset($registro['data_termino']) && $registro['data_termino']) {
    $parts = explode('-', $registro['data_termino']);
    $data_termino_display = $parts[2] . '/' . $parts[1] . '/' . $parts[0];
} elseif (isset($_POST['data_inicio']) && $_POST['data_inicio'] !== '') {
    $data_inicio_iso = $v('data_inicio');
    $data_inicio_iso = str_replace('/', '', $data_inicio_iso);
    $parts = explode('-', $_POST['data_inicio']);
    if (count($parts) === 3) {
        $data_inicio_iso = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
        $data_termino_iso = Pep::calcularDataTermino($data_inicio_iso, (int) ($_POST['periodo_meses'] ?? 12));
        if ($data_termino_iso) {
            $parts = explode('-', $data_termino_iso);
            $data_termino_display = $parts[2] . '/' . $parts[1] . '/' . $parts[0];
        }
    }
}
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
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">PEP <span class="text-danger">*</span></label>
                    <input type="text" name="pep" class="form-control" value="<?= $v('pep') ?>" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Centro de Custo</label>
                    <input type="text" name="centro_custo" class="form-control" maxlength="12" 
                           value="<?= $v('centro_custo') ?>" placeholder="Até 12 caracteres">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Nome do Projeto <span class="text-danger">*</span></label>
                <input type="text" name="projeto" class="form-control" value="<?= $v('projeto') ?>" required>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Data de Início</label>
                    <input type="text" name="data_inicio" class="form-control" placeholder="DD/MM/AAAA"
                           value="<?= $v('data_inicio') ?>" id="dataInicio">
                    <small class="text-muted">Formato: DD/MM/AAAA</small>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Data de Término</label>
                    <input type="text" class="form-control" value="<?= $data_termino_display ?>" 
                           id="dataTermino" readonly>
                    <small class="text-muted">Calculado automaticamente</small>
                </div>
            </div>

            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Período (meses)</label>
                    <input type="number" name="periodo_meses" class="form-control" min="1" 
                           value="<?= $v('periodo_meses') ?>" id="periodoMeses">
                </div>
            </div>

            <hr>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Responsável - Nome</label>
                    <input type="text" name="responsavel_nome" class="form-control" 
                           value="<?= $v('responsavel_nome') ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Responsável - CPF</label>
                    <input type="text" name="responsavel_cpf" class="form-control" placeholder="000.000.000-00"
                           value="<?= $v('responsavel_cpf') ?>">
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-success">Salvar</button>
                <a href="<?= url('peps/listar.php') ?>" class="btn btn-secondary">Voltar</a>
            </div>
        </form>
    </div>
</div>

<script>
/**
 * Calcula a data de término baseado na data de início e período
 */
function calcularDataTermino() {
    const dataInicio = document.getElementById('dataInicio').value;
    const periodo = parseInt(document.getElementById('periodoMeses').value) || 0;

    if (!dataInicio || periodo < 1) {
        document.getElementById('dataTermino').value = '';
        return;
    }

    // Parse data brasileira (DD/MM/AAAA)
    const parts = dataInicio.split('/');
    if (parts.length !== 3) {
        document.getElementById('dataTermino').value = '';
        return;
    }

    const dia = parseInt(parts[0]);
    const mes = parseInt(parts[1]);
    const ano = parseInt(parts[2]);

    const data = new Date(ano, mes - 1, dia);
    if (isNaN(data.getTime())) {
        document.getElementById('dataTermino').value = '';
        return;
    }

    // Adicionar período em meses
    data.setMonth(data.getMonth() + periodo);

    // Formatar para DD/MM/AAAA
    const d = String(data.getDate()).padStart(2, '0');
    const m = String(data.getMonth() + 1).padStart(2, '0');
    const y = data.getFullYear();

    document.getElementById('dataTermino').value = d + '/' + m + '/' + y;
}

// Event listeners
document.getElementById('dataInicio').addEventListener('change', calcularDataTermino);
document.getElementById('dataInicio').addEventListener('blur', calcularDataTermino);
document.getElementById('periodoMeses').addEventListener('change', calcularDataTermino);

// Formatar CPF enquanto digita
document.querySelector('input[name="responsavel_cpf"]').addEventListener('input', function(e) {
    let valor = e.target.value.replace(/\D/g, '');
    if (valor.length > 11) {
        valor = valor.slice(0, 11);
    }
    if (valor.length > 8) {
        valor = valor.slice(0, 8) + '-' + valor.slice(8);
    }
    if (valor.length > 5) {
        valor = valor.slice(0, 5) + '.' + valor.slice(5);
    }
    if (valor.length > 2) {
        valor = valor.slice(0, 2) + '.' + valor.slice(2);
    }
    e.target.value = valor;
});

// Formatar data enquanto digita
document.getElementById('dataInicio').addEventListener('input', function(e) {
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
