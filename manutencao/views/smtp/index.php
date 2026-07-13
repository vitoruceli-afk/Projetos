<?php include __DIR__ . '/../header.php'; ?>

<div class="page-header">
    <h1>📧 Configuração SMTP</h1>
    <p>Configure o servidor de email para envio de mensagens</p>
</div>

<?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
    <div class="alert alert-success">
        ✅ Configurações salvas com sucesso!
    </div>
<?php endif; 

if (isset($_GET['error'])): ?>
    <div class="alert alert-danger">
        ❌ Erro: <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
<?php endif; 

if (!empty($error)): ?>
    <div class="alert alert-danger">
        ❌ <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<?php if ($config): ?>
    <div style="max-width: 800px;">
        <div style="background: white; padding: 2rem; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 2rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
                <h2>Estado do SMTP</h2>
                <div>
                    <?php if ($config['is_enabled']): ?>
                        <span class="badge badge-success">✅ Ativado</span>
                    <?php else: ?>
                        <span class="badge badge-danger">❌ Desativado</span>
                    <?php endif; ?>
                </div>
            </div>

            <form method="POST" action="index.php?page=smtp&action=edit">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                    <div class="form-group">
                        <label for="smtp_host">Host SMTP *</label>
                        <input type="text" id="smtp_host" name="smtp_host" 
                               value="<?php echo htmlspecialchars($config['smtp_host'] ?? ''); ?>"
                               placeholder="smtp.gmail.com" required>
                        <small>Exemplo: smtp.gmail.com, smtp.outlook.com</small>
                    </div>

                    <div class="form-group">
                        <label for="smtp_port">Porta SMTP *</label>
                        <input type="number" id="smtp_port" name="smtp_port" 
                               value="<?php echo intval($config['smtp_port'] ?? 587); ?>"
                               placeholder="587" required>
                        <small>Exemplo: 587 (TLS), 465 (SSL), 25</small>
                    </div>
                </div>

                <div class="form-group">
                    <label for="smtp_encryption">Tipo de Criptografia *</label>
                    <select id="smtp_encryption" name="smtp_encryption" required>
                        <option value="none" <?php echo ($config['smtp_encryption'] === 'none') ? 'selected' : ''; ?>>Nenhum</option>
                        <option value="tls" <?php echo ($config['smtp_encryption'] === 'tls') ? 'selected' : ''; ?>>TLS (Porta 587)</option>
                        <option value="ssl" <?php echo ($config['smtp_encryption'] === 'ssl') ? 'selected' : ''; ?>>SSL (Porta 465)</option>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                    <div class="form-group">
                        <label for="smtp_username">Usuário SMTP</label>
                        <input type="text" id="smtp_username" name="smtp_username" 
                               value="<?php echo htmlspecialchars($config['smtp_username'] ?? ''); ?>"
                               placeholder="seu-email@gmail.com">
                        <small>Geralmente seu email</small>
                    </div>

                    <div class="form-group">
                        <label for="smtp_password">Senha SMTP</label>
                        <input type="password" id="smtp_password" name="smtp_password" 
                               placeholder="Deixe em branco para manter a atual">
                        <small>Sua senha de email ou senha de app específica</small>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                    <div class="form-group">
                        <label for="from_email">Email de Remetente *</label>
                        <input type="email" id="from_email" name="from_email" 
                               value="<?php echo htmlspecialchars($config['from_email'] ?? ''); ?>"
                               placeholder="noreply@exemplo.com" required>
                        <small>Email que aparecerá como remetente</small>
                    </div>

                    <div class="form-group">
                        <label for="from_name">Nome do Remetente *</label>
                        <input type="text" id="from_name" name="from_name" 
                               value="<?php echo htmlspecialchars($config['from_name'] ?? ''); ?>"
                               placeholder="Sistema de Manutenção" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" name="is_enabled" 
                               <?php echo $config['is_enabled'] ? 'checked' : ''; ?>>
                        <strong>Ativar SMTP</strong> (Permitir envio de emails)
                    </label>
                </div>

                <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                    <button type="submit" class="btn">💾 Salvar Configurações</button>
                    <button type="button" class="btn" style="background: #3498db;" onclick="testarSMTP()">📧 Testar Conexão</button>
                </div>
            </form>
        </div>

        <div style="background: #f0f7ff; padding: 1.5rem; border-radius: 4px; border-left: 4px solid #3498db;">
            <h3>📖 Instruções para Gmail</h3>
            <ol style="margin-left: 1.5rem;">
                <li>Host: <code>smtp.gmail.com</code></li>
                <li>Porta: <code>587</code></li>
                <li>Criptografia: <code>TLS</code></li>
                <li>Usuário: seu email (ex: seu@gmail.com)</li>
                <li>Senha: 
                    <a href="https://myaccount.google.com/apppasswords" target="_blank">Gerar Senha de App</a>
                    (não use sua senha normal)
                </li>
            </ol>
        </div>
    </div>

    <script>
    function testarSMTP() {
        const btn = event.target;
        btn.disabled = true;
        btn.textContent = '⏳ Testando...';

        fetch('index.php?page=smtp&action=test')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ ' + data.message);
                } else {
                    alert('❌ ' + data.message);
                }
            })
            .catch(error => {
                alert('❌ Erro ao testar: ' + error.message);
            })
            .finally(() => {
                btn.disabled = false;
                btn.textContent = '📧 Testar Conexão';
            });
    }
    </script>

<?php else: ?>
    <div class="alert alert-info">
        ℹ️ Nenhuma configuração SMTP encontrada. A página será carregada novamente...
    </div>
    <script>
        location.reload();
    </script>
<?php endif; ?>

<?php include __DIR__ . '/../footer.php'; ?>