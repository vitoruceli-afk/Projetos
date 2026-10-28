<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Models\Dispositivo;
use App\Models\TipoDispositivo;
use App\Models\Pep;

require __DIR__ . '/../includes/bootstrap.php';
Auth::exigirAdmin();

/*
|--------------------------------------------------------------------------
| Ordem fixa das colunas do CSV (mapeamento por posição, não por cabeçalho)
|--------------------------------------------------------------------------
*/
const CSV_COLUNAS = [
    'Nome', 'Usuário', 'Processador', 'Memoria', 'Armazenamento', 'SO',
    'Tipo', 'Localização/Projeto', 'Área', '2º Tela', 'PEP', 'PROJETO',
];

// Download do modelo de planilha (antes de qualquer output)
if (isset($_GET['modelo'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="modelo_importacao_dispositivos.csv"');
    echo "\xEF\xBB\xBF" . implode(';', CSV_COLUNAS) . "\r\n";
    echo implode(';', [
        'Notebook Dell 01', 'Fulano da Silva', 'Intel i5-1135G7', '8GB', '256GB SSD',
        'Windows 11', 'Computador', 'Sede - Sala 3', 'TI', 'Não', '1000.01', 'Projeto Exemplo',
    ]) . "\r\n";
    exit;
}

/**
 * Lê um arquivo CSV (delimitado por ";") e devolve as linhas já em UTF-8,
 * ignorando a primeira linha (cabeçalho).
 *
 * @return array<int,array<int,string>>
 */
function lerLinhasCsv(string $caminho): array
{
    $linhas = [];
    $handle = fopen($caminho, 'r');
    if ($handle === false) {
        return $linhas;
    }

    $primeira = true;
    while (($colunas = fgetcsv($handle, 0, ';')) !== false) {
        if ($colunas === [null] || $colunas === false) {
            continue;
        }
        $colunas = array_map(static function ($v) {
            $v = (string) $v;
            if (!mb_check_encoding($v, 'UTF-8')) {
                $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
            }
            return trim($v);
        }, $colunas);

        // Remove BOM que porventura sobre no primeiro campo da primeira linha
        if ($primeira) {
            $colunas[0] = preg_replace('/^\xEF\xBB\xBF/', '', $colunas[0]);
        }

        if ($primeira) {
            $primeira = false;
            continue; // pula o cabeçalho
        }

        if (count(array_filter($colunas, static fn($v) => $v !== '')) === 0) {
            continue; // linha em branco
        }

        $linhas[] = $colunas;
    }
    fclose($handle);
    return $linhas;
}

$resultado = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $arquivo = $_FILES['arquivo_csv'] ?? null;

    if ($arquivo === null || $arquivo['error'] === UPLOAD_ERR_NO_FILE) {
        $resultado = ['erro' => 'Selecione um arquivo CSV para importar.'];
    } elseif ($arquivo['error'] !== UPLOAD_ERR_OK) {
        $resultado = ['erro' => 'Falha no upload do arquivo (código ' . $arquivo['error'] . ').'];
    } else {
        $linhas = lerLinhasCsv($arquivo['tmp_name']);

        if ($linhas === []) {
            $resultado = ['erro' => 'O arquivo está vazio ou não contém linhas de dados após o cabeçalho.'];
        } else {
            $pdo = Database::pdo();

            // Cache local dos tipos/PEPs já vistos nesta importação, para não
            // criar duplicados quando o mesmo Tipo/PEP aparece em várias linhas.
            $tiposCache = [];
            $pepsCache  = [];

            $criados = 0;
            $atualizados = 0;
            $tiposCriados = [];
            $pepsCriados = [];
            $avisos = [];

            $pdo->beginTransaction();
            try {
                foreach ($linhas as $i => $col) {
                    $numeroLinha = $i + 2; // +1 pelo índice base 0, +1 pelo cabeçalho

                    $nome          = $col[0] ?? '';
                    $usuario       = $col[1] ?? '';
                    $processador   = $col[2] ?? '';
                    $memoria       = $col[3] ?? '';
                    $armazenamento = $col[4] ?? '';
                    $so            = $col[5] ?? '';
                    $tipoNome      = $col[6] ?? '';
                    $localizacao   = $col[7] ?? '';
                    $area          = $col[8] ?? '';
                    $segundaTela   = $col[9] ?? '';
                    $pepCodigo     = $col[10] ?? '';
                    $pepProjeto    = $col[11] ?? '';

                    if ($nome === '') {
                        $avisos[] = "Linha $numeroLinha: ignorada — coluna \"Nome\" vazia.";
                        continue;
                    }
                    if ($tipoNome === '') {
                        $avisos[] = "Linha $numeroLinha (\"$nome\"): ignorada — coluna \"Tipo\" vazia.";
                        continue;
                    }

                    // Localiza ou cria o Tipo de Dispositivo
                    $chaveTipo = mb_strtolower($tipoNome);
                    if (!isset($tiposCache[$chaveTipo])) {
                        $tipo = TipoDispositivo::porNomeCI($tipoNome);
                        if ($tipo !== null) {
                            $tiposCache[$chaveTipo] = (int) $tipo['id'];
                        } else {
                            $tiposCache[$chaveTipo] = TipoDispositivo::criar($tipoNome);
                            $tiposCriados[] = $tipoNome;
                        }
                    }
                    $tipoId = $tiposCache[$chaveTipo];

                    // Localiza ou cria o PEP (se informado)
                    $pepId = null;
                    if ($pepCodigo !== '') {
                        $chavePep = mb_strtolower($pepCodigo);
                        if (!isset($pepsCache[$chavePep])) {
                            $pep = Pep::porCodigo($pepCodigo);
                            if ($pep !== null) {
                                $pepsCache[$chavePep] = (int) $pep['id'];
                            } else {
                                $pepsCache[$chavePep] = Pep::criar($pepCodigo, $pepProjeto);
                                $pepsCriados[] = $pepCodigo;
                            }
                        }
                        $pepId = $pepsCache[$chavePep];
                    }

                    $r = Dispositivo::importarLinha([
                        'nome'                => $nome,
                        'tipo_id'             => $tipoId,
                        'pep_id'              => $pepId,
                        'responsavel'         => $usuario,
                        'processador'         => $processador,
                        'memoria'             => $memoria,
                        'armazenamento'       => $armazenamento,
                        'sistema_operacional' => $so,
                        'localizacao'         => $localizacao,
                        'area'                => $area,
                        'segunda_tela'        => $segundaTela,
                    ]);

                    if ($r['criado']) {
                        $criados++;
                    } else {
                        $atualizados++;
                    }
                }

                $pdo->commit();

                $resultado = [
                    'sucesso' => "Importação concluída: $criados dispositivo(s) criado(s), $atualizados atualizado(s).",
                    'tiposCriados' => $tiposCriados,
                    'pepsCriados' => $pepsCriados,
                    'avisos' => $avisos,
                ];
            } catch (\Throwable $e) {
                $pdo->rollBack();
                $resultado = ['erro' => 'Erro ao importar: ' . $e->getMessage() . ' — nenhuma linha foi salva.'];
            }
        }
    }
}

$contexto     = 'inicial';
$tituloPagina = 'Importar Dispositivos (CSV)';
require __DIR__ . '/../includes/header.php';
?>

<h2 class="mb-4">Importar Dispositivos via CSV</h2>

<?php if ($resultado): ?>
    <?php if (isset($resultado['sucesso'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle"></i> <?= e($resultado['sucesso']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php if (!empty($resultado['tiposCriados'])): ?>
            <div class="alert alert-info">
                <strong>Tipos de dispositivo criados automaticamente:</strong>
                <?= e(implode(', ', array_unique($resultado['tiposCriados']))) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($resultado['pepsCriados'])): ?>
            <div class="alert alert-info">
                <strong>PEPs criados automaticamente:</strong>
                <?= e(implode(', ', array_unique($resultado['pepsCriados']))) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($resultado['avisos'])): ?>
            <div class="alert alert-warning">
                <strong>Linhas ignoradas:</strong>
                <ul class="mb-0">
                    <?php foreach ($resultado['avisos'] as $aviso): ?>
                        <li><?= e($aviso) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    <?php if (isset($resultado['erro'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-circle"></i> <?= e($resultado['erro']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="row">
    <div class="col-lg-6 mb-4">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-upload"></i> Enviar arquivo</h5>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label">Arquivo CSV</label>
                        <input type="file" name="arquivo_csv" class="form-control" accept=".csv" required>
                    </div>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-lg"></i> Importar
                    </button>
                    <a href="<?= url('dispositivos/listar.php') ?>" class="btn btn-secondary">Voltar</a>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6 mb-4">
        <div class="card border-info shadow-sm">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-info-circle"></i> Formato esperado</h6>
            </div>
            <div class="card-body small">
                <p>
                    Arquivo <strong>CSV separado por ponto e vírgula (;)</strong>, com uma linha de
                    cabeçalho seguida pelas linhas de dados, nesta ordem de colunas:
                </p>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered">
                        <tbody>
                            <?php foreach (CSV_COLUNAS as $idx => $c): ?>
                                <tr><td><?= $idx + 1 ?></td><td><?= e($c) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <ul class="ps-3">
                    <li><strong>Tipo</strong> e <strong>PEP</strong> que ainda não existirem no sistema são criados automaticamente (o PEP novo usa a coluna <strong>PROJETO</strong> como nome do projeto).</li>
                    <li>Se já existir um dispositivo com o mesmo <strong>Nome</strong> (ignorando maiúsc./minúsc.), ele é <strong>atualizado</strong> em vez de duplicado.</li>
                    <li>Linhas sem <strong>Nome</strong> ou sem <strong>Tipo</strong> são ignoradas e listadas no resumo.</li>
                </ul>
                <a href="<?= url('dispositivos/importar.php?modelo=1') ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-download"></i> Baixar modelo de planilha
                </a>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
