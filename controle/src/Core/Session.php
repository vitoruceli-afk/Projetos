<?php

declare(strict_types=1);

namespace App\Core;

/*
|--------------------------------------------------------------------------
| Session
|--------------------------------------------------------------------------
|
| Encapsula o início de sessão (com cookie seguro) e mensagens flash
| (mensagens exibidas uma única vez após um redirecionamento).
|
*/
final class Session
{
    public static function iniciar(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'domain'   => '',
                'secure'   => false,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            session_start();
        }
    }

    public static function set(string $chave, mixed $valor): void
    {
        $_SESSION[$chave] = $valor;
    }

    public static function get(string $chave, mixed $padrao = null): mixed
    {
        return $_SESSION[$chave] ?? $padrao;
    }

    public static function tem(string $chave): bool
    {
        return isset($_SESSION[$chave]);
    }

    public static function remover(string $chave): void
    {
        unset($_SESSION[$chave]);
    }

    public static function destruir(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $p['path'],
                $p['domain'],
                $p['secure'],
                $p['httponly']
            );
        }

        session_destroy();
    }

    /*
    |----------------------------------------------------------------------
    | MENSAGENS FLASH
    |----------------------------------------------------------------------
    */
    public static function flash(string $tipo, string $texto): void
    {
        $_SESSION['_flash'][] = ['tipo' => $tipo, 'texto' => $texto];
    }

    /**
     * @return array<int, array{tipo:string, texto:string}>
     */
    public static function pegarFlash(): array
    {
        $msgs = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $msgs;
    }
}
