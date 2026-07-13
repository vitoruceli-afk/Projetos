<?php

namespace Models;

use Config\Database;
use Core\Logger;

class Profile
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function create($data)
    {
        try {
            $this->db->insert('profiles', $data);
            $id = $this->db->lastInsertId();

            Logger::log('CREATE', 'profiles', 'profiles', $id, ['new' => $data]);

            return $id;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function update($id, $data)
    {
        try {
            $old_data = $this->getById($id);
            $this->db->update('profiles', $data, 'id = :id', [':id' => $id]);

            Logger::log('UPDATE', 'profiles', 'profiles', $id, [
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
            // Verificar se o perfil está sendo usado
            $users = $this->db->fetch(
                "SELECT COUNT(*) as total FROM users WHERE profile_id = :id",
                [':id' => $id]
            );

            if ($users['total'] > 0) {
                throw new \Exception('Não é possível deletar um perfil que está em uso');
            }

            $old_data = $this->getById($id);
            $this->db->delete('profiles', 'id = :id', [':id' => $id]);
            $this->db->delete('permissions', 'profile_id = :id', [':id' => $id]);

            Logger::log('DELETE', 'profiles', 'profiles', $id, ['old' => $old_data]);

            return true;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function getById($id)
    {
        return $this->db->fetch(
            "SELECT * FROM profiles WHERE id = :id",
            [':id' => $id]
        );
    }

    public function getAll($limit = 15, $offset = 0)
    {
        $sql = "SELECT * FROM profiles ORDER BY name LIMIT :limit OFFSET :offset";
        return $this->db->fetchAll($sql, [':limit' => $limit, ':offset' => $offset]);
    }

    public function count()
    {
        $result = $this->db->fetch("SELECT COUNT(*) as total FROM profiles");
        return $result['total'] ?? 0;
    }

    public function setPermission($profile_id, $module_name, $permissions)
    {
        try {
            // Verificar se já existe permissão
            $existing = $this->db->fetch(
                "SELECT id FROM permissions WHERE profile_id = :profile_id AND module_name = :module",
                [':profile_id' => $profile_id, ':module' => $module_name]
            );

            $data = [
                'can_view' => $permissions['can_view'] ?? 0,
                'can_create' => $permissions['can_create'] ?? 0,
                'can_edit' => $permissions['can_edit'] ?? 0,
                'can_delete' => $permissions['can_delete'] ?? 0
            ];

            if ($existing) {
                $this->db->update('permissions', $data, 
                    'profile_id = :profile_id AND module_name = :module',
                    [':profile_id' => $profile_id, ':module' => $module_name]
                );
            } else {
                $data['profile_id'] = $profile_id;
                $data['module_name'] = $module_name;
                $this->db->insert('permissions', $data);
            }

            Logger::log('UPDATE', 'permissions', 'permissions', null, ['new' => $data]);

            return true;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function getPermissions($profile_id)
    {
        return $this->db->fetchAll(
            "SELECT * FROM permissions WHERE profile_id = :profile_id ORDER BY module_name",
            [':profile_id' => $profile_id]
        );
    }

    public function getPermissionByModule($profile_id, $module_name)
    {
        return $this->db->fetch(
            "SELECT * FROM permissions WHERE profile_id = :profile_id AND module_name = :module",
            [':profile_id' => $profile_id, ':module' => $module_name]
        );
    }

    public function getModules()
    {
        return [
            'users' => 'Gerenciamento de Usuários',
            'profiles' => 'Gerenciamento de Perfis',
            'smtp' => 'Configuração SMTP',
            'forms' => 'Gerenciamento de Formulários',
            'submissions' => 'Preenchimento de Formulários',
            'sectors' => 'Gerenciamento de Setores',
            'reports' => 'Relatórios'
        ];
    }

    public function getAllWithPermissions($profile_id)
    {
        $modules = $this->getModules();
        $permissions = $this->getPermissions($profile_id);
        $permissionMap = [];

        foreach ($permissions as $perm) {
            $permissionMap[$perm['module_name']] = $perm;
        }

        $result = [];
        foreach ($modules as $key => $name) {
            $result[$key] = [
                'name' => $name,
                'permissions' => $permissionMap[$key] ?? [
                    'profile_id' => $profile_id,
                    'module_name' => $key,
                    'can_view' => 0,
                    'can_create' => 0,
                    'can_edit' => 0,
                    'can_delete' => 0
                ]
            ];
        }

        return $result;
    }
}
