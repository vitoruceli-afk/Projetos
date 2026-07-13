<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>🖥️ Relatório de Dispositivos</h1>
</div>

<!-- Filtro -->
<div style="background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 2rem;">
    <form method="GET" style="display: flex; gap: 1rem;">
        <input type="hidden" name="page" value="reports">
        <input type="hidden" name="action" value="devices">
        
        <select name="sector" style="padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; flex: 1;">
            <option value="">🏢 Todos os Setores</option>
            <?php foreach ($sectors as $s): ?>
                <option value="<?php echo $s['id']; ?>" <?php if ($sector_filter == $s['id']) echo 'selected'; ?>>
                    <?php echo htmlspecialchars($s['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        
        <button type="submit" class="btn" style="background: #3498db; color: white; padding: 0.75rem 1.5rem; border: none; border-radius: 4px; cursor: pointer;">🔎 Filtrar</button>
        <?php if ($sector_filter > 0): ?>
            <a href="?page=reports&action=devices" class="btn" style="background: #95a5a6; color: white; text-decoration: none; padding: 0.75rem 1.5rem; border-radius: 4px;">✕ Limpar</a>
        <?php endif; ?>
    </form>
</div>

<!-- Tabela de Dispositivos -->
<?php if (!empty($devices)): ?>
    <div style="background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); overflow: hidden;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #3498db; color: white;">
                    <th style="padding: 1rem; text-align: left;">Nome</th>
                    <th style="padding: 1rem; text-align: left;">Modelo</th>
                    <th style="padding: 1rem; text-align: left;">Setor</th>
                    <th style="padding: 1rem; text-align: left;">Série</th>
                    <th style="padding: 1rem; text-align: left;">Status</th>
                    <th style="padding: 1rem; text-align: center;">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($devices as $device): ?>
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 1rem;"><strong><?php echo htmlspecialchars($device['name']); ?></strong></td>
                        <td style="padding: 1rem;"><?php echo htmlspecialchars($device['model'] ?? 'N/A'); ?></td>
                        <td style="padding: 1rem;">
                            <span style="background: #e7f3ff; padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 0.9rem;">
                                <?php echo htmlspecialchars($device['sector_name'] ?? 'Sem Setor'); ?>
                            </span>
                        </td>
                        <td style="padding: 1rem; font-size: 0.9rem; color: #7f8c8d;"><?php echo htmlspecialchars($device['serial_number'] ?? 'N/A'); ?></td>
                        <td style="padding: 1rem;">
                            <?php if ($device['status'] === 'active'): ?>
                                <span style="background: #27ae60; color: white; padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 0.9rem;">✓ Ativo</span>
                            <?php else: ?>
                                <span style="background: #e74c3c; color: white; padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 0.9rem;">✕ Inativo</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 1rem; text-align: center;">
                            <a href="?page=devices&action=view&id=<?php echo $device['id']; ?>" style="color: #3498db; text-decoration: none;">👁️ Ver</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div style="margin-top: 1rem; text-align: right; color: #7f8c8d;">
        <small>Total: <?php echo count($devices); ?> dispositivo(s)</small>
    </div>
<?php else: ?>
    <div style="background: #e7f3ff; padding: 2rem; text-align: center; border-radius: 8px;">
        <p style="color: #5c7cad;">📭 Nenhum dispositivo encontrado</p>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../footer.php'; ?>
