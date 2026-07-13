<?php

namespace Models;

use Config\Database;
use Core\Logger;

class FormCategory
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function create($data)
    {
        try {
            $this->db->insert('form_categories', $data);
            $id = $this->db->lastInsertId();

            Logger::log('CREATE', 'forms', 'form_categories', $id, ['new' => $data]);

            return $id;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function update($id, $data)
    {
        try {
            $old_data = $this->getById($id);
            $this->db->update('form_categories', $data, 'id = :id', [':id' => $id]);

            Logger::log('UPDATE', 'forms', 'form_categories', $id, [
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
            // Verificar se há formulários nesta categoria
            $forms = $this->db->fetch(
                "SELECT COUNT(*) as total FROM forms WHERE category_id = :id",
                [':id' => $id]
            );

            if ($forms['total'] > 0) {
                throw new \Exception('Não é possível deletar uma categoria que contém formulários');
            }

            $old_data = $this->getById($id);
            $this->db->delete('form_categories', 'id = :id', [':id' => $id]);

            Logger::log('DELETE', 'forms', 'form_categories', $id, ['old' => $old_data]);

            return true;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function getById($id)
    {
        return $this->db->fetch(
            "SELECT * FROM form_categories WHERE id = :id",
            [':id' => $id]
        );
    }

    public function getAll($limit = 15, $offset = 0)
    {
        return $this->db->fetchAll(
            "SELECT * FROM form_categories ORDER BY order_index, name LIMIT :limit OFFSET :offset",
            [':limit' => $limit, ':offset' => $offset]
        );
    }

    public function getAllActive()
    {
        return $this->db->fetchAll(
            "SELECT * FROM form_categories ORDER BY order_index, name"
        );
    }

    public function count()
    {
        $result = $this->db->fetch("SELECT COUNT(*) as total FROM form_categories");
        return $result['total'] ?? 0;
    }

    public function getWithFormCount()
    {
        return $this->db->fetchAll(
            "SELECT fc.*, COUNT(f.id) as form_count 
            FROM form_categories fc 
            LEFT JOIN forms f ON fc.id = f.category_id 
            GROUP BY fc.id 
            ORDER BY fc.order_index, fc.name"
        );
    }
}
