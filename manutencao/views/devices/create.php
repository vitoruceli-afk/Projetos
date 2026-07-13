<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>➕ Novo Dispositivo</h1>
</div>

<?php if (!empty($error)): ?>
    <div style="background: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
        ❌ <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<form method="POST" style="max-width: 800px; background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
    
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
        <div>
            <label for="name">Nome do Dispositivo *</label>
            <input type="text" id="name" name="name" placeholder="Ex: CNC-2000" required style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
        </div>

        <div>
            <label for="sector_id">Setor *</label>
            <select id="sector_id" name="sector_id" required style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
                <option value="">-- Selecione um Setor --</option>
                <?php foreach ($sectors as $sector): ?>
                    <option value="<?php echo $sector['id']; ?>" <?php if ($sector_id == $sector['id']) echo 'selected'; ?>>
                        <?php echo htmlspecialchars($sector['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="model">Modelo</label>
            <input type="text" id="model" name="model" placeholder="Ex: CNC-2000 Pro" style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
        </div>

        <div>
            <label for="manufacturer">Fabricante</label>
            <input type="text" id="manufacturer" name="manufacturer" placeholder="Ex: HAAS" style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
        </div>

        <div>
            <label for="serial_number">Número de Série</label>
            <input type="text" id="serial_number" name="serial_number" placeholder="Ex: SN123456789" style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
        </div>

        <div>
            <label for="acquisition_date">Data de Aquisição</label>
            <input type="date" id="acquisition_date" name="acquisition_date" style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
        </div>

        <div>
            <label for="last_maintenance">Última Manutenção</label>
            <input type="date" id="last_maintenance" name="last_maintenance" style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
        </div>

        <div>
            <label for="maintenance_frequency">Frequência de Manutenção</label>
            <select id="maintenance_frequency" name="maintenance_frequency" style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
                <option value="">-- Selecione --</option>
                <option value="semanal">Semanal</option>
                <option value="quinzenal">Quinzenal</option>
                <option value="mensal">Mensal</option>
                <option value="trimestral">Trimestral</option>
                <option value="semestral">Semestral</option>
                <option value="anual">Anual</option>
                <option value="conforme_necessario">Conforme Necessário</option>
            </select>
        </div>
    </div>

    <div style="display: flex; gap: 1rem; margin-top: 2rem;">
        <button type="submit" class="btn" style="flex: 1; background: #667eea; color: white; padding: 0.75rem; border: none; border-radius: 4px; cursor: pointer; font-size: 1rem; font-weight: bold;">✅ Criar Dispositivo</button>
        <a href="?page=devices" class="btn" style="flex: 1; background: #95a5a6; color: white; text-decoration: none; padding: 0.75rem; text-align: center; border-radius: 4px; font-weight: bold;">❌ Cancelar</a>
    </div>
</form>

<?php include __DIR__ . '/../footer.php'; ?>
