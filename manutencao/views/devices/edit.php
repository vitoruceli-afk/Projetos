<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>✏️ Editar Dispositivo</h1>
</div>

<?php if (!empty($error)): ?>
    <div style="background: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
        ❌ <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<?php if ($device): ?>
    <form method="POST" style="max-width: 800px; background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
            <div>
                <label for="name">Nome do Dispositivo *</label>
                <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($device['name']); ?>" required style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
            </div>

            <div>
                <label for="sector_id">Setor *</label>
                <select id="sector_id" name="sector_id" required style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
                    <option value="">-- Selecione um Setor --</option>
                    <?php foreach ($sectors as $sector): ?>
                        <option value="<?php echo $sector['id']; ?>" <?php if ($device['sector_id'] == $sector['id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($sector['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="model">Modelo</label>
                <input type="text" id="model" name="model" value="<?php echo htmlspecialchars($device['model'] ?? ''); ?>" style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
            </div>

            <div>
                <label for="manufacturer">Fabricante</label>
                <input type="text" id="manufacturer" name="manufacturer" value="<?php echo htmlspecialchars($device['manufacturer'] ?? ''); ?>" style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
            </div>

            <div>
                <label for="serial_number">Número de Série</label>
                <input type="text" id="serial_number" name="serial_number" value="<?php echo htmlspecialchars($device['serial_number'] ?? ''); ?>" style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
            </div>

            <div>
                <label for="acquisition_date">Data de Aquisição</label>
                <input type="date" id="acquisition_date" name="acquisition_date" value="<?php echo htmlspecialchars($device['acquisition_date'] ?? ''); ?>" style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
            </div>

            <div>
                <label for="last_maintenance">Última Manutenção</label>
                <input type="date" id="last_maintenance" name="last_maintenance" value="<?php echo htmlspecialchars($device['last_maintenance'] ?? ''); ?>" style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
            </div>

            <div>
                <label for="maintenance_frequency">Frequência de Manutenção</label>
                <select id="maintenance_frequency" name="maintenance_frequency" style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
                    <option value="">-- Selecione --</option>
                    <option value="semanal" <?php if ($device['maintenance_frequency'] === 'semanal') echo 'selected'; ?>>Semanal</option>
                    <option value="quinzenal" <?php if ($device['maintenance_frequency'] === 'quinzenal') echo 'selected'; ?>>Quinzenal</option>
                    <option value="mensal" <?php if ($device['maintenance_frequency'] === 'mensal') echo 'selected'; ?>>Mensal</option>
                    <option value="trimestral" <?php if ($device['maintenance_frequency'] === 'trimestral') echo 'selected'; ?>>Trimestral</option>
                    <option value="semestral" <?php if ($device['maintenance_frequency'] === 'semestral') echo 'selected'; ?>>Semestral</option>
                    <option value="anual" <?php if ($device['maintenance_frequency'] === 'anual') echo 'selected'; ?>>Anual</option>
                    <option value="conforme_necessario" <?php if ($device['maintenance_frequency'] === 'conforme_necessario') echo 'selected'; ?>>Conforme Necessário</option>
                </select>
            </div>

            <div>
                <label for="status">Status</label>
                <select id="status" name="status" style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
                    <option value="active" <?php if ($device['status'] === 'active') echo 'selected'; ?>>Ativo</option>
                    <option value="inactive" <?php if ($device['status'] === 'inactive') echo 'selected'; ?>>Inativo</option>
                    <option value="manutencao" <?php if ($device['status'] === 'manutencao') echo 'selected'; ?>>Em Manutenção</option>
                </select>
            </div>
        </div>

        <div style="display: flex; gap: 1rem; margin-top: 2rem;">
            <button type="submit" class="btn" style="flex: 1; background: #667eea; color: white; padding: 0.75rem; border: none; border-radius: 4px; cursor: pointer; font-size: 1rem; font-weight: bold;">✅ Salvar Alterações</button>
            <a href="?page=devices" class="btn" style="flex: 1; background: #95a5a6; color: white; text-decoration: none; padding: 0.75rem; text-align: center; border-radius: 4px; font-weight: bold;">❌ Cancelar</a>
        </div>
    </form>

<?php else: ?>
    <div style="background: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px;">
        ❌ Dispositivo não encontrado!
    </div>
    <a href="?page=devices" class="btn">⬅️ Voltar</a>
<?php endif; ?>

<?php include __DIR__ . '/../footer.php'; ?>
