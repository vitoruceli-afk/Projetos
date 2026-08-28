<?php
require_once 'config.php';
if (isset($_SESSION['user_logged_in'])) {
    registrarLog('Autenticação', 'Logout realizado', '', $_SESSION['user_logged_in']);
}
session_unset();
session_destroy();
header("Location: login.php");
exit;
