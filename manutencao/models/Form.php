<?php

namespace Models;

use Config\Database;
use Core\Logger;

class Form
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function create($data)
    {
        try {
            $data['created_by'] = $_SESSION['user_id'] ?? null;
            $this->db->insert('forms', $data);
            $id = $this->db->lastInsertId();

            Logger::log('CREATE', 'forms', 'forms', $id, ['new' => $data]);

            return $id;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function update($id, $data)
    {
        try {
            $old_data = $this->getById($id);
            
            // Não permitir atualizar created_by
            unset($data['created_by']);
            
            $this->db->update('forms', $data, 'id = :id', [':id' => $id]);

            Logger::log('UPDATE', 'forms', 'forms', $id, [
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
            // Verificar se o formulário já foi utilizado
            $submissions = $this->db->fetch(
                "SELECT COUNT(*) as total FROM form_submissions WHERE form_id = :id",
                [':id' => $id]
            );

            if ($submissions['total'] > 0) {
                throw new \Exception('Não é possível deletar um formulário que já foi utilizado');
            }

            $old_data = $this->getById($id);
            $this->db->delete('form_fields', 'form_id = :id', [':id' => $id]);
            $this->db->delete('form_sections', 'form_id = :id', [':id' => $id]);
            $this->db->delete('forms', 'id = :id', [':id' => $id]);

            Logger::log('DELETE', 'forms', 'forms', $id, ['old' => $old_data]);

            return true;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function getById($id)
    {
        return $this->db->fetch(
            "SELECT f.*, c.name as category_name, u.full_name as created_by_name 
            FROM forms f 
            LEFT JOIN form_categories c ON f.category_id = c.id 
            LEFT JOIN users u ON f.created_by = u.id 
            WHERE f.id = :id",
            [':id' => $id]
        );
    }

    public function getAll($filters = [], $limit = 15, $offset = 0)
    {
        $where = [];
        $params = [];

        if (!empty($filters['category_id'])) {
            $where[] = "f.category_id = :category_id";
            $params[':category_id'] = $filters['category_id'];
        }

        if (!empty($filters['search'])) {
            $where[] = "(f.title LIKE :search OR f.description LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        if (isset($filters['is_active'])) {
            $where[] = "f.is_active = :is_active";
            $params[':is_active'] = $filters['is_active'] ? 1 : 0;
        }

        $whereClause = empty($where) ? '1=1' : implode(' AND ', $where);

        $sql = "SELECT f.*, c.name as category_name, u.full_name as created_by_name 
                FROM forms f 
                LEFT JOIN form_categories c ON f.category_id = c.id 
                LEFT JOIN users u ON f.created_by = u.id 
                WHERE $whereClause 
                ORDER BY f.created_at DESC 
                LIMIT :limit OFFSET :offset";

        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    public function count($filters = [])
    {
        $where = [];
        $params = [];

        if (!empty($filters['category_id'])) {
            $where[] = "category_id = :category_id";
            $params[':category_id'] = $filters['category_id'];
        }

        $whereClause = empty($where) ? '1=1' : implode(' AND ', $where);

        $result = $this->db->fetch(
            "SELECT COUNT(*) as total FROM forms WHERE $whereClause",
            $params
        );

        return $result['total'] ?? 0;
    }

    public function getWithFields($id)
    {
        $form = $this->getById($id);
        
        if (!$form) {
            return null;
        }

        $sections = $this->db->fetchAll(
            "SELECT * FROM form_sections WHERE form_id = :id ORDER BY order_index",
            [':id' => $id]
        );

        $fields = $this->db->fetchAll(
            "SELECT * FROM form_fields WHERE form_id = :id ORDER BY section_id, column_index, order_index",
            [':id' => $id]
        );

        $form['sections'] = $sections;
        $form['fields'] = $fields;

        return $form;
    }

    public function addField($form_id, $data)
    {
        try {
            $data['form_id'] = $form_id;
            $this->db->insert('form_fields', $data);
            $id = $this->db->lastInsertId();

            Logger::log('CREATE', 'forms', 'form_fields', $id, ['new' => $data]);

            return $id;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function updateField($id, $data)
    {
        try {
            $old_data = $this->db->fetch(
                "SELECT * FROM form_fields WHERE id = :id",
                [':id' => $id]
            );

            $this->db->update('form_fields', $data, 'id = :id', [':id' => $id]);

            Logger::log('UPDATE', 'forms', 'form_fields', $id, [
                'old' => $old_data,
                'new' => $data
            ]);

            return true;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function deleteField($id)
    {
        try {
            $old_data = $this->db->fetch(
                "SELECT * FROM form_fields WHERE id = :id",
                [':id' => $id]
            );

            $this->db->delete('form_answers', 'field_id = :id', [':id' => $id]);
            $this->db->delete('form_fields', 'id = :id', [':id' => $id]);

            Logger::log('DELETE', 'forms', 'form_fields', $id, ['old' => $old_data]);

            return true;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function getFields($form_id)
    {
        return $this->db->fetchAll(
            "SELECT * FROM form_fields WHERE form_id = :id ORDER BY section_id, column_index, order_index",
            [':id' => $form_id]
        );
    }

    public function addSection($form_id, $data)
    {
        try {
            $data['form_id'] = $form_id;
            $this->db->insert('form_sections', $data);
            return $this->db->lastInsertId();
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function getSections($form_id)
    {
        return $this->db->fetchAll(
            "SELECT * FROM form_sections WHERE form_id = :id ORDER BY order_index",
            [':id' => $form_id]
        );
    }

    public function deleteSection($id)
    {
        return $this->db->delete('form_sections', 'id = :id', [':id' => $id]);
    }

    public function duplicateForm($id)
    {
        try {
            $form = $this->getById($id);
            
            if (!$form) {
                throw new \Exception('Formulário não encontrado');
            }

            unset($form['id']);
            $form['title'] = $form['title'] . ' (cópia)';
            $form['version'] = 1;
            $form['created_by'] = $_SESSION['user_id'] ?? null;

            $this->db->insert('forms', $form);
            $newFormId = $this->db->lastInsertId();

            // Duplicar seções
            $sections = $this->getSections($id);
            $sectionMap = [];

            foreach ($sections as $section) {
                $oldSectionId = $section['id'];
                unset($section['id']);
                $section['form_id'] = $newFormId;
                $this->db->insert('form_sections', $section);
                $sectionMap[$oldSectionId] = $this->db->lastInsertId();
            }

            // Duplicar campos
            $fields = $this->getFields($id);

            foreach ($fields as $field) {
                unset($field['id']);
                $field['form_id'] = $newFormId;
                if (!empty($field['section_id']) && isset($sectionMap[$field['section_id']])) {
                    $field['section_id'] = $sectionMap[$field['section_id']];
                }
                $this->db->insert('form_fields', $field);
            }

            return $newFormId;
        } catch (\Exception $e) {
            throw $e;
        }
    }
}
