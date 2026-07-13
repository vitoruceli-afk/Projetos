<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>✏️ Editar Perfil</h1>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger">
        ❌ <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<?php if ($profile): ?>
    <form method="POST" style="max-width: 600px;">
        <div class="form-group">
            <label for="name">Nome do Perfil *</label>
            <input type="text" id="name" name="name" 
                   value="<?php echo htmlspecialchars($profile['name']); ?>"
                   placeholder="Nome do perfil" required>
        </div>

        <div class="form-group">
            <label for="description">Descrição</label>
            <textarea id="description" name="description" 
                      placeholder="Descrição do perfil"
                      rows="4"><?php echo htmlspecialchars($profile['description'] ?? ''); ?></textarea>
        </div>

        <div class="form-group">
            <label>
                <input type="checkbox" name="is_default" 
                       <?php echo $profile['is_default'] ? 'checked' : ''; ?>>
                Perfil Padrão
            </label>
        </div>

        <div style="display: flex; gap: 1rem;">
            <button type="submit" class="btn">✅ Atualizar Perfil</button>
            <a href="?page=profiles&action=permissions&id=<?php echo $profile['id']; ?>" class="btn" style="background: #f39c12; text-decoration: none;">🔐 Permissões</a>
            <a href="?page=profiles" class="btn" style="background: #95a5a6; text-decoration: none;">❌ Cancelar</a>
        </div>
    </form>

<?php else: ?>
    <div class="alert alert-danger">
        ❌ Perfil não encontrado!
    </div>
    <a href="?page=profiles" class="btn">Voltar</a>
<?php endif; ?>

<?php include __DIR__ . '/../footer.php'; ?>