<?php

namespace Models;

use Config\Database;
use Core\Logger;

class DeviceGroup
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function create($data)
    {
        try {
            $this->db->insert('device_groups', $data);
            $id = $this->db->lastInsertId();

            Logger::log('CREATE', 'devices', 'device_groups', $id, ['new' => $data]);

            return $id;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function update($id, $data)
    {
        try {
            $old_data = $this->getById($id);
            $this->db->update('device_groups', $data, 'id = :id', [':id' => $id]);

            Logger::log('UPDATE', 'devices', 'device_groups', $id, [
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

            $this->db->delete('device_group_members', 'device_group_id = :id', [':id' => $id]);
            $this->db->delete('device_groups', 'id = :id', [':id' => $id]);

            Logger::log('DELETE', 'devices', 'device_groups', $id, ['old' => $old_data]);

            return true;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function getById($id)
    {
        return $this->db->fetch(
            "SELECT * FROM device_groups WHERE id = :id",
            [':id' => $id]
        );
    }

    public function getAll($filters = [])
    {
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "status = :status";
            $params[':status'] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $where[] = "name LIKE :search";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $whereClause = empty($where) ? '1=1' : implode(' AND ', $where);

        return $this->db->fetchAll(
            "SELECT * FROM device_groups WHERE $whereClause ORDER BY name",
            $params
        );
    }

    public function getAllWithMemberCount($filters = [])
    {
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "dg.status = :status";
            $params[':status'] = $filters['status'];
        }

        $whereClause = empty($where) ? '1=1' : implode(' AND ', $where);

        return $this->db->fetchAll(
            "SELECT dg.*, COUNT(dgm.device_id) as device_count
            FROM device_groups dg
            LEFT JOIN device_group_members dgm ON dgm.device_group_id = dg.id
            WHERE $whereClause
            GROUP BY dg.id
            ORDER BY dg.name",
            $params
        );
    }

    public function count($filters = [])
    {
        $where = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = "name LIKE :search";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $whereClause = empty($where) ? '1=1' : implode(' AND ', $where);

        $result = $this->db->fetch(
            "SELECT COUNT(*) as total FROM device_groups WHERE $whereClause",
            $params
        );

        return $result['total'] ?? 0;
    }

    public function validateName($name, $exclude_id = null)
    {
        $where = "name = :name";
        $params = [':name' => $name];

        if ($exclude_id) {
            $where .= " AND id != :id";
            $params[':id'] = $exclude_id;
        }

        $result = $this->db->fetch("SELECT id FROM device_groups WHERE $where", $params);
        return $result === false;
    }

    public function getMembers($groupId)
    {
        return $this->db->fetchAll(
            "SELECT d.*, s.name as sector_name
            FROM devices d
            JOIN device_group_members dgm ON dgm.device_id = d.id
            LEFT JOIN sectors s ON d.sector_id = s.id
            WHERE dgm.device_group_id = :group_id
            ORDER BY d.name",
            [':group_id' => $groupId]
        );
    }

    public function getMemberIds($groupId)
    {
        $rows = $this->db->fetchAll(
            "SELECT device_id FROM device_group_members WHERE device_group_id = :group_id",
            [':group_id' => $groupId]
        );

        return array_map(function ($row) {
            return (int)$row['device_id'];
        }, $rows);
    }

    /**
     * Substitui todos os membros do grupo pela lista informada
     */
    public function setMembers($groupId, array $deviceIds)
    {
        $this->db->delete('device_group_members', 'device_group_id = :group_id', [':group_id' => $groupId]);

        foreach ($deviceIds as $deviceId) {
            $deviceId = intval($deviceId);
            if ($deviceId > 0) {
                $this->db->insert('device_group_members', [
                    'device_group_id' => $groupId,
                    'device_id' => $deviceId,
                ]);
            }
        }

        return true;
    }
}
