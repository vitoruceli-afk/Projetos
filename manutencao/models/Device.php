<?php

namespace Models;

use Config\Database;
use Core\Logger;

class Device
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function create($data)
    {
        try {
            $this->db->insert('devices', $data);
            $id = $this->db->lastInsertId();

            Logger::log('CREATE', 'sectors', 'devices', $id, ['new' => $data]);

            return $id;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function update($id, $data)
    {
        try {
            $old_data = $this->getById($id);
            $this->db->update('devices', $data, 'id = :id', [':id' => $id]);

            Logger::log('UPDATE', 'sectors', 'devices', $id, [
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
            
            $this->db->delete('peripherals', 'device_id = :id', [':id' => $id]);
            $this->db->delete('devices', 'id = :id', [':id' => $id]);

            Logger::log('DELETE', 'sectors', 'devices', $id, ['old' => $old_data]);

            return true;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function getById($id)
    {
        return $this->db->fetch(
            "SELECT d.*, s.name as sector_name
            FROM devices d
            LEFT JOIN sectors s ON d.sector_id = s.id
            WHERE d.id = :id",
            [':id' => $id]
        );
    }

    public function getBySector($sector_id, $filters = [])
    {
        $where = ['d.sector_id = :sector_id'];
        $params = [':sector_id' => $sector_id];

        if (!empty($filters['search'])) {
            $where[] = "(d.name LIKE :search OR d.model LIKE :search OR d.serial_number LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['status'])) {
            $where[] = "d.status = :status";
            $params[':status'] = $filters['status'];
        }

        $whereClause = implode(' AND ', $where);

        return $this->db->fetchAll(
            "SELECT d.*, s.name as sector_name
            FROM devices d
            LEFT JOIN sectors s ON d.sector_id = s.id
            WHERE $whereClause
            ORDER BY d.name",
            $params
        );
    }

    public function getAll($filters = [], $limit = 15, $offset = 0)
    {
        $where = [];
        $params = [];

        if (!empty($filters['sector_id'])) {
            $where[] = "d.sector_id = :sector_id";
            $params[':sector_id'] = $filters['sector_id'];
        }

        if (!empty($filters['search'])) {
            $where[] = "(d.name LIKE :search OR d.model LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['status'])) {
            $where[] = "d.status = :status";
            $params[':status'] = $filters['status'];
        }

        $whereClause = empty($where) ? '1=1' : implode(' AND ', $where);

        $sql = "SELECT d.*, s.name as sector_name
                FROM devices d
                LEFT JOIN sectors s ON d.sector_id = s.id
                WHERE $whereClause
                ORDER BY d.name
                LIMIT :limit OFFSET :offset";

        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    public function count($filters = [])
    {
        $where = [];
        $params = [];

        if (!empty($filters['sector_id'])) {
            $where[] = "sector_id = :sector_id";
            $params[':sector_id'] = $filters['sector_id'];
        }

        $whereClause = empty($where) ? '1=1' : implode(' AND ', $where);

        $result = $this->db->fetch(
            "SELECT COUNT(*) as total FROM devices WHERE $whereClause",
            $params
        );

        return $result['total'] ?? 0;
    }

    public function addPeripheral($device_id, $data)
    {
        try {
            $data['device_id'] = $device_id;
            $this->db->insert('peripherals', $data);
            return $this->db->lastInsertId();
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function updatePeripheral($id, $data)
    {
        try {
            return $this->db->update('peripherals', $data, 'id = :id', [':id' => $id]);
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function deletePeripheral($id)
    {
        try {
            return $this->db->delete('peripherals', 'id = :id', [':id' => $id]);
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function getPeripherals($device_id)
    {
        return $this->db->fetchAll(
            "SELECT * FROM peripherals WHERE device_id = :device_id ORDER BY name",
            [':device_id' => $device_id]
        );
    }

    public function getPeripheralById($id)
    {
        return $this->db->fetch(
            "SELECT * FROM peripherals WHERE id = :id",
            [':id' => $id]
        );
    }

    public function getWithPeripherals($id)
    {
        $device = $this->getById($id);
        if (!$device) {
            return null;
        }

        $device['peripherals'] = $this->getPeripherals($id);
        return $device;
    }

    public function getStatus()
    {
        return ['active', 'inactive', 'maintenance'];
    }

    public function getDeviceTypes()
    {
        $devices = $this->db->fetchAll(
            "SELECT DISTINCT device_type FROM devices WHERE device_type IS NOT NULL ORDER BY device_type"
        );

        $types = [];
        foreach ($devices as $device) {
            if ($device['device_type']) {
                $types[] = $device['device_type'];
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * Buscar dispositivo pela referência no GLPI (itemtype + id do item)
     */
    public function getByGlpiRef($itemtype, $itemsId)
    {
        return $this->db->fetch(
            "SELECT * FROM devices WHERE glpi_itemtype = :itemtype AND glpi_items_id = :items_id",
            [':itemtype' => $itemtype, ':items_id' => $itemsId]
        );
    }

    /**
     * Tentar casar o nome da localização do GLPI com um setor local pelo nome exato
     */
    public function findSectorIdByLocationName($locationName)
    {
        if (empty($locationName)) {
            return null;
        }

        $sector = $this->db->fetch(
            "SELECT id FROM sectors WHERE name = :name",
            [':name' => $locationName]
        );

        return $sector['id'] ?? null;
    }

    /**
     * Criar ou atualizar um dispositivo a partir dos dados trazidos do GLPI.
     * Nunca sobrescreve o sector_id se já houver um definido localmente,
     * para não apagar um ajuste manual feito pela equipe num re-sync.
     *
     * $glpiData: ['glpi_items_id','name','model','manufacturer','serial_number','location_name']
     */
    public function upsertFromGlpi($glpiData, $itemtype = 'Computer')
    {
        $existing = $this->getByGlpiRef($itemtype, $glpiData['glpi_items_id']);
        $now = date('Y-m-d H:i:s');

        $data = [
            'name' => $glpiData['name'],
            'model' => $glpiData['model'] ?? null,
            'manufacturer' => $glpiData['manufacturer'] ?? null,
            'serial_number' => $glpiData['serial_number'] ?? null,
            'last_synced_at' => $now,
        ];

        if ($existing) {
            if (empty($existing['sector_id'])) {
                $data['sector_id'] = $this->findSectorIdByLocationName($glpiData['location_name'] ?? null);
            }

            $this->update($existing['id'], $data);
            return $existing['id'];
        }

        $data['source'] = 'glpi';
        $data['glpi_itemtype'] = $itemtype;
        $data['glpi_items_id'] = $glpiData['glpi_items_id'];
        $data['sector_id'] = $this->findSectorIdByLocationName($glpiData['location_name'] ?? null);
        $data['status'] = 'active';
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        return $this->create($data);
    }
}
