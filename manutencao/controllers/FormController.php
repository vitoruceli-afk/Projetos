<?php

namespace Controllers;

use Core\Auth;
use Config\Database;

class FormController
{
    private $auth;
    private $db;

    public function __construct()
    {
        $this->auth = new Auth();
        $this->db = Database::getInstance();
        $this->auth->requirePermission('forms', 'view');
    }

    /**
     * Listar formulários
     */
    public function index()
    {
        try {
            $forms = $this->db->fetchAll("SELECT * FROM forms ORDER BY id DESC");
            include __DIR__ . '/../views/forms/index.php';
        } catch (\Exception $e) {
            echo "Erro: " . $e->getMessage();
        }
    }

    /**
     * Criar novo formulário
     */
    public function create()
    {
        $this->auth->requirePermission('forms', 'create');
        
        $error = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $category_id = intval($_POST['category_id'] ?? 0);

                if (empty($name)) {
                    throw new \Exception('Nome do formulário é obrigatório');
                }

                $form_id = $this->db->insert('forms', [
                    'name' => $name,
                    'description' => $description,
                    'category_id' => $category_id,
                    'created_by' => $this->auth->getCurrentUserId(),
                    'status' => 'active',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

                $this->auth->logActivity('CREATE', "Novo formulário criado: $name", 'forms', $form_id);

                header('Location: index.php?page=forms&success=1');
                exit;

            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        }

        // Buscar categorias
        $categories = $this->db->fetchAll("SELECT * FROM form_categories");

        include __DIR__ . '/../views/forms/create.php';
    }

    /**
     * Editar formulário
     */
    public function edit()
    {
        $this->auth->requirePermission('forms', 'edit');
        
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            header('Location: index.php?page=forms');
            exit;
        }

        $form = $this->db->fetch("SELECT * FROM forms WHERE id = ?", [$id]);
        if (!$form) {
            header('Location: index.php?page=forms');
            exit;
        }

        $error = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $category_id = intval($_POST['category_id'] ?? 0);
                $status = $_POST['status'] ?? 'active';

                if (empty($name)) {
                    throw new \Exception('Nome do formulário é obrigatório');
                }

                $this->db->update('forms', [
                    'name' => $name,
                    'description' => $description,
                    'category_id' => $category_id,
                    'status' => $status,
                    'updated_at' => date('Y-m-d H:i:s')
                ], 'id = ?', [$id]);

                $this->auth->logActivity('UPDATE', "Formulário atualizado: $name", 'forms', $id);

                header('Location: index.php?page=forms&success=1');
                exit;

            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        }

        $categories = $this->db->fetchAll("SELECT * FROM form_categories");

        include __DIR__ . '/../views/forms/edit.php';
    }

    /**
     * Deletar formulário
     */
    public function delete()
    {
        $this->auth->requirePermission('forms', 'delete');
        
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            header('Location: index.php?page=forms');
            exit;
        }

        try {
            $form = $this->db->fetch("SELECT * FROM forms WHERE id = ?", [$id]);
            if ($form) {
                // Deletar campos do formulário
                $this->db->delete('form_fields', 'form_id = ?', [$id]);
                // Deletar seções do formulário
                $this->db->delete('form_sections', 'form_id = ?', [$id]);
                // Deletar formulário
                $this->db->delete('forms', 'id = ?', [$id]);
                
                $this->auth->logActivity('DELETE', "Formulário deletado: {$form['name']}", 'forms', $id);
            }
        } catch (\Exception $e) {
            // Silenciosamente falhar
        }

        header('Location: index.php?page=forms&success=1');
        exit;
    }

    /**
     * Builder de formulários
     */
    public function builder()
    {
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            header('Location: index.php?page=forms');
            exit;
        }

        $form = $this->db->fetch("SELECT * FROM forms WHERE id = ?", [$id]);
        if (!$form) {
            header('Location: index.php?page=forms');
            exit;
        }

        $fields = $this->db->fetchAll("SELECT * FROM form_fields WHERE form_id = ? ORDER BY order_number ASC", [$id]);
        $sections = $this->db->fetchAll("SELECT * FROM form_sections WHERE form_id = ? ORDER BY order_number ASC", [$id]);

        include __DIR__ . '/../views/forms/builder.php';
    }

    /**
     * Adicionar campo ao formulário (AJAX)
     */
    public function addField()
    {
        header('Content-Type: application/json');
        $this->auth->requirePermission('forms', 'edit');

        try {
            $data = json_decode(file_get_contents('php://input'), true);

            if (!isset($data['form_id']) || !isset($data['label']) || !isset($data['field_type'])) {
                throw new \Exception('Dados inválidos');
            }

            $field_id = $this->db->insert('form_fields', [
                'form_id' => intval($data['form_id']),
                'section_id' => intval($data['section_id'] ?? 0),
                'name' => strtolower(str_replace(' ', '_', $data['label'])),
                'label' => $data['label'],
                'field_type' => $data['field_type'],
                'placeholder' => $data['placeholder'] ?? '',
                'help_text' => $data['help_text'] ?? '',
                'is_required' => $data['is_required'] ?? 0,
                'order_number' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            echo json_encode(['success' => true, 'field_id' => $field_id]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    /**
     * Deletar campo do formulário (AJAX)
     */
    public function deleteField()
    {
        header('Content-Type: application/json');
        $this->auth->requirePermission('forms', 'edit');

        try {
            $data = json_decode(file_get_contents('php://input'), true);

            if (!isset($data['field_id'])) {
                throw new \Exception('Campo não informado');
            }

            $this->db->delete('form_fields', 'id = ?', [$data['field_id']]);

            echo json_encode(['success' => true]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    /**
     * Obter campos do formulário (AJAX)
     */
    public function getFields()
    {
        header('Content-Type: application/json');
        
        try {
            $form_id = intval($_GET['form_id'] ?? 0);
            
            if ($form_id <= 0) {
                throw new \Exception('Formulário inválido');
            }

            $fields = $this->db->fetchAll(
                "SELECT * FROM form_fields WHERE form_id = ? ORDER BY order_number ASC",
                [$form_id]
            );

            echo json_encode(['success' => true, 'fields' => $fields]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    /**
     * Adicionar seção ao formulário (AJAX)
     */
    public function addSection()
    {
        header('Content-Type: application/json');
        $this->auth->requirePermission('forms', 'edit');

        try {
            $data = json_decode(file_get_contents('php://input'), true);

            if (!isset($data['form_id']) || !isset($data['title'])) {
                throw new \Exception('Dados inválidos');
            }

            $section_id = $this->db->insert('form_sections', [
                'form_id' => intval($data['form_id']),
                'title' => $data['title'],
                'description' => $data['description'] ?? '',
                'columns' => intval($data['columns'] ?? 1),
                'order_number' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            echo json_encode(['success' => true, 'section_id' => $section_id]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}