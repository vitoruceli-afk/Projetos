<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>📤 Relatório de Preenchimentos</h1>
</div>

<!-- Filtro -->
<div style="background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 2rem;">
    <form method="GET" style="display: flex; gap: 1rem;">
        <input type="hidden" name="page" value="reports">
        <input type="hidden" name="action" value="submissions">
        
        <select name="form" style="padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; flex: 1;">
            <option value="">📋 Todos os Formulários</option>
            <?php foreach ($forms as $f): ?>
                <option value="<?php echo $f['id']; ?>" <?php if ($form_filter == $f['id']) echo 'selected'; ?>>
                    <?php echo htmlspecialchars($f['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        
        <button type="submit" class="btn" style="background: #667eea; color: white; padding: 0.75rem 1.5rem; border: none; border-radius: 4px; cursor: pointer;">🔎 Filtrar</button>
        <?php if ($form_filter > 0): ?>
            <a href="?page=reports&action=submissions" class="btn" style="background: #95a5a6; color: white; text-decoration: none; padding: 0.75rem 1.5rem; border-radius: 4px;">✕ Limpar</a>
        <?php endif; ?>
    </form>
</div>

<!-- Tabela de Preenchimentos -->
<?php if (!empty($submissions)): ?>
    <div style="background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); overflow: hidden;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #667eea; color: white;">
                    <th style="padding: 1rem; text-align: left;">ID</th>
                    <th style="padding: 1rem; text-align: left;">Formulário</th>
                    <th style="padding: 1rem; text-align: left;">Preenchido Por</th>
                    <th style="padding: 1rem; text-align: left;">Data/Hora</th>
                    <th style="padding: 1rem; text-align: center;">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($submissions as $sub): ?>
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 1rem;"><strong>#<?php echo $sub['id']; ?></strong></td>
                        <td style="padding: 1rem;">📋 <?php echo htmlspecialchars($sub['name']); ?></td>
                        <td style="padding: 1rem;">👤 <?php echo htmlspecialchars($sub['full_name']); ?></td>
                        <td style="padding: 1rem; font-size: 0.9rem; color: #7f8c8d;">
                            <?php echo date('d/m/Y H:i', strtotime($sub['submitted_at'])); ?>
                        </td>
                        <td style="padding: 1rem; text-align: center;">
                            <a href="?page=submissions&action=view&id=<?php echo $sub['id']; ?>" style="color: #3498db; text-decoration: none;">👁️ Ver</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div style="margin-top: 1rem; text-align: right; color: #7f8c8d;">
        <small>Total: <?php echo count($submissions); ?> preenchimento(s)</small>
    </div>
<?php else: ?>
    <div style="background: #e7f3ff; padding: 2rem; text-align: center; border-radius: 8px;">
        <p style="color: #5c7cad;">📭 Nenhum preenchimento encontrado</p>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../footer.php'; ?>
