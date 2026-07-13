<?php

namespace Controllers;

use Core\Auth;
use Config\Database;

class SubmissionController
{
    private $auth;
    private $db;

    public function __construct()
    {
        $this->auth = new Auth();
        $this->db = Database::getInstance();
        $this->auth->requirePermission('submissions', 'view');
    }

    /**
     * Listar preenchimentos
     */
    public function index()
    {
        try {
            $submissions = $this->db->fetchAll("
                SELECT 
                    fs.id,
                    fs.form_id,
                    fs.user_id,
                    fs.submitted_at,
                    fs.status,
                    f.name as form_name,
                    u.full_name as user_name
                FROM form_submissions fs
                LEFT JOIN forms f ON fs.form_id = f.id
                LEFT JOIN users u ON fs.user_id = u.id
                ORDER BY fs.submitted_at DESC
            ");
            
            include __DIR__ . '/../views/submissions/index.php';
        } catch (\Exception $e) {
            echo "Erro: " . $e->getMessage();
        }
    }

    /**
     * Criar novo preenchimento - Etapa 1: Selecionar formulário
     */
    public function create()
    {
        $this->auth->requirePermission('submissions', 'create');
        
        $error = '';
        $forms = $this->db->fetchAll("SELECT * FROM forms WHERE status = 'active'");
        $form_id = intval($_GET['form_id'] ?? $_POST['form_id'] ?? 0);
        $selected_form = null;
        $form_fields = [];

        // Se form_id foi enviado via GET ou POST, mostrar o formulário
        if ($form_id > 0) {
            $selected_form = $this->db->fetch("SELECT * FROM forms WHERE id = ?", [$form_id]);
            
            if (!$selected_form) {
                $error = 'Formulário não encontrado';
                $form_id = 0;
            } else {
                // Buscar campos do formulário
                $form_fields = $this->db->fetchAll(
                    "SELECT * FROM form_fields WHERE form_id = ? ORDER BY order_number ASC",
                    [$form_id]
                );

                // Se foi enviado POST, processar o preenchimento
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_form'])) {
                    try {
                        // Criar preenchimento
                        $submission_id = $this->db->insert('form_submissions', [
                            'form_id' => $form_id,
                            'user_id' => $this->auth->getCurrentUserId(),
                            'submitted_by' => $this->auth->getCurrentUserId(),
                            'submitted_at' => date('Y-m-d H:i:s'),
                            'status' => 'active',
                            'created_at' => date('Y-m-d H:i:s')
                        ]);

                        // Salvar respostas dos campos
                        foreach ($form_fields as $field) {
                            $field_key = 'field_' . $field['id'];
                            if (isset($_POST[$field_key])) {
                                $answer_value = $_POST[$field_key];
                                
                                // Para checkboxes e arrays
                                if (is_array($answer_value)) {
                                    $answer_value = implode(',', $answer_value);
                                }

                                $this->db->insert('form_answers', [
                                    'submission_id' => $submission_id,
                                    'field_id' => $field['id'],
                                    'answer_value' => $answer_value,
                                    'created_at' => date('Y-m-d H:i:s')
                                ]);
                            }
                        }

                        $this->auth->logActivity('CREATE', "Novo preenchimento criado", 'form_submissions', $submission_id);

                        header('Location: index.php?page=submissions&success=1');
                        exit;

                    } catch (\Exception $e) {
                        $error = $e->getMessage();
                    }
                }
            }
        }

        include __DIR__ . '/../views/submissions/create.php';
    }

    /**
     * Visualizar preenchimento
     */
    public function view()
    {
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            header('Location: index.php?page=submissions');
            exit;
        }

        $submission = $this->db->fetch("
            SELECT 
                fs.id,
                fs.form_id,
                fs.user_id,
                fs.submitted_at,
                fs.status,
                f.name as form_name,
                u.full_name as user_name
            FROM form_submissions fs
            LEFT JOIN forms f ON fs.form_id = f.id
            LEFT JOIN users u ON fs.user_id = u.id
            WHERE fs.id = ?
        ", [$id]);

        if (!$submission) {
            header('Location: index.php?page=submissions');
            exit;
        }

        $answers = $this->db->fetchAll("
            SELECT 
                fa.id,
                fa.field_id,
                fa.answer_value,
                ff.label,
                ff.field_type
            FROM form_answers fa
            LEFT JOIN form_fields ff ON fa.field_id = ff.id
            WHERE fa.submission_id = ?
        ", [$id]);

        include __DIR__ . '/../views/submissions/view.php';
    }

    /**
     * Deletar preenchimento
     */
    public function delete()
    {
        $this->auth->requirePermission('submissions', 'delete');
        
        $id = intval($_GET['id'] ?? 0);
        if ($id <= 0) {
            header('Location: index.php?page=submissions');
            exit;
        }

        try {
            // Deletar respostas primeiro
            $this->db->delete('form_answers', 'submission_id = ?', [$id]);
            // Deletar preenchimento
            $this->db->delete('form_submissions', 'id = ?', [$id]);
            
            $this->auth->logActivity('DELETE', "Preenchimento deletado", 'form_submissions', $id);
        } catch (\Exception $e) {
            // Silenciosamente falhar
        }

        header('Location: index.php?page=submissions&success=1');
        exit;
    }
}