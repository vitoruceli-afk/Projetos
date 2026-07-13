<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\CsvReader;
use App\Models\TelefoniaConta;

require __DIR__ . '/../../includes/bootstrap.php';
Auth::exigirAdmin();

$cabecalho = ['Telefone', 'Valor'];

$resultado = null;

/**
 * Mantém apenas os dígitos do telefone para comparação robusta
 * (ignora máscara, parênteses, traços e espaços).
 */
$normalizar = static fn(string $t): string => preg_replace('/\D+/', '', $t) ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $resultado = [
        'atualizadas'        => [],   // [telefone => valor]
        'nao_encontradas'    => [],   // telefones do CSV sem correspondência na base
        'sem_valor_no_csv'   => [],   // contas da base que não vieram no CSV
        'erro'               => null,
    ];

    if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
        $resultado['erro'] = 'Falha no envio do arquivo.';
    } else {
        $linhas = CsvReader::ler($_FILES['arquivo']['tmp_name'], $cabecalho);

        if ($linhas === []) {
            $resultado['erro'] = 'O arquivo não contém dados.';
        } else {
            // Índice das contas da base por telefone normalizado
            $contas = TelefoniaConta::paraAtualizacaoEmMassa();
            $indice = [];           // telefoneNormalizado => ['id'=>.., 'telefone'=>.., 'nome'=>..]
            foreach ($contas as $c) {
                $indice[$normalizar((string) $c['telefone'])] = $c;
            }

            $vistos = [];           // telefones da base efetivamente presentes no CSV

            foreach ($linhas as $cols) {
                $telefoneCsv = trim($cols[0] ?? '');
                if ($telefoneCsv === '') {
                    continue;
                }
                $valor = valorBr($cols[1] ?? '0');
                $chave = $normalizar($telefoneCsv);

                if (isset($indice[$chave])) {
                    TelefoniaConta::atualizarValor((int) $indice[$chave]['id'], $valor);
                    $resultado['atualizadas'][] = [
                        'telefone' => $indice[$chave]['telefone'],
                        'nome'     => $indice[$chave]['nome_usuario'],
                        'valor'    => $valor,
                    ];
                    $vistos[$chave] = true;
                } else {
                    // Está no CSV mas não foi encontrado na base
                    $resultado['nao_encontradas'][] = $telefoneCsv;
                }
            }

            // Contas da base que NÃO estavam no CSV (consumo não informado no mês)
            foreach ($contas as $c) {
                $chave = $normalizar((string) $c['telefone']);
                if (!isset($vistos[$chave])) {
                    $resultado['sem_valor_no_csv'][] = [
                        'telefone' => $c['telefone'],
                        'nome'     => $c['nome_usuario'],
                    ];
                }
            }
        }
    }
}

$contexto     = 'telefonia';
$tituloPagina = 'Atualizar Valores';
require __DIR__ . '/../../includes/header.php';
?>

<h2 class="mb-4">Atualizar Valores por CSV</h2>

<?php if ($resultado !== null && $resultado['erro'] !== null): ?>
    <div class="alert alert-danger"><?= e($resultado['erro']) ?></div>
<?php elseif ($resultado !== null): ?>

    <div class="alert alert-success">
        <strong><?= count($resultado['atualizadas']) ?></strong> conta(s) atualizada(s).
        <?php if ($resultado['nao_encontradas'] !== []): ?>
            &middot; <strong><?= count($resultado['nao_encontradas']) ?></strong> número(s) do CSV não localizado(s) na base.
        <?php endif; ?>
        <?php if ($resultado['sem_valor_no_csv'] !== []): ?>
            &middot; <strong><?= count($resultado['sem_valor_no_csv']) ?></strong> conta(s) da base sem valor no CSV.
        <?php endif; ?>
    </div>

    <?php if ($resultado['atualizadas'] !== []): ?>
        <div class="card border-success mb-3">
            <div class="card-header bg-success text-white">Contas atualizadas</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Telefone</th><th>Usuário</th><th class="text-end">Novo valor</th></tr></thead>
                    <tbody>
                    <?php foreach ($resultado['atualizadas'] as $a): ?>
                        <tr>
                            <td><?= e($a['telefone']) ?></td>
                            <td><?= e($a['nome']) ?></td>
                            <td class="text-end"><?= money($a['valor']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($resultado['nao_encontradas'] !== []): ?>
        <div class="card border-warning mb-3">
            <div class="card-header bg-warning">Números do CSV não encontrados na base (não aplicados)</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($resultado['nao_encontradas'] as $tel): ?>
                    <li class="list-group-item"><?= e($tel) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($resultado['sem_valor_no_csv'] !== []): ?>
        <div class="card border-secondary mb-3">
            <div class="card-header bg-secondary text-white">Contas da base sem valor no CSV (não atualizadas)</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($resultado['sem_valor_no_csv'] as $c): ?>
                    <li class="list-group-item"><?= e($c['telefone']) ?> &middot; <?= e($c['nome']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <div class="mb-3">
                <label class="form-label">Arquivo CSV (consumo do mês)</label>
                <input type="file" name="arquivo" accept=".csv,text/csv" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-success">
                <i class="bi bi-currency-dollar"></i> Atualizar Valores
            </button>
            <a href="<?= url('telefonia/contas/listar.php') ?>" class="btn btn-secondary">Voltar</a>
        </form>
    </div>
</div>

<div class="card border-info">
    <div class="card-header bg-info text-white">Como funciona</div>
    <div class="card-body">
        <p class="mb-2">Envie um CSV com o consumo do mês. Colunas, com cabeçalho na primeira linha:</p>
        <code>Telefone, Valor</code>
        <ul class="mt-3 mb-0">
            <li>O valor de cada conta é atualizado pelo <strong>número de telefone</strong>
                (a comparação ignora máscara, parênteses, traços e espaços).</li>
            <li>Números presentes no CSV mas não encontrados na base são <strong>informados</strong> e ignorados.</li>
            <li>Contas da base que não vierem no CSV são <strong>informadas</strong> (não atualizadas).</li>
            <li>As atualizações válidas são <strong>sempre executadas</strong>, mesmo havendo divergências.</li>
            <li>Separador de colunas pode ser vírgula ou ponto e vírgula (detectado automaticamente).</li>
        </ul>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
