<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>🏢 Gerenciar Setores</h1>
    <a href="?page=sectors&action=create" class="btn">➕ Novo Setor</a>
</div>

<?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
    <div style="background: #d4edda; color: #155724; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
        ✅ Operação realizada com sucesso!
    </div>
<?php endif; ?>

<?php if (!empty($sectors)): ?>
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem;">
        <?php foreach ($sectors as $sector): ?>
            <div style="background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 4px solid #667eea;">
                <h3 style="margin-top: 0;"><?php echo htmlspecialchars($sector['name']); ?></h3>
                
                <p style="color: #7f8c8d; margin: 0.5rem 0;">
                    <strong>📍 Localização:</strong> <?php echo htmlspecialchars($sector['location'] ?? 'N/A'); ?>
                </p>
                
                <p style="color: #7f8c8d; margin: 0.5rem 0; min-height: 40px;">
                    <strong>📝 Descrição:</strong><br>
                    <?php echo htmlspecialchars(strlen($sector['description']) > 60 ? substr($sector['description'], 0, 60) . '...' : $sector['description']); ?>
                </p>

                <p style="margin: 0.5rem 0;">
                    <strong>Status:</strong> 
                    <?php if ($sector['status'] === 'active'): ?>
                        <span style="background: #27ae60; color: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.9rem;">✓ Ativo</span>
                    <?php else: ?>
                        <span style="background: #e74c3c; color: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.9rem;">✕ Inativo</span>
                    <?php endif; ?>
                </p>

                <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                    <a href="?page=sectors&action=view&id=<?php echo $sector['id']; ?>" class="btn" style="flex: 1; text-align: center; background: #3498db; padding: 0.5rem; text-decoration: none; color: white; border-radius: 4px; font-size: 0.9rem;">👁️ Ver</a>
                    <a href="?page=sectors&action=edit&id=<?php echo $sector['id']; ?>" class="btn" style="flex: 1; text-align: center; background: #f39c12; padding: 0.5rem; text-decoration: none; color: white; border-radius: 4px; font-size: 0.9rem;">✏️ Editar</a>
                    <a href="?page=sectors&action=delete&id=<?php echo $sector['id']; ?>" class="btn" style="flex: 1; text-align: center; background: #e74c3c; padding: 0.5rem; text-decoration: none; color: white; border-radius: 4px; font-size: 0.9rem;" onclick="return confirm('Tem certeza?')">🗑️ Deletar</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div style="background: #e7f3ff; padding: 2rem; text-align: center; border-radius: 8px;">
        <p style="color: #5c7cad; font-size: 1.1rem;">📭 Nenhum setor cadastrado</p>
        <a href="?page=sectors&action=create" class="btn">➕ Crie um novo setor</a>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../footer.php'; ?>
