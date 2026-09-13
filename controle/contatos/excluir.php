<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Models\Contato;

require __DIR__ . '/../includes/bootstrap.php';
Auth::exigirAdmin();

if (isset($_GET['id'])) {
    Contato::excluir((int) $_GET['id']);
    Session::flash('success', 'Contato excluído.');
}

header('Location: ' . url('contatos/listar.php'));
exit;
