<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csv;
use App\Core\CsvReader;
use App\Models\Pep;

require __DIR__ . '/../includes/bootstrap.php';
Auth::exigirAdmin();

$cabecalho = ['PEP', 'Projeto', 'Centro de Custo', 'Período (meses)', 'Data de Início', 'Data de Término', 'Responsável (Nome)', 'Responsável (CPF)'];

// Download do modelo CSV
if (isset($_GET['modelo'])) {
    Csv::download('modelo_peps.csv', $cabecalho, [
        ['PEP001', 'Projeto Exemplo A', 'CC001', '12', '01/01/2024', '', 'João Silva', '123.456.789-00'],
        ['PEP002', 'Projeto Exemplo B', 'CC002', '24', '15/03/2024', '', 'Maria Santos', '987.654.321-00'],
    ]);
}

$resultado = null; // ['ok'=>int, 'ignorados'=>int, 'erros'=>array<int,string>]

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $resultado = ['ok' => 0, 'ignorados' => 0, 'erros' => []];

    if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
        $resultado['erros'][] = 'Falha no envio do arquivo.';
    } else {
        $linhas = CsvReader::ler($_FILES['arquivo']['tmp_name'], $cabecalho);

        if ($linhas === []) {
            $resultado['erros'][] = 'O arquivo não contém dados.';
        }

        foreach ($linhas as $n => $cols) {
            $numLinha = $n + 1;

            $codPep               = $cols[0] ?? '';
            $projeto              = $cols[1] ?? '';
            $centro_custo         = trim($cols[2] ?? '');
            $periodo_meses_str    = trim($cols[3] ?? '12');
            $data_inicio_br       = trim($cols[4] ?? '');
            $responsavel_nome     = trim($cols[6] ?? '');
            $responsavel_cpf      = trim($cols[7] ?? '');

            if ($codPep === '' || $projeto === '') {
                $resultado['erros'][] = "Linha {$numLinha}: PEP e Projeto são obrigatórios.";
                continue;
            }

            if (Pep::pepEmUso($codPep)) {
                $resultado['ignorados']++;
                $resultado['erros'][] = "Linha {$numLinha}: PEP \"{$codPep}\" já cadastrado (ignorado).";
                continue;
            }

            // Validar centro de custo
            if ($centro_custo !== '' && strlen($centro_custo) > 12) {
                $resultado['erros'][] = "Linha {$numLinha}: Centro de custo deve ter no máximo 12 caracteres.";
                continue;
            }

            // Validar período
            $periodo_meses = (int) $periodo_meses_str;
            if ($periodo_meses < 1) {
                $resultado['erros'][] = "Linha {$numLinha}: Período deve ser maior que 0.";
                continue;
            }

            // Converter data brasileira para ISO
            $data_inicio = null;
            if ($data_inicio_br !== '') {
                $parts = explode('/', $data_inicio_br);
                if (count($parts) === 3 && strlen($parts[0]) === 2 && strlen($parts[1]) === 2 && strlen($parts[2]) === 4) {
                    $data_inicio = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
                    if (!\DateTime::createFromFormat('Y-m-d', $data_inicio)) {
                        $resultado['erros'][] = "Linha {$numLinha}: Data de início inválida (DD/MM/AAAA).";
                        continue;
                    }
                } else {
                    $resultado['erros'][] = "Linha {$numLinha}: Data de início em formato inválido (use DD/MM/AAAA).";
                    continue;
                }
            }

            try {
                Pep::criar(
                    $codPep,
                    $projeto,
                    $centro_custo,
                    $periodo_meses,
                    $data_inicio,
                    $responsavel_nome,
                    $responsavel_cpf
                );
                $resultado['ok']++;
            } catch (\Throwable $e) {
                $resultado['erros'][] = "Linha {$numLinha}: erro ao gravar (" . $e->getMessage() . ').';
            }
        }
    }
}

$contexto     = 'inicial';
$tituloPagina = 'Importar PEPs';
require __DIR__ . '/../includes/header.php';
?>

<h2 class="mb-4">Importar PEPs (CSV)</h2>

<?php if ($resultado !== null): ?>
    <div class="alert alert-<?= $resultado['ok'] > 0 ? 'success' : 'warning' ?>">
        <strong><?= (int) $resultado['ok'] ?></strong> PEP(s) importado(s) com sucesso.
        <?php if ($resultado['ignorados'] > 0): ?>
            <strong><?= (int) $resultado['ignorados'] ?></strong> já existente(s) ignorado(s).
        <?php endif; ?>
        <?php if ($resultado['erros'] !== []): ?>
            <strong><?= count($resultado['erros']) ?></strong> linha(s) com observação.
        <?php endif; ?>
    </div>
    <?php if ($resultado['erros'] !== []): ?>
        <div class="card border-danger mb-4">
            <div class="card-header bg-danger text-white">Linhas com observação</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($resultado['erros'] as $erro): ?>
                    <li class="list-group-item"><?= e($erro) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <div class="mb-3">
                <label class="form-label">Arquivo CSV</label>
                <input type="file" name="arquivo" accept=".csv,text/csv" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-success">
                <i class="bi bi-upload"></i> Importar
            </button>
            <a href="<?= url('peps/importar.php?modelo=1') ?>" class="btn btn-outline-secondary">
                <i class="bi bi-download"></i> Baixar modelo
            </a>
            <a href="<?= url('peps/listar.php') ?>" class="btn btn-secondary">Voltar</a>
        </form>
    </div>
</div>

<div class="card border-info">
    <div class="card-header bg-info text-white">Formato esperado</div>
    <div class="card-body">
        <p>Colunas (separadas por vírgula ou ponto-e-vírgula), com cabeçalho na primeira linha:</p>
        <code>PEP, Projeto, Centro de Custo, Período (meses), Data de Início, Data de Término, Responsável (Nome), Responsável (CPF)</code>
        <ul class="mt-3 mb-0">
            <li><strong>PEP</strong>: código do PEP (obrigatório, não pode repetir).</li>
            <li><strong>Projeto</strong>: nome do projeto (obrigatório).</li>
            <li><strong>Centro de Custo</strong>: código de centro de custo (até 12 caracteres, opcional).</li>
            <li><strong>Período (meses)</strong>: duração do contrato em meses (padrão: 12, opcional).</li>
            <li><strong>Data de Início</strong>: data de início do contrato em formato DD/MM/AAAA (opcional).</li>
            <li><strong>Data de Término</strong>: calculada automaticamente (pode deixar em branco).</li>
            <li><strong>Responsável (Nome)</strong>: nome do responsável (opcional).</li>
            <li><strong>Responsável (CPF)</strong>: CPF do responsável em formato XXX.XXX.XXX-XX (opcional).</li>
        </ul>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
