<?php

namespace Controllers;

use Core\Auth;
use Config\Database;

class SMTPController
{
    private $auth;
    private $db;

    public function __construct()
    {
        $this->auth = new Auth();
        $this->db = Database::getInstance();
        $this->auth->requirePermission('smtp', 'view');
    }

    /**
     * Exibir configurações SMTP
     */
    public function index()
    {
        try {
            $config = $this->db->fetch("SELECT * FROM smtp_config LIMIT 1");
            
            if (!$config) {
			// Criar configuração padrão se não existir
			try {
				$this->db->insert('smtp_config', [
					'smtp_host' => 'smtp.gmail.com',
					'smtp_port' => 587,
					'smtp_encryption' => 'tls',
					'smtp_username' => '',
					'smtp_password' => '',
					'from_email' => '',
					'from_name' => 'Sistema de Manutenção'
			]);
    } catch (\Exception $e) {
        // Silenciosamente falhar se já existe
    }
    
    $config = $this->db->fetch("SELECT * FROM smtp_config LIMIT 1");
}
            
            include __DIR__ . '/../views/smtp/index.php';
        } catch (\Exception $e) {
            echo "Erro: " . $e->getMessage();
        }
    }

    /**
     * Editar configurações SMTP
     */
    public function edit()
    {
        $this->auth->requirePermission('smtp', 'edit');
        
        $error = '';
        $success = '';
        
        $config = $this->db->fetch("SELECT * FROM smtp_config LIMIT 1");

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $smtp_host = trim($_POST['smtp_host'] ?? '');
                $smtp_port = intval($_POST['smtp_port'] ?? 587);
                $smtp_encryption = $_POST['smtp_encryption'] ?? 'tls';
                $smtp_username = trim($_POST['smtp_username'] ?? '');
                $smtp_password = $_POST['smtp_password'] ?? '';
                $from_email = trim($_POST['from_email'] ?? '');
                $from_name = trim($_POST['from_name'] ?? '');
                $is_enabled = isset($_POST['is_enabled']) ? 1 : 0;

                if (empty($smtp_host) || empty($from_email)) {
                    throw new \Exception('Host SMTP e Email são obrigatórios');
                }

                if (!filter_var($from_email, FILTER_VALIDATE_EMAIL)) {
                    throw new \Exception('Email inválido');
                }

                $update_data = [
                    'smtp_host' => $smtp_host,
                    'smtp_port' => $smtp_port,
                    'smtp_encryption' => $smtp_encryption,
                    'smtp_username' => $smtp_username,
                    'from_email' => $from_email,
                    'from_name' => $from_name,
                    'is_enabled' => $is_enabled,
                    'updated_at' => date('Y-m-d H:i:s')
                ];

                // Só atualizar senha se foi fornecida
                if (!empty($smtp_password)) {
                    $update_data['smtp_password'] = $smtp_password;
                }

                if ($config) {
                    $this->db->update('smtp_config', $update_data, 'id = ?', [$config['id']]);
                } else {
                    $update_data['created_at'] = date('Y-m-d H:i:s');
                    $this->db->insert('smtp_config', $update_data);
                }

                $this->auth->logActivity('UPDATE', 'Configurações SMTP atualizadas', 'smtp_config', 1);

                header('Location: index.php?page=smtp&success=1');
                exit;

            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        }

        include __DIR__ . '/../views/smtp/index.php';
    }

    /**
     * Testar conexão SMTP
     */
    public function test()
    {
        header('Content-Type: application/json');
        
        try {
            $config = $this->db->fetch("SELECT * FROM smtp_config LIMIT 1");

            if (!$config) {
                throw new \Exception('Nenhuma configuração SMTP encontrada');
            }

            // Teste simples com fsockopen
            $host = $config['smtp_host'];
            $port = $config['smtp_port'];
            $timeout = 5;

            $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

            if (!$socket) {
                throw new \Exception("Erro ao conectar: $errstr ($errno)");
            }

            fclose($socket);

            echo json_encode([
                'success' => true,
                'message' => 'Conexão com servidor SMTP realizada com sucesso!'
            ]);

        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }

        exit;
    }

    /**
     * Alternar SMTP ativo/inativo
     */
    public function toggle()
    {
        $this->auth->requirePermission('smtp', 'edit');
        
        try {
            $config = $this->db->fetch("SELECT * FROM smtp_config LIMIT 1");

            if ($config) {
                $new_status = $config['is_enabled'] ? 0 : 1;
                $this->db->update('smtp_config', [
                    'is_enabled' => $new_status,
                    'updated_at' => date('Y-m-d H:i:s')
                ], 'id = ?', [$config['id']]);

                $status = $new_status ? 'ativado' : 'desativado';
                $this->auth->logActivity('UPDATE', "SMTP $status", 'smtp_config', 1);
            }
        } catch (\Exception $e) {
            // Silenciosamente falhar
        }

        header('Location: index.php?page=smtp&success=1');
        exit;
    }
}