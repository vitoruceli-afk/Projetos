<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csv;
use App\Models\Pep;

require __DIR__ . '/../includes/bootstrap.php';
Auth::exigirLogin();

$busca = trim($_GET['busca'] ?? '');
$peps  = Pep::listar($busca);

// Helper para formatar data de ISO para brasileiro
$formatarData = static function(?string $data): string {
    if (!$data || $data === '') return '';
    $parts = explode('-', $data);
    if (count($parts) === 3) {
        return $parts[2] . '/' . $parts[1] . '/' . $parts[0];
    }
    return $data;
};

$linhas = array_map(static function(array $p) use ($formatarData): array {
    return [
        'PEP'                  => $p['pep'],
        'Projeto'              => $p['projeto'],
        'Centro de Custo'      => $p['centro_custo'] ?? '',
        'Período (meses)'      => $p['periodo_meses'] ?? '',
        'Data de Início'       => $formatarData($p['data_inicio'] ?? null),
        'Data de Término'      => $formatarData($p['data_termino'] ?? null),
        'Responsável (Nome)'   => $p['responsavel_nome'] ?? '',
        'Responsável (CPF)'    => $p['responsavel_cpf'] ?? '',
    ];
}, $peps);

Csv::download(
    'peps.csv',
    ['PEP', 'Projeto', 'Centro de Custo', 'Período (meses)', 'Data de Início', 'Data de Término', 'Responsável (Nome)', 'Responsável (CPF)'],
    $linhas
);
