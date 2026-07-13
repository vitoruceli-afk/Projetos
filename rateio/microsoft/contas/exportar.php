<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csv;
use App\Models\MsConta;

require __DIR__ . '/../../includes/bootstrap.php';
Auth::exigirLogin();

/*
| Exporta as contas selecionadas (POST ids[]) ou todas (GET ?todas=1).
| O filtro de busca também é respeitado no "exportar todas".
*/
$ids   = array_map('intval', (array) ($_POST['ids'] ?? []));
$busca = trim($_GET['busca'] ?? $_POST['busca'] ?? '');

$contas = MsConta::listar($busca);

if ($ids !== []) {
    $contas = array_filter($contas, static fn(array $c) => in_array((int) $c['id'], $ids, true));
}

$cabecalho = ['Nome', 'Email', 'PEP', 'Projeto', 'Licencas', 'Valor Total'];

$linhas = array_map(static fn(array $c) => [
    $c['nome'],
    $c['email'],
    $c['pep'],
    $c['projeto'],
    $c['licencas'],
    number_format((float) $c['valor_total'], 2, ',', '.'),
], $contas);

Csv::download('contas_microsoft.csv', $cabecalho, $linhas);
