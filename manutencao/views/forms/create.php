<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>📋 Criar Novo Formulário</h1>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger">
        ❌ <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<form method="POST" style="max-width: 600px;">
    <div class="form-group">
        <label for="name">Nome do Formulário *</label>
        <input type="text" id="name" name="name" 
               value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>"
               placeholder="Ex: Checklist de Inspeção" required>
    </div>

    <div class="form-group">
        <label for="description">Descrição</label>
        <textarea id="description" name="description" 
                  placeholder="Descrição do formulário"
                  rows="4"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
    </div>

    <div class="form-group">
        <label for="category_id">Categoria</label>
        <select id="category_id" name="category_id">
            <option value="0">Sem Categoria</option>
            <?php if (!empty($categories)): ?>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" 
                            <?php echo (isset($_POST['category_id']) && $_POST['category_id'] == $cat['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['name']); ?>
                    </option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
    </div>

    <div style="display: flex; gap: 1rem;">
        <button type="submit" class="btn">✅ Criar Formulário</button>
        <a href="?page=forms" class="btn" style="background: #95a5a6; text-decoration: none;">❌ Cancelar</a>
    </div>
</form>

<?php include __DIR__ . '/../footer.php'; ?>