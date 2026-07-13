<?php include __DIR__ . '/../header.php'; ?>

<?php 
if (isset($_GET['success']) && $_GET['success'] == 1): ?>
    <div class="alert alert-success">
        ✅ Operação realizada com sucesso!
    </div>
<?php endif; 

if (isset($_GET['error'])): ?>
    <div class="alert alert-danger">
        ❌ Erro: <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
<?php endif; ?>

<?php if (!empty($_GET['synced'])): ?>
    <div class="alert alert-info">
        🔄 <?php echo intval($_GET['synced']); ?> técnico(s) sincronizado(s) do GLPI.
    </div>
<?php endif; ?>

<div class="page-header">
    <h1>👥 Usuários</h1>
    <div style="display: flex; gap: 0.5rem;">
        <a href="?page=users&action=syncGlpiTechnicians" class="btn" style="background: #17a2b8;" onclick="return confirm('Sincronizar técnicos (super-admins da entidade NTI-CampusI) do GLPI agora?')">🔄 Sincronizar Técnicos GLPI</a>
        <a href="?page=users&action=create" class="btn">+ Novo Usuário</a>
    </div>
</div>

<?php if (!empty($users)): ?>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Usuário</th>
                <th>Email</th>
                <th>Nome</th>
                <th>Autenticação</th>
                <th>Status</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><?php echo htmlspecialchars($user['id']); ?></td>
                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($user['auth_type']); ?></td>
                    <td>
                        <?php if ($user['status'] === 'active'): ?>
                            <span class="badge badge-success">Ativo</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Inativo</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="?page=users&action=edit&id=<?php echo $user['id']; ?>">Editar</a> |
                        <a href="?page=users&action=delete&id=<?php echo $user['id']; ?>" onclick="return confirm('Tem certeza que deseja deletar?')">Deletar</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <div class="alert alert-info">
        Nenhum usuário encontrado. <a href="?page=users&action=create">Crie um novo usuário</a>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../footer.php'; ?>