<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Models\MsLicenca;

$contexto     = 'microsoft';
$tituloPagina = 'Licenças';

require __DIR__ . '/../../includes/header.php';

$busca    = trim($_GET['busca'] ?? '');
$licencas = MsLicenca::listar($busca);
$ehAdmin  = Auth::ehAdmin();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Licenças</h2>
    <?php if ($ehAdmin): ?>
        <a href="<?= url('microsoft/licencas/form.php') ?>" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> Nova Licença
        </a>
    <?php endif; ?>
</div>

<form method="GET" class="filtro-bar d-flex flex-wrap gap-2 align-items-center mb-3">
    <input type="text" name="busca" class="form-control" placeholder="Buscar em todas as colunas..."
           value="<?= e($busca) ?>">
    <button class="btn btn-outline-secondary"><i class="bi bi-search"></i> Filtrar</button>
    <a href="<?= url('microsoft/licencas/listar.php') ?>" class="btn btn-outline-secondary">Limpar</a>
</form>

<form method="POST">
    <div class="d-flex flex-wrap gap-2 mb-2">
        <button type="submit" formaction="<?= url('microsoft/licencas/exportar.php') ?>"
                class="btn btn-success">
            <i class="bi bi-filetype-csv"></i> Exportar Selecionadas
        </button>
        <a href="<?= url('microsoft/licencas/exportar.php?todas=1&busca=' . urlencode($busca)) ?>"
           class="btn btn-outline-success">Exportar Todas</a>
        <?php if ($ehAdmin): ?>
            <button type="submit" formaction="<?= url('microsoft/licencas/excluir.php') ?>"
                    class="btn btn-danger ms-auto"
                    onclick="return confirm('Excluir as licenças selecionadas?')">
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
                        <th>Código</th>
                        <th>Descrição</th>
                        <th width="130">Valor</th>
                        <th>Modo Cobrança</th>
                        <?php if ($ehAdmin): ?><th width="160">Ações</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($licencas as $l): ?>
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="<?= $l['id'] ?>" class="checkbox-item"></td>
                        <td><?= e($l['codigo_licenca']) ?></td>
                        <td><?= e($l['descricao']) ?></td>
                        <td><?= money($l['valor']) ?></td>
                        <td><?= e($l['modo_cobranca']) ?></td>
                        <?php if ($ehAdmin): ?>
                            <td>
                                <a href="<?= url('microsoft/licencas/form.php?id=' . $l['id']) ?>"
                                   class="btn btn-warning btn-sm">Editar</a>
                                <a href="<?= url('microsoft/licencas/excluir.php?id=' . $l['id']) ?>"
                                   class="btn btn-danger btn-sm"
                                   onclick="return confirm('Excluir esta licença?')">Excluir</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if ($licencas === []): ?>
                    <tr><td colspan="<?= $ehAdmin ? 6 : 5 ?>" class="text-center text-muted">
                        Nenhuma licença encontrada.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
