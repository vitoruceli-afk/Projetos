<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Models\TelefoniaConta;

$contexto     = 'telefonia';
$tituloPagina = 'Contas';

require __DIR__ . '/../../includes/header.php';

$busca   = trim($_GET['busca'] ?? '');
$contas  = TelefoniaConta::listar($busca);
$ehAdmin = Auth::ehAdmin();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Contas</h2>
    <?php if ($ehAdmin): ?>
        <div>
            <a href="<?= url('telefonia/contas/atualizar_valores.php') ?>" class="btn btn-outline-primary">
                <i class="bi bi-currency-dollar"></i> Atualizar Valores (CSV)
            </a>
            <a href="<?= url('telefonia/contas/importar.php') ?>" class="btn btn-outline-danger">
                <i class="bi bi-upload"></i> Importar CSV
            </a>
            <a href="<?= url('telefonia/contas/form.php') ?>" class="btn btn-danger">
                <i class="bi bi-plus-lg"></i> Nova Conta
            </a>
        </div>
    <?php endif; ?>
</div>

<form method="GET" class="filtro-bar d-flex flex-wrap gap-2 align-items-center mb-3">
    <input type="text" name="busca" class="form-control" placeholder="Buscar em todas as colunas..."
           value="<?= e($busca) ?>">
    <button class="btn btn-outline-secondary"><i class="bi bi-search"></i> Filtrar</button>
    <a href="<?= url('telefonia/contas/listar.php') ?>" class="btn btn-outline-secondary">Limpar</a>
</form>

<form method="POST">
    <div class="d-flex flex-wrap gap-2 mb-2">
        <button type="submit" formaction="<?= url('telefonia/contas/exportar.php') ?>" class="btn btn-success">
            <i class="bi bi-filetype-csv"></i> Exportar Selecionadas
        </button>
        <a href="<?= url('telefonia/contas/exportar.php?todas=1&busca=' . urlencode($busca)) ?>"
           class="btn btn-outline-success">Exportar Todas</a>
        <?php if ($ehAdmin): ?>
            <button type="submit" formaction="<?= url('telefonia/contas/excluir.php') ?>"
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
                        <th>Nome do Usuário</th>
                        <th>Telefone</th>
                        <th>Operadora</th>
                        <th>PEP</th>
                        <th width="140">Valor</th>
                        <th>Conta Telefonia</th>
                        <?php if ($ehAdmin): ?><th width="160">Ações</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($contas as $c): ?>
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="<?= $c['id'] ?>" class="checkbox-item"></td>
                        <td><?= e($c['nome_usuario']) ?></td>
                        <td><?= e($c['telefone']) ?></td>
                        <td><?= e($c['operadora']) ?></td>
                        <td><?= e($c['pep']) ?></td>
                        <td><?= money($c['valor']) ?></td>
                        <td><?= e($c['conta_telefonia']) ?></td>
                        <?php if ($ehAdmin): ?>
                            <td>
                                <a href="<?= url('telefonia/contas/form.php?id=' . $c['id']) ?>"
                                   class="btn btn-warning btn-sm">Editar</a>
                                <a href="<?= url('telefonia/contas/excluir.php?id=' . $c['id']) ?>"
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
