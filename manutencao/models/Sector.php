<?php

namespace Models;

use Config\Database;
use Core\Logger;

class Sector
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function create($data)
    {
        try {
            $this->db->insert('sectors', $data);
            $id = $this->db->lastInsertId();

            Logger::log('CREATE', 'sectors', 'sectors', $id, ['new' => $data]);

            return $id;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function update($id, $data)
    {
        try {
            $old_data = $this->getById($id);
            $this->db->update('sectors', $data, 'id = :id', [':id' => $id]);

            Logger::log('UPDATE', 'sectors', 'sectors', $id, [
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
            
            $this->db->delete('devices', 'sector_id = :id', [':id' => $id]);
            $this->db->delete('sectors', 'id = :id', [':id' => $id]);

            Logger::log('DELETE', 'sectors', 'sectors', $id, ['old' => $old_data]);

            return true;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function getById($id)
    {
        return $this->db->fetch(
            "SELECT s.*, u.full_name as responsible_name
            FROM sectors s
            LEFT JOIN users u ON s.responsible_user_id = u.id
            WHERE s.id = :id",
            [':id' => $id]
        );
    }

    public function getAll($filters = [], $limit = 15, $offset = 0)
    {
        $where = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = "(s.name LIKE :search OR s.location LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        if (isset($filters['is_active'])) {
            $where[] = "s.is_active = :is_active";
            $params[':is_active'] = $filters['is_active'] ? 1 : 0;
        }

        $whereClause = empty($where) ? '1=1' : implode(' AND ', $where);

        $sql = "SELECT s.*, u.full_name as responsible_name
                FROM sectors s
                LEFT JOIN users u ON s.responsible_user_id = u.id
                WHERE $whereClause
                ORDER BY s.name
                LIMIT :limit OFFSET :offset";

        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    public function getAllActive()
    {
        return $this->db->fetchAll(
            "SELECT s.*, u.full_name as responsible_name
            FROM sectors s
            LEFT JOIN users u ON s.responsible_user_id = u.id
            WHERE s.is_active = 1
            ORDER BY s.name"
        );
    }

    public function count($filters = [])
    {
        $where = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = "(name LIKE :search OR location LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $whereClause = empty($where) ? '1=1' : implode(' AND ', $where);

        $result = $this->db->fetch(
            "SELECT COUNT(*) as total FROM sectors WHERE $whereClause",
            $params
        );

        return $result['total'] ?? 0;
    }

    public function getWithDeviceCount()
    {
        return $this->db->fetchAll(
            "SELECT s.*, u.full_name as responsible_name, COUNT(d.id) as device_count
            FROM sectors s
            LEFT JOIN users u ON s.responsible_user_id = u.id
            LEFT JOIN devices d ON s.id = d.sector_id
            WHERE s.is_active = 1
            GROUP BY s.id
            ORDER BY s.name"
        );
    }
}
