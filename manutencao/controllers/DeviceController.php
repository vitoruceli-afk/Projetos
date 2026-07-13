<?php

namespace Controllers;

use Core\Auth;
use Config\Database;

class DeviceController
{
    private $auth;
    private $db;

    public function __construct()
    {
        $this->auth = new Auth();
        $this->db = Database::getInstance();
        $this->auth->requirePermission('devices', 'view');
    }

    /**
     * Listar dispositivos
     */
    public function index()
    {
        try {
            $sector_filter = isset($_GET['sector']) ? intval($_GET['sector']) : 0;
            
            if ($sector_filter > 0) {
                $devices = $this->db->fetchAll("
                    SELECT d.*, s.name as sector_name 
                    FROM devices d
                    LEFT JOIN sectors s ON d.sector_id = s.id
                    WHERE d.sector_id = ?
                    ORDER BY d.name ASC
                ", [$sector_filter]);
                $current_sector = $this->db->fetch("SELECT * FROM sectors WHERE id = ?", [$sector_filter]);
            } else {
                $devices = $this->db->fetchAll("
                    SELECT d.*, s.name as sector_name 
                    FROM devices d
                    LEFT JOIN sectors s ON d.sector_id = s.id
                    ORDER BY d.name ASC
                ");
                $current_sector = null;
            }
            
            $sectors = $this->db->fetchAll("SELECT * FROM sectors WHERE status = 'active' ORDER BY name ASC");
            
            include __DIR__ . '/../views/devices/index.php';
        } catch (\Exception $e) {
            echo "Erro: " . $e->getMessage();
        }
    }

    /**
     * Criar novo dispositivo
     */
    public function create()
    {
        $this->auth->requirePermission('devices', 'create');
        
        $error = '';
        $sectors = $this->db->fetchAll("SELECT * FROM sectors WHERE status = 'active' ORDER BY name ASC");
        $sector_id = isset($_GET['sector']) ? intval($_GET['sector']) : 0;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $name = trim($_POST['name'] ?? '');
                $model = trim($_POST['model'] ?? '');
                $serial_number = trim($_POST['serial_number'] ?? '');
                $manufacturer = trim($_POST['manufacturer'] ?? '');
                $sector_id = intval($_POST['sector_id'] ?? 0);
                $acquisition_date = !empty(trim($_POST['acquisition_date'] ?? '')) ? trim($_POST['acquisition_date']) : null;
                $last_maintenance = !empty(trim($_POST['last_maintenance'] ?? '')) ? trim($_POST['last_maintenance']) : null;
                $maintenance_frequency = trim($_POST['maintenance_frequency'] ?? '');

                if (empty($name)) {
                    throw new \Exception('Nome do dispositivo é obrigatório');
                }

                if ($sector_id <= 0) {
                    throw new \Exception('Setor é obrigatório');
                }

                $device_id = $this->db->insert('devices', [
                    'name' => $name,
                    'model' => $model,
                    'manufacturer' => $manufacturer,
                    'serial_number' => $serial_number,
                    'sector_id' => $sector_id,
                    'acquisition_date' => $acquisition_date,
                    'last_maintenance' => $last_maintenance,
                    'maintenance_frequency' => $maintenance_frequency,
                    'status' => 'active',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

                $this->auth->logActivity('CREATE', "Novo dispositivo criado: $name", 'devices', $device_id);

                header('Location: index.php?page=devices&success=1');
                exit;

            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        }

        include __DIR__ . '/../views/devices/create.php';
    }

    /**
     * Editar dispositivo
     */
    public function edit()
    {
        $this->auth->requirePermission('devices', 'edit');
        
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            header('Location: index.php?page=devices');
            exit;
        }

        $device = $this->db->fetch("SELECT * FROM devices WHERE id = ?", [$id]);
        if (!$device) {
            header('Location: index.php?page=devices');
            exit;
        }

        $error = '';
        $sectors = $this->db->fetchAll("SELECT * FROM sectors WHERE status = 'active' ORDER BY name ASC");

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $name = trim($_POST['name'] ?? '');
                $model = trim($_POST['model'] ?? '');
                $serial_number = trim($_POST['serial_number'] ?? '');
                $manufacturer = trim($_POST['manufacturer'] ?? '');
                $sector_id = intval($_POST['sector_id'] ?? 0);
                $acquisition_date = !empty(trim($_POST['acquisition_date'] ?? '')) ? trim($_POST['acquisition_date']) : null;
                $last_maintenance = !empty(trim($_POST['last_maintenance'] ?? '')) ? trim($_POST['last_maintenance']) : null;
                $maintenance_frequency = trim($_POST['maintenance_frequency'] ?? '');
                $status = $_POST['status'] ?? 'active';

                if (empty($name)) {
                    throw new \Exception('Nome do dispositivo é obrigatório');
                }

                if ($sector_id <= 0) {
                    throw new \Exception('Setor é obrigatório');
                }

                $this->db->update('devices', [
                    'name' => $name,
                    'model' => $model,
                    'manufacturer' => $manufacturer,
                    'serial_number' => $serial_number,
                    'sector_id' => $sector_id,
                    'acquisition_date' => $acquisition_date,
                    'last_maintenance' => $last_maintenance,
                    'maintenance_frequency' => $maintenance_frequency,
                    'status' => $status,
                    'updated_at' => date('Y-m-d H:i:s')
                ], 'id = ?', [$id]);

                $this->auth->logActivity('UPDATE', "Dispositivo atualizado: $name", 'devices', $id);

                header('Location: index.php?page=devices&success=1');
                exit;

            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        }

        include __DIR__ . '/../views/devices/edit.php';
    }

    /**
     * Visualizar dispositivo
     */
    public function view()
    {
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            header('Location: index.php?page=devices');
            exit;
        }

        $device = $this->db->fetch("
            SELECT d.*, s.name as sector_name 
            FROM devices d
            LEFT JOIN sectors s ON d.sector_id = s.id
            WHERE d.id = ?
        ", [$id]);

        if (!$device) {
            header('Location: index.php?page=devices');
            exit;
        }

        include __DIR__ . '/../views/devices/view.php';
    }

    /**
     * Sincronizar dispositivos inventariados no GLPI (mantém cadastro manual intacto)
     */
    public function syncGlpi()
    {
        $this->auth->requirePermission('devices', 'edit');

        try {
            $client = new \Core\GLPIClient();
            $computers = $client->getInventoriedComputers();

            $deviceModel = new \Models\Device();
            $count = 0;

            foreach ($computers as $computer) {
                $deviceModel->upsertFromGlpi($computer, 'Computer');
                $count++;
            }

            $this->auth->logActivity('SYNC', "Sincronização GLPI: $count dispositivo(s)", 'devices', null);

            header('Location: index.php?page=devices&success=1&synced=' . $count);
            exit;
        } catch (\Exception $e) {
            header('Location: index.php?page=devices&glpi_error=' . urlencode($e->getMessage()));
            exit;
        }
    }

    /**
     * Listar grupos de dispositivos
     */
    public function groups()
    {
        try {
            $groups = (new \Models\DeviceGroup())->getAllWithMemberCount();
            include __DIR__ . '/../views/devices/groups.php';
        } catch (\Exception $e) {
            echo "Erro: " . $e->getMessage();
        }
    }

    /**
     * Criar novo grupo de dispositivos
     */
    public function groupCreate()
    {
        $this->auth->requirePermission('devices', 'create');

        $error = '';
        $devices = $this->db->fetchAll("
            SELECT d.*, s.name as sector_name
            FROM devices d
            LEFT JOIN sectors s ON d.sector_id = s.id
            ORDER BY d.name ASC
        ");
        $selected_device_ids = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $device_ids = $_POST['device_ids'] ?? [];
                $selected_device_ids = array_map('intval', $device_ids);

                if (empty($name)) {
                    throw new \Exception('Nome do grupo é obrigatório');
                }

                $groupModel = new \Models\DeviceGroup();

                if (!$groupModel->validateName($name)) {
                    throw new \Exception('Já existe um grupo com esse nome');
                }

                if (empty($selected_device_ids)) {
                    throw new \Exception('Selecione ao menos um dispositivo para o grupo');
                }

                $group_id = $groupModel->create([
                    'name' => $name,
                    'description' => $description,
                    'status' => 'active',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

                $groupModel->setMembers($group_id, $selected_device_ids);

                $this->auth->logActivity('CREATE', "Novo grupo de dispositivos criado: $name", 'device_groups', $group_id);

                header('Location: index.php?page=devices&action=groups&success=1');
                exit;

            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        }

        include __DIR__ . '/../views/devices/group_create.php';
    }

    /**
     * Editar grupo de dispositivos
     */
    public function groupEdit()
    {
        $this->auth->requirePermission('devices', 'edit');

        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            header('Location: index.php?page=devices&action=groups');
            exit;
        }

        $groupModel = new \Models\DeviceGroup();
        $group = $groupModel->getById($id);
        if (!$group) {
            header('Location: index.php?page=devices&action=groups');
            exit;
        }

        $error = '';
        $devices = $this->db->fetchAll("
            SELECT d.*, s.name as sector_name
            FROM devices d
            LEFT JOIN sectors s ON d.sector_id = s.id
            ORDER BY d.name ASC
        ");
        $selected_device_ids = $groupModel->getMemberIds($id);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $status = $_POST['status'] ?? 'active';
                $device_ids = $_POST['device_ids'] ?? [];
                $selected_device_ids = array_map('intval', $device_ids);

                if (empty($name)) {
                    throw new \Exception('Nome do grupo é obrigatório');
                }

                if (!$groupModel->validateName($name, $id)) {
                    throw new \Exception('Já existe um grupo com esse nome');
                }

                if (empty($selected_device_ids)) {
                    throw new \Exception('Selecione ao menos um dispositivo para o grupo');
                }

                $groupModel->update($id, [
                    'name' => $name,
                    'description' => $description,
                    'status' => $status,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

                $groupModel->setMembers($id, $selected_device_ids);

                $this->auth->logActivity('UPDATE', "Grupo de dispositivos atualizado: $name", 'device_groups', $id);

                header('Location: index.php?page=devices&action=groups&success=1');
                exit;

            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        }

        include __DIR__ . '/../views/devices/group_edit.php';
    }

    /**
     * Deletar grupo de dispositivos
     */
    public function groupDelete()
    {
        $this->auth->requirePermission('devices', 'delete');

        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            header('Location: index.php?page=devices&action=groups');
            exit;
        }

        try {
            $groupModel = new \Models\DeviceGroup();
            $group = $groupModel->getById($id);
            if ($group) {
                $groupModel->delete($id);
                $this->auth->logActivity('DELETE', "Grupo de dispositivos deletado: {$group['name']}", 'device_groups', $id);
            }
        } catch (\Exception $e) {
            // Silenciosamente falhar
        }

        header('Location: index.php?page=devices&action=groups&success=1');
        exit;
    }

    /**
     * Deletar dispositivo
     */
    public function delete()
    {
        $this->auth->requirePermission('devices', 'delete');
        
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            header('Location: index.php?page=devices');
            exit;
        }

        try {
            $device = $this->db->fetch("SELECT * FROM devices WHERE id = ?", [$id]);
            if ($device) {
                $this->db->delete('devices', 'id = ?', [$id]);
                $this->auth->logActivity('DELETE', "Dispositivo deletado: {$device['name']}", 'devices', $id);
            }
        } catch (\Exception $e) {
            // Silenciosamente falhar
        }

        header('Location: index.php?page=devices&success=1');
        exit;
    }
}
