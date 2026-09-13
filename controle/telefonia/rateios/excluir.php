<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Models\RateioHistorico;

require __DIR__ . '/../../includes/bootstrap.php';
Auth::exigirAdmin();

if (isset($_GET['id'])) {
    $reg = RateioHistorico::buscarPorId((int) $_GET['id']);
    if ($reg !== null && $reg['tipo'] === 'telefonia') {
        RateioHistorico::excluir((int) $_GET['id']);
        Session::flash('success', 'Rateio gerado excluído.');
    }
}

header('Location: ' . url('telefonia/rateios/listar.php'));
exit;
