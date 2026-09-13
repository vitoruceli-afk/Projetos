<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csv;
use App\Core\CsvReader;
use App\Models\TelefoniaConta;
use App\Models\Pep;

require __DIR__ . '/../../includes/bootstrap.php';
Auth::exigirAdmin();

$cabecalho = ['Nome do Usuario', 'Telefone', 'Operadora', 'PEP', 'Valor', 'Conta Telefonia'];

// Download do modelo CSV
if (isset($_GET['modelo'])) {
    Csv::download('modelo_contas_telefonia.csv', $cabecalho, [
        ['Maria Exemplo', '11999998888', 'Claro', 'PEP001', '89,90', 'CTA-123'],
    ]);
}

$resultado = null;

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

            $nome      = $cols[0] ?? '';
            $telefone  = $cols[1] ?? '';
            $operadora = $cols[2] ?? '';
            $codPep    = $cols[3] ?? '';
            $valor     = valorBr($cols[4] ?? '0');
            $contaTelefonia = $cols[5] ?? '';

            if ($nome === '' || $telefone === '' || $codPep === '') {
                $resultado['erros'][] = "Linha {$numLinha}: Nome, Telefone e PEP são obrigatórios.";
                continue;
            }

            $pep = Pep::porCodigo($codPep);
            if ($pep === null) {
                $resultado['erros'][] = "Linha {$numLinha}: PEP \"{$codPep}\" não cadastrado.";
                continue;
            }

            try {
                TelefoniaConta::criar(
                    $nome,
                    $telefone,
                    $operadora,
                    (int) $pep['id'],
                    $valor,
                    $contaTelefonia
                );
                $resultado['ok']++;
            } catch (\Throwable $e) {
                $resultado['erros'][] = "Linha {$numLinha}: erro ao gravar (" . $e->getMessage() . ').';
            }
        }
    }
}

$contexto     = 'telefonia';
$tituloPagina = 'Importar Contas';
require __DIR__ . '/../../includes/header.php';
?>

<h2 class="mb-4">Importar Contas (CSV)</h2>

<?php if ($resultado !== null): ?>
    <div class="alert alert-<?= $resultado['ok'] > 0 ? 'success' : 'warning' ?>">
        <strong><?= (int) $resultado['ok'] ?></strong> conta(s) importada(s) com sucesso.
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
            <a href="<?= url('telefonia/contas/importar.php?modelo=1') ?>" class="btn btn-outline-secondary">
                <i class="bi bi-download"></i> Baixar modelo
            </a>
            <a href="<?= url('telefonia/contas/listar.php') ?>" class="btn btn-secondary">Voltar</a>
        </form>
    </div>
</div>

<div class="card border-info">
    <div class="card-header bg-info text-white">Formato esperado</div>
    <div class="card-body">
        <p>Colunas (separadas por vírgula), com cabeçalho na primeira linha:</p>
        <code>Nome do Usuario, Telefone, Operadora, PEP, Valor, Conta Telefonia</code>
        <ul class="mt-3 mb-0">
            <li><strong>PEP</strong>: código do PEP já cadastrado em PEPs / Projetos.</li>
            <li><strong>Valor</strong>: valor do consumo (ex.: 89,90).</li>
            <li>O separador de colunas pode ser vírgula ou ponto e vírgula (detectado automaticamente).</li>
        </ul>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
