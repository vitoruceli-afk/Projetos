<?php

declare(strict_types=1);

use App\Models\MsCobranca;

$contexto     = 'microsoft';
$tituloPagina = 'Relatórios';

require __DIR__ . '/../../includes/header.php';

$cobrancas = MsCobranca::listar();
?>

<h2 class="mb-4">Relatório Financeiro por PEP</h2>

<p class="text-muted">
    Selecione as cobranças do mês para gerar o rateio proporcional por PEP.
    O resultado é armazenado em <strong>Rateios Gerados</strong> e pode ser exportado em CSV.
</p>

<?php if ($cobrancas === []): ?>
    <div class="alert alert-warning">
        Nenhuma cobrança cadastrada. Cadastre em
        <a href="<?= url('microsoft/cobrancas/listar.php') ?>">Cobranças</a>.
    </div>
<?php else: ?>

<form action="<?= url('microsoft/relatorios/gerar.php') ?>" method="POST">

    <div class="card shadow-sm mb-3">
        <div class="card-header">Identificação do rateio</div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">Descrição (opcional)</label>
                <input type="text" name="descricao" class="form-control"
                       placeholder="Ex.: Rateio Microsoft - referência do mês">
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header">Selecione as cobranças</div>
        <div class="card-body">
            <?php foreach ($cobrancas as $c): ?>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="cobrancas[]"
                           value="<?= $c['id'] ?>" id="cob<?= $c['id'] ?>">
                    <label class="form-check-label" for="cob<?= $c['id'] ?>">
                        <strong>Cobrança #<?= $c['id'] ?></strong>
                        &middot; <?= e($c['mes']) ?>/<?= e($c['ano']) ?>
                        &middot; <?= money($c['valor_total']) ?>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <button type="submit" class="btn btn-success">
        <i class="bi bi-calculator"></i> Gerar e Armazenar Rateio
    </button>
    <a href="<?= url('microsoft/rateios/listar.php') ?>" class="btn btn-outline-secondary">
        Ver Rateios Gerados
    </a>
</form>

<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
