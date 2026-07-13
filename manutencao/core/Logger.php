<?php

namespace Core;

use Config\Database;

class Logger
{
    private static $db = null;

    public static function init()
    {
        self::$db = Database::getInstance();
    }

    public static function log($action, $module = null, $table_name = null, $record_id = null, $values = null, $ip_address = null)
    {
        if (self::$db === null) {
            self::init();
        }

        $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
        $ip_address = $ip_address ?? $_SERVER['REMOTE_ADDR'] ?? null;

        try {
            self::$db->insert('audit_logs', [
                'user_id' => $user_id,
                'action' => $action,
                'module' => $module,
                'table_name' => $table_name,
                'record_id' => $record_id,
                'old_values' => isset($values['old']) ? json_encode($values['old']) : null,
                'new_values' => isset($values['new']) ? json_encode($values['new']) : null,
                'ip_address' => $ip_address
            ]);
        } catch (\Exception $e) {
            error_log('Erro ao registrar log: ' . $e->getMessage());
        }
    }

    public static function logSubmissionChange($submission_id, $action, $old_values = null, $new_values = null)
    {
        if (self::$db === null) {
            self::init();
        }

        $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;

        try {
            self::$db->insert('submission_history', [
                'submission_id' => $submission_id,
                'action' => $action,
                'changed_by' => $user_id,
                'old_values' => $old_values ? json_encode($old_values) : null,
                'new_values' => $new_values ? json_encode($new_values) : null
            ]);
        } catch (\Exception $e) {
            error_log('Erro ao registrar histórico de submissão: ' . $e->getMessage());
        }
    }

    public static function getLogs($filters = [], $limit = 100, $offset = 0)
    {
        if (self::$db === null) {
            self::init();
        }

        $where = [];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = :user_id';
            $params[':user_id'] = $filters['user_id'];
        }

        if (!empty($filters['action'])) {
            $where[] = 'action = :action';
            $params[':action'] = $filters['action'];
        }

        if (!empty($filters['module'])) {
            $where[] = 'module = :module';
            $params[':module'] = $filters['module'];
        }

        $whereClause = empty($where) ? '1=1' : implode(' AND ', $where);

        $sql = "SELECT * FROM audit_logs WHERE $whereClause ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

        return self::$db->fetchAll($sql, $params);
    }
}
