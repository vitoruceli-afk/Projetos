<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Models\Contato;

require __DIR__ . '/../includes/bootstrap.php';
Auth::exigirAdmin();

$contexto     = 'inicial';
$tituloPagina = 'Contatos';

require __DIR__ . '/../includes/header.php';

$busca    = trim($_GET['busca'] ?? '');
$contatos = Contato::listar($busca);
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Contatos / Listas de E-mail</h2>
    <a href="<?= url('contatos/form.php') ?>" class="btn btn-primary">
        <i class="bi bi-plus-lg"></i> Novo Contato
    </a>
</div>

<p class="text-muted">
    Cada contato pode pertencer à lista da gerência <strong>Microsoft</strong>,
    <strong>Telefonia</strong> ou a ambas. Os rateios são enviados para os contatos
    da lista correspondente.
</p>

<form method="GET" class="filtro-bar d-flex flex-wrap gap-2 align-items-center mb-3">
    <input type="text" name="busca" class="form-control" placeholder="Buscar por nome ou e-mail..."
           value="<?= e($busca) ?>">
    <button class="btn btn-outline-secondary"><i class="bi bi-search"></i> Filtrar</button>
    <a href="<?= url('contatos/listar.php') ?>" class="btn btn-outline-secondary">Limpar</a>
</form>

<div class="card shadow-sm">
    <div class="card-body table-responsive">
        <table class="table table-bordered table-hover align-middle">
            <thead class="table-dark">
                <tr>
                    <th>Nome</th>
                    <th>E-mail</th>
                    <th class="text-center">Lista Microsoft</th>
                    <th class="text-center">Lista Telefonia</th>
                    <th width="160">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($contatos as $ct): ?>
                <tr>
                    <td><?= e($ct['nome']) ?></td>
                    <td><?= e($ct['email']) ?></td>
                    <td class="text-center">
                        <?php if ($ct['lista_microsoft']): ?>
                            <span class="badge bg-primary">Sim</span>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($ct['lista_telefonia']): ?>
                            <span class="badge bg-danger">Sim</span>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= url('contatos/form.php?id=' . $ct['id']) ?>" class="btn btn-warning btn-sm">Editar</a>
                        <a href="<?= url('contatos/excluir.php?id=' . $ct['id']) ?>" class="btn btn-danger btn-sm"
                           onclick="return confirm('Excluir este contato?')">Excluir</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($contatos === []): ?>
                <tr><td colspan="5" class="text-center text-muted">Nenhum contato cadastrado.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
