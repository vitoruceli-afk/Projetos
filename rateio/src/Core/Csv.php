<?php

declare(strict_types=1);

namespace App\Core;

/*
|--------------------------------------------------------------------------
| Csv
|--------------------------------------------------------------------------
|
| Gera o download de um arquivo CSV separado por vírgulas.
| Inclui BOM UTF-8 para abertura correta de acentos no Excel.
|
*/
final class Csv
{
    /**
     * @param string               $arquivo  Nome do arquivo (ex.: contas.csv)
     * @param array<int,string>    $cabecalho Linha de cabeçalho
     * @param array<int,array>     $linhas   Linhas de dados
     */
    public static function download(string $arquivo, array $cabecalho, array $linhas): void
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $arquivo . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $saida = fopen('php://output', 'w');

        // BOM UTF-8 (acentos no Excel)
        fwrite($saida, "\xEF\xBB\xBF");

        // Separador vírgula, conforme solicitado
        fputcsv($saida, $cabecalho, ',', '"', '\\');

        foreach ($linhas as $linha) {
            fputcsv($saida, array_values($linha), ',', '"', '\\');
        }

        fclose($saida);
        exit;
    }
}
