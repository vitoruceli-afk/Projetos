<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Models\Pep;

$contexto     = 'inicial';
$tituloPagina = 'PEPs / Projetos';

require __DIR__ . '/../includes/header.php';

$busca = trim($_GET['busca'] ?? '');
$peps  = Pep::listar($busca);
$ehAdmin = Auth::ehAdmin();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">PEPs / Projetos</h2>
    <?php if ($ehAdmin): ?>
        <div>
            <a href="<?= url('peps/importar.php') ?>" class="btn btn-outline-primary">
                <i class="bi bi-upload"></i> Importar CSV
            </a>
            <a href="<?= url('peps/form.php') ?>" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> Novo PEP
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- FILTRO + EXPORTAÇÃO -->
<form method="GET" class="filtro-bar d-flex flex-wrap gap-2 align-items-center mb-3">
    <input type="text" name="busca" class="form-control" placeholder="Buscar em todas as colunas..."
           value="<?= e($busca) ?>">
    <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-search"></i> Filtrar</button>
    <a href="<?= url('peps/listar.php') ?>" class="btn btn-outline-secondary">Limpar</a>
    <a href="<?= url('peps/exportar.php?busca=' . urlencode($busca)) ?>" class="btn btn-success ms-auto">
        <i class="bi bi-filetype-csv"></i> Exportar CSV
    </a>
</form>

<form method="POST" action="<?= url('peps/excluir.php') ?>">
    <?php if ($ehAdmin): ?>
        <button type="submit" class="btn btn-danger mb-2"
                onclick="return confirm('Excluir os PEPs selecionados?')">
            <i class="bi bi-trash"></i> Excluir Selecionados
        </button>
        <a href="<?= url('peps/exportar.php') ?>" class="btn btn-outline-success mb-2">
            Exportar Todos
        </a>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <?php if ($ehAdmin): ?>
                            <th width="40"><input type="checkbox" id="selecionarTodos"></th>
                        <?php endif; ?>
                        <th>PEP</th>
                        <th>Projeto</th>
                        <?php if ($ehAdmin): ?><th width="160">Ações</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($peps as $p): ?>
                    <tr>
                        <?php if ($ehAdmin): ?>
                            <td><input type="checkbox" name="ids[]" value="<?= $p['id'] ?>" class="checkbox-item"></td>
                        <?php endif; ?>
                        <td><?= e($p['pep']) ?></td>
                        <td><?= e($p['projeto']) ?></td>
                        <?php if ($ehAdmin): ?>
                            <td>
                                <a href="<?= url('peps/form.php?id=' . $p['id']) ?>"
                                   class="btn btn-warning btn-sm">Editar</a>
                                <a href="<?= url('peps/excluir.php?id=' . $p['id']) ?>"
                                   class="btn btn-danger btn-sm"
                                   onclick="return confirm('Excluir este PEP?')">Excluir</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if ($peps === []): ?>
                    <tr><td colspan="<?= $ehAdmin ? 4 : 2 ?>" class="text-center text-muted">
                        Nenhum PEP encontrado.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
