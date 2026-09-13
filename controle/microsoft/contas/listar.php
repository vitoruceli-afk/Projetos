<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Models\MsConta;

$contexto     = 'microsoft';
$tituloPagina = 'Contas';

require __DIR__ . '/../../includes/header.php';

$busca   = trim($_GET['busca'] ?? '');
$contas  = MsConta::listar($busca);
$ehAdmin = Auth::ehAdmin();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Contas</h2>
    <?php if ($ehAdmin): ?>
        <div>
            <a href="<?= url('microsoft/contas/importar.php') ?>" class="btn btn-outline-primary">
                <i class="bi bi-upload"></i> Importar CSV
            </a>
            <a href="<?= url('microsoft/contas/form.php') ?>" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> Nova Conta
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- FILTRO -->
<form method="GET" class="filtro-bar d-flex flex-wrap gap-2 align-items-center mb-3">
    <input type="text" name="busca" class="form-control" placeholder="Buscar em todas as colunas..."
           value="<?= e($busca) ?>">
    <button class="btn btn-outline-secondary"><i class="bi bi-search"></i> Filtrar</button>
    <a href="<?= url('microsoft/contas/listar.php') ?>" class="btn btn-outline-secondary">Limpar</a>
</form>

<!-- LISTA + EXPORTAÇÃO/EXCLUSÃO -->
<form method="POST" id="formContas">
    <div class="d-flex flex-wrap gap-2 mb-2">
        <button type="submit" formaction="<?= url('microsoft/contas/exportar.php') ?>"
                class="btn btn-success">
            <i class="bi bi-filetype-csv"></i> Exportar Selecionadas
        </button>
        <a href="<?= url('microsoft/contas/exportar.php?todas=1&busca=' . urlencode($busca)) ?>"
           class="btn btn-outline-success">Exportar Todas</a>

        <?php if ($ehAdmin): ?>
            <button type="submit" formaction="<?= url('microsoft/contas/excluir.php') ?>"
                    class="btn btn-danger ms-auto"
                    onclick="return confirm('Excluir as contas selecionadas?')">
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
                        <th>Nome</th>
                        <th>Email</th>
                        <th>PEP</th>
                        <th>Projeto</th>
                        <th>Licenças</th>
                        <th width="130">Valor Total</th>
                        <?php if ($ehAdmin): ?><th width="160">Ações</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($contas as $c): ?>
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="<?= $c['id'] ?>" class="checkbox-item"></td>
                        <td><?= e($c['nome']) ?></td>
                        <td><?= e($c['email']) ?></td>
                        <td><?= e($c['pep']) ?></td>
                        <td><?= e($c['projeto']) ?></td>
                        <td><?= e($c['licencas']) ?></td>
                        <td><?= money($c['valor_total']) ?></td>
                        <?php if ($ehAdmin): ?>
                            <td>
                                <a href="<?= url('microsoft/contas/form.php?id=' . $c['id']) ?>"
                                   class="btn btn-warning btn-sm">Editar</a>
                                <a href="<?= url('microsoft/contas/excluir.php?id=' . $c['id']) ?>"
                                   class="btn btn-danger btn-sm"
                                   onclick="return confirm('Excluir esta conta?')">Excluir</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if ($contas === []): ?>
                    <tr><td colspan="<?= $ehAdmin ? 8 : 7 ?>" class="text-center text-muted">
                        Nenhuma conta encontrada.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</form>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
