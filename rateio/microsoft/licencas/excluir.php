<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Models\MsLicenca;

require __DIR__ . '/../../includes/bootstrap.php';
Auth::exigirAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ids'])) {
    MsLicenca::excluirVarios((array) $_POST['ids']);
    Session::flash('success', 'Licenças selecionadas excluídas.');
} elseif (isset($_GET['id'])) {
    MsLicenca::excluir((int) $_GET['id']);
    Session::flash('success', 'Licença excluída.');
}

header('Location: ' . url('microsoft/licencas/listar.php'));
exit;
