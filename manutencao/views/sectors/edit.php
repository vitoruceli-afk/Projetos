<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>✏️ Editar Setor</h1>
</div>

<?php if (!empty($error)): ?>
    <div style="background: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
        ❌ <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<?php if ($sector): ?>
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
        <form method="POST" style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <div style="margin-bottom: 1.5rem;">
                <label for="name">Nome do Setor *</label>
                <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($sector['name']); ?>" required style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
            </div>

            <div style="margin-bottom: 1.5rem;">
                <label for="description">Descrição</label>
                <textarea id="description" name="description" rows="4" style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;"><?php echo htmlspecialchars($sector['description'] ?? ''); ?></textarea>
            </div>

            <div style="margin-bottom: 1.5rem;">
                <label for="location">Localização</label>
                <input type="text" id="location" name="location" value="<?php echo htmlspecialchars($sector['location'] ?? ''); ?>" style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
            </div>

            <div style="margin-bottom: 1.5rem;">
                <label for="status">Status</label>
                <select id="status" name="status" style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
                    <option value="active" <?php if ($sector['status'] === 'active') echo 'selected'; ?>>Ativo</option>
                    <option value="inactive" <?php if ($sector['status'] === 'inactive') echo 'selected'; ?>>Inativo</option>
                </select>
            </div>

            <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                <button type="submit" class="btn" style="flex: 1; background: #667eea; color: white; padding: 0.75rem; border: none; border-radius: 4px; cursor: pointer; font-size: 1rem; font-weight: bold;">✅ Salvar Alterações</button>
                <a href="?page=sectors" class="btn" style="flex: 1; background: #95a5a6; color: white; text-decoration: none; padding: 0.75rem; text-align: center; border-radius: 4px; font-weight: bold;">❌ Cancelar</a>
            </div>
        </form>

        <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <h3>🖥️ Dispositivos Neste Setor</h3>
            
            <?php if (!empty($devices)): ?>
                <table style="width: 100%; margin-top: 1rem;">
                    <thead>
                        <tr style="border-bottom: 2px solid #667eea;">
                            <th style="text-align: left; padding: 0.5rem;">Nome</th>
                            <th style="text-align: left; padding: 0.5rem;">Modelo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($devices as $device): ?>
                            <tr style="border-bottom: 1px solid #ddd;">
                                <td style="padding: 0.5rem;"><?php echo htmlspecialchars($device['name'] ?? 'N/A'); ?></td>
                                <td style="padding: 0.5rem;"><?php echo htmlspecialchars($device['model'] ?? 'N/A'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="color: #7f8c8d; text-align: center; margin-top: 1rem;">Nenhum dispositivo neste setor</p>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <div style="background: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px;">
        ❌ Setor não encontrado!
    </div>
    <a href="?page=sectors" class="btn">⬅️ Voltar</a>
<?php endif; ?>

<?php include __DIR__ . '/../footer.php'; ?>
