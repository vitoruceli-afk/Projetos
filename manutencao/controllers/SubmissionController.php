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
        $form_sections = [];

        $devices = $this->db->fetchAll("
            SELECT d.*, s.name as sector_name
            FROM devices d
            LEFT JOIN sectors s ON d.sector_id = s.id
            WHERE d.status = 'active'
            ORDER BY d.name ASC
        ");
        $technicians = (new \Models\User())->getTechnicianUsers();
        $current_user_id = $this->auth->getCurrentUserId();

        $deviceGroupModel = new \Models\DeviceGroup();
        $device_groups = $deviceGroupModel->getAllWithMemberCount(['status' => 'active']);
        $group_members = [];
        foreach ($device_groups as $group) {
            $group_members[$group['id']] = $deviceGroupModel->getMembers($group['id']);
        }

        // Se form_id foi enviado via GET ou POST, mostrar o formulário
        if ($form_id > 0) {
            $selected_form = $this->db->fetch("SELECT * FROM forms WHERE id = ?", [$form_id]);

            if (!$selected_form) {
                $error = 'Formulário não encontrado';
                $form_id = 0;
            } else {
                // Buscar seções do formulário
                $form_sections = $this->db->fetchAll(
                    "SELECT * FROM form_sections WHERE form_id = ? ORDER BY order_number ASC",
                    [$form_id]
                );

                // Buscar campos do formulário
                $form_fields = $this->db->fetchAll(
                    "SELECT * FROM form_fields WHERE form_id = ? ORDER BY order_number ASC",
                    [$form_id]
                );

                // Se foi enviado POST, processar o preenchimento
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_form'])) {
                    try {
                        $target = $_POST['device_target'] ?? '';
                        $device_id = null;
                        $device_group_id = null;

                        if (strpos($target, 'device_') === 0) {
                            $device_id = intval(substr($target, 7));
                        } elseif (strpos($target, 'group_') === 0) {
                            $device_group_id = intval(substr($target, 6));
                        }

                        $technician_id = intval($_POST['technician_id'] ?? 0);

                        // Criar preenchimento
                        $submission_id = $this->db->insert('form_submissions', [
                            'form_id' => $form_id,
                            'user_id' => $this->auth->getCurrentUserId(),
                            'submitted_by' => $this->auth->getCurrentUserId(),
                            'device_id' => $device_id ?: null,
                            'device_group_id' => $device_group_id ?: null,
                            'technician_id' => $technician_id ?: null,
                            'submitted_at' => date('Y-m-d H:i:s'),
                            'status' => 'active',
                            'created_at' => date('Y-m-d H:i:s')
                        ]);

                        // Se o alvo for um grupo, registrar dispositivos específicos marcados com problema
                        $reported_issues = [];
                        if ($device_group_id) {
                            $issue_device_ids = $_POST['issue_device'] ?? [];
                            foreach ($issue_device_ids as $issue_device_id) {
                                $issue_device_id = intval($issue_device_id);
                                $description = trim($_POST['issue_desc_' . $issue_device_id] ?? '');

                                if ($issue_device_id > 0 && $description !== '') {
                                    $issue_field_id = intval($_POST['issue_field_' . $issue_device_id] ?? 0);

                                    $this->db->insert('submission_device_issues', [
                                        'submission_id' => $submission_id,
                                        'device_id' => $issue_device_id,
                                        'field_id' => $issue_field_id ?: null,
                                        'description' => $description,
                                        'created_at' => date('Y-m-d H:i:s')
                                    ]);

                                    $device_name = $this->db->fetch("SELECT name FROM devices WHERE id = ?", [$issue_device_id]);
                                    $issue_field_name = $issue_field_id
                                        ? $this->db->fetch("SELECT label FROM form_fields WHERE id = ?", [$issue_field_id])
                                        : null;

                                    $issue_label = ($device_name['name'] ?? "Dispositivo #$issue_device_id");
                                    if (!empty($issue_field_name['label'])) {
                                        $issue_label .= ' (' . $issue_field_name['label'] . ')';
                                    }
                                    $reported_issues[] = $issue_label . ': ' . $description;
                                }
                            }
                        }

                        // Salvar respostas dos campos
                        $answers_summary = [];
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

                                $answers_summary[] = $field['label'] . ': ' . $answer_value;
                            }
                        }

                        $this->auth->logActivity('CREATE', "Novo preenchimento criado", 'form_submissions', $submission_id);

                        // Abrir chamado no GLPI (best-effort — nunca impede o preenchimento de ser salvo)
                        $glpi_warning = null;
                        if (($device_id || $device_group_id) && $technician_id) {
                            $technician = $this->db->fetch("SELECT * FROM users WHERE id = ?", [$technician_id]);

                            if (empty($technician['glpi_user_id'])) {
                                $glpi_warning = 'Técnico selecionado não está sincronizado com o GLPI; chamado não foi criado.';
                            } else {
                                try {
                                    if ($device_id) {
                                        $device = $this->db->fetch("SELECT * FROM devices WHERE id = ?", [$device_id]);

                                        $ticket_name = $selected_form['name'] . ' - ' . ($device['name'] ?? 'Dispositivo #' . $device_id);
                                        $ticket_content = "Preenchimento #$submission_id do formulário \"{$selected_form['name']}\"\n"
                                            . 'Dispositivo: ' . ($device['name'] ?? '-') . "\n\n"
                                            . implode("\n", $answers_summary);
                                    } else {
                                        $group = $deviceGroupModel->getById($device_group_id);
                                        $members = $group_members[$device_group_id] ?? $deviceGroupModel->getMembers($device_group_id);
                                        $member_names = array_map(function ($m) {
                                            return '- ' . $m['name'];
                                        }, $members);

                                        $ticket_name = $selected_form['name'] . ' - Grupo: ' . ($group['name'] ?? "Grupo #$device_group_id");
                                        $ticket_content = "Preenchimento #$submission_id do formulário \"{$selected_form['name']}\"\n"
                                            . 'Grupo: ' . ($group['name'] ?? '-') . "\n"
                                            . "Dispositivos do grupo:\n" . implode("\n", $member_names) . "\n\n"
                                            . implode("\n", $answers_summary);

                                        if (!empty($reported_issues)) {
                                            $ticket_content .= "\n\nProblemas reportados:\n- " . implode("\n- ", $reported_issues);
                                        }
                                    }

                                    $client = new \Core\GLPIClient();
                                    $ticket_id = $client->createClosedTicket(
                                        $ticket_name,
                                        $ticket_content,
                                        $technician['glpi_user_id']
                                    );

                                    $this->db->update('form_submissions', ['glpi_ticket_id' => $ticket_id], 'id = ?', [$submission_id]);
                                } catch (\Exception $e) {
                                    $glpi_warning = 'Preenchimento salvo, mas falha ao criar chamado no GLPI: ' . $e->getMessage();
                                    $this->db->update('form_submissions', ['glpi_ticket_error' => $e->getMessage()], 'id = ?', [$submission_id]);
                                    error_log('GLPI ticket creation failed for submission ' . $submission_id . ': ' . $e->getMessage());
                                }
                            }
                        }

                        $redirect = 'index.php?page=submissions&success=1';
                        if ($glpi_warning) {
                            $redirect .= '&glpi_warning=' . urlencode($glpi_warning);
                        }

                        header('Location: ' . $redirect);
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