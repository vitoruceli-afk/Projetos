<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>➕ Novo Setor</h1>
</div>

<?php if (!empty($error)): ?>
    <div style="background: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
        ❌ <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<form method="POST" style="max-width: 600px; background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
    <div style="margin-bottom: 1.5rem;">
        <label for="name">Nome do Setor *</label>
        <input type="text" id="name" name="name" placeholder="Ex: Setor de Montagem" required style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
    </div>

    <div style="margin-bottom: 1.5rem;">
        <label for="description">Descrição</label>
        <textarea id="description" name="description" rows="4" placeholder="Descrição do setor..." style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;"></textarea>
    </div>

    <div style="margin-bottom: 1.5rem;">
        <label for="location">Localização</label>
        <input type="text" id="location" name="location" placeholder="Ex: Galpão A, Andar 2" style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
    </div>

    <div style="display: flex; gap: 1rem; margin-top: 2rem;">
        <button type="submit" class="btn" style="flex: 1; background: #667eea; color: white; padding: 0.75rem; border: none; border-radius: 4px; cursor: pointer; font-size: 1rem; font-weight: bold;">✅ Criar Setor</button>
        <a href="?page=sectors" class="btn" style="flex: 1; background: #95a5a6; color: white; text-decoration: none; padding: 0.75rem; text-align: center; border-radius: 4px; font-weight: bold;">❌ Cancelar</a>
    </div>
</form>

<?php include __DIR__ . '/../footer.php'; ?>
