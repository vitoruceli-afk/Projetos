<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>🗂️ Grupos de Dispositivos</h1>
    <div style="display: flex; gap: 0.5rem;">
        <a href="?page=devices" class="btn" style="background: #95a5a6;">⬅️ Dispositivos</a>
        <a href="?page=devices&action=groupCreate" class="btn">➕ Novo Grupo</a>
    </div>
</div>

<?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
    <div style="background: #d4edda; color: #155724; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
        ✅ Operação realizada com sucesso!
    </div>
<?php endif; ?>

<?php if (!empty($groups)): ?>
    <div style="background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); overflow: hidden;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #667eea; color: white;">
                    <th style="padding: 1rem; text-align: left;">🗂️ Nome</th>
                    <th style="padding: 1rem; text-align: left;">Descrição</th>
                    <th style="padding: 1rem; text-align: left;">🖥️ Dispositivos</th>
                    <th style="padding: 1rem; text-align: left;">Status</th>
                    <th style="padding: 1rem; text-align: center;">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groups as $group): ?>
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 1rem;"><strong><?php echo htmlspecialchars($group['name']); ?></strong></td>
                        <td style="padding: 1rem; color: #7f8c8d;"><?php echo htmlspecialchars($group['description'] ?? ''); ?></td>
                        <td style="padding: 1rem;">
                            <span style="background: #e7f3ff; padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 0.9rem;">
                                <?php echo intval($group['device_count']); ?> dispositivo(s)
                            </span>
                        </td>
                        <td style="padding: 1rem;">
                            <?php if ($group['status'] === 'active'): ?>
                                <span style="background: #27ae60; color: white; padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 0.9rem;">✓ Ativo</span>
                            <?php else: ?>
                                <span style="background: #e74c3c; color: white; padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 0.9rem;">✕ Inativo</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 1rem; text-align: center;">
                            <a href="?page=devices&action=groupEdit&id=<?php echo $group['id']; ?>" style="color: #f39c12; text-decoration: none; margin: 0 0.5rem;">✏️</a>
                            <a href="?page=devices&action=groupDelete&id=<?php echo $group['id']; ?>" style="color: #e74c3c; text-decoration: none; margin: 0 0.5rem;" onclick="return confirm('Tem certeza? Isso não apaga os dispositivos, só o grupo.')">🗑️</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div style="margin-top: 1rem; text-align: right; color: #7f8c8d;">
        <small>Total: <?php echo count($groups); ?> grupo(s)</small>
    </div>
<?php else: ?>
    <div style="background: #e7f3ff; padding: 3rem; text-align: center; border-radius: 8px;">
        <p style="color: #5c7cad; font-size: 1.2rem;">📭 Nenhum grupo de dispositivos criado</p>
        <a href="?page=devices&action=groupCreate" class="btn" style="background: #667eea; color: white; padding: 0.75rem 1.5rem; text-decoration: none; border-radius: 4px; display: inline-block;">➕ Crie seu primeiro grupo</a>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../footer.php'; ?>
