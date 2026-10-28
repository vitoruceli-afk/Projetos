<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Models\RateioHistorico;

require __DIR__ . '/../../includes/bootstrap.php';
Auth::exigirAdmin();

if (isset($_GET['id'])) {
    RateioHistorico::excluir((int) $_GET['id']);
    Session::flash('success', 'Rateio gerado excluído.');
}

header('Location: ' . url('microsoft/rateios/listar.php'));
exit;
