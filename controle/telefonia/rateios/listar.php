<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Models\RateioHistorico;

$contexto     = 'telefonia';
$tituloPagina = 'Rateios Gerados';

require __DIR__ . '/../../includes/header.php';

$busca    = trim($_GET['busca'] ?? '');
$rateios  = RateioHistorico::listar('telefonia', $busca);
$ehAdmin  = Auth::ehAdmin();
?>

<h2 class="mb-3">Rateios Gerados</h2>
<p class="text-muted">Histórico dos rateios financeiros gerados no mês para consulta posterior.</p>

<form method="GET" class="filtro-bar d-flex flex-wrap gap-2 align-items-center mb-3">
    <input type="text" name="busca" class="form-control" placeholder="Buscar..."
           value="<?= e($busca) ?>">
    <button class="btn btn-outline-secondary"><i class="bi bi-search"></i> Filtrar</button>
    <a href="<?= url('telefonia/rateios/listar.php') ?>" class="btn btn-outline-secondary">Limpar</a>
</form>

<div class="card shadow-sm">
    <div class="card-body table-responsive">
        <table class="table table-bordered table-hover align-middle">
            <thead class="table-dark">
                <tr>
                    <th>Descrição</th>
                    <th>Referência</th>
                    <th>Gerado em</th>
                    <th>Gerado por</th>
                    <th width="150">Total Rateado</th>
                    <th width="200">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rateios as $r): ?>
                <tr>
                    <td><?= e($r['descricao']) ?></td>
                    <td><?= e($r['mes']) ?>/<?= e($r['ano']) ?></td>
                    <td><?= e(date('d/m/Y H:i', strtotime($r['gerado_em']))) ?></td>
                    <td><?= e($r['gerado_por']) ?></td>
                    <td><?= money($r['total_final']) ?></td>
                    <td>
                        <a href="<?= url('telefonia/rateios/ver.php?id=' . $r['id']) ?>"
                           class="btn btn-info btn-sm">Consultar</a>
                        <a href="<?= url('telefonia/rateios/exportar.php?id=' . $r['id']) ?>"
                           class="btn btn-success btn-sm">CSV</a>
                        <a href="<?= url('telefonia/rateios/enviar.php?id=' . $r['id']) ?>"
                           class="btn btn-primary btn-sm"
                           onclick="return confirm('Enviar este rateio por e-mail para a lista Telefonia?')">
                           <i class="bi bi-envelope"></i> E-mail</a>
                        <?php if ($ehAdmin): ?>
                            <a href="<?= url('telefonia/rateios/excluir.php?id=' . $r['id']) ?>"
                               class="btn btn-danger btn-sm"
                               onclick="return confirm('Excluir este rateio gerado?')">Excluir</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rateios === []): ?>
                <tr><td colspan="6" class="text-center text-muted">Nenhum rateio gerado ainda.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
