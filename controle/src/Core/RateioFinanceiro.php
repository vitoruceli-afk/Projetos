<?php

declare(strict_types=1);

namespace App\Core;

/*
|--------------------------------------------------------------------------
| RateioFinanceiro
|--------------------------------------------------------------------------
|
| Serviço que calcula o rateio financeiro proporcional por PEP, replicando
| a regra da aplicação original:
|
|   U = valor das contas/licenças do usuário
|   diferença = valor do boleto - soma das contas
|   percentual = (U / soma das contas) * diferença
|   X (valor final) = U + percentual
|
| É reutilizado pelos relatórios Microsoft e Telefonia.
|
*/
final class RateioFinanceiro
{
    /**
     * @param array<int,array{pep:string,projeto:?string,nome:string,email:?string,valor_usuario:mixed}> $contas
     *
     * @return array{
     *   linhas: array<int,array>,
     *   totais_pep: array<string,float>,
     *   valor_boleto: float,
     *   total_contas: float,
     *   diferenca: float,
     *   total_final: float
     * }
     */
    public static function calcular(array $contas, float $valorBoleto): array
    {
        // Soma total das contas
        $totalContas = 0.0;
        foreach ($contas as $c) {
            $totalContas += (float) $c['valor_usuario'];
        }

        $diferenca = $valorBoleto - $totalContas;

        $linhas     = [];
        $totaisPep  = [];
        $totalFinal = 0.0;

        foreach ($contas as $c) {
            $pep = (string) $c['pep'];
            $u   = (float) $c['valor_usuario'];

            $percentual = $totalContas > 0 ? ($u / $totalContas) * $diferenca : 0.0;
            $x          = $u + $percentual;

            $totaisPep[$pep] = ($totaisPep[$pep] ?? 0.0) + $x;
            $totalFinal     += $x;

            $linhas[] = [
                'pep'        => $pep,
                'projeto'    => $c['projeto'] ?? '',
                'nome'       => $c['nome'] ?? '',
                'email'      => $c['email'] ?? '',
                'conta_telefonia' => (string) ($c['conta_telefonia'] ?? ''),
                'valor_usuario' => round($u, 2),
                'percentual' => round($percentual, 2),
                'valor_final' => round($x, 2),
            ];
        }

        return [
            'linhas'       => $linhas,
            'totais_pep'   => $totaisPep,
            'valor_boleto' => round($valorBoleto, 2),
            'total_contas' => round($totalContas, 2),
            'diferenca'    => round($diferenca, 2),
            'total_final'  => round($totalFinal, 2),
        ];
    }
}
