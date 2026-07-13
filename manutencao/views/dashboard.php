<?php
// views/dashboard.php
// Incluir header com navbar + sidebar
include 'header.php';
?>

    <!-- PAGE HEADER -->
    <div class="page-header">
        <div>
            <h1>
                <i class="fas fa-tachometer-alt"></i>
                Dashboard
            </h1>
            <p>Bem-vindo ao Sistema de Manutenção de Máquinas</p>
        </div>
    </div>

    <!-- CARDS DE ESTATÍSTICAS (MANTÉM OS ATUAIS) -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
        
        <!-- Card Usuários -->
        <div class="stat-card" style="border-left-color: #667eea;">
            <div style="display: flex; justify-content: space-between; align-items: start;">
                <div>
                    <p style="margin: 0 0 0.5rem 0; color: var(--gray); font-size: 0.9rem;">👥 Usuários Ativos</p>
                    <h2 style="margin: 0; color: #667eea; font-size: 2rem;"><?php echo $stats['users']; ?></h2>
                </div>
                <i class="fas fa-users" style="font-size: 2.5rem; color: #667eea; opacity: 0.2;"></i>
            </div>
            <a href="index.php?page=users" style="color: var(--primary); text-decoration: none; font-size: 0.9rem;">Ver detalhes →</a>
        </div>

        <!-- Card Formulários -->
        <div class="stat-card" style="border-left-color: #f39c12;">
            <div style="display: flex; justify-content: space-between; align-items: start;">
                <div>
                    <p style="margin: 0 0 0.5rem 0; color: var(--gray); font-size: 0.9rem;">📋 Formulários</p>
                    <h2 style="margin: 0; color: #f39c12; font-size: 2rem;"><?php echo $stats['forms']; ?></h2>
                </div>
                <i class="fas fa-file-alt" style="font-size: 2.5rem; color: #f39c12; opacity: 0.2;"></i>
            </div>
            <a href="index.php?page=forms" style="color: var(--primary); text-decoration: none; font-size: 0.9rem;">Ver detalhes →</a>
        </div>

        <!-- Card Preenchimentos -->
        <div class="stat-card" style="border-left-color: #27ae60;">
            <div style="display: flex; justify-content: space-between; align-items: start;">
                <div>
                    <p style="margin: 0 0 0.5rem 0; color: var(--gray); font-size: 0.9rem;">✓ Preenchimentos</p>
                    <h2 style="margin: 0; color: #27ae60; font-size: 2rem;"><?php echo $stats['submissions']; ?></h2>
                </div>
                <i class="fas fa-check-circle" style="font-size: 2.5rem; color: #27ae60; opacity: 0.2;"></i>
            </div>
            <a href="index.php?page=submissions" style="color: var(--primary); text-decoration: none; font-size: 0.9rem;">Ver detalhes →</a>
        </div>

        <!-- Card Setores -->
        <div class="stat-card" style="border-left-color: #e74c3c;">
            <div style="display: flex; justify-content: space-between; align-items: start;">
                <div>
                    <p style="margin: 0 0 0.5rem 0; color: var(--gray); font-size: 0.9rem;">🏢 Setores</p>
                    <h2 style="margin: 0; color: #e74c3c; font-size: 2rem;"><?php echo $stats['sectors']; ?></h2>
                </div>
                <i class="fas fa-building" style="font-size: 2.5rem; color: #e74c3c; opacity: 0.2;"></i>
            </div>
            <a href="index.php?page=sectors" style="color: var(--primary); text-decoration: none; font-size: 0.9rem;">Ver detalhes →</a>
        </div>

    </div>

    <!-- SEÇÃO DE INFORMAÇÕES -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
        
        <!-- Card Rápido de Acesso -->
        <div class="card">
            <h3 style="margin-top: 0;">🚀 Acesso Rápido</h3>
            <p style="color: var(--gray);">Acione rapidamente as ações mais comuns do sistema:</p>
            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                <a href="index.php?page=users" class="btn btn-primary btn-sm" style="text-align: center;">
                    <i class="fas fa-users"></i> Gerenciar Usuários
                </a>
                <a href="index.php?page=forms" class="btn btn-primary btn-sm" style="text-align: center;">
                    <i class="fas fa-file-alt"></i> Criar Formulário
                </a>
                <a href="index.php?page=submissions" class="btn btn-primary btn-sm" style="text-align: center;">
                    <i class="fas fa-check-circle"></i> Ver Preenchimentos
                </a>
                <a href="index.php?page=reports" class="btn btn-primary btn-sm" style="text-align: center;">
                    <i class="fas fa-chart-bar"></i> Ver Relatórios
                </a>
            </div>
        </div>

        <!-- Card Informações do Sistema -->
        <div class="card">
            <h3 style="margin-top: 0;">📊 Sistema</h3>
            <p style="color: var(--gray);">Informações gerais do sistema:</p>
            <div>
                <p style="margin: 0.5rem 0;"><strong>Versão:</strong> 1.0.0</p>
                <p style="margin: 0.5rem 0;"><strong>Status:</strong> <span class="badge badge-success">Operacional</span></p>
                <p style="margin: 0.5rem 0;"><strong>Banco de Dados:</strong> Conectado</p>
                <p style="margin-top: 1rem; color: var(--gray); font-size: 0.85rem;">
                    Última sincronização: agora
                </p>
            </div>
        </div>

    </div>

    <!-- SEÇÃO DE ESTATÍSTICAS GERAIS -->
    <div style="margin-top: 2rem;">
        <h2 style="color: var(--dark); margin-bottom: 1.5rem; font-size: 1.3rem;">
            <i class="fas fa-chart-bar"></i> Estatísticas Gerais
        </h2>
        
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Módulo</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Ação</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>👥 Usuários</td>
                        <td><?php echo $stats['users']; ?></td>
                        <td><span class="badge badge-success">Ativo</span></td>
                        <td><a href="index.php?page=users" style="color: var(--primary); text-decoration: none;">Gerenciar</a></td>
                    </tr>
                    <tr>
                        <td>📋 Formulários</td>
                        <td><?php echo $stats['forms']; ?></td>
                        <td><span class="badge badge-info">Disponível</span></td>
                        <td><a href="index.php?page=forms" style="color: var(--primary); text-decoration: none;">Gerenciar</a></td>
                    </tr>
                    <tr>
                        <td>✓ Preenchimentos</td>
                        <td><?php echo $stats['submissions']; ?></td>
                        <td><span class="badge <?php echo $stats['submissions'] > 0 ? 'badge-success' : 'badge-warning'; ?>"><?php echo $stats['submissions'] > 0 ? 'Ativo' : 'Vazio'; ?></span></td>
                        <td><a href="index.php?page=submissions" style="color: var(--primary); text-decoration: none;">Ver</a></td>
                    </tr>
                    <tr>
                        <td>🏢 Setores</td>
                        <td><?php echo $stats['sectors']; ?></td>
                        <td><span class="badge badge-success">Ativo</span></td>
                        <td><a href="index.php?page=sectors" style="color: var(--primary); text-decoration: none;">Gerenciar</a></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

<?php
// Incluir footer
include 'footer.php';
?>
