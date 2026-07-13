<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csv;
use App\Models\MsLicenca;

require __DIR__ . '/../../includes/bootstrap.php';
Auth::exigirLogin();

$ids   = array_map('intval', (array) ($_POST['ids'] ?? []));
$busca = trim($_GET['busca'] ?? $_POST['busca'] ?? '');

$licencas = MsLicenca::listar($busca);

if ($ids !== []) {
    $licencas = array_filter($licencas, static fn(array $l) => in_array((int) $l['id'], $ids, true));
}

$cabecalho = ['Codigo', 'Descricao', 'Valor', 'Modo Cobranca'];

$linhas = array_map(static fn(array $l) => [
    $l['codigo_licenca'],
    $l['descricao'],
    number_format((float) $l['valor'], 2, ',', '.'),
    $l['modo_cobranca'],
], $licencas);

Csv::download('licencas_microsoft.csv', $cabecalho, $linhas);
