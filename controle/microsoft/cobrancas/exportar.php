<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csv;
use App\Models\MsCobranca;

require __DIR__ . '/../../includes/bootstrap.php';
Auth::exigirLogin();

$ids   = array_map('intval', (array) ($_POST['ids'] ?? []));
$busca = trim($_GET['busca'] ?? $_POST['busca'] ?? '');

$cobrancas = MsCobranca::listar($busca);

if ($ids !== []) {
    $cobrancas = array_filter($cobrancas, static fn(array $c) => in_array((int) $c['id'], $ids, true));
}

$cabecalho = ['Mes', 'Ano', 'Valor Total'];

$linhas = array_map(static fn(array $c) => [
    $c['mes'],
    $c['ano'],
    number_format((float) $c['valor_total'], 2, ',', '.'),
], $cobrancas);

Csv::download('cobrancas_microsoft.csv', $cabecalho, $linhas);
