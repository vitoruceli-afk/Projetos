<?php
require_once 'config.php';

if (isset($_SESSION['user_logged_in'])) {
    logActivity('auth', 'Logoff realizado');
    removeActiveSession(session_id());
}

session_destroy();
header("Location: login.php");
exit;
?>
