<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
|
| Singleton de conexão PDO. Use Database::pdo() em qualquer lugar para
| obter a instância única de conexão.
|
*/
final class Database
{
    private static ?PDO $instancia = null;

    private function __construct()
    {
    }

    public static function pdo(): PDO
    {
        if (self::$instancia instanceof PDO) {
            return self::$instancia;
        }

        $host    = Config::get('db.host', 'localhost');
        $porta   = (int) Config::get('db.porta', 3306);
        $banco   = Config::get('db.banco');
        $usuario = Config::get('db.usuario');
        $senha   = Config::get('db.senha');
        $charset = Config::get('db.charset', 'utf8mb4');

        $dsn = "mysql:host={$host};port={$porta};dbname={$banco};charset={$charset}";

        try {
            self::$instancia = new PDO($dsn, $usuario, $senha, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            die('Erro na conexão com o banco de dados: ' . $e->getMessage());
        }

        return self::$instancia;
    }
}
