<?php

declare(strict_types=1);

namespace App\Core;

/*
|--------------------------------------------------------------------------
| CsvReader
|--------------------------------------------------------------------------
|
| Lê um arquivo CSV enviado para importação em massa.
| - Remove BOM UTF-8.
| - Detecta o separador (vírgula ou ponto-e-vírgula).
| - Detecta e descarta a linha de cabeçalho.
|
*/
final class CsvReader
{
    /**
     * @param array<int,string> $cabecalhoEsperado nomes de colunas (para detectar header)
     *
     * @return array<int,array<int,string>> linhas de dados (sem cabeçalho)
     */
    public static function ler(string $caminhoArquivo, array $cabecalhoEsperado = []): array
    {
        $conteudo = file_get_contents($caminhoArquivo);
        if ($conteudo === false) {
            return [];
        }

        // Remove BOM UTF-8
        $conteudo = preg_replace('/^\xEF\xBB\xBF/', '', $conteudo);

        // Detecta separador pela primeira linha
        $primeiraLinha = strtok($conteudo, "\r\n") ?: '';
        $sep = substr_count($primeiraLinha, ';') > substr_count($primeiraLinha, ',') ? ';' : ',';

        $linhas = [];
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $conteudo);
        rewind($stream);

        while (($campos = fgetcsv($stream, 0, $sep, '"', '\\')) !== false) {
            // ignora linhas totalmente vazias
            if ($campos === [null] || (count($campos) === 1 && trim((string) $campos[0]) === '')) {
                continue;
            }
            $linhas[] = array_map(static fn($v) => trim((string) ($v ?? '')), $campos);
        }
        fclose($stream);

        // Descarta cabeçalho se a primeira linha parecer um cabeçalho
        if ($linhas !== [] && self::pareceCabecalho($linhas[0], $cabecalhoEsperado)) {
            array_shift($linhas);
        }

        return $linhas;
    }

    private static function pareceCabecalho(array $linha, array $cabecalhoEsperado): bool
    {
        if ($cabecalhoEsperado === []) {
            return false;
        }
        $a = array_map(static fn($v) => mb_strtolower(trim((string) $v)), $linha);
        foreach ($cabecalhoEsperado as $col) {
            if (in_array(mb_strtolower($col), $a, true)) {
                return true;
            }
        }
        return false;
    }
}
