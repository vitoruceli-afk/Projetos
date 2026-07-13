<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>✏️ Editar Usuário</h1>
    <p>Atualize as informações do usuário</p>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger">
        ❌ <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<?php if (!empty($success)): ?>
    <div class="alert alert-success">
        ✅ Usuário atualizado com sucesso!
    </div>
<?php endif; ?>

<?php if ($user): ?>
    <form method="POST" style="max-width: 600px;">
        <div class="form-group">
            <label for="username">Nome de Usuário</label>
            <input type="text" id="username" 
                   value="<?php echo htmlspecialchars($user['username']); ?>"
                   disabled style="background: #f0f0f0; cursor: not-allowed;">
            <small>Não pode ser alterado</small>
        </div>

        <div class="form-group">
            <label for="email">Email *</label>
            <input type="email" id="email" name="email" 
                   value="<?php echo htmlspecialchars($user['email']); ?>"
                   placeholder="email@exemplo.com" required>
        </div>

        <div class="form-group">
            <label for="full_name">Nome Completo *</label>
            <input type="text" id="full_name" name="full_name" 
                   value="<?php echo htmlspecialchars($user['full_name']); ?>"
                   placeholder="Seu nome completo" required>
        </div>

        <div class="form-group">
            <label for="auth_type">Tipo de Autenticação</label>
            <input type="text" id="auth_type" 
                   value="<?php echo htmlspecialchars($user['auth_type']); ?>"
                   disabled style="background: #f0f0f0; cursor: not-allowed;">
            <small>Não pode ser alterado</small>
        </div>

        <div class="form-group">
            <label for="status">Status *</label>
            <select id="status" name="status" required>
                <option value="active" <?php echo ($user['status'] === 'active') ? 'selected' : ''; ?>>Ativo</option>
                <option value="inactive" <?php echo ($user['status'] === 'inactive') ? 'selected' : ''; ?>>Inativo</option>
            </select>
        </div>

        <div style="background: #f9f9f9; padding: 1.5rem; border-radius: 4px; margin-bottom: 1.5rem;">
            <h3 style="margin-bottom: 1rem;">🔐 Alterar Senha (Opcional)</h3>
            
            <div class="form-group">
                <label for="password">Nova Senha <small>(deixe em branco para manter a atual)</small></label>
                <input type="password" id="password" name="password" 
                       placeholder="Deixe em branco para não alterar">
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirmar Nova Senha</label>
                <input type="password" id="confirm_password" name="confirm_password" 
                       placeholder="Repita a nova senha">
            </div>
        </div>

        <div style="display: flex; gap: 1rem;">
            <button type="submit" class="btn">✅ Atualizar Usuário</button>
            <a href="index.php?page=users" class="btn" style="background: #95a5a6; text-decoration: none;">❌ Cancelar</a>
            <a href="index.php?page=users&action=delete&id=<?php echo $user['id']; ?>" 
               class="btn btn-danger" 
               onclick="return confirm('Tem certeza que deseja deletar este usuário?')">🗑️ Deletar</a>
        </div>
    </form>

    <div style="margin-top: 2rem; padding: 1rem; background: #f9f9f9; border-radius: 4px;">
        <h3>📋 Informações do Usuário</h3>
        <p><strong>ID:</strong> <?php echo htmlspecialchars($user['id']); ?></p>
        <p><strong>Criado em:</strong> <?php echo htmlspecialchars($user['created_at']); ?></p>
        <p><strong>Atualizado em:</strong> <?php echo htmlspecialchars($user['updated_at']); ?></p>
        <p><strong>Último Login:</strong> <?php echo $user['last_login'] ? htmlspecialchars($user['last_login']) : 'Nunca'; ?></p>
    </div>

<?php else: ?>
    <div class="alert alert-danger">
        ❌ Usuário não encontrado!
    </div>
    <a href="index.php?page=users" class="btn">Voltar para Usuários</a>
<?php endif; ?>

<?php include __DIR__ . '/../footer.php'; ?>