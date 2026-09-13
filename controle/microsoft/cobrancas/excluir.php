<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Models\MsCobranca;

require __DIR__ . '/../../includes/bootstrap.php';
Auth::exigirAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ids'])) {
    MsCobranca::excluirVarios((array) $_POST['ids']);
    Session::flash('success', 'Cobranças selecionadas excluídas.');
} elseif (isset($_GET['id'])) {
    MsCobranca::excluir((int) $_GET['id']);
    Session::flash('success', 'Cobrança excluída.');
}

header('Location: ' . url('microsoft/cobrancas/listar.php'));
exit;
