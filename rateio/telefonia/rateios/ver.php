<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Models\RateioHistorico;

require __DIR__ . '/../../includes/bootstrap.php';
Auth::exigirLogin();

$id  = (int) ($_GET['id'] ?? 0);
$reg = RateioHistorico::buscarPorId($id);

if ($reg === null || $reg['tipo'] !== 'telefonia') {
    Session::flash('danger', 'Rateio não encontrado.');
    header('Location: ' . url('telefonia/rateios/listar.php'));
    exit;
}

$linhas = json_decode($reg['dados_json'] ?? '[]', true) ?: [];

// Resumo agrupado por Conta Telefonia -> PEP
$resumo     = [];   // [conta][pep] = soma valor_final
$totalConta = [];   // [conta] = soma valor_final
foreach ($linhas as $l) {
    $conta = trim((string) ($l['conta_telefonia'] ?? ''));
    if ($conta === '') {
        $conta = '(Sem conta telefonia)';
    }
    $pep   = (string) $l['pep'];
    $valor = (float) $l['valor_final'];

    $resumo[$conta][$pep] = ($resumo[$conta][$pep] ?? 0) + $valor;
    $totalConta[$conta]   = ($totalConta[$conta] ?? 0) + $valor;
}
ksort($resumo);

$contexto     = 'telefonia';
$tituloPagina = 'Consultar Rateio';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0"><?= e($reg['descricao']) ?></h2>
    <a href="<?= url('telefonia/rateios/exportar.php?id=' . $reg['id']) ?>" class="btn btn-success">
        <i class="bi bi-filetype-csv"></i> Exportar CSV
    </a>
</div>

<p class="text-muted">
    Referência <?= e($reg['mes']) ?>/<?= e($reg['ano']) ?>
    &middot; gerado por <?= e($reg['gerado_por']) ?>
    em <?= e(date('d/m/Y H:i', strtotime($reg['gerado_em']))) ?>
</p>

<div class="card shadow-sm mb-4">
    <div class="card-body table-responsive">
        <table class="table table-bordered align-middle">
            <thead class="table-dark">
                <tr>
                    <th>PEP</th>
                    <th>Projeto</th>
                    <th>Usuário</th>
                    <th>Conta Telefonia</th>
                    <th class="text-end">Valor Usuário (U)</th>
                    <th class="text-end">% Diferença</th>
                    <th class="text-end">Valor Final (X)</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($linhas as $l): ?>
                <tr>
                    <td><?= e($l['pep']) ?></td>
                    <td><?= e($l['projeto']) ?></td>
                    <td><?= e($l['nome']) ?></td>
                    <td><?= e($l['conta_telefonia'] ?? '') ?></td>
                    <td class="text-end"><?= money($l['valor_usuario']) ?></td>
                    <td class="text-end"><?= money($l['percentual']) ?></td>
                    <td class="text-end"><?= money($l['valor_final']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header fw-bold">Resumo por Conta Telefonia</div>
    <div class="card-body table-responsive">
        <table class="table table-bordered align-middle mb-0">
            <tbody>
            <?php foreach ($resumo as $conta => $peps): ?>
                <tr class="table-dark">
                    <th colspan="2">Conta Telefonia: <?= e($conta) ?></th>
                    <th class="text-end"><?= money($totalConta[$conta]) ?></th>
                </tr>
                <?php foreach ($peps as $pep => $tot): ?>
                    <tr>
                        <td style="width:48px"></td>
                        <td>PEP <?= e($pep) ?></td>
                        <td class="text-end"><?= money($tot) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="row">
    <div class="col-md-3"><div class="card text-center"><div class="card-body">
        <small class="text-muted">Valor das Notas</small>
        <h5><?= money($reg['valor_boleto']) ?></h5>
    </div></div></div>
    <div class="col-md-3"><div class="card text-center"><div class="card-body">
        <small class="text-muted">Soma das Contas</small>
        <h5><?= money($reg['total_contas']) ?></h5>
    </div></div></div>
    <div class="col-md-3"><div class="card text-center"><div class="card-body">
        <small class="text-muted">Diferença</small>
        <h5><?= money($reg['diferenca']) ?></h5>
    </div></div></div>
    <div class="col-md-3"><div class="card text-center bg-success text-white"><div class="card-body">
        <small>Total Geral Rateado</small>
        <h5><?= money($reg['total_final']) ?></h5>
    </div></div></div>
</div>

<a href="<?= url('telefonia/rateios/listar.php') ?>" class="btn btn-secondary mt-3">Voltar</a>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
