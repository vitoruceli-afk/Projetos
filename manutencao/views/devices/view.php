<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>👁️ Visualizar Dispositivo</h1>
</div>

<?php if ($device): ?>
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
        <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 4px solid #667eea;">
            <h2 style="margin-top: 0;">🖥️ <?php echo htmlspecialchars($device['name']); ?></h2>
            
            <div style="margin: 1.5rem 0;">
                <p><strong>📱 Modelo:</strong><br>
                <?php echo htmlspecialchars($device['model'] ?? 'N/A'); ?></p>
                
                <p><strong>🏭 Setor:</strong><br>
                <span style="background: #e7f3ff; padding: 0.25rem 0.75rem; border-radius: 4px;">
                    <?php echo htmlspecialchars($device['sector_name'] ?? 'Sem Setor'); ?>
                </span></p>
                
                <p><strong>🔢 Número de Série:</strong><br>
                <?php echo htmlspecialchars($device['serial_number'] ?? 'N/A'); ?></p>
                
                <p><strong>🏢 Fabricante:</strong><br>
                <?php echo htmlspecialchars($device['manufacturer'] ?? 'N/A'); ?></p>
                
                <p><strong>📅 Data de Aquisição:</strong><br>
                <?php echo $device['acquisition_date'] ? date('d/m/Y', strtotime($device['acquisition_date'])) : 'Não informada'; ?></p>
                
                <p><strong>🔧 Última Manutenção:</strong><br>
                <?php echo $device['last_maintenance'] ? date('d/m/Y', strtotime($device['last_maintenance'])) : 'Nunca realizada'; ?></p>
                
                <p><strong>📅 Frequência de Manutenção:</strong><br>
                <?php 
                $freq_map = [
                    'semanal' => 'Semanal',
                    'quinzenal' => 'Quinzenal',
                    'mensal' => 'Mensal',
                    'trimestral' => 'Trimestral',
                    'semestral' => 'Semestral',
                    'anual' => 'Anual',
                    'conforme_necessario' => 'Conforme Necessário'
                ];
                echo htmlspecialchars($freq_map[$device['maintenance_frequency']] ?? 'Não definida');
                ?></p>
                
                <p><strong>Status:</strong><br>
                <?php 
                $status_colors = [
                    'active' => '#27ae60',
                    'inactive' => '#e74c3c',
                    'manutencao' => '#f39c12'
                ];
                $status_labels = [
                    'active' => 'Ativo',
                    'inactive' => 'Inativo',
                    'manutencao' => 'Em Manutenção'
                ];
                $color = $status_colors[$device['status']] ?? '#95a5a6';
                $label = $status_labels[$device['status']] ?? 'Desconhecido';
                ?>
                <span style="background: <?php echo $color; ?>; color: white; padding: 0.25rem 0.75rem; border-radius: 4px;">
                    <?php echo $label; ?>
                </span></p>
                
                <p><strong>📅 Data de Criação:</strong><br>
                <?php echo date('d/m/Y H:i', strtotime($device['created_at'])); ?></p>
                
                <p><strong>🔄 Última Atualização:</strong><br>
                <?php echo date('d/m/Y H:i', strtotime($device['updated_at'])); ?></p>
            </div>

            <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                <a href="?page=devices&action=edit&id=<?php echo $device['id']; ?>" class="btn" style="flex: 1; background: #f39c12; color: white; padding: 0.75rem; text-decoration: none; text-align: center; border-radius: 4px; font-weight: bold;">✏️ Editar</a>
                <a href="?page=devices&action=delete&id=<?php echo $device['id']; ?>" class="btn" style="flex: 1; background: #e74c3c; color: white; padding: 0.75rem; text-decoration: none; text-align: center; border-radius: 4px; font-weight: bold;" onclick="return confirm('Tem certeza que deseja deletar?')">🗑️ Deletar</a>
                <a href="?page=devices" class="btn" style="flex: 1; background: #95a5a6; color: white; padding: 0.75rem; text-decoration: none; text-align: center; border-radius: 4px; font-weight: bold;">⬅️ Voltar</a>
            </div>
        </div>

        <!-- Informações Adicionais -->
        <div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 4px solid #3498db;">
            <h3 style="margin-top: 0;">📊 Informações do Dispositivo</h3>
            
            <div style="background: #f9f9f9; padding: 1.5rem; border-radius: 4px; margin: 1rem 0;">
                <h4 style="margin-top: 0; color: #667eea;">🎯 Resumo</h4>
                <ul style="list-style: none; padding: 0; margin: 0;">
                    <li style="padding: 0.5rem 0;"><strong>ID:</strong> #<?php echo $device['id']; ?></li>
                    <li style="padding: 0.5rem 0;"><strong>Nome:</strong> <?php echo htmlspecialchars($device['name']); ?></li>
                    <li style="padding: 0.5rem 0;"><strong>Setor:</strong> <?php echo htmlspecialchars($device['sector_name'] ?? 'N/A'); ?></li>
                    <li style="padding: 0.5rem 0;"><strong>Status Atual:</strong> 
                        <span style="background: <?php echo $status_colors[$device['status']] ?? '#95a5a6'; ?>; color: white; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.9rem;">
                            <?php echo $label; ?>
                        </span>
                    </li>
                </ul>
            </div>

            <div style="background: #fff3cd; padding: 1.5rem; border-radius: 4px; border-left: 4px solid #f39c12;">
                <h4 style="margin-top: 0; color: #7d5c0d;">⚠️ Próxima Manutenção</h4>
                <?php 
                if ($device['last_maintenance']) {
                    $last = new DateTime($device['last_maintenance']);
                    $now = new DateTime();
                    $interval = $last->diff($now);
                    $days = $interval->days;
                    
                    if ($days < 30) {
                        echo "<p style='color: #7d5c0d; margin: 0;'>⏰ Manutenção devido em breve!</p>";
                    } elseif ($days < 60) {
                        echo "<p style='color: #7d5c0d; margin: 0;'>📅 Próxima manutenção em " . $days . " dias</p>";
                    } else {
                        echo "<p style='color: #7d5c0d; margin: 0;'>✓ Dispositivo em bom estado</p>";
                    }
                } else {
                    echo "<p style='color: #7d5c0d; margin: 0;'>📝 Nenhuma manutenção registrada</p>";
                }
                ?>
            </div>

            <div style="background: #d1ecf1; padding: 1.5rem; border-radius: 4px; border-left: 4px solid #17a2b8; margin-top: 1rem;">
                <h4 style="margin-top: 0; color: #0c5460;">ℹ️ Histórico</h4>
                <ul style="list-style: none; padding: 0; margin: 0; font-size: 0.9rem;">
                    <li style="padding: 0.5rem 0; color: #0c5460;"><strong>Criado em:</strong> <?php echo date('d/m/Y H:i', strtotime($device['created_at'])); ?></li>
                    <li style="padding: 0.5rem 0; color: #0c5460;"><strong>Atualizado em:</strong> <?php echo date('d/m/Y H:i', strtotime($device['updated_at'])); ?></li>
                </ul>
            </div>
        </div>
    </div>

<?php else: ?>
    <div style="background: #f8d7da; color: #721c24; padding: 2rem; text-align: center; border-radius: 8px;">
        <h2>❌ Dispositivo não encontrado!</h2>
        <p>O dispositivo que você está procurando não existe ou foi deletado.</p>
        <a href="?page=devices" class="btn" style="background: #667eea; color: white; padding: 0.75rem 1.5rem; text-decoration: none; border-radius: 4px;">⬅️ Voltar</a>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../footer.php'; ?>
