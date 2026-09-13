<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/*
|--------------------------------------------------------------------------
| BaseModel
|--------------------------------------------------------------------------
|
| Classe base para os modelos. Fornece CRUD genérico e busca textual em
| todas as colunas (usada pelos filtros das telas de listagem).
|
*/
abstract class BaseModel
{
    /** Nome da tabela no banco. */
    protected static string $tabela = '';

    /** Colunas pesquisáveis pelo filtro "buscar em todas as colunas". */
    protected static array $colunasBusca = [];

    protected static function pdo(): PDO
    {
        return Database::pdo();
    }

    /**
     * Retorna todos os registros (com ordenação opcional).
     */
    public static function todos(string $ordenarPor = 'id'): array
    {
        $sql = 'SELECT * FROM ' . static::$tabela . ' ORDER BY ' . $ordenarPor;
        return self::pdo()->query($sql)->fetchAll();
    }

    public static function buscarPorId(int $id): ?array
    {
        $stmt = self::pdo()->prepare(
            'SELECT * FROM ' . static::$tabela . ' WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function excluir(int $id): void
    {
        $stmt = self::pdo()->prepare(
            'DELETE FROM ' . static::$tabela . ' WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    /**
     * Exclui vários registros de uma vez.
     *
     * @param array<int,int> $ids
     */
    public static function excluirVarios(array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = self::pdo()->prepare(
            'DELETE FROM ' . static::$tabela . " WHERE id IN ($placeholders)"
        );
        $stmt->execute($ids);
    }

    /**
     * Aplica um termo de busca a todas as colunas pesquisáveis.
     * Retorna a cláusula WHERE e os parâmetros.
     *
     * @return array{0:string, 1:array}
     */
    protected static function clausulaBusca(string $termo, array $colunas): array
    {
        $termo = trim($termo);

        if ($termo === '' || $colunas === []) {
            return ['', []];
        }

        $condicoes = [];
        $params    = [];

        foreach ($colunas as $coluna) {
            $condicoes[] = "$coluna LIKE ?";
            $params[]    = '%' . $termo . '%';
        }

        return ['(' . implode(' OR ', $condicoes) . ')', $params];
    }
}
