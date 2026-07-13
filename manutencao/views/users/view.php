<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>👤 Detalhes do Usuário</h1>
</div>

<?php if ($user): ?>
    <div style="max-width: 600px; background: white; padding: 2rem; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <div style="margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid #eee;">
            <h2><?php echo htmlspecialchars($user['full_name']); ?></h2>
            <p style="color: #7f8c8d;">@<?php echo htmlspecialchars($user['username']); ?></p>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
            <div>
                <label style="font-weight: 600; color: #667eea;">ID</label>
                <p><?php echo htmlspecialchars($user['id']); ?></p>
            </div>

            <div>
                <label style="font-weight: 600; color: #667eea;">Email</label>
                <p><?php echo htmlspecialchars($user['email']); ?></p>
            </div>

            <div>
                <label style="font-weight: 600; color: #667eea;">Autenticação</label>
                <p><?php echo htmlspecialchars($user['auth_type']); ?></p>
            </div>

            <div>
                <label style="font-weight: 600; color: #667eea;">Status</label>
                <p>
                    <?php if ($user['status'] === 'active'): ?>
                        <span class="badge badge-success">Ativo</span>
                    <?php else: ?>
                        <span class="badge badge-danger">Inativo</span>
                    <?php endif; ?>
                </p>
            </div>

            <div>
                <label style="font-weight: 600; color: #667eea;">Criado em</label>
                <p><?php echo htmlspecialchars($user['created_at']); ?></p>
            </div>

            <div>
                <label style="font-weight: 600; color: #667eea;">Atualizado em</label>
                <p><?php echo htmlspecialchars($user['updated_at']); ?></p>
            </div>

            <div>
                <label style="font-weight: 600; color: #667eea;">Último Login</label>
                <p><?php echo $user['last_login'] ? htmlspecialchars($user['last_login']) : 'Nunca acessou'; ?></p>
            </div>
        </div>

        <div style="margin-top: 2rem; display: flex; gap: 1rem;">
            <a href="index.php?page=users&action=edit&id=<?php echo $user['id']; ?>" class="btn">✏️ Editar</a>
            <a href="index.php?page=users" class="btn" style="background: #95a5a6; text-decoration: none;">⬅️ Voltar</a>
        </div>
    </div>

<?php else: ?>
    <div class="alert alert-danger">
        ❌ Usuário não encontrado!
    </div>
    <a href="index.php?page=users" class="btn">Voltar para Usuários</a>
<?php endif; ?>

<?php include __DIR__ . '/../footer.php'; ?>