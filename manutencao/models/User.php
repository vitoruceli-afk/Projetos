<?php

namespace Models;

use Config\Database;
use Core\Logger;

class User
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function create($data)
    {
        try {
            $data['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
            unset($data['password']);

            $this->db->insert('users', $data);
            $id = $this->db->lastInsertId();

            Logger::log('CREATE', 'users', 'users', $id, ['new' => $data]);

            return $id;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function update($id, $data)
    {
        try {
            $old_data = $this->getById($id);

            if (isset($data['password']) && !empty($data['password'])) {
                $data['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
                unset($data['password']);
            } else {
                unset($data['password']);
            }

            $this->db->update('users', $data, 'id = :id', [':id' => $id]);

            Logger::log('UPDATE', 'users', 'users', $id, [
                'old' => $old_data,
                'new' => $data
            ]);

            return true;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function delete($id)
    {
        try {
            $old_data = $this->getById($id);
            $this->db->delete('users', 'id = :id', [':id' => $id]);

            Logger::log('DELETE', 'users', 'users', $id, ['old' => $old_data]);

            return true;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function getById($id)
    {
        return $this->db->fetch(
            "SELECT u.*, p.name as profile_name FROM users u 
            LEFT JOIN profiles p ON u.profile_id = p.id 
            WHERE u.id = :id",
            [':id' => $id]
        );
    }

    public function getByUsername($username)
    {
        return $this->db->fetch(
            "SELECT u.*, p.name as profile_name FROM users u 
            LEFT JOIN profiles p ON u.profile_id = p.id 
            WHERE u.username = :username",
            [':username' => $username]
        );
    }

    public function getAll($filters = [], $limit = 15, $offset = 0)
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = "(u.username LIKE :search OR u.email LIKE :search OR u.full_name LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['status'])) {
            $where[] = "u.status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['auth_type'])) {
            $where[] = "u.auth_type = :auth_type";
            $params[':auth_type'] = $filters['auth_type'];
        }

        if (!empty($filters['profile_id'])) {
            $where[] = "u.profile_id = :profile_id";
            $params[':profile_id'] = $filters['profile_id'];
        }

        $whereClause = implode(' AND ', $where);

        $sql = "SELECT u.*, p.name as profile_name FROM users u 
                LEFT JOIN profiles p ON u.profile_id = p.id 
                WHERE $whereClause 
                ORDER BY u.created_at DESC 
                LIMIT :limit OFFSET :offset";

        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    public function count($filters = [])
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = "(username LIKE :search OR email LIKE :search OR full_name LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['status'])) {
            $where[] = "status = :status";
            $params[':status'] = $filters['status'];
        }

        $whereClause = implode(' AND ', $where);

        $result = $this->db->fetch(
            "SELECT COUNT(*) as total FROM users WHERE $whereClause",
            $params
        );

        return $result['total'] ?? 0;
    }

    public function changeStatus($id, $status)
    {
        return $this->update($id, ['status' => $status]);
    }

    public function validateUsername($username, $exclude_id = null)
    {
        $where = "username = :username";
        $params = [':username' => $username];

        if ($exclude_id) {
            $where .= " AND id != :id";
            $params[':id'] = $exclude_id;
        }

        $result = $this->db->fetch("SELECT id FROM users WHERE $where", $params);
        return $result === false;
    }

    public function validateEmail($email, $exclude_id = null)
    {
        $where = "email = :email";
        $params = [':email' => $email];

        if ($exclude_id) {
            $where .= " AND id != :id";
            $params[':id'] = $exclude_id;
        }

        $result = $this->db->fetch("SELECT id FROM users WHERE $where", $params);
        return $result === false;
    }

    public function getUsersWithoutLDAP()
    {
        return $this->db->fetchAll(
            "SELECT * FROM users WHERE auth_type = 'local' ORDER BY full_name"
        );
    }

    public function getTechnicianUsers()
    {
        return $this->db->fetchAll(
            "SELECT u.* FROM users u
            JOIN profiles p ON u.profile_id = p.id
            WHERE p.name = 'Técnico' AND u.status = 'active'
            ORDER BY u.full_name"
        );
    }

    /**
     * Buscar usuário local pelo id do usuário no GLPI
     */
    public function getByGlpiUserId($glpiUserId)
    {
        return $this->db->fetch(
            "SELECT * FROM users WHERE glpi_user_id = :glpi_user_id",
            [':glpi_user_id' => $glpiUserId]
        );
    }

    /**
     * Criar ou atualizar um usuário local (perfil Técnico) a partir dos
     * super-admins trazidos do GLPI. Nunca sobrescreve o profile_id de um
     * usuário já existente, e nunca reatribui uma senha já definida.
     *
     * $glpiData: ['glpi_user_id','username','full_name','email']
     */
    public function upsertFromGlpi($glpiData, $technicianProfileId = 3)
    {
        $existing = $this->getByGlpiUserId($glpiData['glpi_user_id']);
        $now = date('Y-m-d H:i:s');

        if ($existing) {
            $this->update($existing['id'], [
                'full_name' => $glpiData['full_name'],
                'email' => $glpiData['email'] ?? $existing['email'],
                'status' => 'active',
            ]);
            return $existing['id'];
        }

        $username = $glpiData['username'];
        if (!$this->validateUsername($username)) {
            $username = $username . '_glpi' . $glpiData['glpi_user_id'];
        }

        $email = $glpiData['email'] ?? ($username . '@glpi.local');
        if (!$this->validateEmail($email)) {
            $email = 'glpi_user_' . $glpiData['glpi_user_id'] . '@glpi.local';
        }

        return $this->create([
            'username' => $username,
            'email' => $email,
            'password' => bin2hex(random_bytes(32)),
            'full_name' => $glpiData['full_name'],
            'auth_type' => 'glpi',
            'profile_id' => $technicianProfileId,
            'glpi_user_id' => $glpiData['glpi_user_id'],
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
