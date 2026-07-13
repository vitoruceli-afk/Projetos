<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>🖥️ Gerenciar Dispositivos</h1>
    <div style="display: flex; gap: 0.5rem;">
        <a href="?page=devices&action=groups" class="btn" style="background: #6c5ce7;">🗂️ Grupos</a>
        <a href="?page=devices&action=syncGlpi" class="btn" style="background: #17a2b8;" onclick="return confirm('Sincronizar dispositivos inventariados no GLPI agora?')">🔄 Sincronizar com GLPI</a>
        <a href="?page=devices&action=create" class="btn">➕ Novo Dispositivo</a>
    </div>
</div>

<?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
    <div style="background: #d4edda; color: #155724; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
        ✅ Operação realizada com sucesso!
        <?php if (!empty($_GET['synced'])): ?>
            (<?php echo intval($_GET['synced']); ?> dispositivo(s) sincronizado(s) do GLPI)
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!empty($_GET['glpi_error'])): ?>
    <div style="background: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
        ❌ Erro ao sincronizar com o GLPI: <?php echo htmlspecialchars($_GET['glpi_error']); ?>
    </div>
<?php endif; ?>

<!-- Filtro por Setor -->
<div style="background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 2rem;">
    <h3 style="margin-top: 0;">🔍 Filtrar por Setor</h3>
    <form method="GET" style="display: flex; gap: 1rem;">
        <input type="hidden" name="page" value="devices">
        <select name="sector" style="padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; flex: 1; font-size: 1rem;">
            <option value="">📋 Todos os Setores</option>
            <?php foreach ($sectors as $sector): ?>
                <option value="<?php echo $sector['id']; ?>" <?php if ($sector_filter == $sector['id']) echo 'selected'; ?>>
                    <?php echo htmlspecialchars($sector['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn" style="background: #667eea; color: white; padding: 0.75rem 1.5rem; border: none; border-radius: 4px; cursor: pointer;">🔎 Filtrar</button>
        <?php if ($sector_filter > 0): ?>
            <a href="?page=devices" class="btn" style="background: #95a5a6; color: white; text-decoration: none; padding: 0.75rem 1.5rem; text-align: center; border-radius: 4px;">✕ Limpar</a>
        <?php endif; ?>
    </form>
</div>

<!-- Lista de Dispositivos -->
<?php if (!empty($devices)): ?>
    <div style="background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); overflow: hidden;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #667eea; color: white;">
                    <th style="padding: 1rem; text-align: left;">🖥️ Nome</th>
                    <th style="padding: 1rem; text-align: left;">📱 Modelo</th>
                    <th style="padding: 1rem; text-align: left;">🏭 Setor</th>
                    <th style="padding: 1rem; text-align: left;">🔢 Série</th>
                    <th style="padding: 1rem; text-align: left;">Origem</th>
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
                            <?php if (($device['source'] ?? 'manual') === 'glpi'): ?>
                                <span style="background: #17a2b8; color: white; padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 0.85rem;">🔗 GLPI</span>
                            <?php else: ?>
                                <span style="background: #95a5a6; color: white; padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 0.85rem;">✍️ Manual</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 1rem;">
                            <?php if ($device['status'] === 'active'): ?>
                                <span style="background: #27ae60; color: white; padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 0.9rem;">✓ Ativo</span>
                            <?php else: ?>
                                <span style="background: #e74c3c; color: white; padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 0.9rem;">✕ Inativo</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 1rem; text-align: center;">
                            <a href="?page=devices&action=view&id=<?php echo $device['id']; ?>" style="color: #3498db; text-decoration: none; margin: 0 0.5rem;">👁️</a>
                            <a href="?page=devices&action=edit&id=<?php echo $device['id']; ?>" style="color: #f39c12; text-decoration: none; margin: 0 0.5rem;">✏️</a>
                            <a href="?page=devices&action=delete&id=<?php echo $device['id']; ?>" style="color: #e74c3c; text-decoration: none; margin: 0 0.5rem;" onclick="return confirm('Tem certeza?')">🗑️</a>
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
    <div style="background: #e7f3ff; padding: 3rem; text-align: center; border-radius: 8px;">
        <p style="color: #5c7cad; font-size: 1.2rem;">📭 Nenhum dispositivo encontrado</p>
        <a href="?page=devices&action=create" class="btn" style="background: #667eea; color: white; padding: 0.75rem 1.5rem; text-decoration: none; border-radius: 4px; display: inline-block;">➕ Crie seu primeiro dispositivo</a>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../footer.php'; ?>
