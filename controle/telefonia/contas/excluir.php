<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Models\TelefoniaConta;

require __DIR__ . '/../../includes/bootstrap.php';
Auth::exigirAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ids'])) {
    TelefoniaConta::excluirVarios((array) $_POST['ids']);
    Session::flash('success', 'Contas selecionadas excluídas.');
} elseif (isset($_GET['id'])) {
    TelefoniaConta::excluir((int) $_GET['id']);
    Session::flash('success', 'Conta excluída.');
}

header('Location: ' . url('telefonia/contas/listar.php'));
exit;
