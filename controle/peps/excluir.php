<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Models\Pep;

require __DIR__ . '/../includes/bootstrap.php';
Auth::exigirAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ids'])) {
    Pep::excluirVarios((array) $_POST['ids']);
    Session::flash('success', 'PEPs selecionados excluídos.');
} elseif (isset($_GET['id'])) {
    Pep::excluir((int) $_GET['id']);
    Session::flash('success', 'PEP excluído.');
}

header('Location: ' . url('peps/listar.php'));
exit;
