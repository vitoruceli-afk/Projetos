<?php

namespace Controllers;

use Core\Auth;
use Config\Database;

class UserController
{
    private $auth;
    private $db;

    public function __construct()
    {
        $this->auth = new Auth();
        $this->db = Database::getInstance();
        $this->auth->requirePermission('users', 'view');
    }

    /**
     * Listar usuários
     */
    public function index()
    {
        try {
            $users = $this->db->fetchAll("SELECT * FROM users ORDER BY id DESC");
            include __DIR__ . '/../views/users/index.php';
        } catch (\Exception $e) {
            echo "Erro: " . $e->getMessage();
        }
    }

    /**
     * Criar novo usuário
     */
    public function create()
    {
        $this->auth->requirePermission('users', 'create');
        
        $error = '';
        $success = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                // Validar dados
                $username = trim($_POST['username'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $full_name = trim($_POST['full_name'] ?? '');
                $auth_type = $_POST['auth_type'] ?? 'local';
                $password = $_POST['password'] ?? '';
                $confirm_password = $_POST['confirm_password'] ?? '';
                $profile_id = intval($_POST['profile_id'] ?? 0);

                // Validações
                if (empty($username) || empty($email) || empty($full_name) || empty($password)) {
                    throw new \Exception('Todos os campos são obrigatórios');
                }

                if (strlen($username) < 3) {
                    throw new \Exception('Usuário deve ter no mínimo 3 caracteres');
                }

                if ($password !== $confirm_password) {
                    throw new \Exception('As senhas não conferem');
                }

                if (strlen($password) < 6) {
                    throw new \Exception('Senha deve ter no mínimo 6 caracteres');
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new \Exception('Email inválido');
                }

                // Verificar se usuário já existe
                $existing_user = $this->db->fetch(
                    "SELECT id FROM users WHERE username = ? OR email = ?",
                    [$username, $email]
                );

                if ($existing_user) {
                    throw new \Exception('Usuário ou email já cadastrado');
                }

                // Verificar se profile_id é válido
                if ($profile_id <= 0) {
                    throw new \Exception('Perfil inválido');
                }

                // Criptografar senha
                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                // Inserir usuário
                $result = $this->db->insert('users', [
                    'username' => $username,
                    'email' => $email,
                    'full_name' => $full_name,
                    'password_hash' => $password_hash,
                    'auth_type' => $auth_type,
                    'profile_id' => $profile_id,
                    'status' => 'active',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

                // Log da atividade
                $this->auth->logActivity('CREATE', "Novo usuário criado: $username", 'users', $result);

                // Redirecionar com sucesso
                header('Location: index.php?page=users&success=1');
                exit;

            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        }

        include __DIR__ . '/../views/users/create.php';
    }

    /**
     * Editar usuário
     */
    public function edit()
    {
        $this->auth->requirePermission('users', 'edit');
        
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            header('Location: index.php?page=users');
            exit;
        }

        $user = $this->db->fetch("SELECT * FROM users WHERE id = ?", [$id]);
        if (!$user) {
            header('Location: index.php?page=users');
            exit;
        }

        $error = '';
        $success = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $email = trim($_POST['email'] ?? '');
                $full_name = trim($_POST['full_name'] ?? '');
                $status = $_POST['status'] ?? 'active';
                $password = $_POST['password'] ?? '';

                if (empty($email) || empty($full_name)) {
                    throw new \Exception('Email e nome são obrigatórios');
                }

                // Preparar dados para atualizar
                $update_data = [
                    'email' => $email,
                    'full_name' => $full_name,
                    'status' => $status,
                    'updated_at' => date('Y-m-d H:i:s')
                ];

                // Se forneceu nova senha, atualizar
                if (!empty($password)) {
                    if (strlen($password) < 6) {
                        throw new \Exception('Senha deve ter no mínimo 6 caracteres');
                    }
                    $update_data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                }

                $this->db->update('users', $update_data, 'id = ?', [$id]);

                $this->auth->logActivity('UPDATE', "Usuário atualizado: {$user['username']}", 'users', $id);

                header('Location: index.php?page=users&success=1');
                exit;

            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        }

        include __DIR__ . '/../views/users/edit.php';
    }

    /**
     * Sincronizar técnicos (super-admins do GLPI na entidade configurada) como usuários locais
     */
    public function syncGlpiTechnicians()
    {
        $this->auth->requirePermission('users', 'edit');

        try {
            $client = new \Core\GLPIClient();
            $technicians = $client->getSuperAdminTechnicians();

            $userModel = new \Models\User();
            $count = 0;

            foreach ($technicians as $technician) {
                $userModel->upsertFromGlpi($technician);
                $count++;
            }

            $this->auth->logActivity('SYNC', "Sincronização GLPI: $count técnico(s)", 'users', null);

            header('Location: index.php?page=users&success=1&synced=' . $count);
            exit;
        } catch (\Exception $e) {
            header('Location: index.php?page=users&error=' . urlencode($e->getMessage()));
            exit;
        }
    }

    /**
     * Deletar usuário
     */
    public function delete()
    {
        $this->auth->requirePermission('users', 'delete');
        
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            header('Location: index.php?page=users');
            exit;
        }

        // Não permitir deletar o próprio usuário
        if ($id === $this->auth->getCurrentUserId()) {
            header('Location: index.php?page=users&error=nao_pode_deletar_proprio_usuario');
            exit;
        }

        try {
            $user = $this->db->fetch("SELECT * FROM users WHERE id = ?", [$id]);
            if ($user) {
                $this->db->delete('users', 'id = ?', [$id]);
                $this->auth->logActivity('DELETE', "Usuário deletado: {$user['username']}", 'users', $id);
            }
        } catch (\Exception $e) {
            // Silenciosamente falhar
        }

        header('Location: index.php?page=users&success=1');
        exit;
    }

    /**
     * Ver detalhes do usuário
     */
    public function view()
    {
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            header('Location: index.php?page=users');
            exit;
        }

        $user = $this->db->fetch("SELECT * FROM users WHERE id = ?", [$id]);
        if (!$user) {
            header('Location: index.php?page=users');
            exit;
        }

        include __DIR__ . '/../views/users/view.php';
    }
}