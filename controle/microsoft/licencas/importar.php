<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csv;
use App\Core\CsvReader;
use App\Models\MsLicenca;

require __DIR__ . '/../../includes/bootstrap.php';
Auth::exigirAdmin();

$cabecalho = ['Codigo', 'Descricao', 'Valor', 'Modo Cobranca'];

// Download do modelo CSV
if (isset($_GET['modelo'])) {
    Csv::download('modelo_licencas_microsoft.csv', $cabecalho, [
        ['E3', 'Office 365 E3', '120,00', 'Mensal'],
    ]);
}

$resultado = null; // ['ok'=>int, 'erros'=>array<int,string>]

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $resultado = ['ok' => 0, 'erros' => []];

    if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
        $resultado['erros'][] = 'Falha no envio do arquivo.';
    } else {
        $linhas = CsvReader::ler($_FILES['arquivo']['tmp_name'], $cabecalho);

        if ($linhas === []) {
            $resultado['erros'][] = 'O arquivo não contém dados.';
        }

        foreach ($linhas as $n => $cols) {
            $numLinha = $n + 1;

            $codigo    = $cols[0] ?? '';
            $descricao = $cols[1] ?? '';
            $valor     = valorBr($cols[2] ?? '0');
            $modo      = $cols[3] ?? '';

            if ($codigo === '' || $descricao === '') {
                $resultado['erros'][] = "Linha {$numLinha}: Código e Descrição são obrigatórios.";
                continue;
            }

            try {
                MsLicenca::criar($codigo, $descricao, $valor, $modo);
                $resultado['ok']++;
            } catch (\Throwable $e) {
                $resultado['erros'][] = "Linha {$numLinha}: erro ao gravar (" . $e->getMessage() . ').';
            }
        }
    }
}

$contexto     = 'microsoft';
$tituloPagina = 'Importar Licenças';
require __DIR__ . '/../../includes/header.php';
?>

<h2 class="mb-4">Importar Licenças (CSV)</h2>

<?php if ($resultado !== null): ?>
    <div class="alert alert-<?= $resultado['ok'] > 0 ? 'success' : 'warning' ?>">
        <strong><?= (int) $resultado['ok'] ?></strong> licença(s) importada(s) com sucesso.
        <?php if ($resultado['erros'] !== []): ?>
            <strong><?= count($resultado['erros']) ?></strong> linha(s) com problema.
        <?php endif; ?>
    </div>
    <?php if ($resultado['erros'] !== []): ?>
        <div class="card border-danger mb-4">
            <div class="card-header bg-danger text-white">Linhas não importadas</div>
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
            <a href="<?= url('microsoft/licencas/importar.php?modelo=1') ?>" class="btn btn-outline-secondary">
                <i class="bi bi-download"></i> Baixar modelo
            </a>
            <a href="<?= url('microsoft/licencas/listar.php') ?>" class="btn btn-secondary">Voltar</a>
        </form>
    </div>
</div>

<div class="card border-info">
    <div class="card-header bg-info text-white">Formato esperado</div>
    <div class="card-body">
        <p>Colunas (separadas por vírgula), com cabeçalho na primeira linha:</p>
        <code>Codigo, Descricao, Valor, Modo Cobranca</code>
        <ul class="mt-3 mb-0">
            <li><strong>Valor</strong>: valor da licença (ex.: 120,00).</li>
            <li><strong>Modo Cobranca</strong>: opcional (ex.: Mensal, Anual).</li>
            <li>O separador de colunas pode ser vírgula ou ponto e vírgula (detectado automaticamente).</li>
        </ul>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
