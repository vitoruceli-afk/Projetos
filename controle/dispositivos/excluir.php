<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Models\Dispositivo;

require __DIR__ . '/../includes/bootstrap.php';
Auth::exigirAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ids'])) {
    Dispositivo::excluirVarios((array) $_POST['ids']);
    Session::flash('success', 'Dispositivos selecionados excluídos.');
} elseif (isset($_GET['id'])) {
    Dispositivo::excluir((int) $_GET['id']);
    Session::flash('success', 'Dispositivo excluído.');
}

header('Location: ' . url('dispositivos/listar.php'));
exit;
