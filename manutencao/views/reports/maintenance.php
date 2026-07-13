<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>🔧 Relatório de Manutenção</h1>
</div>

<!-- Máquinas com Manutenção Pendente -->
<div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 2rem;">
    <h3 style="margin-top: 0; color: #e74c3c;">⚠️ Máquinas Pendentes de Manutenção</h3>
    
    <?php if (!empty($pending_maintenance)): ?>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #fff3cd; border-bottom: 2px solid #f39c12;">
                        <th style="padding: 1rem; text-align: left;">Dispositivo</th>
                        <th style="padding: 1rem; text-align: left;">Setor</th>
                        <th style="padding: 1rem; text-align: left;">Última Manutenção</th>
                        <th style="padding: 1rem; text-align: center;">Dias</th>
                        <th style="padding: 1rem; text-align: left;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending_maintenance as $device): ?>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 1rem;"><strong>🖥️ <?php echo htmlspecialchars($device['name']); ?></strong></td>
                            <td style="padding: 1rem;">🏭 <?php echo htmlspecialchars($device['sector_name'] ?? 'Sem Setor'); ?></td>
                            <td style="padding: 1rem;">
                                <?php echo $device['last_maintenance'] ? date('d/m/Y', strtotime($device['last_maintenance'])) : '🔴 Nunca'; ?>
                            </td>
                            <td style="padding: 1rem; text-align: center; font-weight: bold;">
                                <?php if ($device['days_since_maintenance']): ?>
                                    <span style="color: <?php echo ($device['days_since_maintenance'] > 30) ? '#e74c3c' : '#f39c12'; ?>;">
                                        <?php echo $device['days_since_maintenance']; ?> dias
                                    </span>
                                <?php else: ?>
                                    <span style="color: #95a5a6;">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 1rem;">
                                <?php 
                                $days = $device['days_since_maintenance'];
                                if ($days === null) {
                                    echo '<span style="background: #e74c3c; color: white; padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 0.9rem;">🔴 Nunca mantida</span>';
                                } elseif ($days > 30) {
                                    echo '<span style="background: #e74c3c; color: white; padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 0.9rem;">🔴 Urgente</span>';
                                } elseif ($days > 15) {
                                    echo '<span style="background: #f39c12; color: white; padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 0.9rem;">🟠 Próximo</span>';
                                } else {
                                    echo '<span style="background: #27ae60; color: white; padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 0.9rem;">🟢 OK</span>';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p style="color: #7f8c8d; text-align: center;">✓ Todas as máquinas estão atualizadas!</p>
    <?php endif; ?>
</div>

<!-- Máquinas que Nunca Tiveram Manutenção -->
<div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 2rem;">
    <h3 style="margin-top: 0; color: #e74c3c;">🔴 Máquinas Sem Histórico de Manutenção</h3>
    
    <?php if (!empty($never_maintained)): ?>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8d7da; border-bottom: 2px solid #e74c3c;">
                        <th style="padding: 1rem; text-align: left;">Dispositivo</th>
                        <th style="padding: 1rem; text-align: left;">Modelo</th>
                        <th style="padding: 1rem; text-align: left;">Setor</th>
                        <th style="padding: 1rem; text-align: left;">Data de Aquisição</th>
                        <th style="padding: 1rem; text-align: left;">Ação Recomendada</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($never_maintained as $device): ?>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 1rem;"><strong>🖥️ <?php echo htmlspecialchars($device['name']); ?></strong></td>
                            <td style="padding: 1rem;"><?php echo htmlspecialchars($device['model'] ?? 'N/A'); ?></td>
                            <td style="padding: 1rem;">🏭 <?php echo htmlspecialchars($device['sector_name'] ?? 'Sem Setor'); ?></td>
                            <td style="padding: 1rem;">
                                <?php echo $device['acquisition_date'] ? date('d/m/Y', strtotime($device['acquisition_date'])) : 'Não informado'; ?>
                            </td>
                            <td style="padding: 1rem;">
                                <span style="background: #e74c3c; color: white; padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 0.9rem;">
                                    ⚠️ Agendar manutenção inicial
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p style="color: #7f8c8d; text-align: center;">✓ Todas as máquinas possuem histórico de manutenção!</p>
    <?php endif; ?>
</div>

<!-- Legenda -->
<div style="background: #f9f9f9; padding: 1.5rem; border-radius: 8px; border-left: 4px solid #667eea;">
    <h4 style="margin-top: 0;">📋 Legenda de Status</h4>
    <ul style="list-style: none; padding: 0; margin: 0;">
        <li style="padding: 0.5rem 0;"><span style="background: #27ae60; color: white; padding: 0.25rem 0.75rem; border-radius: 4px;">🟢 OK</span> - Última manutenção há menos de 15 dias</li>
        <li style="padding: 0.5rem 0;"><span style="background: #f39c12; color: white; padding: 0.25rem 0.75rem; border-radius: 4px;">🟠 Próximo</span> - Última manutenção entre 15 e 30 dias</li>
        <li style="padding: 0.5rem 0;"><span style="background: #e74c3c; color: white; padding: 0.25rem 0.75rem; border-radius: 4px;">🔴 Urgente</span> - Última manutenção há mais de 30 dias</li>
        <li style="padding: 0.5rem 0;"><span style="background: #e74c3c; color: white; padding: 0.25rem 0.75rem; border-radius: 4px;">🔴 Nunca mantida</span> - Sem histórico de manutenção</li>
    </ul>
</div>

<?php include __DIR__ . '/../footer.php'; ?>
