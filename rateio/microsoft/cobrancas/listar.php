<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Models\MsCobranca;

$contexto     = 'microsoft';
$tituloPagina = 'Cobranças';

require __DIR__ . '/../../includes/header.php';

$busca     = trim($_GET['busca'] ?? '');
$cobrancas = MsCobranca::listar($busca);
$ehAdmin   = Auth::ehAdmin();

$meses = [1=>'Jan',2=>'Fev',3=>'Mar',4=>'Abr',5=>'Mai',6=>'Jun',
          7=>'Jul',8=>'Ago',9=>'Set',10=>'Out',11=>'Nov',12=>'Dez'];
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Cobranças</h2>
    <?php if ($ehAdmin): ?>
        <a href="<?= url('microsoft/cobrancas/form.php') ?>" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> Nova Cobrança
        </a>
    <?php endif; ?>
</div>

<form method="GET" class="filtro-bar d-flex flex-wrap gap-2 align-items-center mb-3">
    <input type="text" name="busca" class="form-control" placeholder="Buscar em todas as colunas..."
           value="<?= e($busca) ?>">
    <button class="btn btn-outline-secondary"><i class="bi bi-search"></i> Filtrar</button>
    <a href="<?= url('microsoft/cobrancas/listar.php') ?>" class="btn btn-outline-secondary">Limpar</a>
</form>

<form method="POST">
    <div class="d-flex flex-wrap gap-2 mb-2">
        <button type="submit" formaction="<?= url('microsoft/cobrancas/exportar.php') ?>"
                class="btn btn-success">
            <i class="bi bi-filetype-csv"></i> Exportar Selecionadas
        </button>
        <a href="<?= url('microsoft/cobrancas/exportar.php?todas=1&busca=' . urlencode($busca)) ?>"
           class="btn btn-outline-success">Exportar Todas</a>
        <?php if ($ehAdmin): ?>
            <button type="submit" formaction="<?= url('microsoft/cobrancas/excluir.php') ?>"
                    class="btn btn-danger ms-auto"
                    onclick="return confirm('Excluir as cobranças selecionadas?')">
                <i class="bi bi-trash"></i> Excluir Selecionadas
            </button>
        <?php endif; ?>
    </div>

    <div class="card shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th width="40"><input type="checkbox" id="selecionarTodos"></th>
                        <th>Mês</th>
                        <th>Ano</th>
                        <th width="160">Valor Total</th>
                        <?php if ($ehAdmin): ?><th width="160">Ações</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($cobrancas as $c): ?>
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="<?= $c['id'] ?>" class="checkbox-item"></td>
                        <td><?= e($meses[(int) $c['mes']] ?? $c['mes']) ?> (<?= e($c['mes']) ?>)</td>
                        <td><?= e($c['ano']) ?></td>
                        <td><?= money($c['valor_total']) ?></td>
                        <?php if ($ehAdmin): ?>
                            <td>
                                <a href="<?= url('microsoft/cobrancas/form.php?id=' . $c['id']) ?>"
                                   class="btn btn-warning btn-sm">Editar</a>
                                <a href="<?= url('microsoft/cobrancas/excluir.php?id=' . $c['id']) ?>"
                                   class="btn btn-danger btn-sm"
                                   onclick="return confirm('Excluir esta cobrança?')">Excluir</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if ($cobrancas === []): ?>
                    <tr><td colspan="<?= $ehAdmin ? 5 : 4 ?>" class="text-center text-muted">
                        Nenhuma cobrança encontrada.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
