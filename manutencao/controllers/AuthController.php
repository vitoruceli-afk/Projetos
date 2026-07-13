<?php

namespace Controllers;

use Core\Auth;

class AuthController
{
    private $auth;

    public function __construct()
    {
        $this->auth = new Auth();
    }

    public function login()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $username = trim($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';
                $auth_type = $_POST['auth_type'] ?? 'local';

                if (empty($username) || empty($password)) {
                    throw new \Exception('Usuário e senha são obrigatórios');
                }

                if ($auth_type === 'ldap') {
                    // LDAP login aqui (opcional)
                    throw new \Exception('LDAP não configurado');
                } else {
                    $this->auth->login($username, $password);
                }

                header('Location: ' . \Config\Config::BASE_URL . '/index.php?page=dashboard');
                exit;

            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        }

        include __DIR__ . '/../views/login.php';
    }

    public function logout()
    {
        $this->auth->logout();
        header('Location: ' . \Config\Config::BASE_URL . '/index.php?page=login');
        exit;
    }

    public function profile()
    {
        $this->auth->requireLogin();
        $user = $this->auth->getCurrentUser();
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Lógica de atualização de perfil
        }

        include __DIR__ . '/../views/profile.php';
    }
}