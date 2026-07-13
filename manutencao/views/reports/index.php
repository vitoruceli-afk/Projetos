<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>📊 Relatórios</h1>
    <p>Análise completa do sistema de manutenção</p>
</div>

<!-- Cards de Estatísticas -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
    <!-- Card: Formulários -->
    <div style="background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 4px solid #667eea;">
        <p style="color: #7f8c8d; margin: 0 0 0.5rem 0;">📋 Formulários Ativos</p>
        <h2 style="color: #667eea; margin: 0;"><?php echo $total_forms['count']; ?></h2>
        <a href="?page=reports&action=submissions" style="color: #667eea; text-decoration: none; font-size: 0.9rem;">Ver detalhes →</a>
    </div>

    <!-- Card: Preenchimentos -->
    <div style="background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 4px solid #27ae60;">
        <p style="color: #7f8c8d; margin: 0 0 0.5rem 0;">📤 Total de Preenchimentos</p>
        <h2 style="color: #27ae60; margin: 0;"><?php echo $total_submissions['count']; ?></h2>
        <a href="?page=reports&action=submissions" style="color: #27ae60; text-decoration: none; font-size: 0.9rem;">Ver detalhes →</a>
    </div>

    <!-- Card: Dispositivos -->
    <div style="background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 4px solid #3498db;">
        <p style="color: #7f8c8d; margin: 0 0 0.5rem 0;">🖥️ Dispositivos Ativos</p>
        <h2 style="color: #3498db; margin: 0;"><?php echo $total_devices['count']; ?></h2>
        <a href="?page=reports&action=devices" style="color: #3498db; text-decoration: none; font-size: 0.9rem;">Ver detalhes →</a>
    </div>

    <!-- Card: Setores -->
    <div style="background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 4px solid #f39c12;">
        <p style="color: #7f8c8d; margin: 0 0 0.5rem 0;">🏢 Setores Ativos</p>
        <h2 style="color: #f39c12; margin: 0;"><?php echo $total_sectors['count']; ?></h2>
    </div>
</div>

<!-- Menu de Relatórios -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
    <a href="?page=reports&action=submissions" style="background: #667eea; color: white; padding: 1.5rem; border-radius: 8px; text-decoration: none; text-align: center; transition: background 0.3s;">
        <div style="font-size: 2rem; margin-bottom: 0.5rem;">📤</div>
        <div style="font-weight: bold;">Preenchimentos</div>
    </a>
    <a href="?page=reports&action=devices" style="background: #3498db; color: white; padding: 1.5rem; border-radius: 8px; text-decoration: none; text-align: center; transition: background 0.3s;">
        <div style="font-size: 2rem; margin-bottom: 0.5rem;">🖥️</div>
        <div style="font-weight: bold;">Dispositivos</div>
    </a>
    <a href="?page=reports&action=maintenance" style="background: #e74c3c; color: white; padding: 1.5rem; border-radius: 8px; text-decoration: none; text-align: center; transition: background 0.3s;">
        <div style="font-size: 2rem; margin-bottom: 0.5rem;">🔧</div>
        <div style="font-weight: bold;">Manutenção</div>
    </a>
</div>

<!-- Formulários Mais Preenchidos -->
<div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 2rem;">
    <h3 style="margin-top: 0;">📊 Formulários Mais Preenchidos</h3>
    
    <?php if (!empty($top_forms)): ?>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f5f5f5; border-bottom: 2px solid #ddd;">
                    <th style="padding: 1rem; text-align: left;">Formulário</th>
                    <th style="padding: 1rem; text-align: center;">Total de Preenchimentos</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($top_forms as $form): ?>
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 1rem;">📋 <?php echo htmlspecialchars($form['name']); ?></td>
                        <td style="padding: 1rem; text-align: center; font-weight: bold; color: #667eea;"><?php echo $form['total']; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p style="color: #7f8c8d; text-align: center;">Nenhum dado disponível</p>
    <?php endif; ?>
</div>

<!-- Dispositivos por Setor -->
<div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 2rem;">
    <h3 style="margin-top: 0;">🏢 Dispositivos por Setor</h3>
    
    <?php if (!empty($devices_by_sector)): ?>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f5f5f5; border-bottom: 2px solid #ddd;">
                    <th style="padding: 1rem; text-align: left;">Setor</th>
                    <th style="padding: 1rem; text-align: center;">Dispositivos</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($devices_by_sector as $sector): ?>
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 1rem;">🏭 <?php echo htmlspecialchars($sector['name']); ?></td>
                        <td style="padding: 1rem; text-align: center; font-weight: bold; color: #3498db;"><?php echo $sector['total']; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p style="color: #7f8c8d; text-align: center;">Nenhum dado disponível</p>
    <?php endif; ?>
</div>

<!-- Últimos Preenchimentos -->
<div style="background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
    <h3 style="margin-top: 0;">⏱️ Últimos Preenchimentos</h3>
    
    <?php if (!empty($recent_submissions)): ?>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f5f5f5; border-bottom: 2px solid #ddd;">
                    <th style="padding: 1rem; text-align: left;">Formulário</th>
                    <th style="padding: 1rem; text-align: left;">Preenchido Por</th>
                    <th style="padding: 1rem; text-align: left;">Data/Hora</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_submissions as $sub): ?>
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 1rem;">📋 <?php echo htmlspecialchars($sub['name']); ?></td>
                        <td style="padding: 1rem;">👤 <?php echo htmlspecialchars($sub['full_name']); ?></td>
                        <td style="padding: 1rem; color: #7f8c8d; font-size: 0.9rem;">
                            <?php echo date('d/m/Y H:i', strtotime($sub['submitted_at'])); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p style="color: #7f8c8d; text-align: center;">Nenhum preenchimento realizado</p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../footer.php'; ?>
