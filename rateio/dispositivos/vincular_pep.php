<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Models\Dispositivo;
use App\Models\Pep;

require __DIR__ . '/../includes/bootstrap.php';
Auth::exigirAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ids'])) {
    $pep_id = !empty($_POST['pep_id']) ? (int) $_POST['pep_id'] : null;

    if ($pep_id !== null && Pep::buscarPorId($pep_id) === null) {
        Session::flash('danger', 'PEP inválido.');
    } else {
        foreach ((array) $_POST['ids'] as $id) {
            Dispositivo::vincularPep((int) $id, $pep_id);
        }
        Session::flash(
            'success',
            $pep_id !== null ? 'Dispositivos vinculados ao PEP selecionado.' : 'Vínculo com PEP removido dos dispositivos selecionados.'
        );
    }
} else {
    Session::flash('danger', 'Selecione ao menos um dispositivo.');
}

header('Location: ' . url('dispositivos/listar.php'));
exit;
