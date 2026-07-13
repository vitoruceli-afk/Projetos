<?php

namespace Controllers;

use Core\Auth;
use Config\Database;

class ProfileController
{
    private $auth;
    private $db;

    public function __construct()
    {
        $this->auth = new Auth();
        $this->db = Database::getInstance();
        $this->auth->requirePermission('profiles', 'view');
    }

    /**
     * Listar perfis
     */
    public function index()
    {
        try {
            $profiles = $this->db->fetchAll("SELECT * FROM profiles ORDER BY id DESC");
            include __DIR__ . '/../views/profiles/index.php';
        } catch (\Exception $e) {
            echo "Erro: " . $e->getMessage();
        }
    }

    /**
     * Criar novo perfil
     */
    public function create()
    {
        $this->auth->requirePermission('profiles', 'create');
        
        $error = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $is_default = isset($_POST['is_default']) ? 1 : 0;

                if (empty($name)) {
                    throw new \Exception('Nome do perfil é obrigatório');
                }

                // Verificar se já existe
                $existing = $this->db->fetch(
                    "SELECT id FROM profiles WHERE name = ?",
                    [$name]
                );

                if ($existing) {
                    throw new \Exception('Perfil com este nome já existe');
                }

                // Inserir perfil
                $profile_id = $this->db->insert('profiles', [
                    'name' => $name,
                    'description' => $description,
                    'is_default' => $is_default,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

                $this->auth->logActivity('CREATE', "Novo perfil criado: $name", 'profiles', $profile_id);

                header('Location: index.php?page=profiles&success=1');
                exit;

            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        }

        include __DIR__ . '/../views/profiles/create.php';
    }

    /**
     * Editar perfil
     */
    public function edit()
    {
        $this->auth->requirePermission('profiles', 'edit');
        
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            header('Location: index.php?page=profiles');
            exit;
        }

        $profile = $this->db->fetch("SELECT * FROM profiles WHERE id = ?", [$id]);
        if (!$profile) {
            header('Location: index.php?page=profiles');
            exit;
        }

        $error = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $is_default = isset($_POST['is_default']) ? 1 : 0;

                if (empty($name)) {
                    throw new \Exception('Nome do perfil é obrigatório');
                }

                $this->db->update('profiles', [
                    'name' => $name,
                    'description' => $description,
                    'is_default' => $is_default,
                    'updated_at' => date('Y-m-d H:i:s')
                ], 'id = ?', [$id]);

                $this->auth->logActivity('UPDATE', "Perfil atualizado: $name", 'profiles', $id);

                header('Location: index.php?page=profiles&success=1');
                exit;

            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        }

        include __DIR__ . '/../views/profiles/edit.php';
    }

    /**
     * Deletar perfil
     */
    public function delete()
    {
        $this->auth->requirePermission('profiles', 'delete');
        
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            header('Location: index.php?page=profiles');
            exit;
        }

        try {
            // Verificar se há usuários com este perfil
            $users_with_profile = $this->db->fetch(
                "SELECT COUNT(*) as count FROM users WHERE profile_id = ?",
                [$id]
            );

            if ($users_with_profile['count'] > 0) {
                header('Location: index.php?page=profiles&error=perfil_em_uso');
                exit;
            }

            $profile = $this->db->fetch("SELECT * FROM profiles WHERE id = ?", [$id]);
            if ($profile) {
                $this->db->delete('profiles', 'id = ?', [$id]);
                $this->db->delete('permissions', 'profile_id = ?', [$id]);
                $this->auth->logActivity('DELETE', "Perfil deletado: {$profile['name']}", 'profiles', $id);
            }
        } catch (\Exception $e) {
            // Silenciosamente falhar
        }

        header('Location: index.php?page=profiles&success=1');
        exit;
    }

    /**
     * Gerenciar permissões
     */
    public function permissions()
    {
        $this->auth->requirePermission('profiles', 'edit');
        
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            header('Location: index.php?page=profiles');
            exit;
        }

        $profile = $this->db->fetch("SELECT * FROM profiles WHERE id = ?", [$id]);
        if (!$profile) {
            header('Location: index.php?page=profiles');
            exit;
        }

        $permissions = $this->db->fetchAll(
            "SELECT * FROM permissions WHERE profile_id = ?",
            [$id]
        );

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                // Deletar permissões antigas
                $this->db->delete('permissions', 'profile_id = ?', [$id]);

                // Inserir novas permissões
                $modules = ['users', 'profiles', 'forms', 'submissions', 'sectors', 'smtp', 'reports'];
                $actions = ['can_view', 'can_create', 'can_edit', 'can_delete'];

                foreach ($modules as $module) {
                    $permission_data = [
                        'profile_id' => $id,
                        'module_name' => $module,
                        'can_view' => isset($_POST["{$module}_view"]) ? 1 : 0,
                        'can_create' => isset($_POST["{$module}_create"]) ? 1 : 0,
                        'can_edit' => isset($_POST["{$module}_edit"]) ? 1 : 0,
                        'can_delete' => isset($_POST["{$module}_delete"]) ? 1 : 0
                    ];
                    $this->db->insert('permissions', $permission_data);
                }

                $this->auth->logActivity('UPDATE', "Permissões do perfil atualizadas", 'profiles', $id);

                header('Location: index.php?page=profiles&success=1');
                exit;

            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        }

        include __DIR__ . '/../views/profiles/permissions.php';
    }
}