<?php

declare(strict_types=1);

namespace App\Models;

/*
|--------------------------------------------------------------------------
| Contato
|--------------------------------------------------------------------------
|
| Contatos de e-mail e a quais listas pertencem (Microsoft / Telefonia).
|
*/
final class Contato extends BaseModel
{
    protected static string $tabela = 'contatos';
    protected static array $colunasBusca = ['nome', 'email'];

    public static function listar(string $busca = ''): array
    {
        [$where, $params] = self::clausulaBusca($busca, self::$colunasBusca);

        $sql = 'SELECT * FROM contatos';
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        $sql .= ' ORDER BY nome';

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Retorna os e-mails de uma lista ('microsoft' | 'telefonia').
     *
     * @return array<int, array{nome:string, email:string}>
     */
    public static function daLista(string $lista): array
    {
        $coluna = $lista === 'telefonia' ? 'lista_telefonia' : 'lista_microsoft';

        $stmt = self::pdo()->prepare(
            "SELECT nome, email FROM contatos WHERE $coluna = 1 ORDER BY nome"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function criar(string $nome, string $email, bool $microsoft, bool $telefonia): int
    {
        $stmt = self::pdo()->prepare('
            INSERT INTO contatos (nome, email, lista_microsoft, lista_telefonia)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$nome, $email, $microsoft ? 1 : 0, $telefonia ? 1 : 0]);
        return (int) self::pdo()->lastInsertId();
    }

    public static function atualizar(int $id, string $nome, string $email, bool $microsoft, bool $telefonia): void
    {
        $stmt = self::pdo()->prepare('
            UPDATE contatos
            SET nome = ?, email = ?, lista_microsoft = ?, lista_telefonia = ?
            WHERE id = ?
        ');
        $stmt->execute([$nome, $email, $microsoft ? 1 : 0, $telefonia ? 1 : 0, $id]);
    }
}
