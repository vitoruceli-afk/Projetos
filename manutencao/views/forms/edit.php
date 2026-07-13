<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>✏️ Editar Formulário</h1>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger">
        ❌ <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<?php if ($form): ?>
    <form method="POST" style="max-width: 600px;">
        <div class="form-group">
            <label for="name">Nome do Formulário *</label>
            <input type="text" id="name" name="name" 
                   value="<?php echo htmlspecialchars($form['name']); ?>"
                   placeholder="Nome do formulário" required>
        </div>

        <div class="form-group">
            <label for="description">Descrição</label>
            <textarea id="description" name="description" 
                      placeholder="Descrição do formulário"
                      rows="4"><?php echo htmlspecialchars($form['description'] ?? ''); ?></textarea>
        </div>

        <div class="form-group">
            <label for="category_id">Categoria</label>
            <select id="category_id" name="category_id">
                <option value="0">Sem Categoria</option>
                <?php if (!empty($categories)): ?>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" 
                                <?php echo ($form['category_id'] == $cat['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="status">Status *</label>
            <select id="status" name="status" required>
                <option value="active" <?php echo ($form['status'] === 'active') ? 'selected' : ''; ?>>Ativo</option>
                <option value="inactive" <?php echo ($form['status'] === 'inactive') ? 'selected' : ''; ?>>Inativo</option>
            </select>
        </div>

        <div style="display: flex; gap: 1rem;">
            <button type="submit" class="btn">✅ Atualizar Formulário</button>
            <a href="?page=forms&action=builder&id=<?php echo $form['id']; ?>" class="btn" style="background: #f39c12; text-decoration: none;">🔧 Builder</a>
            <a href="?page=forms" class="btn" style="background: #95a5a6; text-decoration: none;">❌ Cancelar</a>
        </div>
    </form>

<?php else: ?>
    <div class="alert alert-danger">
        ❌ Formulário não encontrado!
    </div>
    <a href="?page=forms" class="btn">Voltar</a>
<?php endif; ?>

<?php include __DIR__ . '/../footer.php'; ?>