<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>👁️ Visualizar Setor</h1>
</div>

<?php if ($sector): ?>
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
        <!-- Informações do Setor -->
        <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 4px solid #667eea;">
            <h2 style="margin-top: 0;"><?php echo htmlspecialchars($sector['name']); ?></h2>
            
            <div style="margin: 1rem 0;">
                <p><strong>📍 Localização:</strong><br>
                <?php echo htmlspecialchars($sector['location'] ?? 'Não informada'); ?></p>
                
                <p><strong>📝 Descrição:</strong><br>
                <?php echo htmlspecialchars($sector['description'] ?? 'Sem descrição'); ?></p>
                
                <p><strong>Status:</strong><br>
                <?php if ($sector['status'] === 'active'): ?>
                    <span style="background: #27ae60; color: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.9rem;">✓ Ativo</span>
                <?php else: ?>
                    <span style="background: #e74c3c; color: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.9rem;">✕ Inativo</span>
                <?php endif; ?>
                </p>
                
                <p><strong>📅 Data de Criação:</strong><br>
                <?php echo date('d/m/Y H:i', strtotime($sector['created_at'])); ?></p>
                
                <p><strong>🔄 Última Atualização:</strong><br>
                <?php echo date('d/m/Y H:i', strtotime($sector['updated_at'])); ?></p>
            </div>

            <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                <a href="?page=sectors&action=edit&id=<?php echo $sector['id']; ?>" class="btn" style="flex: 1; background: #f39c12; color: white; padding: 0.75rem; text-decoration: none; text-align: center; border-radius: 4px; font-weight: bold;">✏️ Editar</a>
                <a href="?page=sectors&action=delete&id=<?php echo $sector['id']; ?>" class="btn" style="flex: 1; background: #e74c3c; color: white; padding: 0.75rem; text-decoration: none; text-align: center; border-radius: 4px; font-weight: bold;" onclick="return confirm('Tem certeza que deseja deletar?')">🗑️ Deletar</a>
                <a href="?page=sectors" class="btn" style="flex: 1; background: #95a5a6; color: white; padding: 0.75rem; text-decoration: none; text-align: center; border-radius: 4px; font-weight: bold;">⬅️ Voltar</a>
            </div>
        </div>

        <!-- Dispositivos -->
        <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 4px solid #3498db;">
            <h3 style="margin-top: 0;">🖥️ Dispositivos (<?php echo count($devices); ?>)</h3>
            
            <?php if (!empty($devices)): ?>
                <table style="width: 100%; margin-top: 1rem;">
                    <thead>
                        <tr style="border-bottom: 2px solid #3498db;">
                            <th style="text-align: left; padding: 0.75rem;">Nome</th>
                            <th style="text-align: left; padding: 0.75rem;">Modelo</th>
                            <th style="text-align: left; padding: 0.75rem;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($devices as $device): ?>
                            <tr style="border-bottom: 1px solid #ddd;">
                                <td style="padding: 0.75rem;"><?php echo htmlspecialchars($device['name'] ?? 'N/A'); ?></td>
                                <td style="padding: 0.75rem;"><?php echo htmlspecialchars($device['model'] ?? 'N/A'); ?></td>
                                <td style="padding: 0.75rem;">
                                    <?php if (($device['status'] ?? '') === 'active'): ?>
                                        <span style="background: #27ae60; color: white; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;">Ativo</span>
                                    <?php else: ?>
                                        <span style="background: #e74c3c; color: white; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.85rem;">Inativo</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div style="background: #f0f0f0; padding: 2rem; text-align: center; border-radius: 4px; margin-top: 1rem;">
                    <p style="color: #7f8c8d;">📭 Nenhum dispositivo cadastrado neste setor</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <div style="background: #f8d7da; color: #721c24; padding: 2rem; text-align: center; border-radius: 8px;">
        <h2>❌ Setor não encontrado!</h2>
        <p>O setor que você está procurando não existe ou foi deletado.</p>
        <a href="?page=sectors" class="btn" style="background: #667eea; color: white; padding: 0.75rem 1.5rem; text-decoration: none; border-radius: 4px;">⬅️ Voltar</a>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../footer.php'; ?>
