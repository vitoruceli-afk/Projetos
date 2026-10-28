<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csv;
use App\Models\TelefoniaConta;

require __DIR__ . '/../../includes/bootstrap.php';
Auth::exigirLogin();

$ids   = array_map('intval', (array) ($_POST['ids'] ?? []));
$busca = trim($_GET['busca'] ?? $_POST['busca'] ?? '');

$contas = TelefoniaConta::listar($busca);

if ($ids !== []) {
    $contas = array_filter($contas, static fn(array $c) => in_array((int) $c['id'], $ids, true));
}

$cabecalho = ['Nome do Usuario', 'Telefone', 'Operadora', 'PEP', 'Projeto', 'Valor', 'Conta Telefonia'];

$linhas = array_map(static fn(array $c) => [
    $c['nome_usuario'],
    $c['telefone'],
    $c['operadora'],
    $c['pep'],
    $c['projeto'],
    number_format((float) $c['valor'], 2, ',', '.'),
    $c['conta_telefonia'],
], $contas);

Csv::download('contas_telefonia.csv', $cabecalho, $linhas);
