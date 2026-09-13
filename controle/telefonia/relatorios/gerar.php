<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Core\Database;
use App\Core\RateioFinanceiro;
use App\Models\TelefoniaCobranca;
use App\Models\TelefoniaConta;
use App\Models\RateioHistorico;

require __DIR__ . '/../../includes/bootstrap.php';
Auth::exigirLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['cobrancas'])) {
    Session::flash('danger', 'Selecione ao menos uma cobrança.');
    header('Location: ' . url('telefonia/relatorios/index.php'));
    exit;
}

$idsCobranca = array_map('intval', (array) $_POST['cobrancas']);
$descricao   = trim($_POST['descricao'] ?? '');

$pdo  = Database::pdo();
$ph   = implode(',', array_fill(0, count($idsCobranca), '?'));
$stmt = $pdo->prepare("SELECT * FROM telefonia_cobrancas WHERE id IN ($ph)");
$stmt->execute($idsCobranca);
$cobrancas = $stmt->fetchAll();

if ($cobrancas === []) {
    Session::flash('danger', 'Cobranças não encontradas.');
    header('Location: ' . url('telefonia/relatorios/index.php'));
    exit;
}

// Cada cobrança pertence a uma Conta Telefonia. Agrupa o valor do boleto por
// conta e rateia cada grupo somente entre os números que pertencem a ela.
$boletoPorConta = [];
foreach ($cobrancas as $c) {
    $conta = trim((string) $c['conta_telefonia']);
    $boletoPorConta[$conta] = ($boletoPorConta[$conta] ?? 0.0) + (float) $c['valor_total'];
}

$linhas        = [];
$valorBoleto   = 0.0;
$totalContas   = 0.0;
$diferenca     = 0.0;
$totalFinal    = 0.0;
$contasSemPep  = [];

foreach ($boletoPorConta as $conta => $valor) {
    // Chaves de array numéricas (ex.: "434545780") são convertidas para int
    // pelo PHP; forçamos de volta para string por causa do strict_types.
    $conta = (string) $conta;

    $contasDaConta = TelefoniaConta::totaisPorPep($conta === '' ? null : $conta);

    if ($contasDaConta === []) {
        $contasSemPep[] = $conta !== '' ? $conta : '(sem Conta Telefonia)';
        continue;
    }

    $resultadoConta = RateioFinanceiro::calcular($contasDaConta, $valor);

    $linhas      = array_merge($linhas, $resultadoConta['linhas']);
    $valorBoleto += $resultadoConta['valor_boleto'];
    $totalContas += $resultadoConta['total_contas'];
    $diferenca   += $resultadoConta['diferenca'];
    $totalFinal  += $resultadoConta['total_final'];
}

if ($linhas === []) {
    Session::flash('danger', 'Nenhum número de telefone encontrado para as Contas Telefonia das cobranças selecionadas.');
    header('Location: ' . url('telefonia/relatorios/index.php'));
    exit;
}

usort($cobrancas, static fn(array $a, array $b) => [(int) $b['ano'], (int) $b['mes']] <=> [(int) $a['ano'], (int) $a['mes']]);
$ref = $cobrancas[0];

if ($descricao === '') {
    $descricao = 'Rateio Telefonia ' . $ref['mes'] . '/' . $ref['ano'];
}

$id = RateioHistorico::registrar(
    'telefonia',
    (int) $ref['mes'],
    (int) $ref['ano'],
    $descricao,
    Auth::nome(),
    round($valorBoleto, 2),
    round($totalContas, 2),
    round($diferenca, 2),
    round($totalFinal, 2),
    $linhas
);

if ($contasSemPep !== []) {
    Session::flash('warning', 'Atenção: sem números cadastrados para a(s) Conta Telefonia: '
        . implode(', ', $contasSemPep) . '. O valor dessa(s) cobrança(s) não entrou no rateio.');
}

Session::flash('success', 'Rateio gerado e armazenado com sucesso.');
header('Location: ' . url('telefonia/rateios/ver.php?id=' . $id));
exit;
