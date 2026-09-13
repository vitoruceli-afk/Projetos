<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Models\Usuario;

require __DIR__ . '/../includes/bootstrap.php';
Auth::exigirAdmin();

$contexto     = 'inicial';
$tituloPagina = 'Usuários';

require __DIR__ . '/../includes/header.php';

$usuarios = Usuario::todos('nome');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Usuários</h2>
    <div>
        <a href="<?= url('usuarios/ldap.php') ?>" class="btn btn-outline-secondary">
            <i class="bi bi-hdd-network"></i> Integração LDAP
        </a>
        <a href="<?= url('usuarios/form.php') ?>" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> Novo Usuário
        </a>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body table-responsive">
        <table class="table table-bordered table-hover align-middle">
            <thead class="table-dark">
                <tr>
                    <th>Nome</th>
                    <th>Login</th>
                    <th>Email</th>
                    <th>Perfil</th>
                    <th>Origem</th>
                    <th width="170">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($usuarios as $u): ?>
                <tr>
                    <td><?= e($u['nome']) ?></td>
                    <td><?= e($u['usuario']) ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td>
                        <span class="badge bg-<?= $u['perfil'] === 'admin' ? 'success' : 'info' ?>">
                            <?= e(ucfirst($u['perfil'])) ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge bg-<?= $u['origem'] === 'ldap' ? 'warning text-dark' : 'secondary' ?>">
                            <?= e(strtoupper($u['origem'])) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($u['origem'] === 'local'): ?>
                            <a href="<?= url('usuarios/form.php?id=' . $u['id']) ?>"
                               class="btn btn-warning btn-sm">Editar</a>
                        <?php endif; ?>
                        <a href="<?= url('usuarios/excluir.php?id=' . $u['id']) ?>"
                           class="btn btn-danger btn-sm"
                           onclick="return confirm('Excluir este usuário?')">Excluir</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($usuarios === []): ?>
                <tr><td colspan="6" class="text-center text-muted">Nenhum usuário cadastrado.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
