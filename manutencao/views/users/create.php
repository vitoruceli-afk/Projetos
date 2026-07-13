<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>👤 Criar Novo Usuário</h1>
    <p>Adicione um novo usuário ao sistema</p>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger">
        ❌ <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<form method="POST" style="max-width: 600px;">
    <div class="form-group">
        <label for="username">Nome de Usuário *</label>
        <input type="text" id="username" name="username" 
               value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
               placeholder="Usuário único (mínimo 3 caracteres)" required>
    </div>

    <div class="form-group">
        <label for="email">Email *</label>
        <input type="email" id="email" name="email" 
               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
               placeholder="email@exemplo.com" required>
    </div>

    <div class="form-group">
        <label for="full_name">Nome Completo *</label>
        <input type="text" id="full_name" name="full_name" 
               value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''; ?>"
               placeholder="Seu nome completo" required>
    </div>

    <div class="form-group">
        <label for="auth_type">Tipo de Autenticação *</label>
        <select id="auth_type" name="auth_type" required>
            <option value="local" <?php echo (isset($_POST['auth_type']) && $_POST['auth_type'] === 'local') ? 'selected' : ''; ?>>Local</option>
            <option value="ldap" <?php echo (isset($_POST['auth_type']) && $_POST['auth_type'] === 'ldap') ? 'selected' : ''; ?>>LDAP</option>
        </select>
    </div>

    <div class="form-group">
        <label for="password">Senha * <small>(mínimo 6 caracteres)</small></label>
        <input type="password" id="password" name="password" 
               placeholder="Senha segura" required>
    </div>

    <div class="form-group">
        <label for="confirm_password">Confirmar Senha *</label>
        <input type="password" id="confirm_password" name="confirm_password" 
               placeholder="Repita a senha" required>
    </div>

    <div class="form-group">
        <label for="profile_id">Perfil *</label>
        <select id="profile_id" name="profile_id" required>
            <option value="">Selecione um perfil</option>
            <option value="1" <?php echo (isset($_POST['profile_id']) && $_POST['profile_id'] === '1') ? 'selected' : ''; ?>>Administrador</option>
            <option value="2" <?php echo (isset($_POST['profile_id']) && $_POST['profile_id'] === '2') ? 'selected' : ''; ?>>Usuário Padrão</option>
            <option value="3" <?php echo (isset($_POST['profile_id']) && $_POST['profile_id'] === '3') ? 'selected' : ''; ?>>Técnico</option>
        </select>
    </div>

    <div style="display: flex; gap: 1rem;">
        <button type="submit" class="btn">✅ Criar Usuário</button>
        <a href="index.php?page=users" class="btn" style="background: #95a5a6; text-decoration: none;">❌ Cancelar</a>
    </div>
</form>

<?php include __DIR__ . '/../footer.php'; ?>