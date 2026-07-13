<?php

namespace Controllers;

use Core\Auth;
use Config\Database;

class ReportController
{
    private $auth;
    private $db;

    public function __construct()
    {
        $this->auth = new Auth();
        $this->db = Database::getInstance();
        $this->auth->requirePermission('reports', 'view');
    }

    /**
     * Página principal de relatórios
     */
    public function index()
    {
        try {
            // Estatísticas Gerais
            $total_forms = $this->db->fetch("SELECT COUNT(*) as count FROM forms WHERE status = 'active'");
            $total_submissions = $this->db->fetch("SELECT COUNT(*) as count FROM form_submissions");
            $total_devices = $this->db->fetch("SELECT COUNT(*) as count FROM devices WHERE status = 'active'");
            $total_sectors = $this->db->fetch("SELECT COUNT(*) as count FROM sectors WHERE status = 'active'");

            // Submissões por mês
            $submissions_by_month = $this->db->fetchAll("
                SELECT DATE_FORMAT(submitted_at, '%Y-%m') as mes, COUNT(*) as total
                FROM form_submissions
                GROUP BY DATE_FORMAT(submitted_at, '%Y-%m')
                ORDER BY mes DESC
                LIMIT 12
            ");

            // Formulários mais preenchidos
            $top_forms = $this->db->fetchAll("
                SELECT f.id, f.name, COUNT(fs.id) as total
                FROM forms f
                LEFT JOIN form_submissions fs ON f.id = fs.form_id
                GROUP BY f.id, f.name
                ORDER BY total DESC
                LIMIT 10
            ");

            // Dispositivos por setor
            $devices_by_sector = $this->db->fetchAll("
                SELECT s.name, COUNT(d.id) as total
                FROM sectors s
                LEFT JOIN devices d ON s.id = d.sector_id
                GROUP BY s.id, s.name
                ORDER BY total DESC
            ");

            // Últimos preenchimentos
            $recent_submissions = $this->db->fetchAll("
                SELECT fs.id, fs.submitted_at, f.name, u.full_name
                FROM form_submissions fs
                LEFT JOIN forms f ON fs.form_id = f.id
                LEFT JOIN users u ON fs.user_id = u.id
                ORDER BY fs.submitted_at DESC
                LIMIT 10
            ");

            include __DIR__ . '/../views/reports/index.php';
        } catch (\Exception $e) {
            echo "Erro: " . $e->getMessage();
        }
    }

    /**
     * Relatório detalhado de preenchimentos
     */
    public function submissions()
    {
        try {
            $form_filter = intval($_GET['form'] ?? 0);
            
            $forms = $this->db->fetchAll("SELECT * FROM forms WHERE status = 'active'");
            
            if ($form_filter > 0) {
                $submissions = $this->db->fetchAll("
                    SELECT fs.id, fs.submitted_at, fs.status, f.name, u.full_name
                    FROM form_submissions fs
                    LEFT JOIN forms f ON fs.form_id = f.id
                    LEFT JOIN users u ON fs.user_id = u.id
                    WHERE fs.form_id = ?
                    ORDER BY fs.submitted_at DESC
                ", [$form_filter]);
            } else {
                $submissions = $this->db->fetchAll("
                    SELECT fs.id, fs.submitted_at, fs.status, f.name, u.full_name
                    FROM form_submissions fs
                    LEFT JOIN forms f ON fs.form_id = f.id
                    LEFT JOIN users u ON fs.user_id = u.id
                    ORDER BY fs.submitted_at DESC
                ");
            }

            include __DIR__ . '/../views/reports/submissions.php';
        } catch (\Exception $e) {
            echo "Erro: " . $e->getMessage();
        }
    }

    /**
     * Relatório de dispositivos
     */
    public function devices()
    {
        try {
            $sector_filter = intval($_GET['sector'] ?? 0);
            
            $sectors = $this->db->fetchAll("SELECT * FROM sectors WHERE status = 'active'");
            
            if ($sector_filter > 0) {
                $devices = $this->db->fetchAll("
                    SELECT d.*, s.name as sector_name
                    FROM devices d
                    LEFT JOIN sectors s ON d.sector_id = s.id
                    WHERE d.sector_id = ?
                    ORDER BY d.name ASC
                ", [$sector_filter]);
            } else {
                $devices = $this->db->fetchAll("
                    SELECT d.*, s.name as sector_name
                    FROM devices d
                    LEFT JOIN sectors s ON d.sector_id = s.id
                    ORDER BY d.name ASC
                ");
            }

            include __DIR__ . '/../views/reports/devices.php';
        } catch (\Exception $e) {
            echo "Erro: " . $e->getMessage();
        }
    }

    /**
     * Relatório de manutenção
     */
    public function maintenance()
    {
        try {
            // Dispositivos com manutenção pendente
            $pending_maintenance = $this->db->fetchAll("
                SELECT d.*, s.name as sector_name,
                       DATEDIFF(CURDATE(), d.last_maintenance) as days_since_maintenance
                FROM devices d
                LEFT JOIN sectors s ON d.sector_id = s.id
                WHERE d.status = 'active'
                ORDER BY d.last_maintenance ASC
            ");

            // Máquinas que nunca tiveram manutenção
            $never_maintained = $this->db->fetchAll("
                SELECT d.*, s.name as sector_name
                FROM devices d
                LEFT JOIN sectors s ON d.sector_id = s.id
                WHERE d.status = 'active' AND d.last_maintenance IS NULL
                ORDER BY d.acquisition_date ASC
            ");

            include __DIR__ . '/../views/reports/maintenance.php';
        } catch (\Exception $e) {
            echo "Erro: " . $e->getMessage();
        }
    }
}
