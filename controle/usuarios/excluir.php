<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Models\Usuario;

require __DIR__ . '/../includes/bootstrap.php';
Auth::exigirAdmin();

$id = (int) ($_GET['id'] ?? 0);

if ($id === (int) Session::get('usuario_id')) {
    Session::flash('danger', 'Você não pode excluir o próprio usuário.');
} elseif ($id > 0) {
    Usuario::excluir($id);
    Session::flash('success', 'Usuário excluído.');
}

header('Location: ' . url('usuarios/listar.php'));
exit;
