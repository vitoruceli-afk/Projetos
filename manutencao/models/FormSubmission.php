<?php

namespace Models;

use Config\Database;
use Core\Logger;

class FormSubmission
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function create($data)
    {
        try {
            $data['submitted_by'] = $_SESSION['user_id'] ?? null;
            $data['submission_date'] = date('Y-m-d');
            $data['submission_time'] = date('H:i:s');

            $this->db->insert('form_submissions', $data);
            $id = $this->db->lastInsertId();

            Logger::log('CREATE', 'submissions', 'form_submissions', $id, ['new' => $data]);
            Logger::logSubmissionChange($id, 'CRIADA');

            return $id;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function update($id, $data)
    {
        try {
            $old_data = $this->getById($id);
            
            unset($data['submitted_by']);
            unset($data['submission_date']);
            unset($data['submission_time']);

            $this->db->update('form_submissions', $data, 'id = :id', [':id' => $id]);

            Logger::log('UPDATE', 'submissions', 'form_submissions', $id, [
                'old' => $old_data,
                'new' => $data
            ]);

            Logger::logSubmissionChange($id, 'EDITADA', $old_data, $data);

            return true;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function delete($id)
    {
        try {
            $old_data = $this->getById($id);
            
            $this->db->delete('form_answers', 'submission_id = :id', [':id' => $id]);
            $this->db->delete('submission_history', 'submission_id = :id', [':id' => $id]);
            $this->db->delete('attachments', 'submission_id = :id', [':id' => $id]);
            $this->db->delete('form_submissions', 'id = :id', [':id' => $id]);

            Logger::log('DELETE', 'submissions', 'form_submissions', $id, ['old' => $old_data]);
            Logger::logSubmissionChange($id, 'DELETADA', $old_data);

            return true;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function getById($id)
    {
        return $this->db->fetch(
            "SELECT fs.*, 
                    f.title as form_title,
                    s.name as sector_name,
                    d.name as device_name,
                    u.full_name as submitted_by_name,
                    t.full_name as technician_name
            FROM form_submissions fs
            LEFT JOIN forms f ON fs.form_id = f.id
            LEFT JOIN sectors s ON fs.sector_id = s.id
            LEFT JOIN devices d ON fs.device_id = d.id
            LEFT JOIN users u ON fs.submitted_by = u.id
            LEFT JOIN users t ON fs.technician_id = t.id
            WHERE fs.id = :id",
            [':id' => $id]
        );
    }

    public function getAll($filters = [], $limit = 15, $offset = 0)
    {
        $where = [];
        $params = [];

        if (!empty($filters['form_id'])) {
            $where[] = "fs.form_id = :form_id";
            $params[':form_id'] = $filters['form_id'];
        }

        if (!empty($filters['sector_id'])) {
            $where[] = "fs.sector_id = :sector_id";
            $params[':sector_id'] = $filters['sector_id'];
        }

        if (!empty($filters['device_id'])) {
            $where[] = "fs.device_id = :device_id";
            $params[':device_id'] = $filters['device_id'];
        }

        if (!empty($filters['search'])) {
            $where[] = "(f.title LIKE :search OR s.name LIKE :search OR d.name LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['submission_type'])) {
            $where[] = "fs.submission_type = :submission_type";
            $params[':submission_type'] = $filters['submission_type'];
        }

        if (!empty($filters['maintenance_type'])) {
            $where[] = "fs.maintenance_type = :maintenance_type";
            $params[':maintenance_type'] = $filters['maintenance_type'];
        }

        if (!empty($filters['is_active'])) {
            $where[] = "fs.is_active = :is_active";
            $params[':is_active'] = $filters['is_active'] ? 1 : 0;
        }

        if (!empty($filters['date_from'])) {
            $where[] = "fs.submission_date >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = "fs.submission_date <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }

        $whereClause = empty($where) ? '1=1' : implode(' AND ', $where);

        $sql = "SELECT fs.*, 
                f.title as form_title,
                s.name as sector_name,
                d.name as device_name,
                u.full_name as submitted_by_name,
                t.full_name as technician_name
        FROM form_submissions fs
        LEFT JOIN forms f ON fs.form_id = f.id
        LEFT JOIN sectors s ON fs.sector_id = s.id
        LEFT JOIN devices d ON fs.device_id = d.id
        LEFT JOIN users u ON fs.submitted_by = u.id
        LEFT JOIN users t ON fs.technician_id = t.id
        WHERE $whereClause
        ORDER BY fs.submission_date DESC, fs.submission_time DESC
        LIMIT :limit OFFSET :offset";

        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    public function count($filters = [])
    {
        $where = [];
        $params = [];

        if (!empty($filters['form_id'])) {
            $where[] = "form_id = :form_id";
            $params[':form_id'] = $filters['form_id'];
        }

        if (!empty($filters['sector_id'])) {
            $where[] = "sector_id = :sector_id";
            $params[':sector_id'] = $filters['sector_id'];
        }

        $whereClause = empty($where) ? '1=1' : implode(' AND ', $where);

        $result = $this->db->fetch(
            "SELECT COUNT(*) as total FROM form_submissions WHERE $whereClause",
            $params
        );

        return $result['total'] ?? 0;
    }

    public function saveAnswer($submission_id, $field_id, $value)
    {
        try {
            // Verificar se já existe resposta
            $existing = $this->db->fetch(
                "SELECT id FROM form_answers WHERE submission_id = :submission_id AND field_id = :field_id",
                [':submission_id' => $submission_id, ':field_id' => $field_id]
            );

            if ($existing) {
                $this->db->update('form_answers', 
                    ['answer_value' => $value],
                    'submission_id = :submission_id AND field_id = :field_id',
                    [':submission_id' => $submission_id, ':field_id' => $field_id]
                );
            } else {
                $this->db->insert('form_answers', [
                    'submission_id' => $submission_id,
                    'field_id' => $field_id,
                    'answer_value' => $value
                ]);
            }

            return true;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function getAnswers($submission_id)
    {
        return $this->db->fetchAll(
            "SELECT fa.*, ff.field_name, ff.field_label, ff.field_type 
            FROM form_answers fa
            LEFT JOIN form_fields ff ON fa.field_id = ff.id
            WHERE fa.submission_id = :submission_id",
            [':submission_id' => $submission_id]
        );
    }

    public function saveAttachment($submission_id, $field_id, $file_path, $file_name, $file_size, $file_type)
    {
        try {
            $this->db->insert('attachments', [
                'submission_id' => $submission_id,
                'field_id' => $field_id,
                'file_name' => $file_name,
                'file_path' => $file_path,
                'file_size' => $file_size,
                'file_type' => $file_type,
                'uploaded_by' => $_SESSION['user_id'] ?? null
            ]);

            return $this->db->lastInsertId();
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function getAttachments($submission_id)
    {
        return $this->db->fetchAll(
            "SELECT * FROM attachments WHERE submission_id = :submission_id ORDER BY created_at DESC",
            [':submission_id' => $submission_id]
        );
    }

    public function getHistory($submission_id)
    {
        return $this->db->fetchAll(
            "SELECT sh.*, u.full_name as changed_by_name 
            FROM submission_history sh
            LEFT JOIN users u ON sh.changed_by = u.id
            WHERE sh.submission_id = :submission_id
            ORDER BY sh.action_date DESC",
            [':submission_id' => $submission_id]
        );
    }

    public function toggleStatus($id, $is_active)
    {
        return $this->update($id, ['is_active' => $is_active ? 1 : 0]);
    }

    public function getSubmissionsByTechnician($technician_id, $date_from = null, $date_to = null)
    {
        $where = ['fs.technician_id = :technician_id'];
        $params = [':technician_id' => $technician_id];

        if ($date_from) {
            $where[] = 'fs.submission_date >= :date_from';
            $params[':date_from'] = $date_from;
        }

        if ($date_to) {
            $where[] = 'fs.submission_date <= :date_to';
            $params[':date_to'] = $date_to;
        }

        $whereClause = implode(' AND ', $where);

        return $this->db->fetchAll(
            "SELECT fs.*, f.title as form_title, s.name as sector_name, d.name as device_name
            FROM form_submissions fs
            LEFT JOIN forms f ON fs.form_id = f.id
            LEFT JOIN sectors s ON fs.sector_id = s.id
            LEFT JOIN devices d ON fs.device_id = d.id
            WHERE $whereClause
            ORDER BY fs.submission_date DESC",
            $params
        );
    }
}
