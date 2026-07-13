<?php

namespace Controllers;

use Core\Auth;
use Config\Database;

class SectorController
{
    private $auth;
    private $db;

    public function __construct()
    {
        $this->auth = new Auth();
        $this->db = Database::getInstance();
        $this->auth->requirePermission('sectors', 'view');
    }

    /**
     * Listar setores
     */
    public function index()
    {
        try {
            $sectors = $this->db->fetchAll("SELECT * FROM sectors ORDER BY id DESC");
            include __DIR__ . '/../views/sectors/index.php';
        } catch (\Exception $e) {
            echo "Erro: " . $e->getMessage();
        }
    }

    /**
     * Criar novo setor
     */
    public function create()
    {
        $this->auth->requirePermission('sectors', 'create');
        
        $error = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $location = trim($_POST['location'] ?? '');

                if (empty($name)) {
                    throw new \Exception('Nome do setor é obrigatório');
                }

                $sector_id = $this->db->insert('sectors', [
                    'name' => $name,
                    'description' => $description,
                    'location' => $location,
                    'status' => 'active',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

                $this->auth->logActivity('CREATE', "Novo setor criado: $name", 'sectors', $sector_id);

                header('Location: index.php?page=sectors&success=1');
                exit;

            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        }

        include __DIR__ . '/../views/sectors/create.php';
    }

    /**
     * Editar setor
     */
    public function edit()
    {
        $this->auth->requirePermission('sectors', 'edit');
        
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            header('Location: index.php?page=sectors');
            exit;
        }

        $sector = $this->db->fetch("SELECT * FROM sectors WHERE id = ?", [$id]);
        if (!$sector) {
            header('Location: index.php?page=sectors');
            exit;
        }

        $error = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $location = trim($_POST['location'] ?? '');
                $status = $_POST['status'] ?? 'active';

                if (empty($name)) {
                    throw new \Exception('Nome do setor é obrigatório');
                }

                $this->db->update('sectors', [
                    'name' => $name,
                    'description' => $description,
                    'location' => $location,
                    'status' => $status,
                    'updated_at' => date('Y-m-d H:i:s')
                ], 'id = ?', [$id]);

                $this->auth->logActivity('UPDATE', "Setor atualizado: $name", 'sectors', $id);

                header('Location: index.php?page=sectors&success=1');
                exit;

            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        }

        $devices = $this->db->fetchAll("SELECT * FROM devices WHERE sector_id = ?", [$id]);

        include __DIR__ . '/../views/sectors/edit.php';
    }

    /**
     * Deletar setor
     */
    public function delete()
    {
        $this->auth->requirePermission('sectors', 'delete');
        
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            header('Location: index.php?page=sectors');
            exit;
        }

        try {
            $sector = $this->db->fetch("SELECT * FROM sectors WHERE id = ?", [$id]);
            if ($sector) {
                $this->db->delete('sectors', 'id = ?', [$id]);
                $this->auth->logActivity('DELETE', "Setor deletado: {$sector['name']}", 'sectors', $id);
            }
        } catch (\Exception $e) {
            // Silenciosamente falhar
        }

        header('Location: index.php?page=sectors&success=1');
        exit;
    }

    /**
     * Visualizar setor
     */
    public function view()
    {
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            header('Location: index.php?page=sectors');
            exit;
        }

        $sector = $this->db->fetch("SELECT * FROM sectors WHERE id = ?", [$id]);
        if (!$sector) {
            header('Location: index.php?page=sectors');
            exit;
        }

        $devices = $this->db->fetchAll("SELECT * FROM devices WHERE sector_id = ? ORDER BY id DESC", [$id]);

        include __DIR__ . '/../views/sectors/view.php';
    }
}
