<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>🔐 Criar Novo Perfil</h1>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger">
        ❌ <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<form method="POST" style="max-width: 600px;">
    <div class="form-group">
        <label for="name">Nome do Perfil *</label>
        <input type="text" id="name" name="name" 
               value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>"
               placeholder="Ex: Gerente, Técnico, etc" required>
    </div>

    <div class="form-group">
        <label for="description">Descrição</label>
        <textarea id="description" name="description" 
                  placeholder="Descrição do perfil"
                  rows="4"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
    </div>

    <div class="form-group">
        <label>
            <input type="checkbox" name="is_default" 
                   <?php echo (isset($_POST['is_default']) && $_POST['is_default']) ? 'checked' : ''; ?>>
            Perfil Padrão (novos usuários recebem este perfil)
        </label>
    </div>

    <div style="display: flex; gap: 1rem;">
        <button type="submit" class="btn">✅ Criar Perfil</button>
        <a href="?page=profiles" class="btn" style="background: #95a5a6; text-decoration: none;">❌ Cancelar</a>
    </div>
</form>

<?php include __DIR__ . '/../footer.php'; ?>