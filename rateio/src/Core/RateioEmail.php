<?php

declare(strict_types=1);

namespace App\Core;

/*
|--------------------------------------------------------------------------
| RateioEmail
|--------------------------------------------------------------------------
|
| Monta o assunto, o corpo HTML e o anexo CSV de um rateio gerado, para
| envio por e-mail. O conteúdo é o RESUMO POR PEP (não o rateio completo):
|   - Telefonia: agrupado por Conta Telefonia -> PEP (com subtotal da conta)
|   - Microsoft: total por PEP
|
*/
final class RateioEmail
{
    public static function assunto(array $reg): string
    {
        $gerencia = $reg['tipo'] === 'telefonia' ? 'Telefonia' : 'Microsoft';
        return "Rateio {$gerencia} - {$reg['descricao']} ({$reg['mes']}/{$reg['ano']})";
    }

    public static function nomeArquivo(array $reg): string
    {
        return 'resumo_pep_' . $reg['tipo'] . '_' . $reg['id'] . '.csv';
    }

    /*
    |----------------------------------------------------------------------
    | RESUMO POR PEP
    |----------------------------------------------------------------------
    |
    | Telefonia -> ['conta' => ['pep' => valor, ...], ...]  (ordenado)
    | Microsoft -> ['pep' => valor, ...]                    (ordenado)
    */
    private static function resumo(array $reg): array
    {
        $linhas = json_decode($reg['dados_json'] ?? '[]', true) ?: [];

        if ($reg['tipo'] === 'telefonia') {
            $grupos = [];
            foreach ($linhas as $l) {
                $conta = trim((string) ($l['conta_telefonia'] ?? ''));
                if ($conta === '') {
                    $conta = '(Sem conta telefonia)';
                }
                $pep = (string) $l['pep'];
                $grupos[$conta][$pep] = ($grupos[$conta][$pep] ?? 0) + (float) $l['valor_final'];
            }
            ksort($grupos);
            foreach ($grupos as &$peps) {
                ksort($peps);
            }
            return $grupos;
        }

        $peps = [];
        foreach ($linhas as $l) {
            $pep = (string) $l['pep'];
            $peps[$pep] = ($peps[$pep] ?? 0) + (float) $l['valor_final'];
        }
        ksort($peps);
        return $peps;
    }

    /**
     * Gera o CSV do resumo por PEP (separado por vírgula, com BOM UTF-8).
     */
    public static function csv(array $reg): string
    {
        $fp = fopen('php://temp', 'r+');
        fwrite($fp, "\xEF\xBB\xBF");

        if ($reg['tipo'] === 'telefonia') {
            fputcsv($fp, ['Conta Telefonia', 'PEP', 'Valor'], ',', '"', '\\');
            foreach (self::resumo($reg) as $conta => $peps) {
                $totalConta = 0.0;
                foreach ($peps as $pep => $valor) {
                    fputcsv($fp, [$conta, $pep, self::num($valor)], ',', '"', '\\');
                    $totalConta += $valor;
                }
                fputcsv($fp, [$conta, 'TOTAL DA CONTA', self::num($totalConta)], ',', '"', '\\');
            }
        } else {
            fputcsv($fp, ['PEP', 'Valor'], ',', '"', '\\');
            foreach (self::resumo($reg) as $pep => $valor) {
                fputcsv($fp, [$pep, self::num($valor)], ',', '"', '\\');
            }
        }

        rewind($fp);
        $conteudo = stream_get_contents($fp);
        fclose($fp);

        return (string) $conteudo;
    }

    /**
     * Corpo HTML com os totais e o resumo por PEP.
     */
    public static function corpoHtml(array $reg): string
    {
        $gerencia = $reg['tipo'] === 'telefonia' ? 'Telefonia' : 'Microsoft';
        $h   = static fn($v) => htmlspecialchars((string) $v);
        $tot = static fn($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');

        $html  = '<div style="font-family:Arial,Helvetica,sans-serif;color:#222">';
        $html .= '<h2 style="margin:0 0 4px">Rateio ' . $h($gerencia) . '</h2>';
        $html .= '<p style="margin:0 0 12px;color:#555">' . $h($reg['descricao'])
               . ' &middot; Referência ' . $h($reg['mes']) . '/' . $h($reg['ano']) . '</p>';

        // Totais
        $html .= '<table cellpadding="6" style="border-collapse:collapse;font-size:14px;margin-bottom:16px">'
            . '<tr><td style="border:1px solid #ddd">Valor base</td><td style="border:1px solid #ddd">' . $tot($reg['valor_boleto']) . '</td></tr>'
            . '<tr><td style="border:1px solid #ddd">Soma das contas</td><td style="border:1px solid #ddd">' . $tot($reg['total_contas']) . '</td></tr>'
            . '<tr><td style="border:1px solid #ddd">Diferença</td><td style="border:1px solid #ddd">' . $tot($reg['diferenca']) . '</td></tr>'
            . '<tr><td style="border:1px solid #ddd"><strong>Total rateado</strong></td><td style="border:1px solid #ddd"><strong>' . $tot($reg['total_final']) . '</strong></td></tr>'
            . '</table>';

        // Resumo por PEP
        $html .= '<h3 style="margin:0 0 8px">Resumo por PEP</h3>';
        $html .= '<table cellpadding="6" style="border-collapse:collapse;font-size:14px;width:100%;max-width:560px">';

        if ($reg['tipo'] === 'telefonia') {
            foreach (self::resumo($reg) as $conta => $peps) {
                $totalConta = array_sum($peps);
                $html .= '<tr style="background:#22272e;color:#fff">'
                    . '<th align="left" style="border:1px solid #ddd">Conta Telefonia: ' . $h($conta) . '</th>'
                    . '<th align="right" style="border:1px solid #ddd">' . $tot($totalConta) . '</th></tr>';
                foreach ($peps as $pep => $valor) {
                    $html .= '<tr>'
                        . '<td style="border:1px solid #ddd;padding-left:24px">PEP ' . $h($pep) . '</td>'
                        . '<td align="right" style="border:1px solid #ddd">' . $tot($valor) . '</td></tr>';
                }
            }
        } else {
            $html .= '<tr style="background:#22272e;color:#fff">'
                . '<th align="left" style="border:1px solid #ddd">PEP</th>'
                . '<th align="right" style="border:1px solid #ddd">Valor</th></tr>';
            foreach (self::resumo($reg) as $pep => $valor) {
                $html .= '<tr>'
                    . '<td style="border:1px solid #ddd">PEP ' . $h($pep) . '</td>'
                    . '<td align="right" style="border:1px solid #ddd">' . $tot($valor) . '</td></tr>';
            }
        }

        $html .= '</table>';
        $html .= '<p style="margin:14px 0 0;color:#555">O mesmo resumo por PEP segue em anexo (CSV).</p>';
        $html .= '</div>';

        return $html;
    }

    private static function num(mixed $v): string
    {
        return number_format((float) $v, 2, ',', '.');
    }
}
