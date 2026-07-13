<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AUTOLOADER + HELPERS GLOBAIS
|--------------------------------------------------------------------------
|
| Carrega automaticamente as classes do namespace "App\" a partir da
| pasta /src e define funções utilitárias usadas nas views.
|
*/

/*
|--------------------------------------------------------------------------
| AUTOLOAD (namespace App\  ->  /src)
|--------------------------------------------------------------------------
*/
spl_autoload_register(static function (string $classe): void {

    $prefixo = 'App\\';

    if (strncmp($prefixo, $classe, strlen($prefixo)) !== 0) {
        return;
    }

    $relativo = substr($classe, strlen($prefixo));
    $arquivo  = __DIR__ . '/' . str_replace('\\', '/', $relativo) . '.php';

    if (is_file($arquivo)) {
        require $arquivo;
    }
});

/*
|--------------------------------------------------------------------------
| e() — escapa saída HTML
|--------------------------------------------------------------------------
*/
if (!function_exists('e')) {
    function e(mixed $valor): string
    {
        return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

/*
|--------------------------------------------------------------------------
| money() — formata valor em Real
|--------------------------------------------------------------------------
*/
if (!function_exists('money')) {
    function money(float|int|string|null $valor): string
    {
        return 'R$ ' . number_format((float) $valor, 2, ',', '.');
    }
}

/*
|--------------------------------------------------------------------------
| valorBr() — interpreta um valor monetário em formato BR ("1.234,56")
|--------------------------------------------------------------------------
*/
if (!function_exists('valorBr')) {
    function valorBr(string $texto): float
    {
        $texto = trim($texto);
        if ($texto === '') {
            return 0.0;
        }
        // Quando há vírgula, ela é o separador decimal e o ponto é de milhar.
        if (str_contains($texto, ',')) {
            $texto = str_replace('.', '', $texto);
            $texto = str_replace(',', '.', $texto);
        }
        return (float) $texto;
    }
}

/*
|--------------------------------------------------------------------------
| url() — monta uma URL respeitando a base_url da configuração
|--------------------------------------------------------------------------
*/
if (!function_exists('url')) {
    function url(string $caminho = ''): string
    {
        $base = rtrim(\App\Core\Config::get('app.base_url', ''), '/');
        return $base . '/' . ltrim($caminho, '/');
    }
}
