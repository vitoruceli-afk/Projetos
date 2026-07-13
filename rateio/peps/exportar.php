<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csv;
use App\Models\Pep;

require __DIR__ . '/../includes/bootstrap.php';
Auth::exigirLogin();

$busca = trim($_GET['busca'] ?? '');
$peps  = Pep::listar($busca);

$linhas = array_map(static fn(array $p) => [
    'PEP'     => $p['pep'],
    'Projeto' => $p['projeto'],
], $peps);

Csv::download('peps.csv', ['PEP', 'Projeto'], $linhas);
