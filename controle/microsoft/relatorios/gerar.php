<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Core\Database;
use App\Core\RateioFinanceiro;
use App\Models\MsCobranca;
use App\Models\MsConta;
use App\Models\RateioHistorico;

require __DIR__ . '/../../includes/bootstrap.php';
Auth::exigirLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['cobrancas'])) {
    Session::flash('danger', 'Selecione ao menos uma cobrança.');
    header('Location: ' . url('microsoft/relatorios/index.php'));
    exit;
}

$idsCobranca = array_map('intval', (array) $_POST['cobrancas']);
$descricao   = trim($_POST['descricao'] ?? '');

// Valor do boleto = soma das cobranças selecionadas
$valorBoleto = MsCobranca::somaSelecionadas($idsCobranca);

// Contas agregadas por PEP
$contas = MsConta::totaisPorPep();

if ($contas === []) {
    Session::flash('danger', 'Não há contas com licenças para ratear.');
    header('Location: ' . url('microsoft/relatorios/index.php'));
    exit;
}

$resultado = RateioFinanceiro::calcular($contas, $valorBoleto);

// Mês/ano de referência = maior cobrança selecionada
$pdo = Database::pdo();
$ph  = implode(',', array_fill(0, count($idsCobranca), '?'));
$stmt = $pdo->prepare("SELECT mes, ano FROM ms_cobrancas WHERE id IN ($ph) ORDER BY ano DESC, mes DESC LIMIT 1");
$stmt->execute($idsCobranca);
$ref = $stmt->fetch() ?: ['mes' => (int) date('n'), 'ano' => (int) date('Y')];

if ($descricao === '') {
    $descricao = 'Rateio Microsoft ' . $ref['mes'] . '/' . $ref['ano'];
}

$id = RateioHistorico::registrar(
    'microsoft',
    (int) $ref['mes'],
    (int) $ref['ano'],
    $descricao,
    Auth::nome(),
    $resultado['valor_boleto'],
    $resultado['total_contas'],
    $resultado['diferenca'],
    $resultado['total_final'],
    $resultado['linhas']
);

Session::flash('success', 'Rateio gerado e armazenado com sucesso.');
header('Location: ' . url('microsoft/rateios/ver.php?id=' . $id));
exit;
