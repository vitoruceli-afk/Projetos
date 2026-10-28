<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csv;
use App\Models\RateioHistorico;

require __DIR__ . '/../../includes/bootstrap.php';
Auth::exigirLogin();

$id  = (int) ($_GET['id'] ?? 0);
$reg = RateioHistorico::buscarPorId($id);

if ($reg === null || $reg['tipo'] !== 'microsoft') {
    \App\Core\Session::flash('danger', 'Rateio não encontrado.');
    header('Location: ' . url('microsoft/rateios/listar.php'));
    exit;
}

$linhas = json_decode($reg['dados_json'] ?? '[]', true) ?: [];

$cabecalho = ['PEP', 'Projeto', 'Nome', 'Valor Usuario', '% Diferenca', 'Valor Final'];

$dados = array_map(static fn(array $l) => [
    $l['pep'],
    $l['projeto'],
    $l['nome'],
    number_format((float) $l['valor_usuario'], 2, ',', '.'),
    number_format((float) $l['percentual'], 2, ',', '.'),
    number_format((float) $l['valor_final'], 2, ',', '.'),
], $linhas);

Csv::download('rateio_microsoft_' . $reg['id'] . '.csv', $cabecalho, $dados);
