<?php

declare(strict_types=1);

use App\Models\TelefoniaCobranca;

$contexto     = 'telefonia';
$tituloPagina = 'Relatórios';

require __DIR__ . '/../../includes/header.php';

$cobrancas = TelefoniaCobranca::listar();
?>

<h2 class="mb-4">Relatório Financeiro por PEP</h2>

<p class="text-muted">
    Selecione as cobranças para gerar o rateio proporcional por PEP,
    usando o valor lançado em cada conta. O resultado é armazenado em
    <strong>Rateios Gerados</strong> e pode ser exportado em CSV.
</p>

<?php if ($cobrancas === []): ?>
    <div class="alert alert-warning">
        Nenhuma cobrança cadastrada. Cadastre em
        <a href="<?= url('telefonia/cobrancas/listar.php') ?>">Cobranças</a>.
    </div>
<?php else: ?>
<form action="<?= url('telefonia/relatorios/gerar.php') ?>" method="POST">

    <div class="card shadow-sm mb-3">
        <div class="card-header">Identificação do rateio</div>
        <div class="card-body">
            <input type="text" name="descricao" class="form-control"
                   placeholder="Descrição (opcional)">
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
                        &middot; Conta Telefonia:
                        <span class="badge bg-secondary"><?= e($c['conta_telefonia'] ?: '(sem conta)') ?></span>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <button type="submit" class="btn btn-success">
        <i class="bi bi-calculator"></i> Gerar e Armazenar Rateio
    </button>
    <a href="<?= url('telefonia/rateios/listar.php') ?>" class="btn btn-outline-secondary">
        Ver Rateios Gerados
    </a>
</form>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
