<?php

namespace Models;

use Config\Database;
use Core\Logger;

class SMTPConfig
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function getConfig()
    {
        return $this->db->fetch(
            "SELECT * FROM smtp_config ORDER BY id DESC LIMIT 1"
        );
    }

    public function updateConfig($data)
    {
        try {
            $current = $this->getConfig();
            
            if (!$current) {
                $this->db->insert('smtp_config', $data);
                $id = $this->db->lastInsertId();
                Logger::log('CREATE', 'smtp', 'smtp_config', $id, ['new' => $data]);
                return $id;
            } else {
                $old_data = $current;
                $this->db->update('smtp_config', $data, 'id = :id', [':id' => $current['id']]);
                Logger::log('UPDATE', 'smtp', 'smtp_config', $current['id'], [
                    'old' => $old_data,
                    'new' => $data
                ]);
                return $current['id'];
            }
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function isActive()
    {
        $config = $this->getConfig();
        return $config && $config['is_active'] == 1;
    }

    public function toggleStatus($status)
    {
        $config = $this->getConfig();
        if (!$config) {
            throw new \Exception('Nenhuma configuração SMTP encontrada');
        }

        return $this->updateConfig([
            'is_active' => $status ? 1 : 0
        ]);
    }

    public function sendEmail($to, $subject, $body, $isHtml = true)
    {
        if (!$this->isActive()) {
            throw new \Exception('Configuração SMTP não está ativa');
        }

        $config = $this->getConfig();

        try {
            if ($config['auth_type'] === 'oauth') {
                return $this->sendViaOAuth($to, $subject, $body, $config, $isHtml);
            } else {
                return $this->sendViaBasicAuth($to, $subject, $body, $config, $isHtml);
            }
        } catch (\Exception $e) {
            throw $e;
        }
    }

    private function sendViaBasicAuth($to, $subject, $body, $config, $isHtml)
    {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $config['smtp_host'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['smtp_username'];
            $mail->Password = $config['smtp_password'];
            $mail->SMTPSecure = $config['smtp_encryption'] ?: \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_TLSREQUIRED;
            $mail->Port = $config['smtp_port'];

            $mail->setFrom($config['from_email'], $config['from_name']);
            $mail->addAddress($to);
            $mail->isHTML($isHtml);
            $mail->Subject = $subject;
            $mail->Body = $body;

            $mail->send();
            return true;
        } catch (\Exception $e) {
            throw new \Exception("Erro ao enviar email: " . $e->getMessage());
        }
    }

    private function sendViaOAuth($to, $subject, $body, $config, $isHtml)
    {
        // Implementação OAuth seria aqui
        // Por enquanto vamos usar um fallback para basic auth
        throw new \Exception('OAuth ainda não implementado. Por favor use configuração básica.');
    }

    public function testConnection()
    {
        $config = $this->getConfig();

        if (!$config) {
            throw new \Exception('Nenhuma configuração SMTP encontrada');
        }

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $config['smtp_host'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['smtp_username'];
            $mail->Password = $config['smtp_password'];
            $mail->SMTPSecure = $config['smtp_encryption'] ?: \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_TLSREQUIRED;
            $mail->Port = $config['smtp_port'];

            if (!$mail->smtpConnect()) {
                throw new \Exception('Falha ao conectar ao servidor SMTP');
            }

            $mail->smtpClose();
            return true;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function validateConfigData($data)
    {
        $errors = [];

        if (empty($data['smtp_host'])) {
            $errors[] = 'Host SMTP é obrigatório';
        }

        if (empty($data['smtp_port']) || !is_numeric($data['smtp_port'])) {
            $errors[] = 'Porta SMTP deve ser um número válido';
        }

        if (empty($data['smtp_username'])) {
            $errors[] = 'Usuário SMTP é obrigatório';
        }

        if (empty($data['smtp_password'])) {
            $errors[] = 'Senha SMTP é obrigatória';
        }

        if (empty($data['from_email']) || !filter_var($data['from_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email de origem deve ser um email válido';
        }

        return $errors;
    }
}
