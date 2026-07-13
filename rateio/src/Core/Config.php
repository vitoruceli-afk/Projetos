<?php

declare(strict_types=1);

namespace App\Core;

/*
|--------------------------------------------------------------------------
| Config
|--------------------------------------------------------------------------
|
| Carrega o arquivo config/config.php e disponibiliza acesso via notação
| de ponto. Ex.: Config::get('db.host').
|
*/
final class Config
{
    private static array $dados = [];

    public static function carregar(string $arquivo): void
    {
        self::$dados = require $arquivo;
    }

    public static function get(string $chave, mixed $padrao = null): mixed
    {
        $partes = explode('.', $chave);
        $valor  = self::$dados;

        foreach ($partes as $parte) {
            if (is_array($valor) && array_key_exists($parte, $valor)) {
                $valor = $valor[$parte];
            } else {
                return $padrao;
            }
        }

        return $valor;
    }

    /**
     * Sobrescreve valores em tempo de execução (ex.: LDAP vindo do banco).
     */
    public static function set(string $chave, mixed $valor): void
    {
        $partes = explode('.', $chave);
        $ref     = &self::$dados;

        foreach ($partes as $i => $parte) {
            if ($i === count($partes) - 1) {
                $ref[$parte] = $valor;
            } else {
                if (!isset($ref[$parte]) || !is_array($ref[$parte])) {
                    $ref[$parte] = [];
                }
                $ref = &$ref[$parte];
            }
        }
    }

    public static function todos(): array
    {
        return self::$dados;
    }
}
