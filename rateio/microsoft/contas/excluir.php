<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Models\MsConta;

require __DIR__ . '/../../includes/bootstrap.php';
Auth::exigirAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ids'])) {
    MsConta::excluirVarios((array) $_POST['ids']);
    Session::flash('success', 'Contas selecionadas excluídas.');
} elseif (isset($_GET['id'])) {
    MsConta::excluir((int) $_GET['id']);
    Session::flash('success', 'Conta excluída.');
}

header('Location: ' . url('microsoft/contas/listar.php'));
exit;
