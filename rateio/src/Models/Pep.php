<?php

declare(strict_types=1);

namespace App\Models;

/*
|--------------------------------------------------------------------------
| Pep
|--------------------------------------------------------------------------
|
| Cadastro central de PEP + Projeto, compartilhado entre os rateios
| Microsoft e Telefonia.
|
*/
final class Pep extends BaseModel
{
    protected static string $tabela = 'peps';
    protected static array $colunasBusca = ['pep', 'projeto'];

    public static function listar(string $busca = ''): array
    {
        [$where, $params] = self::clausulaBusca($busca, self::$colunasBusca);

        $sql = 'SELECT * FROM peps';
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        $sql .= ' ORDER BY pep';

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function criar(string $pep, string $projeto): int
    {
        $stmt = self::pdo()->prepare('INSERT INTO peps (pep, projeto) VALUES (?, ?)');
        $stmt->execute([$pep, $projeto]);
        return (int) self::pdo()->lastInsertId();
    }

    public static function atualizar(int $id, string $pep, string $projeto): void
    {
        $stmt = self::pdo()->prepare('UPDATE peps SET pep = ?, projeto = ? WHERE id = ?');
        $stmt->execute([$pep, $projeto, $id]);
    }

    public static function porCodigo(string $pep): ?array
    {
        $stmt = self::pdo()->prepare('SELECT * FROM peps WHERE pep = ? LIMIT 1');
        $stmt->execute([trim($pep)]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function pepEmUso(string $pep, ?int $ignorarId = null): bool
    {
        $sql = 'SELECT id FROM peps WHERE pep = ?';
        $params = [$pep];
        if ($ignorarId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $ignorarId;
        }
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return (bool) $stmt->fetch();
    }
}
