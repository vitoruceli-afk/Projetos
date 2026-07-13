<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>➕ Novo Grupo de Dispositivos</h1>
</div>

<?php if (!empty($error)): ?>
    <div style="background: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
        ❌ <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<form method="POST" style="max-width: 900px; background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
        <div>
            <label for="name">Nome do Grupo *</label>
            <input type="text" id="name" name="name" placeholder="Ex: Impressoras do Bloco 5" required
                   value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                   style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
        </div>
        <div>
            <label for="description">Descrição</label>
            <input type="text" id="description" name="description" placeholder="Opcional"
                   value="<?php echo htmlspecialchars($_POST['description'] ?? ''); ?>"
                   style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem;">
        </div>
    </div>

    <label>Dispositivos do Grupo *</label>
    <input type="text" id="device_filter" onkeyup="filterDevices()" placeholder="🔍 Filtrar por nome, setor ou série..."
           style="width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem; margin: 0.5rem 0;">

    <div style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px; padding: 0.5rem;">
        <?php foreach ($devices as $device): ?>
            <label class="device-row" style="display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem; border-bottom: 1px solid #f0f0f0; font-weight: normal; cursor: pointer;">
                <input type="checkbox" name="device_ids[]" value="<?php echo $device['id']; ?>"
                       <?php echo in_array($device['id'], $selected_device_ids) ? 'checked' : ''; ?>>
                <span>
                    <?php echo htmlspecialchars($device['name']); ?>
                    <?php if (!empty($device['sector_name'])): ?>
                        <small style="color: #7f8c8d;">(<?php echo htmlspecialchars($device['sector_name']); ?>)</small>
                    <?php endif; ?>
                    <?php if (($device['source'] ?? 'manual') === 'glpi'): ?>
                        <small style="color: #17a2b8;">[GLPI]</small>
                    <?php endif; ?>
                </span>
            </label>
        <?php endforeach; ?>
        <?php if (empty($devices)): ?>
            <p style="color: #7f8c8d; padding: 0.5rem;">Nenhum dispositivo cadastrado ainda.</p>
        <?php endif; ?>
    </div>

    <div style="display: flex; gap: 1rem; margin-top: 2rem;">
        <button type="submit" class="btn" style="flex: 1; background: #667eea; color: white; padding: 0.75rem; border: none; border-radius: 4px; cursor: pointer; font-size: 1rem; font-weight: bold;">✅ Criar Grupo</button>
        <a href="?page=devices&action=groups" class="btn" style="flex: 1; background: #95a5a6; color: white; text-decoration: none; padding: 0.75rem; text-align: center; border-radius: 4px; font-weight: bold;">❌ Cancelar</a>
    </div>
</form>

<script>
function filterDevices() {
    const term = document.getElementById('device_filter').value.toLowerCase();
    document.querySelectorAll('.device-row').forEach(function (row) {
        row.style.display = row.textContent.toLowerCase().includes(term) ? 'flex' : 'none';
    });
}
</script>

<?php include __DIR__ . '/../footer.php'; ?>
