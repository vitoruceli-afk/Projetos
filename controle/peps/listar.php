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

// Helper para formatar data de ISO para brasileiro
$formatarData = static function(?string $data): string {
    if (!$data || $data === '') return '—';
    $parts = explode('-', $data);
    if (count($parts) === 3) {
        return $parts[2] . '/' . $parts[1] . '/' . $parts[0];
    }
    return $data;
};
?>

<div class="page-head">
    <div>
        <h1 class="page-title">PEPs / Projetos</h1>
        <p class="page-sub">Cadastro de Projetos/PEPs compartilhado entre os rateios</p>
    </div>
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
<div class="entity-list-toolbar">
    <form method="GET" class="d-flex flex-wrap gap-2 align-items-center" style="flex: 1;">
        <input type="text" name="busca" class="form-control" placeholder="Buscar em PEP, projeto, centro de custo..."
               value="<?= e($busca) ?>" style="max-width: 400px;">
        <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-search"></i> Filtrar</button>
        <a href="<?= url('peps/listar.php') ?>" class="btn btn-outline-secondary">Limpar</a>
        <a href="<?= url('peps/exportar.php?busca=' . urlencode($busca)) ?>" class="btn btn-success ms-auto">
            <i class="bi bi-filetype-csv"></i> Exportar CSV
        </a>
    </form>
</div>

<?php if ($ehAdmin): ?>
<form method="POST" action="<?= url('peps/excluir.php') ?>" class="mb-3">
    <button type="submit" class="btn btn-danger"
            onclick="return confirm('Excluir os PEPs selecionados? Esta ação não pode ser desfeita.')">
        <i class="bi bi-trash"></i> Excluir Selecionados
    </button>
    <a href="<?= url('peps/exportar.php') ?>" class="btn btn-outline-success">
        <i class="bi bi-download"></i> Exportar Todos
    </a>
<?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <?php if ($ehAdmin): ?>
                            <th width="40"><input type="checkbox" id="selecionarTodos"></th>
                        <?php endif; ?>
                        <th>PEP</th>
                        <th>Projeto</th>
                        <th>Centro de Custo</th>
                        <th>Período</th>
                        <th>Data de Início</th>
                        <th>Data de Término</th>
                        <th>Responsável</th>
                        <th>CPF</th>
                        <?php if ($ehAdmin): ?><th width="120">Ações</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($peps as $p): ?>
                    <tr>
                        <?php if ($ehAdmin): ?>
                            <td><input type="checkbox" name="ids[]" value="<?= $p['id'] ?>" class="checkbox-item"></td>
                        <?php endif; ?>
                        <td>
                            <strong><?= e($p['pep']) ?></strong>
                        </td>
                        <td><?= e($p['projeto']) ?></td>
                        <td>
                            <?php if ($p['centro_custo']): ?>
                                <span class="badge bg-info"><?= e($p['centro_custo']) ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($p['periodo_meses']): ?>
                                <?= $p['periodo_meses'] ?> mês<?= $p['periodo_meses'] > 1 ? 'es' : '' ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $formatarData($p['data_inicio']) ?></td>
                        <td><?= $formatarData($p['data_termino']) ?></td>
                        <td><?= $p['responsavel_nome'] ? e($p['responsavel_nome']) : '—' ?></td>
                        <td>
                            <?php if ($p['responsavel_cpf']): ?>
                                <code><?= e($p['responsavel_cpf']) ?></code>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($ehAdmin): ?>
                            <td>
                                <a href="<?= url('peps/form.php?id=' . $p['id']) ?>"
                                   class="btn btn-warning btn-sm" title="Editar">
                                   <i class="bi bi-pencil"></i> Editar
                                </a>
                                <a href="<?= url('peps/excluir.php?id=' . $p['id']) ?>"
                                   class="btn btn-danger btn-sm"
                                   onclick="return confirm('Excluir este PEP?')" title="Excluir">
                                   <i class="bi bi-trash"></i>
                                </a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if ($peps === []): ?>
                    <tr><td colspan="<?= $ehAdmin ? 10 : 9 ?>" class="text-center text-muted py-4">
                        <i class="bi bi-inbox" style="font-size: 2rem; opacity: 0.5;"></i><br>
                        Nenhum PEP encontrado.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php if ($ehAdmin): ?>
</form>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
