<?php
// views/profile.php
// Incluir header com navbar + sidebar
include 'header.php';

use Models\User;

// Obter dados do usuário logado
$userModel = new User();
$current_user = $userModel->getById($_SESSION['user_id']);

$success = '';
$error = '';

// Processar atualização de perfil
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Validações
        if (empty($full_name)) {
            throw new Exception('Nome completo é obrigatório');
        }

        if (empty($email)) {
            throw new Exception('Email é obrigatório');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Email inválido');
        }

        // Se houver alteração de senha
        if (!empty($new_password)) {
            // Verificar senha atual
            if (empty($current_password)) {
                throw new Exception('Senha atual é obrigatória para alterar a senha');
            }

            // Verificar se a senha atual está correta
            if (!password_verify($current_password, $current_user['password_hash'])) {
                throw new Exception('Senha atual incorreta');
            }

            // Verificar se as senhas novas coincidem
            if ($new_password !== $confirm_password) {
                throw new Exception('As senhas novas não coincidem');
            }

            if (strlen($new_password) < 6) {
                throw new Exception('A nova senha deve ter no mínimo 6 caracteres');
            }
        }

        // Preparar dados para atualização
        $updateData = [
            'full_name' => $full_name,
            'email' => $email
        ];

        // Adicionar nova senha se fornecida
        if (!empty($new_password)) {
            $updateData['password'] = $new_password;
        }

        // Atualizar usuário
        $userModel->update($_SESSION['user_id'], $updateData);

        // Atualizar dados da sessão
        $_SESSION['user_name'] = $full_name;

        $success = 'Perfil atualizado com sucesso!';

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

?>

<!-- PAGE HEADER -->
<div class="page-header">
    <div>
        <h1>👤 Meu Perfil</h1>
        <p>Edite suas informações pessoais</p>
    </div>
</div>

<!-- ALERTS -->
<?php if (!empty($success)): ?>
    <div class="alert alert-success">
        ✓ <?php echo htmlspecialchars($success); ?>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger">
        ✗ <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<!-- PROFILE FORM -->
<div style="max-width: 600px; margin: 0 auto;">
    <div class="card">
        <h2 style="margin-top: 0; color: #333;">Informações Pessoais</h2>
        
        <form method="POST">
            <!-- Nome Completo -->
            <div class="form-group">
                <label for="full_name">Nome Completo</label>
                <input 
                    type="text" 
                    id="full_name" 
                    name="full_name" 
                    value="<?php echo htmlspecialchars($current_user['full_name'] ?? ''); ?>"
                    required
                >
            </div>

            <!-- Usuário (somente leitura) -->
            <div class="form-group">
                <label for="username">Usuário (não editável)</label>
                <input 
                    type="text" 
                    id="username" 
                    name="username" 
                    value="<?php echo htmlspecialchars($current_user['username'] ?? ''); ?>"
                    readonly
                    style="background-color: #f5f5f5; cursor: not-allowed;"
                >
            </div>

            <!-- Email -->
            <div class="form-group">
                <label for="email">Email</label>
                <input 
                    type="email" 
                    id="email" 
                    name="email" 
                    value="<?php echo htmlspecialchars($current_user['email'] ?? ''); ?>"
                    required
                >
            </div>

            <!-- Tipo de Autenticação (somente leitura) -->
            <div class="form-group">
                <label for="auth_type">Tipo de Autenticação</label>
                <input 
                    type="text" 
                    id="auth_type" 
                    name="auth_type" 
                    value="<?php echo htmlspecialchars(ucfirst($current_user['auth_type'] ?? 'local')); ?>"
                    readonly
                    style="background-color: #f5f5f5; cursor: not-allowed;"
                >
            </div>

            <hr style="margin: 2rem 0; border: none; border-top: 1px solid #ddd;">

            <h3 style="color: #333; margin-bottom: 1rem;">Alterar Senha (Opcional)</h3>
            <p style="color: #7f8c8d; font-size: 0.9rem; margin-bottom: 1rem;">
                Deixe em branco se não deseja alterar a senha
            </p>

            <!-- Senha Atual -->
            <div class="form-group">
                <label for="current_password">Senha Atual</label>
                <input 
                    type="password" 
                    id="current_password" 
                    name="current_password"
                    placeholder="Digite sua senha atual se quiser alterar"
                >
                <small style="color: #7f8c8d;">Necessária para alterar a senha</small>
            </div>

            <!-- Nova Senha -->
            <div class="form-group">
                <label for="new_password">Nova Senha</label>
                <input 
                    type="password" 
                    id="new_password" 
                    name="new_password"
                    placeholder="Deixe em branco para não alterar"
                >
                <small style="color: #7f8c8d;">Mínimo 6 caracteres</small>
            </div>

            <!-- Confirmar Nova Senha -->
            <div class="form-group">
                <label for="confirm_password">Confirmar Nova Senha</label>
                <input 
                    type="password" 
                    id="confirm_password" 
                    name="confirm_password"
                    placeholder="Confirme a nova senha"
                >
            </div>

            <!-- Botões -->
            <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                <button type="submit" class="btn" style="background-color: #667eea;">
                    💾 Salvar Alterações
                </button>
                <a href="index.php?page=dashboard" class="btn" style="background-color: #95a5a6; text-decoration: none;">
                    ← Voltar
                </a>
            </div>
        </form>
    </div>

    <!-- INFORMAÇÕES ADICIONAIS -->
    <div class="card" style="margin-top: 2rem; background: #f8f9fa;">
        <h3 style="margin-top: 0; color: #667eea;">📌 Informações da Conta</h3>
        <ul style="list-style: none; padding: 0;">
            <li style="padding: 0.5rem 0; border-bottom: 1px solid #ddd;">
                <strong>Status:</strong> 
                <span style="color: #27ae60;">● Ativo</span>
            </li>
            <li style="padding: 0.5rem 0; border-bottom: 1px solid #ddd;">
                <strong>Perfil:</strong> 
                <?php echo htmlspecialchars($current_user['profile_name'] ?? 'Sem perfil'); ?>
            </li>
            <li style="padding: 0.5rem 0;">
                <strong>Criado em:</strong> 
                <?php echo date('d/m/Y H:i', strtotime($current_user['created_at'] ?? 'now')); ?>
            </li>
        </ul>
    </div>
</div>

<?php
// Incluir footer
include 'footer.php';
?>
