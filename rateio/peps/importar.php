<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csv;
use App\Core\CsvReader;
use App\Models\Pep;

require __DIR__ . '/../includes/bootstrap.php';
Auth::exigirAdmin();

$cabecalho = ['PEP', 'Projeto'];

// Download do modelo CSV
if (isset($_GET['modelo'])) {
    Csv::download('modelo_peps.csv', $cabecalho, [
        ['PEP001', 'Projeto Exemplo A'],
        ['PEP002', 'Projeto Exemplo B'],
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

            $codPep  = $cols[0] ?? '';
            $projeto = $cols[1] ?? '';

            if ($codPep === '' || $projeto === '') {
                $resultado['erros'][] = "Linha {$numLinha}: PEP e Projeto são obrigatórios.";
                continue;
            }

            if (Pep::pepEmUso($codPep)) {
                $resultado['ignorados']++;
                $resultado['erros'][] = "Linha {$numLinha}: PEP \"{$codPep}\" já cadastrado (ignorado).";
                continue;
            }

            try {
                Pep::criar($codPep, $projeto);
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
        <p>Colunas (separadas por vírgula), com cabeçalho na primeira linha:</p>
        <code>PEP, Projeto</code>
        <ul class="mt-3 mb-0">
            <li><strong>PEP</strong>: código do PEP (não pode repetir um já cadastrado).</li>
            <li><strong>Projeto</strong>: nome do projeto correspondente.</li>
            <li>O separador de colunas pode ser vírgula ou ponto e vírgula (detectado automaticamente).</li>
        </ul>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
