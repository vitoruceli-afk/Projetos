<?php

namespace Core;

use Config\Database;

class Auth
{
    private $db;
    private $user = null;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Fazer login do usuário
     */
    public function login($username, $password, $remember = false)
    {
        try {
            $user = $this->db->fetch(
                "SELECT * FROM users WHERE (username = :username OR email = :username) AND status = 'active'",
                [':username' => $username]
            );

            if (!$user) {
                throw new \Exception('Usuário não encontrado');
            }

            if (!password_verify($password, $user['password_hash'])) {
                throw new \Exception('Senha incorreta');
            }

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_profile_id'] = $user['profile_id'];
            $_SESSION['full_name'] = $user['full_name'];

            $this->db->update('users', [
                'last_login' => date('Y-m-d H:i:s')
            ], 'id = :id', [':id' => $user['id']]);

            $this->user = $user;
            return true;

        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Fazer logout do usuário
     */
    public function logout()
    {
        session_destroy();
        $this->user = null;
    }

    /**
     * Verificar se usuário está logado
     */
    public function isLoggedIn()
    {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }

    /**
     * Exigir que usuário esteja logado
     */
    public function requireLogin()
    {
        if (!$this->isLoggedIn()) {
            header('Location: ' . \Config\Config::BASE_URL . '/index.php?page=login');
            exit;
        }
    }

    /**
     * Obter usuário atual
     */
    public function getCurrentUser()
    {
        if (!$this->isLoggedIn()) {
            return null;
        }

        if ($this->user === null) {
            $this->user = $this->db->fetch(
                "SELECT * FROM users WHERE id = :id",
                [':id' => $_SESSION['user_id']]
            );
        }

        return $this->user;
    }

    /**
     * Obter ID do usuário atual
     */
    public function getCurrentUserId()
    {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Obter username do usuário atual
     */
    public function getCurrentUsername()
    {
        return $_SESSION['username'] ?? null;
    }

    /**
     * Obter nome completo do usuário atual
     */
    public function getCurrentFullName()
    {
        return $_SESSION['full_name'] ?? null;
    }

    /**
     * Verificar se usuário tem permissão
     */
    public function hasPermission($module, $action = 'view')
    {
        if (!$this->isLoggedIn()) {
            return false;
        }

        $profileId = $_SESSION['user_profile_id'] ?? null;
        
        if (empty($profileId)) {
            return false;
        }

        try {
            $permission = $this->db->fetch(
                "SELECT * FROM permissions WHERE profile_id = :profile_id AND module_name = :module",
                [':profile_id' => $profileId, ':module' => $module]
            );

            if (!$permission) {
                // Se não houver restrição, permitir por padrão
                return true;
            }

            switch ($action) {
                case 'create':
                    return (int)$permission['can_create'] === 1;
                case 'edit':
                    return (int)$permission['can_edit'] === 1;
                case 'delete':
                    return (int)$permission['can_delete'] === 1;
                case 'view':
                default:
                    return (int)$permission['can_view'] === 1;
            }
        } catch (\Exception $e) {
            // Se houver erro, permitir por segurança
            return true;
        }
    }

    /**
     * Exigir permissão (lança exceção se não tiver)
     */
    public function requirePermission($module, $action = 'view')
    {
        $this->requireLogin();

        if (!$this->hasPermission($module, $action)) {
            throw new \Exception("Você não tem permissão para acessar este módulo: $module ($action)");
        }
    }

    /**
     * Obter perfil do usuário
     */
    public function getUserProfile()
    {
        if (!$this->isLoggedIn()) {
            return null;
        }

        $profileId = $_SESSION['user_profile_id'] ?? null;

        if (empty($profileId)) {
            return null;
        }

        try {
            return $this->db->fetch(
                "SELECT * FROM profiles WHERE id = :id",
                [':id' => $profileId]
            );
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Obter todas as permissões do usuário
     */
    public function getAllPermissions()
    {
        if (!$this->isLoggedIn()) {
            return [];
        }

        $profileId = $_SESSION['user_profile_id'] ?? null;

        if (empty($profileId)) {
            return [];
        }

        try {
            return $this->db->fetchAll(
                "SELECT * FROM permissions WHERE profile_id = :profile_id",
                [':profile_id' => $profileId]
            );
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Log de atividade
     */
    public function logActivity($action, $description, $table = null, $record_id = null)
    {
        try {
            $this->db->insert('audit_logs', [
                'user_id' => $this->getCurrentUserId(),
                'action' => $action,
                'description' => $description,
                'table_name' => $table,
                'record_id' => $record_id,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            // Falha silenciosa em log
        }
    }
}